<?php

require_once __DIR__ . '/../db.php';

// If already logged in, redirect to homepage
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: homepage.php');
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $rateLimitEnabled = filter_var(env('ENABLE_ADMIN_RATE_LIMIT', 'true'), FILTER_VALIDATE_BOOLEAN);
    if ($rateLimitEnabled && !check_rate_limit('admin_login', 5, 900)) {
        error_log('Admin login rate limit hit for IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $error = 'Too many login attempts. Please try again in 15 minutes.';
    } else {
        $email = strtolower(trim($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $error = 'Please enter email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $pdo = pdo();
                
                // Detect which columns exist in the users table
                $columnsStmt = $pdo->query("SHOW COLUMNS FROM users");
                $columns = $columnsStmt->fetchAll(\PDO::FETCH_COLUMN);
                $hasFirstName = in_array('first_name', $columns);
                
                // Query admin user from database with correct columns
                if ($hasFirstName) {
                    $stmt = $pdo->prepare("SELECT id, email, password, first_name, last_name FROM users WHERE email = :email AND role = 'Admin' LIMIT 1");
                } else {
                    $stmt = $pdo->prepare("SELECT id, email, password, name FROM users WHERE email = :email AND role = 'Admin' LIMIT 1");
                }
                $stmt->execute([':email' => $email]);
                $admin = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($admin && password_verify($password, $admin['password'])) {
                    // Transparently rehash from bcrypt to Argon2id
                    if (password_needs_rehash($admin['password'], PASSWORD_ARGON2ID)) {
                        $newHash = password_hash($password, PASSWORD_ARGON2ID);
                        $rehashStmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                        $rehashStmt->execute([$newHash, $admin['id']]);
                    }

                    // Valid admin credentials - reset rate limit
                    reset_rate_limit('admin_login');
                    
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $admin['id'];
                    $_SESSION['role'] = 'Admin';
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_name'] = $hasFirstName ? trim($admin['first_name'] . ' ' . $admin['last_name']) : $admin['name'];
                    header('Location: homepage.php');
                    exit;
                } else {
                    $error = 'Invalid email or password';
                }
            } catch (\PDOException $e) {
                error_log("Admin login error: " . $e->getMessage());
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
    <title>GateWatch | Admin Login</title>
    <link rel="icon" type="image/png" href="../assets/images/gatewatch-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="../assets/css/styles.css">
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
        <div class="glass-effect rounded-2xl shadow-2xl overflow-hidden fade-in">
            <div class="bg-gradient-to-r from-[#0056b3] to-[#003d82] px-8 py-6 text-center">
                <div class="flex justify-center mb-4">
                    <img src="../assets/images/pcu-logo.png" alt="PCU Logo" class="w-20 h-20">
                </div>
                <h1 class="text-2xl font-bold text-white">Admin</h1>
                <p class="text-blue-100 text-sm mt-1">PCU GateWatch</p>
            </div>

            <div class="px-8 py-8">
                <?php if (!empty($error)): ?>
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
                        <label for="username" class="block text-sm font-semibold text-slate-700 mb-2">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            Email Address
                        </label>
                        <input
                            type="email"
                            id="username"
                            name="username"
                            required
                            placeholder="Enter your email"
                            class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:outline-none input-focus transition-all text-slate-700 placeholder-slate-400"
                            value="<?php echo e($_POST['username'] ?? ''); ?>"
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
                                aria-label="Toggle password visibility"
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

        document.querySelector('form').addEventListener('submit', function() {
            const button = this.querySelector('button[type="submit"]');
            button.disabled = true;
            button.innerHTML = `
                <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Signing In...
            `;
        });
    </script>
</body>
</html>