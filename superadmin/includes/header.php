<?php
// Centralized super admin auth check (includes no-cache headers)
require_superadmin_auth();
send_security_headers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GateWatch Super Admin | <?php echo e($page_title ?? 'Dashboard'); ?></title>
    <link rel="icon" type="image/png" href="../assets/images/gatewatch-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <!-- SweetAlert2 for beautiful alerts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Alpine.js for reactive UI -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style type="text/tailwindcss">
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
            background-image: url('../pcu-building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            filter: blur(2px);
            -webkit-filter: blur(2px);
            z-index: -1;
            opacity: 0.9;
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
        .card-hover {
            transition: all 0.3s ease;
        }
        .card-hover:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .slide-in {
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @font-face {
            font-family: 'old-english-canterbury';
            src: url('../assets/fonts/canterbury-webfont.woff2') format('woff2'),
                 url('../assets/fonts/canterbury-webfont.woff') format('woff');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }

        .brand-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
        }

        .brand-logo {
            width: 2.5rem;
            height: 2.5rem;
            flex-shrink: 0;
        }

        .brand-wordmark {
            font-family: 'old-english-canterbury', serif;
            font-weight: 400;
            font-size: clamp(1.05rem, 2.2vw, 1.875rem);
            line-height: 1;
            letter-spacing: 0;
            color: #000000;
            text-rendering: optimizeLegibility;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            .brand-link {
                gap: 0.4rem;
            }

            .brand-logo {
                width: 2rem;
                height: 2rem;
            }

            .brand-wordmark {
                font-size: clamp(0.88rem, 3.5vw, 1.05rem);
                white-space: normal;
                line-height: 1.15;
                letter-spacing: 0;
                max-width: calc(100vw - 10rem);
            }

            .superadmin-user-trigger {
                display: inline-flex !important;
                flex-direction: row !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 0.45rem !important;
                min-height: 2rem !important;
                padding-top: 0 !important;
                padding-bottom: 0 !important;
            }

            .superadmin-user-menu {
                display: flex !important;
                align-items: center !important;
                align-self: center !important;
                transform: translateY(1px) !important;
            }

            .superadmin-user-avatar {
                display: grid !important;
                place-items: center !important;
                flex-shrink: 0 !important;
                line-height: 1 !important;
                padding: 0 !important;
            }

            .superadmin-user-avatar span {
                width: 100% !important;
                height: 100% !important;
                display: grid !important;
                place-items: center !important;
                line-height: 1 !important;
                margin: 0 !important;
                text-align: center !important;
                transform: translateY(0.5px) !important;
            }

            .superadmin-user-chevron {
                display: block !important;
                flex-shrink: 0 !important;
                margin: 0 !important;
                align-self: center !important;
                line-height: 1 !important;
                transform: translateY(0.5px) !important;
            }
        }
    </style>
    <?php session_guard_script('../superadmin/superadmin_login.php'); ?>
</head>
<body class="bg-pcu min-h-screen">
    <!-- Navigation -->
    <nav class="glass-effect shadow-lg sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between min-h-[4rem] py-2 items-center">
                <!-- Logo -->
                <div class="flex items-center">
                    <a href="homepage.php" class="brand-link transition-transform hover:scale-105 hover:opacity-90">
                        <img src="../assets/images/pcu-logo.png" alt="PCU Logo" class="brand-logo">
                        <span class="brand-wordmark">Philippine Christian University</span>
                    </a>
                </div>
                
                <!-- User Menu -->
                <div class="superadmin-user-menu flex items-center gap-4" x-data="{ open: false }">
                    <div class="relative">
                        <button 
                            @click="open = !open"
                            class="superadmin-user-trigger flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-slate-100 transition-colors"
                        >
                            <div class="superadmin-user-avatar w-8 h-8 bg-gradient-to-br from-[#0056b3] to-[#003d82] rounded-full flex items-center justify-center">
                                <span class="text-white font-semibold text-sm"><?php echo strtoupper(substr($_SESSION['superadmin_name'] ?? 'SA', 0, 2)); ?></span>
                            </div>
                            <span class="text-slate-700 font-medium hidden sm:inline"><?php echo e($_SESSION['superadmin_name'] ?? 'Super Admin'); ?></span>
                            <svg class="superadmin-user-chevron w-4 h-4 text-slate-400 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        
                        <!-- Dropdown -->
                        <div 
                            x-show="open" 
                            @click.away="open = false"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-slate-200 overflow-hidden z-50"
                            style="display: none;"
                        >
                            <div class="px-4 py-3 border-b border-slate-100">
                                <p class="text-sm font-semibold text-slate-800"><?php echo e($_SESSION['superadmin_name'] ?? 'Super Admin'); ?></p>
                                <p class="text-xs text-slate-500"><?php echo e($_SESSION['superadmin_email'] ?? ''); ?></p>
                            </div>
                            <div class="border-t border-slate-100">
                                <a href="audit_logs.php" class="flex items-center gap-3 px-4 py-3 text-sm text-slate-700 hover:bg-slate-50 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6m3 6V7m3 10v-4m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    Audit Logs
                                </a>
                                <a href="superadmin_logout.php" class="flex items-center gap-3 px-4 py-3 text-sm text-red-600 hover:bg-red-50 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                    Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content Area -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
