<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/admin_face_helper.php';

header('Content-Type: application/json');
send_no_cache_headers();

if (!isset($_SESSION['superadmin_logged_in']) || $_SESSION['superadmin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

require_permission('admin.create', [
    'actor_role' => 'superadmin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission admin.create.',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Face recognition is disabled. Enable it to register new admins.']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));
$studentId = trim($_POST['student_id'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if (empty($name) || empty($email) || empty($studentId) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'All fields are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email format']);
    exit;
}

if (strlen($password) < 12) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must be at least 12 characters']);
    exit;
}

if (!preg_match('/[A-Z]/', $password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must include at least one uppercase letter']);
    exit;
}

if (!preg_match('/[^A-Za-z0-9]/', $password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must include at least one special character']);
    exit;
}

if ($password !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Passwords do not match']);
    exit;
}

try {
    $pdo = pdo();
    ensure_admin_face_registration_tables($pdo);
    admin_face_cleanup_expired_registrations($pdo);

    // Prevent duplicates in real tables
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Email already exists']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE student_id = ? LIMIT 1');
    $stmt->execute([$studentId]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Admin ID already exists']);
        exit;
    }

    // Also prevent duplicates in staging
    $stmt = $pdo->prepare('SELECT id FROM admin_face_registration_tokens WHERE email = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'A pending registration already exists for this email']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM admin_face_registration_tokens WHERE student_id = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([$studentId]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'A pending registration already exists for this Admin ID']);
        exit;
    }

    $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

    $rawToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $ttlMinutes = (int)env('ADMIN_REGISTRATION_TOKEN_TTL_MINUTES', '30');
    if ($ttlMinutes < 5 || $ttlMinutes > 180) {
        $ttlMinutes = 30;
    }

    // Store expiry in UTC so token validity checks are timezone-safe across app/DB settings.
    $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

    $stmt = $pdo->prepare('
        INSERT INTO admin_face_registration_tokens
            (token, name, email, student_id, password_hash, created_by_superadmin_id, expires_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $rawToken,
        $name,
        $email,
        $studentId,
        $passwordHash,
        (int)$_SESSION['superadmin_id'],
        $expiresAt,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Proceed to face enrollment to complete admin registration.',
        'token' => $rawToken,
        'enrollment_url' => 'enroll_admin_face_registration.php?token=' . urlencode($rawToken),
        'expires_minutes' => $ttlMinutes,
    ]);

} catch (Throwable $e) {
    error_log('start_admin_registration error: ' . $e->getMessage());
    http_response_code(500);
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Unable to start admin registration']);
    }
}
