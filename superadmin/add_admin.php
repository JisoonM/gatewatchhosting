<?php

require_once __DIR__ . '/../db.php';

// Set JSON response header
header('Content-Type: application/json');
send_no_cache_headers();

// Check if super admin is logged in
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

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Verify CSRF token
$csrfToken = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Enforce face-first admin registration when face recognition is enabled.
if (filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error' => 'Face enrollment is required before creating a new admin. Please use the admin registration flow.',
        'enrollment_start' => 'start_admin_registration.php',
    ]);
    exit;
}

// Get form data
$name = trim($_POST['name'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));
$studentId = trim($_POST['student_id'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// Validate input
if (empty($name) || empty($email) || empty($studentId) || empty($password)) {
    echo json_encode(['success' => false, 'error' => 'All fields are required']);
    exit;
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid email format']);
    exit;
}

// Enforce strong password policy for admin accounts.
if (strlen($password) < 12) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 12 characters']);
    exit;
}

if (!preg_match('/[A-Z]/', $password)) {
    echo json_encode(['success' => false, 'error' => 'Password must include at least one uppercase letter']);
    exit;
}

if (!preg_match('/[^A-Za-z0-9]/', $password)) {
    echo json_encode(['success' => false, 'error' => 'Password must include at least one special character']);
    exit;
}

// Validate passwords match
if ($password !== $confirmPassword) {
    echo json_encode(['success' => false, 'error' => 'Passwords do not match']);
    exit;
}

try {
    $pdo = pdo();
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Email already exists']);
        exit;
    }
    
    // Check if student_id already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE student_id = ?");
    $stmt->execute([$studentId]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Admin ID already exists']);
        exit;
    }
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_ARGON2ID);
    
    // Insert new admin into users table
    $stmt = $pdo->prepare("
        INSERT INTO users (student_id, name, email, password, role, status, created_at) 
        VALUES (?, ?, ?, ?, 'Admin', 'Active', NOW())
    ");
    $stmt->execute([$studentId, $name, $email, $hashedPassword]);
    $newAdminId = $pdo->lastInsertId();
    
    // Insert into admin_accounts table for tracking
    $stmt = $pdo->prepare("
        INSERT INTO admin_accounts (user_id, created_by, status, created_at) 
        VALUES (?, ?, 'Active', NOW())
    ");
    $stmt->execute([$newAdminId, $_SESSION['superadmin_id']]);
    $adminAccountId = $pdo->lastInsertId();
    
    // Log the action
    $stmt = $pdo->prepare("
        INSERT INTO superadmin_audit_log (super_admin_id, action, target_admin_id, details, ip_address, user_agent) 
        VALUES (?, 'CREATE_ADMIN', ?, ?, ?, ?)
    ");
    $stmt->execute([
        $_SESSION['superadmin_id'],
        $adminAccountId,
        json_encode(['name' => $name, 'email' => $email, 'admin_id' => $studentId]),
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);
    
    // Commit transaction
    $pdo->commit();
    rotate_csrf_after_critical_action();
    apply_session_isolation_on_privilege_change([
        'target_user_id' => (int)$newAdminId,
        'target_role' => 'admin',
    ]);

    $faceEnabled = filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    
    echo json_encode([
        'success' => true,
        'message' => "Admin account for '{$name}' has been created successfully.",
        'admin_user_id' => (int)$newAdminId,
        'admin_account_id' => (int)$adminAccountId,
        'face_enrollment_required' => $faceEnabled,
        'enroll_url' => $faceEnabled ? ('enroll_admin_face.php?admin_id=' . (int)$newAdminId) : null,
    ]);
    
} catch (PDOException $e) {
    // Rollback on error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Add Admin error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred. Please try again.']);
}
