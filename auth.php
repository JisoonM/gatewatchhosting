<?php
// auth.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

send_security_headers();

$action = $_POST['action'] ?? '';
$redirectBack = function(string $page, array $params = []) {
    $q = http_build_query($params);
    header("Location: {$page}" . ($q ? "?{$q}" : ''));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

verify_csrf();

try {
    switch ($action) {
        case 'signup':
            handleSignup();
            break;
        case 'login':
            handleLogin();
            break;
        case 'logout':
            logout_student_session();
            $_SESSION['toast'] = 'Logged out';
            send_no_cache_headers();
            header('Location: login.php');
            exit;
        case 'set_toast':
            // Keep toast content bounded and text-only for safe session storage.
            $toastMessage = trim((string)($_POST['message'] ?? ''));
            $_SESSION['toast'] = substr(strip_tags($toastMessage), 0, 255);
            echo json_encode(['success' => true]);
            exit;
        default:
            http_response_code(400);
            exit('Unknown action');
    }
} catch (Throwable $e) {
    error_log("[PCU RFID Error] " . $e->getMessage());
    error_log("[PCU RFID Error] Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    if (APP_DEBUG) {
        echo "<h1>Server Error</h1>";
        echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    exit('Server error');
}

function handleSignup(): void {
    $pdo = pdo();

    // Get and validate form data
    $student_id = trim($_POST['student_id'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $surname    = trim($_POST['surname'] ?? '');
    
    // Combine name fields into full name
    $name_parts = array_filter([$first_name, $middle_name, $surname]);
    $name = implode(' ', $name_parts);
    
    $email      = strtolower(trim($_POST['email'] ?? ''));
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $role       = 'Student'; // Set default role to Student

    // Only log whether fields were present, never the values themselves.
    error_log("[PCU RFID] Signup attempt: fields_present=" . json_encode([
        'student_id'   => !empty($student_id),
        'first_name'   => !empty($first_name),
        'surname'      => !empty($surname),
        'email'        => !empty($email),
        'has_password' => !empty($password),
    ]));

    if (!$student_id || !$first_name || !$surname || !$email || !$password || !$confirm) {
        redirect_error('signup.php', 'Please fill in all required fields.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirect_error('signup.php', 'Invalid email.');
    }
    if ($password !== $confirm) {
        redirect_error('signup.php', 'Passwords do not match.');
    }
    if (strlen($password) < 8) {
        redirect_error('signup.php', 'Password must be at least 8 characters.');
    }

    // Always set role as Student and level as College for new signups
    $role = 'Student';
    $_POST['student_type'] = 'college'; // Ensure college type is set

    // Check duplicates by email only. New accounts are assigned TEMP IDs until admin verification.
    $stmt = $pdo->prepare('SELECT id, email, student_id, role, verification_status FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':email' => $email]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // If trying to register with admin email
        if ($existing['role'] === 'Admin' && $existing['email'] === $email) {
            $_SESSION['info'] = 'This email is already registered. If you are the administrator, please use the admin login page. If this is your first time, please wait for verification approval.';
            header('Location: login.php');
            exit;
        }
        // If email already exists
        redirect_error('signup.php', 'Email already in use.');
    }

    $hash = password_hash($password, PASSWORD_ARGON2ID);
    $temporaryStudentId = generate_temporary_student_id($pdo);

    // Create user with Pending status and pending verification
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO users (student_id, name, email, password, role, status, verification_status) VALUES (:sid, :name, :email, :pass, :role, "Pending", "pending")');
        $stmt->execute([
            ':sid' => $temporaryStudentId,
            ':name' => $name,
            ':email' => $email,
            ':pass' => $hash,
            ':role' => $role,
        ]);
        $userId = (int)$pdo->lastInsertId();

        $pdo->commit();

        // Send registration submitted email
        $subject = 'PCU RFID System - Registration Submitted';
        $body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                <div style="background-color: #0056b3; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                    <h1 style="color: white; margin: 0; font-size: 28px;">PCU RFID System</h1>
                </div>
                <div style="background-color: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px;">
                    <h2 style="color: #0056b3; margin-top: 0;">Registration Received!</h2>
                    <p>Hello ' . htmlspecialchars($name) . ',</p>
                    <p>Thank you for registering with the PCU RFID System. Your account has been successfully created and is now awaiting verification by the Student Services Office.</p>
                    
                    <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin: 0; color: #856404;"><strong>⏳ Verification Required</strong></p>
                        <p style="margin: 10px 0 0 0; color: #856404;">Your credentials will be verified by our administrators. You will receive an email notification once your account is approved.</p>
                    </div>
                    
                    <h3 style="color: #333; margin-top: 25px;">Account Details:</h3>
                    <ul style="color: #555; line-height: 1.8;">
                        <li><strong>Temporary ID:</strong> ' . htmlspecialchars($temporaryStudentId) . '</li>
                        <li><strong>Name:</strong> ' . htmlspecialchars($name) . '</li>
                        <li><strong>Email:</strong> ' . htmlspecialchars($email) . '</li>
                    </ul>

                    <div style="background-color: #d1ecf1; border-left: 4px solid #0c5460; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin: 0; color: #0c5460;"><strong>Verification Flow</strong></p>
                        <p style="margin: 10px 0 0 0; color: #0c5460;">A temporary student ID is assigned first. The administrator will replace it with your official 9-digit student ID during verification.</p>
                    </div>
                    
                    <p style="color: #6c757d; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6;">
                        <strong>Note:</strong> You will not be able to log in until your account has been verified. This typically takes 1-2 business days.
                    </p>
                    
                    <p style="color: #6c757d; font-size: 12px; text-align: center; margin-top: 30px;">
                        This is an automated message from the PCU RFID System.<br>
                        If you did not create this account, please contact the Student Services Office immediately.
                    </p>
                </div>
            </div>';
        sendMail($email, $subject, $body);

        // Redirect to login page with info message
        $_SESSION['info'] = 'Registration submitted successfully! Your account is pending for verification by the Student Services Office. You will receive an email after the verification.';
        header('Location: login.php');
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handleLogin(): void {
    // Rate limiting: 5 attempts per 15 minutes
    if (!check_rate_limit('login', 5, 900)) {
        $_SESSION['error'] = 'Too many login attempts. Please try again in 15 minutes.';
        header('Location: login.php');
        exit;
    }
    
    $pdo = pdo();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        redirect_error('login.php', 'Please enter email and password.');
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        sleep(1);
        redirect_error('login.php', 'Invalid credentials.');
    }

    if ($user['status'] === 'Locked') {
        // Auto-unlock accounts where the lock duration has expired
        if (!empty($user['locked_until']) && strtotime($user['locked_until']) < time()) {
            $unlockStmt = $pdo->prepare(
                'UPDATE users SET status = "Active", failed_attempts = 0, locked_until = NULL WHERE id = :id'
            );
            $unlockStmt->execute([':id' => $user['id']]);
            $user['status'] = 'Active';
            $user['failed_attempts'] = 0;
        } else {
            redirect_error('login.php', 'Account locked due to too many failed attempts. Please try again later.');
        }
    }

    // Check verification status
    if (isset($user['verification_status'])) {
        if ($user['verification_status'] === 'pending') {
            $_SESSION['info'] = 'Your account is pending verification. Please wait for approval from the Student Services Office.';
            header('Location: login.php');
            exit;
        }
        if ($user['verification_status'] === 'denied') {
            $_SESSION['error'] = 'Your account verification was denied. Please contact the Student Services Office for more information.';
            header('Location: login.php');
            exit;
        }
    }
    if (!password_verify($password, $user['password'])) {
        $failed = (int)$user['failed_attempts'] + 1;
        $maxAttempts = 5;
        $lockDurationSeconds = 1800; // 30 minutes

        if ($failed >= $maxAttempts) {
            // Set locked_until timestamp instead of permanent lock
            $lockedUntil = date('Y-m-d H:i:s', time() + $lockDurationSeconds);
            $stmt = $pdo->prepare(
                'UPDATE users SET failed_attempts = :f, status = "Locked", locked_until = :lu WHERE id = :id'
            );
            $stmt->execute([':f' => $failed, ':lu' => $lockedUntil, ':id' => $user['id']]);
            redirect_error('login.php', 'Too many failed attempts. Account locked for 30 minutes.');
        }

        $stmt = $pdo->prepare('UPDATE users SET failed_attempts = :f WHERE id = :id');
        $stmt->execute([':f' => $failed, ':id' => $user['id']]);
        redirect_error('login.php', 'Invalid credentials.');
    }

    // Transparently rehash from bcrypt to Argon2id
    if (password_needs_rehash($user['password'], PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID);
        $rehashStmt = $pdo->prepare('UPDATE users SET password = :pass WHERE id = :id');
        $rehashStmt->execute([':pass' => $newHash, ':id' => $user['id']]);
    }

    if ($user['status'] === 'Pending') {
        $_SESSION['info'] = 'Your account is pending verification. Please wait for admin approval.';
        header('Location: login.php');
        exit;
    }

    // Success: reset attempts, update last_login, set session
    $stmt = $pdo->prepare('UPDATE users SET failed_attempts = 0, last_login = NOW() WHERE id = :id');
    $stmt->execute([':id' => $user['id']]);

    // Reset rate limit on successful login
    reset_rate_limit('login');

        // Prevent session fixation by rotating the session identifier on login.
        session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];

    // Redirect based on role
    if ($user['role'] === 'Student') {
        header('Location: homepage.php');
        exit;
    } elseif ($user['role'] === 'Admin') {
        header('Location: admin.php');
        exit;
    }
    
    // If role is not recognized
    redirect_error('login.php', 'Invalid user role. This system is for students only.');
    exit;
}

/**
 * Store an error message in the session and redirect.
 * Uses session flash instead of URL query parameter to avoid
 * leaking messages into browser history, logs, and referrer headers.
 */
function redirect_error(string $page, string $msg): void {
    $_SESSION['error'] = $msg;
    header('Location: ' . $page);
    exit;
}
