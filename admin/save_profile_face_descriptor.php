<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/encryption_helper.php';
require_once __DIR__ . '/../includes/profile_face_descriptor_helper.php';

header('Content-Type: application/json');
send_api_security_headers();
require_same_origin_api_request();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('student.update', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission student.update.',
]);

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!check_rate_limit('save_profile_face_descriptor', 40, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait before trying again.']);
    exit;
}

try {
    $payload = get_json_input();

    $studentId = filter_var($payload['student_id'] ?? null, FILTER_VALIDATE_INT);
    $descriptor = $payload['descriptor'] ?? null;
    $detectionScore = filter_var($payload['detection_score'] ?? null, FILTER_VALIDATE_FLOAT);
    $sourcePicture = trim((string)($payload['source_picture'] ?? ''));
    if ($sourcePicture !== '') {
        $sourcePicture = substr($sourcePicture, 0, 255);
    } else {
        $sourcePicture = '';
    }

    if (!$studentId || $studentId <= 0) {
        throw new Exception('Invalid student ID');
    }
    if (!is_array($descriptor) || count($descriptor) !== 128) {
        throw new Exception('Invalid face descriptor payload');
    }

    foreach ($descriptor as $i => $value) {
        if (!is_numeric($value)) {
            throw new Exception('Descriptor values must be numeric');
        }
        $descriptor[$i] = (float)$value;
    }

    if ($detectionScore === false || $detectionScore === null || $detectionScore < 0 || $detectionScore > 1) {
        $detectionScore = null;
    } else {
        $detectionScore = (float)$detectionScore;
    }

    $pdo = pdo();
    $stmt = $pdo->prepare("SELECT id, profile_picture FROM users WHERE id = ? AND role = 'Student' LIMIT 1");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        throw new Exception('Student not found');
    }

    $encrypted = encrypt_descriptor(json_encode($descriptor, JSON_THROW_ON_ERROR));

    upsert_profile_face_descriptor(
        $pdo,
        (int)$studentId,
        $encrypted['ciphertext'],
        $encrypted['iv'],
        $encrypted['tag'],
        $detectionScore,
        $sourcePicture !== '' ? $sourcePicture : (string)($student['profile_picture'] ?? ''),
        isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null
    );

    rotate_csrf_after_critical_action();

    echo json_encode([
        'success' => true,
        'message' => 'Profile face descriptor saved successfully.',
    ]);
} catch (\PDOException $e) {
    error_log('save_profile_face_descriptor database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (RuntimeException $e) {
    error_log('save_profile_face_descriptor encryption error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Encryption error while saving descriptor']);
} catch (Throwable $e) {
    http_response_code(400);
    if (APP_DEBUG) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid face descriptor request']);
    }
}

