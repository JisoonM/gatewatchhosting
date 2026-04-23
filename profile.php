<?php

use Exception;

require_once 'db.php';
require_student_auth();

$user = $_SESSION['user'];

// Get complete user information
try {
    $pdo = pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $userInfo = $stmt->fetch();
    
    // Set default profile picture if none exists
    if (empty($userInfo['profile_picture'])) {
        $userInfo['profile_picture'] = 'assets/images/profiles/default.jpg';
    }
    
    if (!$userInfo) {
        session_unset();
        session_destroy();
        $_SESSION['error'] = 'User not found';
        header('Location: login.php');
        exit;
    }
} catch (Exception $e) {
    error_log('Error fetching user profile: ' . $e->getMessage());
    $_SESSION['error'] = 'Failed to load profile';
    header('Location: homepage.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GateWatch | Profile</title>
    <link rel="icon" type="image/png" href="assets/images/gatewatch-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        .bg-pcu {
            position: relative;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .bg-pcu::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url('pcu-building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            filter: blur(2px);
            -webkit-filter: blur(2px);
            z-index: -1;
            opacity: 0.9;
        }
        
        .profile-container {
            position: relative;
            transition: transform 0.3s ease;
        }
        
        .profile-container:hover {
            transform: scale(1.05);
        }
    </style>
    <?php session_guard_script('login.php'); ?>
</head>
<body class="text-slate-800">
    <div class="bg-pcu min-h-screen">
        <!-- Navbar -->
        <nav class="bg-white/90 backdrop-blur-sm border-b border-slate-200 fixed w-full top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <!-- Logo -->
                    <div class="flex items-center">
                        <a href="homepage.php" class="flex items-center hover:opacity-80 transition-opacity">
                            <img src="pcu-logo.png" alt="PCU Logo" class="h-10 w-10">
                            <span class="ml-2 text-xl font-semibold text-sky-700">PCU RFID</span>
                        </a>
                    </div>
                    
                    <!-- Back Button -->
                    <div class="flex items-center">
                        <a href="homepage.php" class="flex items-center px-4 py-2 text-sm font-medium text-slate-700 hover:text-sky-600 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-1">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                            </svg>
                            Back to Home
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 pt-20 pb-12">
            <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-xl p-8 mt-8">
                <!-- Profile Picture Section -->
                <?php
                    // Get the first letter of the user's name
                    $firstLetter = strtoupper(substr($userInfo['name'], 0, 1));
                    
                    // Generate a consistent color based on the name
                    $colors = ['bg-blue-500', 'bg-green-500', 'bg-yellow-500', 'bg-red-500', 'bg-purple-500', 'bg-pink-500'];
                    $colorIndex = ord($firstLetter) % count($colors);
                    $bgColor = $colors[$colorIndex];
                ?>
                <div class="flex justify-center mb-8">
                    <div class="profile-container">
                        <div class="w-32 h-32 rounded-full <?= $bgColor ?> flex items-center justify-center text-white text-6xl font-bold shadow-lg">
                            <?= htmlspecialchars($firstLetter) ?>
                        </div>
                    </div>
                </div>

                <!-- Profile Information -->
                <div class="space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Student ID</label>
                        <div class="mt-1 text-slate-900 bg-slate-50 rounded-lg px-4 py-2.5 border border-slate-200">
                            <?= htmlspecialchars($userInfo['student_id']) ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Full Name</label>
                        <div class="mt-1 text-slate-900 bg-slate-50 rounded-lg px-4 py-2.5 border border-slate-200">
                            <?= htmlspecialchars($userInfo['name']) ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Email Address</label>
                        <div class="mt-1 text-slate-900 bg-slate-50 rounded-lg px-4 py-2.5 border border-slate-200">
                            <?= htmlspecialchars($userInfo['email']) ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Role</label>
                        <div class="mt-1 text-slate-900 bg-slate-50 rounded-lg px-4 py-2.5 border border-slate-200">
                            <?= htmlspecialchars($userInfo['role']) ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Account Status</label>
                        <div class="mt-1">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-medium <?= $userInfo['status'] === 'Active' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' ?>">
                                <?= htmlspecialchars($userInfo['status']) ?>
                            </span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Last Login</label>
                        <div class="mt-1 text-slate-900 bg-slate-50 rounded-lg px-4 py-2.5 border border-slate-200">
                            <?= $userInfo['last_login'] ? date('F j, Y g:i A', strtotime($userInfo['last_login'])) : 'Never' ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Notification -->
    <div id="notification" class="fixed top-20 right-4 max-w-sm bg-white rounded-lg shadow-lg p-4 transition-all duration-300 transform translate-x-[150%] invisible z-40">
        <div class="flex items-center">
            <div id="notificationIcon" class="flex-shrink-0 w-6 h-6 mr-3"></div>
            <div id="notificationMessage" class="text-sm font-medium"></div>
        </div>
    </div>

    <script>
        function showNotification(message, type = 'success') {
            const notification = document.getElementById('notification');
            const icon = document.getElementById('notificationIcon');
            const messageEl = document.getElementById('notificationMessage');
            
            // Set icon and colors based on type
            if (type === 'success') {
                icon.innerHTML = `<svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>`;
            } else {
                icon.innerHTML = `<svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>`;
            }
            
            messageEl.textContent = message;
            
            // Show notification
            notification.classList.remove('invisible', 'translate-x-[150%]');
            notification.classList.add('translate-x-0');
            
            // Hide after 3 seconds
            setTimeout(() => {
                notification.classList.remove('translate-x-0');
                notification.classList.add('translate-x-[150%]');
                // Add invisible class after animation completes
                setTimeout(() => {
                    notification.classList.add('invisible');
                }, 300);
            }, 3000);
        }

    </script>
</body>
</html>