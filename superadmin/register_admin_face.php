<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/encryption_helper.php';
require_once __DIR__ . '/includes/admin_face_helper.php';

header('Content-Type: application/json');
send_api_security_headers();
require_same_origin_api_request();

// ---- AUTH CHECK ----
if (!isset($_SESSION['superadmin_logged_in']) || $_SESSION['superadmin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('admin.create', [
    'actor_role' => 'superadmin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission admin.create.',
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
if (!check_rate_limit('superadmin_admin_face_register', 30, 3600)) { // 30 registrations per hour per IP
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

    // NOTE: We reuse the client payload key `student_id` from FaceRecognitionSystem.registerFace().
    // Here it represents the target admin's `users.id`.
    $adminUserId = filter_var($input['student_id'] ?? null, FILTER_VALIDATE_INT);
    $descriptor = $input['descriptor'] ?? null;
    $label = trim((string)($input['label'] ?? 'front'));
    $qualityScore = filter_var($input['quality_score'] ?? 0, FILTER_VALIDATE_FLOAT);

    if (!$adminUserId || $adminUserId <= 0) {
        throw new Exception('Invalid admin user ID');
    }

    if (!is_array($descriptor) || count($descriptor) !== 128) {
        throw new Exception('Invalid face descriptor - must be 128-dimensional array');
    }

    foreach ($descriptor as $i => $val) {
        if (!is_numeric($val)) {
            throw new Exception("Invalid descriptor value at index $i");
        }
        $descriptor[$i] = (float)$val;
    }

    $allowedLabels = ['front', 'left', 'right', 'up', 'down'];
    $label = strtolower($label);
    if (!in_array($label, $allowedLabels, true)) {
        $label = 'front';
    }

    if ($qualityScore < 0 || $qualityScore > 1) {
        $qualityScore = 0;
    }

    $pdo = pdo();
    ensure_admin_face_tables($pdo);

    // Verify admin exists
    $stmt = $pdo->prepare("SELECT id, name, email, status FROM users WHERE id = ? AND role = 'Admin' LIMIT 1");
    $stmt->execute([$adminUserId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        throw new Exception('Admin not found');
    }

    // Prevent enrollment for deleted accounts (if soft-delete is used elsewhere)
    if (array_key_exists('deleted_at', $admin) && !empty($admin['deleted_at'])) {
        throw new Exception('Admin account is deleted');
    }

    // Basic caps
    $maxDescriptors = (int)env('FACE_MAX_DESCRIPTORS_PER_ADMIN', '3');
    if ($maxDescriptors < 1 || $maxDescriptors > 10) {
        $maxDescriptors = 3;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM admin_face_descriptors WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$adminUserId]);
    $currentCount = (int)$stmt->fetchColumn();

    if ($currentCount >= $maxDescriptors) {
        throw new Exception("Maximum of $maxDescriptors face descriptors per admin reached.");
    }

    // Cross-student duplicate-face protection:
    // block admin enrollment if this face already matches any student's active descriptor.
    $crossUserBlockThreshold = (float)env('FACE_ENROLL_CROSS_USER_BLOCK_THRESHOLD', (string)env('FACE_MATCH_THRESHOLD', '0.45'));
    if ($crossUserBlockThreshold <= 0 || $crossUserBlockThreshold > 2) {
        $crossUserBlockThreshold = 0.45;
    }

    $stmt = $pdo->prepare("\
        SELECT fd.user_id, fd.descriptor_data, fd.descriptor_iv, fd.descriptor_tag, u.name, u.student_id\
        FROM face_descriptors fd\
        INNER JOIN users u ON fd.user_id = u.id\
        WHERE fd.is_active = 1\
          AND u.role = 'Student'\
    ");
    $stmt->execute();
    $studentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $closestDistance = PHP_FLOAT_MAX;
    $closestMatch = null;

    foreach ($studentRows as $row) {
        try {
            $decrypted = decrypt_descriptor(
                (string)$row['descriptor_data'],
                (string)$row['descriptor_iv'],
                (string)$row['descriptor_tag']
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

            if ($distance < $closestDistance) {
                $closestDistance = $distance;
                $closestMatch = $row;
            }
        } catch (RuntimeException $e) {
            continue;
        }
    }

    if ($closestDistance < $crossUserBlockThreshold && is_array($closestMatch)) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'This face is already enrolled to a student account. Enrollment blocked.',
            'conflict' => [
                'student_name' => (string)($closestMatch['name'] ?? ''),
                'student_id' => (string)($closestMatch['student_id'] ?? ''),
                'distance' => $closestDistance,
                'threshold' => $crossUserBlockThreshold,
            ],
        ]);
        exit;
    }

    // Enforce one active per label
    $stmt = $pdo->prepare('SELECT id FROM admin_face_descriptors WHERE user_id = ? AND label = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$adminUserId, $label]);
    if ($stmt->fetch()) {
        throw new Exception("A face descriptor with label '$label' already exists for this admin.");
    }

    $descriptorJson = json_encode($descriptor);
    $encrypted = encrypt_descriptor($descriptorJson);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO admin_face_descriptors
                (user_id, descriptor_data, descriptor_iv, descriptor_tag, label, quality_score, registered_by_superadmin_id, is_active, descriptor_dimension)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 1, 128)
        ');
        $stmt->execute([
            $adminUserId,
            $encrypted['ciphertext'],
            $encrypted['iv'],
            $encrypted['tag'],
            $label,
            $qualityScore,
            (int)($_SESSION['superadmin_id'] ?? 0) ?: null,
        ]);

        // Best-effort audit log entry; do not store descriptor.
        try {
            $audit = $pdo->prepare('
                INSERT INTO superadmin_audit_log (super_admin_id, action, target_admin_id, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $audit->execute([
                (int)$_SESSION['superadmin_id'],
                'ENROLL_ADMIN_FACE',
                null,
                json_encode([
                    'admin_user_id' => $adminUserId,
                    'admin_email' => (string)($admin['email'] ?? ''),
                    'label' => $label,
                    'quality_score' => $qualityScore,
                ]),
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            ]);
        } catch (Throwable $e) {
            // Ignore audit failures.
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM admin_face_descriptors WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$adminUserId]);
    $total = (int)$stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => 'Admin face descriptor registered successfully.',
        'total_descriptors' => $total,
        'label' => $label
    ]);

} catch (Exception $e) {
    http_response_code(400);
    error_log('register_admin_face error: ' . $e->getMessage());
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid face registration request']);
    }
}
