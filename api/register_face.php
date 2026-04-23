<?php

/**
 * API: Register Face Descriptor
 * Endpoint: POST /api/register_face.php
 * 
 * Security: Admin-only, CSRF protected, rate limited, encrypted storage
 * Receives a face descriptor from the admin panel and stores it encrypted.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/encryption_helper.php';

header('Content-Type: application/json');
send_api_security_headers();
require_same_origin_api_request();

// ---- AUTH CHECK ----
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Admin login required']);
    exit;
}

require_permission('face.register', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission face.register.',
]);

// ---- CSRF CHECK ----
$headers = getallheaders();
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// ---- METHOD CHECK ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---- RATE LIMITING ----
if (!check_rate_limit('face_register', 10, 3600)) { // 10 registrations per hour per IP
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait before trying again.']);
    exit;
}

// ---- FEATURE CHECK ----
if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Face recognition is disabled']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    // Validate required fields
    $studentId = filter_var($input['student_id'] ?? null, FILTER_VALIDATE_INT);
    $descriptor = $input['descriptor'] ?? null;
    $label = trim($input['label'] ?? 'front');
    $qualityScore = filter_var($input['quality_score'] ?? 0, FILTER_VALIDATE_FLOAT);
    
    if (!$studentId || $studentId <= 0) {
        throw new Exception('Invalid student ID');
    }
    
    if (!is_array($descriptor) || count($descriptor) !== 128) {
        throw new Exception('Invalid face descriptor - must be 128-dimensional array');
    }
    
    // Validate each descriptor value is a float
    foreach ($descriptor as $i => $val) {
        if (!is_numeric($val)) {
            throw new Exception("Invalid descriptor value at index $i");
        }
        $descriptor[$i] = (float)$val;
    }
    
    // Sanitize label
    $allowedLabels = ['front', 'left', 'right'];
    if (!in_array($label, $allowedLabels)) {
        $label = 'front';
    }
    
    // Validate quality score
    if ($qualityScore < 0 || $qualityScore > 1) {
        $qualityScore = 0;
    }
    
    $pdo = pdo();
    
    // Verify student exists and is active
    $stmt = $pdo->prepare("SELECT id, name, student_id, status FROM users WHERE id = ? AND role = 'Student'");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Student not found');
    }
    
    if ($student['status'] !== 'Active') {
        throw new Exception('Student account is not active');
    }

    // Cross-student duplicate-face protection:
    // block enrollment if this face already matches another student's active descriptor.
    $crossUserBlockThreshold = (float)env('FACE_ENROLL_CROSS_USER_BLOCK_THRESHOLD', (string)env('FACE_MATCH_THRESHOLD', '0.45'));
    if ($crossUserBlockThreshold <= 0 || $crossUserBlockThreshold > 2) {
        $crossUserBlockThreshold = 0.45;
    }

    $crossStudentSql = <<<'SQL'
        SELECT fd.user_id, fd.descriptor_data, fd.descriptor_iv, fd.descriptor_tag, u.name, u.student_id
        FROM face_descriptors fd
        INNER JOIN users u ON fd.user_id = u.id
        WHERE fd.is_active = 1
          AND fd.user_id != ?
          AND u.role = 'Student'
    SQL;
    $stmt = $pdo->prepare($crossStudentSql);
    $stmt->execute([$studentId]);
    $otherStudentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $closestOtherDistance = PHP_FLOAT_MAX;
    $closestOtherMatch = null;

    foreach ($otherStudentRows as $row) {
        try {
            $decrypted = decrypt_descriptor(
                $row['descriptor_data'],
                $row['descriptor_iv'],
                $row['descriptor_tag']
            );
            $existingDescriptor = json_decode($decrypted, true);

            if (!is_array($existingDescriptor) || count($existingDescriptor) !== 128) {
                continue;
            }

            $sum = 0.0;
            for ($i = 0; $i < 128; $i++) {
                $diff = $descriptor[$i] - (float)$existingDescriptor[$i];
                $sum += $diff * $diff;
            }
            $distance = sqrt($sum);

            if ($distance < $closestOtherDistance) {
                $closestOtherDistance = $distance;
                $closestOtherMatch = $row;
            }
        } catch (\Throwable $e) {
            error_log('Cross-student duplicate check: descriptor row skipped for candidate user ' . (int)$row['user_id'] . ': ' . $e->getMessage());
            continue;
        }
    }

    if ($closestOtherDistance < $crossUserBlockThreshold && is_array($closestOtherMatch)) {
        $matchedStudentName = (string)($closestOtherMatch['name'] ?? 'another student');
        $matchedStudentCode = (string)($closestOtherMatch['student_id'] ?? 'N/A');
        throw new Exception('This face is already enrolled to another student account (' . $matchedStudentName . ' - ' . $matchedStudentCode . '). Enrollment blocked.');
    }
    
    // Check max descriptors per student
    $maxDescriptors = (int)env('FACE_MAX_DESCRIPTORS_PER_STUDENT', '3');
    if ($maxDescriptors <= 0 || $maxDescriptors > 3) {
        $maxDescriptors = 3;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM face_descriptors WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$studentId]);
    $currentCount = (int)$stmt->fetchColumn();
    
    if ($currentCount >= $maxDescriptors) {
        throw new Exception("Maximum of $maxDescriptors face descriptors per student reached. Delete existing ones first.");
    }
    
    // Check for duplicate label
    $stmt = $pdo->prepare("SELECT id FROM face_descriptors WHERE user_id = ? AND label = ? AND is_active = 1");
    $stmt->execute([$studentId, $label]);
    if ($stmt->fetch()) {
        throw new Exception("A face descriptor with label '$label' already exists for this student. Choose a different angle or delete the existing one.");
    }
    
    // ── Inter-descriptor distance validation ──
    // Fetch existing descriptors for this student and compare
    $stmt = $pdo->prepare("
        SELECT descriptor_data, descriptor_iv, descriptor_tag 
        FROM face_descriptors 
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([$studentId]);
    $existingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($existingRows)) {
        $duplicateThreshold = 0.15; // Nearly identical = likely same capture
        $inconsistentThreshold = 0.7; // Too different = likely different person
        
        foreach ($existingRows as $row) {
            try {
                $decrypted = decrypt_descriptor(
                    $row['descriptor_data'],
                    $row['descriptor_iv'],
                    $row['descriptor_tag']
                );
                $existingDescriptor = json_decode($decrypted, true);
                
                if (!is_array($existingDescriptor) || count($existingDescriptor) !== 128) continue;
                
                // Euclidean distance
                $sum = 0.0;
                for ($i = 0; $i < 128; $i++) {
                    $diff = (float)$descriptor[$i] - (float)$existingDescriptor[$i];
                    $sum += $diff * $diff;
                }
                $distance = sqrt($sum);
                
                if ($distance < $duplicateThreshold) {
                    throw new Exception('This descriptor is too similar to an existing one (likely duplicate capture). Please capture a different angle.');
                }
                
                if ($distance > $inconsistentThreshold) {
                    throw new Exception('This descriptor is too different from existing ones. Ensure this is the same student, or re-enroll from scratch.');
                }
            } catch (\Throwable $e) {
                // Corrupt or legacy descriptor row — skip and continue with remaining rows
                error_log('Inter-descriptor check: descriptor row skipped for user ' . $studentId . ': ' . $e->getMessage());
                continue;
            }
        }
    }
    
    // Encrypt the descriptor
    $descriptorJson = json_encode($descriptor);
    $encrypted = encrypt_descriptor($descriptorJson);
    
    // Store encrypted descriptor
    $pdo->beginTransaction();
    
    try {
        // Bump global version counter atomically
        $pdo->exec("UPDATE face_descriptor_version_counter SET current_version = current_version + 1 WHERE id = 1");
        $newVersion = (int)$pdo->query("SELECT current_version FROM face_descriptor_version_counter WHERE id = 1")->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO face_descriptors 
            (user_id, descriptor_data, descriptor_iv, descriptor_tag, label, quality_score, registered_by, is_active, version, descriptor_dimension)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
        ");
        $stmt->execute([
            $studentId,
            $encrypted['ciphertext'],
            $encrypted['iv'],
            $encrypted['tag'],
            $label,
            $qualityScore,
            $_SESSION['admin_id'],
            $newVersion,
            count($descriptor)
        ]);
        
        $descriptorId = $pdo->lastInsertId();
        
        // Update users table denormalized flag
        $stmt = $pdo->prepare("UPDATE users SET face_registered = 1, face_registered_at = NOW() WHERE id = ?");
        $stmt->execute([$studentId]);
        
        // Log the registration
        $stmt = $pdo->prepare("
            INSERT INTO face_registration_log 
            (user_id, action, descriptor_count, performed_by, ip_address, user_agent)
            VALUES (?, 'registered', 1, ?, ?, ?)
        ");
        $stmt->execute([
            $studentId,
            $_SESSION['admin_id'],
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
        
        $pdo->commit();
        rotate_csrf_after_critical_action();
        
        echo json_encode([
            'success' => true,
            'message' => "Face descriptor ($label) registered for " . $student['name'],
            'descriptor_id' => $descriptorId,
            'total_descriptors' => $currentCount + 1
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (\PDOException $e) {
    error_log('Face registration database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while registering face descriptor. Please try again.']);
} catch (RuntimeException $e) {
    // Encryption/config-related runtime errors
    error_log('Face registration encryption error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Encryption error - check server configuration']);
} catch (Exception $e) {
    error_log('Face registration error: ' . $e->getMessage());
    $statusCode = 400;
    if (stripos($e->getMessage(), 'already enrolled') !== false) {
        $statusCode = 409;
    }
    http_response_code($statusCode);
    if (APP_DEBUG) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid registration request']);
    }
}
