<?php

require_once __DIR__ . '/../db.php';

// Auto-setup: Create super admin tables if they don't exist
try {
    $pdo = pdo();
    
    // Create super_admins table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS super_admins (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
            last_login DATETIME DEFAULT NULL,
            login_attempts INT NOT NULL DEFAULT 0,
            locked_until DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Create admin_accounts table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_accounts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            created_by INT NOT NULL,
            status ENUM('Active', 'Inactive', 'Suspended') NOT NULL DEFAULT 'Active',
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user (user_id),
            INDEX idx_user_id (user_id),
            INDEX idx_created_by (created_by),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Create superadmin_audit_log table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS superadmin_audit_log (
            id INT PRIMARY KEY AUTO_INCREMENT,
            super_admin_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            target_admin_id INT DEFAULT NULL,
            details TEXT DEFAULT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_super_admin_id (super_admin_id),
            INDEX idx_action (action),
            INDEX idx_target_admin (target_admin_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // SECURITY: Only seed the default super-admin account when SUPERADMIN_AUTO_SETUP=true
    // is explicitly set in .env. This prevents silent privilege escalation on every
    // page load. Enable only during initial installation, then set to false.
    if (filter_var(env('SUPERADMIN_AUTO_SETUP', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        $defaultEmail    = env('SUPERADMIN_EMAIL', '');
        $defaultPassword = env('SUPERADMIN_PASSWORD', '');
        $defaultName     = env('SUPERADMIN_NAME', 'Super Admin');

        if (!empty($defaultEmail) && !empty($defaultPassword)) {
            $stmt = $pdo->prepare("SELECT id FROM super_admins WHERE email = ?");
            $stmt->execute([$defaultEmail]);

            if (!$stmt->fetch()) {
                $hashedPassword = normalize_env_password($defaultPassword);
                $stmt = $pdo->prepare("INSERT INTO super_admins (username, email, password, status) VALUES (?, ?, ?, 'Active')");
                $stmt->execute([$defaultName, $defaultEmail, $hashedPassword]);
            }
        }
    }
    
} catch (PDOException $e) {
    error_log("Super Admin table setup error: " . $e->getMessage());
}

// If already logged in, redirect to homepage
if (isset($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true) {
    header('Location: homepage.php');
    exit;
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!check_rate_limit('superadmin_login', 5, 900)) {
        $error = 'Too many login attempts. Please try again in 15 minutes.';
    } else {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter email and password.';
    } else {
        try {
            $pdo = pdo();
            
            // Query super admin from database
            $stmt = $pdo->prepare("SELECT id, username, email, password, status, login_attempts, locked_until FROM super_admins WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $superAdmin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($superAdmin) {
                // Check if account is locked
                if ($superAdmin['locked_until'] && strtotime($superAdmin['locked_until']) > time()) {
                    $remainingTime = ceil((strtotime($superAdmin['locked_until']) - time()) / 60);
                    $error = "Account locked. Try again in {$remainingTime} minutes.";
                } elseif ($superAdmin['status'] !== 'Active') {
                    $error = 'Your account has been deactivated. Please contact system administrator.';
                } elseif (password_verify($password, $superAdmin['password'])) {
                    // Transparently rehash from bcrypt to Argon2id
                    if (password_needs_rehash($superAdmin['password'], PASSWORD_ARGON2ID)) {
                        $newHash = password_hash($password, PASSWORD_ARGON2ID);
                        $rehashStmt = $pdo->prepare('UPDATE super_admins SET password = ? WHERE id = ?');
                        $rehashStmt->execute([$newHash, $superAdmin['id']]);
                    }

                    reset_rate_limit('superadmin_login');

                    // Valid credentials - reset login attempts
                    $stmt = $pdo->prepare("UPDATE super_admins SET login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?");
                    $stmt->execute([$superAdmin['id']]);
                    
                    // Set session
                    session_regenerate_id(true);
                    $_SESSION['superadmin_logged_in'] = true;
                    $_SESSION['superadmin_id'] = $superAdmin['id'];
                    $_SESSION['superadmin_name'] = $superAdmin['username'];
                    $_SESSION['superadmin_email'] = $superAdmin['email'];
                    
                    // Log the login action
                    $stmt = $pdo->prepare("INSERT INTO superadmin_audit_log (super_admin_id, action, ip_address, user_agent) VALUES (?, 'LOGIN', ?, ?)");
                    $stmt->execute([
                        $superAdmin['id'],
                        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);
                    
                    header('Location: homepage.php');
                    exit;
                } else {
                    // Invalid password - increment login attempts
                    $attempts = $superAdmin['login_attempts'] + 1;
                    $lockUntil = null;
                    
                    if ($attempts >= 5) {
                        $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                        $error = 'Too many failed attempts. Account locked for 15 minutes.';
                    } else {
                        $error = 'Invalid email or password. ' . (5 - $attempts) . ' attempts remaining.';
                    }
                    
                    $stmt = $pdo->prepare("UPDATE super_admins SET login_attempts = ?, locked_until = ? WHERE id = ?");
                    $stmt->execute([$attempts, $lockUntil, $superAdmin['id']]);
                }
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (PDOException $e) {
            error_log("Super Admin login error: " . $e->getMessage());
            $error = 'Login system error. Please try again.';
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GateWatch | Super Admin Login</title>
    <link rel="icon" type="image/png" href="../assets/images/gatewatch-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <!-- SweetAlert2 for beautiful alerts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style type="text/tailwindcss">
        .bg-pcu {
            position: relative;
        }
        .bg-pcu::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url('../pcu-building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            filter: blur(2px);
            -webkit-filter: blur(2px);
            z-index: -1;
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .btn-hover {
            transition: all 0.3s ease;
        }
        .btn-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 86, 179, 0.3);
        }
        .input-focus:focus {
            border-color: #0056b3;
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
    </style>
</head>
<body class="bg-pcu min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Login Card -->
        <div class="glass-effect rounded-2xl shadow-2xl overflow-hidden fade-in">
            <!-- Header -->
            <div class="bg-gradient-to-r from-[#0056b3] to-[#003d82] px-8 py-6 text-center">
                <div class="flex justify-center mb-4">
                    <a href="superadmin_login.php" class="inline-flex items-center justify-center" aria-label="Go to Super Admin Login page">
                        <img src="../assets/images/pcu-logo.png" alt="PCU Logo" class="w-20 h-20">
                    </a>
                </div>
                <h1 class="text-2xl font-bold text-white">Super Admin</h1>
                <p class="text-blue-100 text-sm mt-1">PCU GateWatch</p>
            </div>
            
            <!-- Form -->
            <div class="px-8 py-8">
                <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center gap-3 fade-in">
                    <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="text-sm"><?php echo e($error); ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="" class="space-y-6">
                    <?php echo csrf_input(); ?>
                    
                    <div>
                        <label for="email" class="block text-sm font-semibold text-slate-700 mb-2">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            Email Address
                        </label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            required
                            placeholder="Enter your email"
                            class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:outline-none input-focus transition-all text-slate-700 placeholder-slate-400"
                            value="<?php echo e($_POST['email'] ?? ''); ?>"
                        >
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-semibold text-slate-700 mb-2">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                            Password
                        </label>
                        <div class="relative">
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                required
                                placeholder="Enter your password"
                                class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:outline-none input-focus transition-all text-slate-700 placeholder-slate-400 pr-12"
                            >
                            <button 
                                type="button" 
                                onclick="togglePassword()"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors"
                            >
                                <svg id="eyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button 
                        type="submit" 
                        class="w-full bg-gradient-to-r from-[#0056b3] to-[#003d82] text-white py-3 px-4 rounded-xl font-semibold btn-hover focus:outline-none focus:ring-2 focus:ring-[#0056b3] focus:ring-offset-2 transition-all flex items-center justify-center gap-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                        </svg>
                        Sign In
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Footer -->
        <p class="text-center mt-6 text-white text-sm font-medium drop-shadow-lg">
            © <?php echo date('Y'); ?> Philippine Christian University
        </p>
    </div>

    <script>
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            eyeIcon.innerHTML = `
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
            `;
        } else {
            passwordInput.type = 'password';
            eyeIcon.innerHTML = `
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            `;
        }
    }
    
    // Show error with SweetAlert if exists
    <?php if ($error): ?>
    // Error is already shown in the form, but we can also use SweetAlert for emphasis
    <?php endif; ?>
    </script>
</body>
</html>
