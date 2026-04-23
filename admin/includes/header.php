<?php
// Check if admin is logged in (centralized)
require_admin_auth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GateWatch Admin | <?php echo $page_title ?? 'Dashboard'; ?></title>
    <link rel="icon" type="image/png" href="../assets/images/gatewatch-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style type="text/tailwindcss">
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
            box-shadow: 0 4px 6px -1px rgba(0, 86, 179, 0.1), 0 2px 4px -1px rgba(0, 86, 179, 0.06);
        }
    </style>
    <?php session_guard_script('../admin/admin_login.php'); ?>
</head>
<body class="bg-slate-50 min-h-screen">
    <nav class="bg-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex">
                    <div class="flex-shrink-0 flex items-center">
                        <img src="../pcu-logo.png" alt="PCU Logo" class="h-8 w-8">
                        <span class="ml-2 text-xl font-semibold text-[#0056b3]">PCU RFID Admin</span>
                    </div>
                </div>
                <div class="flex items-center">
                    <form action="admin_login.php" method="post" class="ml-4">
                        <input type="hidden" name="logout" value="1">
                        <button type="submit" class="text-slate-600 hover:text-[#0056b3] transition-colors">
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-slate-800"><?php echo $page_title ?? 'Dashboard'; ?></h1>
        </div>