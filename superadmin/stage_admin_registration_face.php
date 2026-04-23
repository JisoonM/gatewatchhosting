<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/encryption_helper.php';
require_once __DIR__ . '/includes/admin_face_helper.php';

header('Content-Type: application/json');
send_api_security_headers();
require_same_origin_api_request();

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

$headers = getallheaders();
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!check_rate_limit('superadmin_admin_registration_face', 60, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait before trying again.']);
    exit;
}

if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Face recognition is disabled']);
    exit;
}

try {
    $input = json_decode(get_raw_request_body(), true);
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $token = trim((string)($input['token'] ?? ''));
    $descriptor = $input['descriptor'] ?? null;
    $label = strtolower(trim((string)($input['label'] ?? 'front')));
    $qualityScore = filter_var($input['quality_score'] ?? 0, FILTER_VALIDATE_FLOAT);

    if ($token === '' || strlen($token) < 20) {
        throw new Exception('Invalid registration token');
    }

    $allowedLabels = ['front', 'left', 'right'];
    if (!in_array($label, $allowedLabels, true)) {
        throw new Exception('Invalid label');
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

    if ($qualityScore < 0 || $qualityScore > 1) {
        $qualityScore = 0;
    }

    $pdo = pdo();
    ensure_admin_face_registration_tables($pdo);
    admin_face_cleanup_expired_registrations($pdo);

    // Verify token exists and belongs to this superadmin
    $stmt = $pdo->prepare('SELECT id, name, email, student_id, created_by_superadmin_id FROM admin_face_registration_tokens WHERE token = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([$token]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        http_response_code(410);
        echo json_encode(['success' => false, 'error' => 'Registration token expired or invalid']);
        exit;
    }

    if ((int)$reg['created_by_superadmin_id'] !== (int)($_SESSION['superadmin_id'] ?? 0)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    // Prevent duplicate label in staging
    $stmt = $pdo->prepare('SELECT id FROM admin_face_registration_faces WHERE token = ? AND label = ? LIMIT 1');
    $stmt->execute([$token, $label]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => "A face descriptor with label '$label' is already staged."]); 
        exit;
    }

    // Cross-student duplicate-face protection:
    // block admin registration if this face matches any active STUDENT descriptor.
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

    $encrypted = encrypt_descriptor(json_encode($descriptor));

    $stmt = $pdo->prepare('
        INSERT INTO admin_face_registration_faces
            (token, descriptor_data, descriptor_iv, descriptor_tag, label, quality_score)
        VALUES
            (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $token,
        $encrypted['ciphertext'],
        $encrypted['iv'],
        $encrypted['tag'],
        $label,
        $qualityScore,
    ]);

    $stmt = $pdo->prepare('SELECT label FROM admin_face_registration_faces WHERE token = ? ORDER BY created_at ASC');
    $stmt->execute([$token]);
    $labels = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'message' => 'Face descriptor staged successfully.',
        'labels' => array_values($labels),
        'label' => $label,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid face enrollment request']);
    }
}
