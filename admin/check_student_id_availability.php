<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$rawBody = get_raw_request_body();
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request payload']);
    exit;
}

$studentCode = trim((string)($data['student_id'] ?? ''));
if (!preg_match('/^\d{9}$/', $studentCode)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Student ID must be exactly 9 digits.']);
    exit;
}

$expectedUserId = filter_var($data['expected_user_id'] ?? null, FILTER_VALIDATE_INT);

try {
    $pdo = pdo();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE student_id = ? AND role = "Student" AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$studentCode]);
    $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$existing) {
        echo json_encode([
            'success' => true,
            'available' => true,
            'code' => 'available',
            'message' => 'PASS: Student ID is available.'
        ]);
        exit;
    }

    if ($expectedUserId && (int)$existing['id'] === (int)$expectedUserId) {
        echo json_encode([
            'success' => true,
            'available' => true,
            'code' => 'same_student',
            'message' => 'PASS: Student ID belongs to this student record.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'available' => false,
        'code' => 'already_taken',
        'message' => 'This student ID is already taken.'
    ]);
} catch (\PDOException $e) {
    error_log('Student ID availability check DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while checking student ID availability']);
}
