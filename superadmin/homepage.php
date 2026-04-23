<?php

require_once __DIR__ . '/../db.php';

require_superadmin_auth();

$page_title = 'Admin Management Dashboard';

$flashError = $_SESSION['error'] ?? null;
if (isset($_SESSION['error'])) {
    unset($_SESSION['error']);
}

// Get statistics
try {
    $pdo = pdo();
    
    // Total admins
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'");
    $totalAdmins = $stmt->fetchColumn();
    
    // Active admins (check in admin_accounts or default to Active status)
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM users u 
        LEFT JOIN admin_accounts aa ON u.id = aa.user_id 
        WHERE u.role = 'Admin' AND (aa.status = 'Active' OR aa.status IS NULL)
    ");
    $activeAdmins = $stmt->fetchColumn();
    
    // Inactive/Suspended admins
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM admin_accounts WHERE status IN ('Inactive', 'Suspended')
    ");
    $inactiveAdmins = $stmt->fetchColumn();
    
    // Get all admins with their details
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.student_id,
            u.created_at,
            u.last_login,
            u.status as user_status,
            COALESCE(aa.status, 'Active') as admin_status,
            aa.created_by,
            aa.notes,
            sa.username as created_by_name
        FROM users u
        LEFT JOIN admin_accounts aa ON u.id = aa.user_id
        LEFT JOIN super_admins sa ON aa.created_by = sa.id
        WHERE u.role = 'Admin'
        ORDER BY u.created_at DESC
    ");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent audit logs
    $stmt = $pdo->query("
        SELECT 
            sal.id,
            sal.action,
            sal.details,
            sal.ip_address,
            sal.created_at,
            sa.username as admin_name,
            u.name as target_name
        FROM superadmin_audit_log sal
        JOIN super_admins sa ON sal.super_admin_id = sa.id
        LEFT JOIN admin_accounts aa ON sal.target_admin_id = aa.id
        LEFT JOIN users u ON aa.user_id = u.id
        ORDER BY sal.created_at DESC
        LIMIT 10
    ");
    $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Super Admin Dashboard error: " . $e->getMessage());
    $totalAdmins = 0;
    $activeAdmins = 0;
    $inactiveAdmins = 0;
    $admins = [];
    $auditLogs = [];
}

// Include header
include 'includes/header.php';
?>

<style>
.superadmin-hero-glass {
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.52);
    background: linear-gradient(140deg, rgba(255, 255, 255, 0.95), rgba(241, 245, 249, 0.92));
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.18);
}

.superadmin-hero-glass::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: radial-gradient(circle at 86% 18%, rgba(2, 132, 199, 0.14), transparent 35%),
                radial-gradient(circle at 10% 90%, rgba(14, 165, 233, 0.1), transparent 42%);
}

.superadmin-hero-copy {
    position: relative;
    z-index: 1;
}

.superadmin-hero-kicker {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.3rem 0.75rem;
    margin-bottom: 0.55rem;
    border-radius: 999px;
    border: 1px solid rgba(2, 132, 199, 0.2);
    background: linear-gradient(140deg, rgba(2, 132, 199, 0.13), rgba(255, 255, 255, 0.72));
    color: #0369a1;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.superadmin-hero-footer {
    position: relative;
    z-index: 1;
    margin-top: 0.95rem;
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
}

.superadmin-hero-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.28rem 0.62rem;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.34);
    background: rgba(255, 255, 255, 0.78);
    color: #475569;
    font-size: 0.72rem;
    font-weight: 600;
}

.superadmin-page-footer {
    position: relative;
    margin-top: 2rem;
    background: rgba(8, 13, 28, 0.9);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.superadmin-page-footer::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: radial-gradient(circle at 12% 20%, rgba(14, 165, 233, 0.2), transparent 33%),
                radial-gradient(circle at 90% 78%, rgba(59, 130, 246, 0.16), transparent 36%);
}

.superadmin-footer-inner {
    position: relative;
    z-index: 1;
}

.superadmin-footer-bottom {
    background: rgba(4, 8, 18, 0.96);
    border-top: 1px solid rgba(255, 255, 255, 0.07);
}

.superadmin-panel-glass {
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(186, 230, 253, 0.78);
    background: linear-gradient(140deg, rgba(255, 255, 255, 0.92), rgba(239, 246, 255, 0.82));
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    box-shadow: 0 24px 52px rgba(15, 23, 42, 0.24), inset 0 1px 0 rgba(255, 255, 255, 0.86);
    transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}

.superadmin-panel-glass::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: radial-gradient(circle at 100% 0%, rgba(56, 189, 248, 0.34), transparent 42%),
                radial-gradient(circle at 0% 100%, rgba(2, 132, 199, 0.18), transparent 50%);
}

.superadmin-panel-glass:hover {
    transform: translateY(-3px);
    border-color: rgba(125, 211, 252, 0.95);
    box-shadow: 0 28px 58px rgba(15, 23, 42, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.superadmin-panel-glass > * {
    position: relative;
    z-index: 1;
}

.superadmin-panel-header {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.74), rgba(224, 242, 254, 0.42));
    border-bottom: 1px solid rgba(148, 163, 184, 0.26);
}

.superadmin-modal-shell {
    border: 1px solid rgba(186, 230, 253, 0.72);
    background: linear-gradient(150deg, rgba(255, 255, 255, 0.92), rgba(239, 246, 255, 0.82));
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    box-shadow: 0 28px 70px rgba(15, 23, 42, 0.34), inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.superadmin-modal-header {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.74), rgba(224, 242, 254, 0.42));
    border-bottom: 1px solid rgba(148, 163, 184, 0.26);
}

.superadmin-form-input {
    border: 1px solid rgba(148, 163, 184, 0.45);
    background: rgba(255, 255, 255, 0.8);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}

.superadmin-form-input:focus {
    border-color: rgba(14, 116, 214, 0.75);
    box-shadow: 0 0 0 3px rgba(14, 116, 214, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.94);
}

.superadmin-stat-card {
    min-height: 132px;
}

.superadmin-accounts-panel {
    border-color: rgba(125, 211, 252, 0.72);
}

.superadmin-activity-panel {
    border-color: rgba(103, 232, 249, 0.68);
}

@media screen and (max-width: 768px) {
    .superadmin-mobile-fix .mobile-hero {
        display: grid !important;
        grid-template-columns: 1fr auto !important;
        align-items: center !important;
        gap: 0.75rem !important;
        padding: 1rem !important;
    }

    .superadmin-mobile-fix .mobile-hero-copy h1 {
        font-size: 2rem !important;
        line-height: 1.15 !important;
    }

    .superadmin-mobile-fix .mobile-hero-copy p {
        font-size: 1.06rem !important;
        line-height: 1.45 !important;
    }

    .superadmin-mobile-fix .mobile-hero-action {
        width: auto !important;
        min-width: 8.8rem !important;
        justify-content: center !important;
        padding: 0.75rem 0.9rem !important;
        border-radius: 0.9rem !important;
        font-size: 1.05rem !important;
        line-height: 1.25 !important;
        text-align: center !important;
    }

    .superadmin-mobile-fix .mobile-stat-grid {
        display: grid !important;
        grid-template-columns: 1fr !important;
        gap: 0.75rem !important;
    }

    .superadmin-mobile-fix .mobile-stat-card {
        padding: 0.95rem 1rem !important;
        border-radius: 0.9rem !important;
    }

    .superadmin-mobile-fix .mobile-stat-content {
        display: grid !important;
        grid-template-columns: 1fr auto !important;
        align-items: center !important;
        gap: 0.75rem !important;
    }

    .superadmin-mobile-fix .mobile-stat-label {
        font-size: 1rem !important;
        margin-bottom: 0.2rem !important;
    }

    .superadmin-mobile-fix .mobile-stat-value {
        font-size: 1.9rem !important;
        line-height: 1 !important;
    }

    .superadmin-mobile-fix .mobile-stat-icon {
        width: 3rem !important;
        height: 3rem !important;
        border-radius: 0.85rem !important;
        display: grid !important;
        place-items: center !important;
        padding: 0 !important;
        line-height: 1 !important;
    }

    .superadmin-mobile-fix .mobile-stat-icon svg {
        width: 1.4rem !important;
        height: 1.4rem !important;
        display: block !important;
        margin: 0 !important;
    }

    .superadmin-mobile-fix .mobile-admin-avatar {
        display: grid !important;
        place-items: center !important;
        padding: 0 !important;
        line-height: 1 !important;
    }

    .superadmin-mobile-fix .mobile-admin-avatar span {
        display: block !important;
        margin: 0 !important;
        line-height: 1 !important;
        text-align: center !important;
    }

    .superadmin-mobile-fix .mobile-admin-list {
        display: grid !important;
        gap: 0.7rem !important;
        padding: 0.75rem !important;
    }

    .superadmin-mobile-fix .mobile-admin-row {
        border: 1px solid #e2e8f0 !important;
        border-radius: 0.85rem !important;
        padding: 0.8rem !important;
        background: #ffffff !important;
    }

    .superadmin-mobile-fix .mobile-admin-top {
        display: grid !important;
        grid-template-columns: 1fr auto !important;
        align-items: start !important;
        gap: 0.65rem !important;
        margin-bottom: 0.5rem !important;
    }

    .superadmin-mobile-fix .mobile-admin-left {
        display: grid !important;
        grid-template-columns: auto 1fr !important;
        align-items: center !important;
        gap: 0.65rem !important;
        min-width: 0 !important;
    }

    .superadmin-mobile-fix .mobile-admin-meta {
        display: grid !important;
        gap: 0.15rem !important;
        min-width: 0 !important;
    }

    .superadmin-mobile-fix .mobile-admin-meta p {
        margin: 0 !important;
    }

    .superadmin-mobile-fix .mobile-admin-actions {
        display: flex !important;
        justify-content: flex-end !important;
        align-items: center !important;
        gap: 0.35rem !important;
    }

    .superadmin-mobile-fix .mobile-admin-footer {
        display: grid !important;
        grid-template-columns: 1fr auto !important;
        align-items: center !important;
        gap: 0.55rem !important;
        padding-left: 0 !important;
    }
}
</style>

<div class="superadmin-mobile-fix">

<!-- Page Header -->
<div class="mb-6 fade-in">
    <div class="mobile-hero superadmin-hero-glass flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-xl p-4 sm:p-6 shadow-lg">
        <div class="mobile-hero-copy">
            <span class="superadmin-hero-kicker">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 1.343-3 3v1h6v-1c0-1.657-1.343-3-3-3zm0 9a2 2 0 01-2-2v-1h4v1a2 2 0 01-2 2zm8-6c0 4.418-3.582 8-8 8s-8-3.582-8-8 3.582-8 8-8 8 3.582 8 8z"/>
                </svg>
                Super Admin Control
            </span>
            <h1 class="text-2xl font-bold text-slate-800"><?php echo e($page_title); ?></h1>
            <p class="text-slate-600 mt-1">Manage administrator accounts for the PCU RFID System</p>
            <div class="superadmin-hero-footer">
                <span class="superadmin-hero-chip">Secure account governance</span>
                <span class="superadmin-hero-chip">Face-first registration enabled</span>
            </div>
        </div>
        <button 
            onclick="openAddAdminModal()"
            class="mobile-hero-action inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-[#0056b3] to-[#003d82] text-white rounded-xl font-semibold btn-hover shadow-lg"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
            </svg>
            Add New Admin
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="mobile-stat-grid grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-6 mb-6 sm:mb-8">
    <!-- Total Admins -->
    <div class="mobile-stat-card superadmin-panel-glass superadmin-stat-card rounded-xl shadow-lg p-4 sm:p-6 card-hover fade-in" style="animation-delay: 0.1s;">
        <div class="mobile-stat-content flex items-center justify-between">
            <div>
                <p class="mobile-stat-label text-xs sm:text-sm font-medium text-slate-500 mb-0.5 sm:mb-1">Total Admins</p>
                <p class="mobile-stat-value text-xl sm:text-3xl font-bold text-slate-800"><?php echo $totalAdmins; ?></p>
            </div>
            <div class="mobile-stat-icon w-10 h-10 sm:w-14 sm:h-14 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                <svg class="w-5 h-5 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
            </div>
        </div>
    </div>
    
    <!-- Active Admins -->
    <div class="mobile-stat-card superadmin-panel-glass superadmin-stat-card rounded-xl shadow-lg p-4 sm:p-6 card-hover fade-in" style="animation-delay: 0.2s;">
        <div class="mobile-stat-content flex items-center justify-between">
            <div>
                <p class="mobile-stat-label text-xs sm:text-sm font-medium text-slate-500 mb-0.5 sm:mb-1">Active Admins</p>
                <p class="mobile-stat-value text-xl sm:text-3xl font-bold text-green-600"><?php echo $activeAdmins; ?></p>
            </div>
            <div class="mobile-stat-icon w-10 h-10 sm:w-14 sm:h-14 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg">
                <svg class="w-5 h-5 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
        </div>
    </div>
    
    <!-- Inactive Admins -->
    <div class="mobile-stat-card superadmin-panel-glass superadmin-stat-card rounded-xl shadow-lg p-4 sm:p-6 card-hover fade-in" style="animation-delay: 0.3s;">
        <div class="mobile-stat-content flex items-center justify-between">
            <div>
                <p class="mobile-stat-label text-xs sm:text-sm font-medium text-slate-500 mb-0.5 sm:mb-1">Inactive/Suspended</p>
                <p class="mobile-stat-value text-xl sm:text-3xl font-bold text-amber-600"><?php echo $inactiveAdmins; ?></p>
            </div>
            <div class="mobile-stat-icon w-10 h-10 sm:w-14 sm:h-14 bg-gradient-to-br from-amber-500 to-amber-600 rounded-xl flex items-center justify-center shadow-lg">
                <svg class="w-5 h-5 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Admin List -->
<div class="superadmin-panel-glass superadmin-accounts-panel rounded-xl shadow-lg overflow-hidden mb-8 fade-in" style="animation-delay: 0.4s;">
    <div class="superadmin-panel-header px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-lg font-bold text-slate-800">Administrator Accounts</h2>
            <p class="text-sm text-slate-500">Manage all admin users who can access the admin panel</p>
        </div>
        <div class="relative">
            <input 
                type="text" 
                id="searchAdmins" 
                placeholder="Search admins..."
                class="pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#0056b3] focus:border-transparent text-sm w-full sm:w-64"
            >
            <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
    </div>
    
    <?php if (empty($admins)): ?>
    <div class="p-12 text-center">
        <svg class="w-16 h-16 text-slate-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
        </svg>
        <h3 class="text-lg font-semibold text-slate-600 mb-2">No Admins Found</h3>
        <p class="text-slate-500 mb-4">Get started by creating your first admin account.</p>
        <button 
            onclick="openAddAdminModal()"
            class="inline-flex items-center gap-2 px-4 py-2 bg-[#0056b3] text-white rounded-lg font-medium hover:bg-[#003d82] transition-colors"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
            </svg>
            Add Admin
        </button>
    </div>
    <?php else: ?>
    <!-- Mobile Card View -->
    <div class="mobile-admin-list sm:hidden divide-y divide-slate-100">
        <?php foreach ($admins as $admin): ?>
        <div class="mobile-admin-row p-4 hover:bg-slate-50 transition-colors admin-row" data-name="<?php echo e(strtolower($admin['name'])); ?>" data-email="<?php echo e(strtolower($admin['email'])); ?>">
            <div class="mobile-admin-top flex items-center justify-between mb-2">
                <div class="mobile-admin-left flex items-center gap-3">
                    <div class="mobile-admin-avatar w-9 h-9 bg-gradient-to-br from-[#0056b3] to-[#003d82] rounded-full flex items-center justify-center flex-shrink-0">
                        <span class="text-white font-semibold text-xs"><?php echo strtoupper(substr($admin['name'], 0, 2)); ?></span>
                    </div>
                    <div class="mobile-admin-meta">
                        <p class="font-semibold text-slate-800 text-sm"><?php echo e($admin['name']); ?></p>
                        <p class="text-xs text-slate-500"><?php echo e($admin['email']); ?></p>
                    </div>
                </div>
                <?php 
                $statusClass = 'bg-green-100 text-green-700';
                if ($admin['admin_status'] === 'Inactive') {
                    $statusClass = 'bg-slate-100 text-slate-600';
                } elseif ($admin['admin_status'] === 'Suspended') {
                    $statusClass = 'bg-red-100 text-red-700';
                }
                ?>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                    <?php echo e($admin['admin_status']); ?>
                </span>
            </div>
            <div class="mobile-admin-footer flex items-center justify-between pl-12">
                <p class="text-xs text-slate-400">ID: <?php echo e($admin['student_id']); ?></p>
                <div class="mobile-admin-actions flex items-center gap-1">
                    <?php if ($admin['admin_status'] === 'Active'): ?>
                    <button 
                        onclick="toggleAdminStatus(<?php echo $admin['id']; ?>, 'Suspended')"
                        class="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition-colors" 
                        title="Suspend Admin"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                        </svg>
                    </button>
                    <?php else: ?>
                    <button 
                        onclick="toggleAdminStatus(<?php echo $admin['id']; ?>, 'Active')"
                        class="p-1.5 text-green-600 hover:bg-green-50 rounded-lg transition-colors" 
                        title="Activate Admin"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </button>
                    <?php endif; ?>
                    <button
                        onclick="openChangePasswordModal(<?php echo $admin['id']; ?>, '<?php echo e(addslashes($admin['name'])); ?>')"
                        class="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                        title="Change Password"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2h-1V9a5 5 0 00-10 0v2H6a2 2 0 00-2 2v6a2 2 0 002 2zm3-10V9a3 3 0 016 0v2H9z"/>
                        </svg>
                    </button>
                    <button 
                        onclick="confirmDeleteAdmin(<?php echo $admin['id']; ?>, '<?php echo e(addslashes($admin['name'])); ?>')"
                        class="p-1.5 text-red-600 hover:bg-red-50 rounded-lg transition-colors" 
                        title="Remove Admin"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Desktop Table View -->
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full" id="adminsTable">
            <thead class="bg-slate-50/80">
                <tr>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Admin</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Email</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Created</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">Last Login</th>
                    <th class="px-6 py-4 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($admins as $admin): ?>
                <tr class="hover:bg-slate-50 transition-colors admin-row" data-name="<?php echo e(strtolower($admin['name'])); ?>" data-email="<?php echo e(strtolower($admin['email'])); ?>">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-[#0056b3] to-[#003d82] rounded-full flex items-center justify-center flex-shrink-0">
                                <span class="text-white font-semibold text-sm"><?php echo strtoupper(substr($admin['name'], 0, 2)); ?></span>
                            </div>
                            <div>
                                <p class="font-semibold text-slate-800"><?php echo e($admin['name']); ?></p>
                                <p class="text-xs text-slate-500">ID: <?php echo e($admin['student_id']); ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <p class="text-slate-600"><?php echo e($admin['email']); ?></p>
                    </td>
                    <td class="px-6 py-4">
                        <?php 
                        $statusClass = 'bg-green-100 text-green-700';
                        if ($admin['admin_status'] === 'Inactive') {
                            $statusClass = 'bg-slate-100 text-slate-600';
                        } elseif ($admin['admin_status'] === 'Suspended') {
                            $statusClass = 'bg-red-100 text-red-700';
                        }
                        ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                            <?php echo e($admin['admin_status']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <p class="text-slate-600 text-sm"><?php echo date('M j, Y', strtotime($admin['created_at'])); ?></p>
                        <?php if ($admin['created_by_name']): ?>
                        <p class="text-xs text-slate-400">by <?php echo e($admin['created_by_name']); ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($admin['last_login']): ?>
                        <p class="text-slate-600 text-sm"><?php echo date('M j, Y g:i A', strtotime($admin['last_login'])); ?></p>
                        <?php else: ?>
                        <p class="text-slate-400 text-sm">Never</p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center justify-center gap-2">
                            <?php if ($admin['admin_status'] === 'Active'): ?>
                            <button 
                                onclick="toggleAdminStatus(<?php echo $admin['id']; ?>, 'Suspended')"
                                class="p-2 text-amber-600 hover:bg-amber-50 rounded-lg transition-colors" 
                                title="Suspend Admin"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                </svg>
                            </button>
                            <?php else: ?>
                            <button 
                                onclick="toggleAdminStatus(<?php echo $admin['id']; ?>, 'Active')"
                                class="p-2 text-green-600 hover:bg-green-50 rounded-lg transition-colors" 
                                title="Activate Admin"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </button>
                            <?php endif; ?>
                            <button
                                onclick="openChangePasswordModal(<?php echo $admin['id']; ?>, '<?php echo e(addslashes($admin['name'])); ?>')"
                                class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                title="Change Password"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2h-1V9a5 5 0 00-10 0v2H6a2 2 0 00-2 2v6a2 2 0 002 2zm3-10V9a3 3 0 016 0v2H9z"/>
                                </svg>
                            </button>
                            <button 
                                onclick="confirmDeleteAdmin(<?php echo $admin['id']; ?>, '<?php echo e(addslashes($admin['name'])); ?>')"
                                class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" 
                                title="Remove Admin"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div>

<!-- Recent Activity / Audit Log -->
<div class="superadmin-panel-glass superadmin-activity-panel rounded-xl shadow-lg overflow-hidden fade-in" style="animation-delay: 0.5s;">
    <div class="superadmin-panel-header px-6 py-4">
        <h2 class="text-lg font-bold text-slate-800">Recent Activity</h2>
        <p class="text-sm text-slate-500">Audit log of all super admin actions</p>
    </div>
    
    <?php if (empty($auditLogs)): ?>
    <div class="p-8 text-center">
        <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
        </svg>
        <p class="text-slate-500">No activity recorded yet</p>
    </div>
    <?php else: ?>
    <div class="divide-y divide-slate-100 max-h-96 overflow-y-auto">
        <?php foreach ($auditLogs as $log): ?>
        <div class="px-6 py-4 hover:bg-slate-50 transition-colors">
            <div class="flex items-start gap-4">
                <?php
                $actionIcon = '';
                $actionColor = 'text-slate-400';
                switch ($log['action']) {
                    case 'LOGIN':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>';
                        $actionColor = 'text-blue-500';
                        break;
                    case 'LOGOUT':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>';
                        $actionColor = 'text-slate-500';
                        break;
                    case 'CREATE_ADMIN':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>';
                        $actionColor = 'text-green-500';
                        break;
                    case 'DELETE_ADMIN':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>';
                        $actionColor = 'text-red-500';
                        break;
                    case 'SUSPEND_ADMIN':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>';
                        $actionColor = 'text-amber-500';
                        break;
                    case 'ACTIVATE_ADMIN':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>';
                        $actionColor = 'text-green-500';
                        break;
                    case 'RESET_ADMIN_PASSWORD':
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2h-1V9a5 5 0 00-10 0v2H6a2 2 0 00-2 2v6a2 2 0 002 2zm3-10V9a3 3 0 016 0v2H9z"/>';
                        $actionColor = 'text-blue-500';
                        break;
                    default:
                        $actionIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>';
                }
                ?>
                <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 <?php echo $actionColor; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?php echo $actionIcon; ?>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-slate-800">
                        <?php echo e($log['admin_name']); ?> 
                        <span class="text-slate-500 font-normal">
                            <?php
                            switch ($log['action']) {
                                case 'LOGIN': echo 'logged in'; break;
                                case 'LOGOUT': echo 'logged out'; break;
                                case 'CREATE_ADMIN': echo 'created admin ' . e($log['target_name'] ?? ''); break;
                                case 'DELETE_ADMIN': echo 'removed admin ' . e($log['target_name'] ?? ''); break;
                                case 'SUSPEND_ADMIN': echo 'suspended admin ' . e($log['target_name'] ?? ''); break;
                                case 'ACTIVATE_ADMIN': echo 'activated admin ' . e($log['target_name'] ?? ''); break;
                                case 'RESET_ADMIN_PASSWORD': echo 'reset password for admin ' . e($log['target_name'] ?? ''); break;
                                default: echo $log['action'];
                            }
                            ?>
                        </span>
                    </p>
                    <p class="text-xs text-slate-400 mt-1">
                        <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?> • IP: <?php echo e($log['ip_address']); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add Admin Modal -->
<div id="addAdminModal" class="fixed inset-0 bg-slate-950/55 hidden items-center justify-center z-50 p-4 backdrop-blur-sm">
    <div class="superadmin-modal-shell rounded-2xl w-full max-w-md transform transition-all scale-95 opacity-0" id="addAdminModalContent">
        <div class="superadmin-modal-header px-6 py-4 flex items-center justify-between">
            <h3 class="text-xl font-bold text-slate-800">Add New Admin</h3>
            <button onclick="closeAddAdminModal()" class="p-2 hover:bg-slate-100 rounded-lg transition-colors">
                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <form id="addAdminForm" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Full Name</label>
                <input 
                    type="text" 
                    name="name" 
                    required
                    placeholder="Enter full name"
                    class="superadmin-form-input w-full px-4 py-3 rounded-xl focus:outline-none"
                >
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Email Address</label>
                <input 
                    type="email" 
                    name="email" 
                    required
                    placeholder="Enter email address"
                    class="superadmin-form-input w-full px-4 py-3 rounded-xl focus:outline-none"
                >
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Admin ID</label>
                <input 
                    type="text" 
                    name="student_id" 
                    required
                    placeholder="Enter admin ID (e.g., ADMIN-001)"
                    class="superadmin-form-input w-full px-4 py-3 rounded-xl focus:outline-none"
                >
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Password</label>
                <div class="relative">
                    <input 
                        type="password" 
                        name="password" 
                        id="newAdminPassword"
                        required
                        minlength="8"
                        placeholder="Enter password (min 8 characters)"
                        class="superadmin-form-input w-full px-4 py-3 rounded-xl focus:outline-none pr-12"
                    >
                    <button 
                        type="button" 
                        onclick="toggleNewPassword()"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                    >
                        <svg id="newEyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Confirm Password</label>
                <input 
                    type="password" 
                    name="confirm_password" 
                    id="confirmAdminPassword"
                    required
                    placeholder="Confirm password"
                    class="superadmin-form-input w-full px-4 py-3 rounded-xl focus:outline-none"
                >
            </div>
            
            <div class="flex gap-3 pt-4">
                <button 
                    type="button" 
                    onclick="closeAddAdminModal()"
                    class="flex-1 px-4 py-3 border border-slate-300 text-slate-700 rounded-xl font-semibold hover:bg-slate-50 transition-colors"
                >
                    Cancel
                </button>
                <button 
                    type="submit"
                    class="flex-1 px-4 py-3 bg-gradient-to-r from-[#0056b3] to-[#003d82] text-white rounded-xl font-semibold btn-hover"
                >
                    Continue
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Change Admin Password Modal -->
<div id="changePasswordModal" class="fixed inset-0 bg-slate-950/55 hidden items-center justify-center z-50 p-4 backdrop-blur-sm">
    <div class="superadmin-modal-shell rounded-2xl w-full max-w-3xl transform transition-all scale-95 opacity-0" id="changePasswordModalContent">
        <div class="superadmin-modal-header px-6 py-4 flex items-center justify-between">
            <div>
                <h3 class="text-xl font-bold text-slate-800">Change Admin Password</h3>
                <p class="text-sm text-slate-500">Verify admin face first, then set a new secure password.</p>
            </div>
            <button type="button" onclick="closeChangePasswordModal()" class="p-2 hover:bg-slate-100 rounded-lg transition-colors">
                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="p-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <p id="changePasswordAdminLabel" class="text-sm font-semibold text-slate-700 mb-3"></p>
                    <div id="changePasswordStatus" class="bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4">
                        Face verification is required before changing this admin password.
                    </div>
                    <div class="relative bg-black rounded-lg overflow-hidden mb-3" style="max-width: 520px;">
                        <video id="changePasswordVideo" class="w-full" autoplay muted playsinline></video>
                        <canvas id="changePasswordCanvas" class="absolute top-0 left-0 w-full h-full pointer-events-none"></canvas>
                    </div>
                    <button id="btnVerifyAdminFace" type="button" class="w-full px-4 py-3 bg-gradient-to-r from-[#0056b3] to-[#003d82] text-white rounded-xl font-semibold btn-hover disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                        Verify Admin Face
                    </button>
                </div>

                <div>
                    <form id="changePasswordForm" class="space-y-4 hidden">
                        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
                            Face verified. Enter the new password for this admin account.
                        </div>

                        <div>
                            <label for="resetNewPassword" class="block text-sm font-semibold text-slate-700 mb-2">New Password</label>
                            <input
                                id="resetNewPassword"
                                type="password"
                                minlength="12"
                                autocomplete="new-password"
                                required
                                placeholder="At least 12 chars, 1 uppercase, 1 special"
                                class="superadmin-form-input w-full px-4 py-3 rounded-xl focus:outline-none"
                            >
                        </div>

                        <div>
                            <label for="resetConfirmPassword" class="block text-sm font-semibold text-slate-700 mb-2">Confirm Password</label>
                            <input
                                id="resetConfirmPassword"
                                type="password"
                                minlength="12"
                                autocomplete="new-password"
                                required
                                placeholder="Confirm new password"
                                class="superadmin-form-input w-full px-4 py-3 rounded-xl focus:outline-none"
                            >
                        </div>

                        <ul class="text-xs text-slate-500 space-y-1">
                            <li>Minimum 12 characters</li>
                            <li>At least one uppercase letter</li>
                            <li>At least one special character</li>
                        </ul>

                        <button id="btnSubmitPasswordChange" type="submit" class="w-full px-4 py-3 bg-slate-900 text-white rounded-xl font-semibold disabled:opacity-50 disabled:cursor-not-allowed">
                            Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

</main>

<!-- Footer -->
<footer class="superadmin-page-footer">
    <div class="superadmin-footer-inner max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-3">
                <img src="../assets/images/pcu-logo.png" alt="PCU Logo" class="w-10 h-10 object-contain" style="filter: drop-shadow(0 0 6px rgba(255,255,255,0.28));">
                <div>
                    <p class="text-sm font-semibold text-white">Philippine Christian University</p>
                    <p class="text-xs text-slate-400">Super Admin Portal</p>
                </div>
            </div>
            <div class="text-sm text-slate-300">
                1648 Taft Avenue corner Pedro Gil St., Malate, Manila
            </div>
            <div class="text-sm text-sky-300 font-medium">
                Monday to Saturday • 8 am to 5 pm
            </div>
        </div>
    </div>
    <div class="superadmin-footer-bottom">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3">
            <p class="text-center text-xs text-slate-400">
                Copyright © <?php echo date('Y'); ?> Philippine Christian University. All rights reserved.
            </p>
        </div>
    </div>
</footer>

<script defer src="../assets/js/vendor/face-api.min.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/vendor/face-api.min.js'); ?>"></script>
<script defer src="../assets/js/face-recognition.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/face-recognition.js'); ?>"></script>

<script>
// CSRF Token
const csrfToken = '<?php echo e($_SESSION['csrf_token']); ?>';
const enrollmentFlashError = <?php echo json_encode($flashError); ?>;
const faceRecognitionEnabled = <?php echo json_encode(filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)); ?>;

let adminPasswordResetState = {
    adminId: null,
    adminName: '',
    resetToken: null,
    faceVerified: false,
    faceSystem: null,
    liveTimer: null,
};

if (enrollmentFlashError) {
    Swal.fire({
        icon: 'error',
        title: 'Face Enrollment Could Not Start',
        text: enrollmentFlashError,
        confirmButtonColor: '#0056b3'
    });
}

// Search functionality
document.getElementById('searchAdmins').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('.admin-row');
    
    rows.forEach(row => {
        const name = row.dataset.name;
        const email = row.dataset.email;
        
        if (name.includes(searchTerm) || email.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});

// Add Admin Modal
function openAddAdminModal() {
    const modal = document.getElementById('addAdminModal');
    const content = document.getElementById('addAdminModalContent');
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    setTimeout(() => {
        content.classList.remove('scale-95', 'opacity-0');
        content.classList.add('scale-100', 'opacity-100');
    }, 10);
}

function closeAddAdminModal() {
    const modal = document.getElementById('addAdminModal');
    const content = document.getElementById('addAdminModalContent');
    
    content.classList.remove('scale-100', 'opacity-100');
    content.classList.add('scale-95', 'opacity-0');
    
    setTimeout(() => {
        modal.classList.remove('flex');
        modal.classList.add('hidden');
        document.getElementById('addAdminForm').reset();
    }, 200);
}

function setChangePasswordStatus(message, type = 'info') {
    const box = document.getElementById('changePasswordStatus');
    if (!box) return;

    box.textContent = message;
    if (type === 'error') box.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
    else if (type === 'success') box.className = 'bg-green-50 text-green-700 text-sm p-3 rounded-lg mb-4';
    else if (type === 'warn') box.className = 'bg-yellow-50 text-yellow-700 text-sm p-3 rounded-lg mb-4';
    else box.className = 'bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4';
}

function stopChangePasswordCamera() {
    if (adminPasswordResetState.liveTimer) {
        clearInterval(adminPasswordResetState.liveTimer);
        adminPasswordResetState.liveTimer = null;
    }

    try {
        if (adminPasswordResetState.faceSystem) {
            adminPasswordResetState.faceSystem.stopCamera();
        }
    } catch (e) {
        console.warn('Failed stopping camera', e);
    }
}

function resetChangePasswordModalState() {
    stopChangePasswordCamera();
    adminPasswordResetState.resetToken = null;
    adminPasswordResetState.faceVerified = false;
    adminPasswordResetState.faceSystem = null;

    const form = document.getElementById('changePasswordForm');
    if (form) {
        form.reset();
        form.classList.add('hidden');
    }

    const verifyBtn = document.getElementById('btnVerifyAdminFace');
    if (verifyBtn) {
        verifyBtn.disabled = true;
        verifyBtn.textContent = 'Verify Admin Face';
    }

    setChangePasswordStatus('Face verification is required before changing this admin password.', 'info');
}

async function postJson(url, payload) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
            'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) {
        throw new Error(data.error || `Request failed (${response.status})`);
    }

    return data;
}

async function openChangePasswordModal(adminId, adminName) {
    if (!faceRecognitionEnabled) {
        Swal.fire({
            icon: 'error',
            title: 'Face Recognition Disabled',
            text: 'Enable face recognition before changing admin passwords.',
            confirmButtonColor: '#0056b3'
        });
        return;
    }

    if (typeof FaceRecognitionSystem === 'undefined') {
        Swal.fire({
            icon: 'error',
            title: 'Face Engine Not Ready',
            text: 'Face recognition scripts are still loading. Please wait a few seconds and try again.',
            confirmButtonColor: '#0056b3'
        });
        return;
    }

    adminPasswordResetState.adminId = Number(adminId);
    adminPasswordResetState.adminName = adminName;
    document.getElementById('changePasswordAdminLabel').textContent = `Admin: ${adminName}`;

    resetChangePasswordModalState();

    const modal = document.getElementById('changePasswordModal');
    const content = document.getElementById('changePasswordModalContent');

    modal.classList.remove('hidden');
    modal.classList.add('flex');

    setTimeout(() => {
        content.classList.remove('scale-95', 'opacity-0');
        content.classList.add('scale-100', 'opacity-100');
    }, 10);

    adminPasswordResetState.faceSystem = new FaceRecognitionSystem({
        modelPath: '../assets/models',
        minConfidence: 0.5,
        csrfToken,
        onStatusChange: () => {},
        onError: (message) => setChangePasswordStatus(message, 'error')
    });

    setChangePasswordStatus('Loading face recognition models…', 'info');
    const modelsReady = await adminPasswordResetState.faceSystem.loadModels();
    if (!modelsReady) {
        setChangePasswordStatus('Unable to load face recognition models.', 'error');
        return;
    }

    setChangePasswordStatus('Starting camera…', 'info');
    const cameraReady = await adminPasswordResetState.faceSystem.startCamera(
        document.getElementById('changePasswordVideo'),
        document.getElementById('changePasswordCanvas')
    );

    if (!cameraReady) {
        setChangePasswordStatus('Camera access failed. Check browser permissions.', 'error');
        return;
    }

    setChangePasswordStatus('Camera ready. Position admin face then click Verify Admin Face.', 'success');
    document.getElementById('btnVerifyAdminFace').disabled = false;

    adminPasswordResetState.liveTimer = setInterval(async () => {
        if (!adminPasswordResetState.faceSystem || adminPasswordResetState.faceVerified) return;
        await adminPasswordResetState.faceSystem.detectSingleFace();
    }, 350);
}

function closeChangePasswordModal() {
    const modal = document.getElementById('changePasswordModal');
    const content = document.getElementById('changePasswordModalContent');

    content.classList.remove('scale-100', 'opacity-100');
    content.classList.add('scale-95', 'opacity-0');

    setTimeout(() => {
        modal.classList.remove('flex');
        modal.classList.add('hidden');
        resetChangePasswordModalState();
    }, 200);
}

async function verifyAdminFaceForPasswordReset() {
    const btn = document.getElementById('btnVerifyAdminFace');
    btn.disabled = true;

    try {
        if (!adminPasswordResetState.faceSystem) {
            throw new Error('Face system is not initialized');
        }

        setChangePasswordStatus('Capturing face descriptor…', 'info');
        const detection = await adminPasswordResetState.faceSystem.detectSingleFace();

        if (!detection) {
            throw new Error('No face detected. Please align the admin face to the camera.');
        }

        if (detection.score < 0.5) {
            throw new Error('Low confidence capture. Improve lighting and try again.');
        }

        const quality = adminPasswordResetState.faceSystem.assessFaceQuality(detection);
        if (!quality.acceptable) {
            throw new Error('Face quality too low. Keep face centered and steady.');
        }

        setChangePasswordStatus('Verifying face credentials on the server…', 'info');
        const result = await postJson('change_admin_password.php', {
            action: 'verify_face',
            admin_user_id: adminPasswordResetState.adminId,
            query_descriptor: detection.descriptor
        });

        adminPasswordResetState.faceVerified = true;
        adminPasswordResetState.resetToken = result.reset_token;
        document.getElementById('changePasswordForm').classList.remove('hidden');
        btn.textContent = 'Face Verified';
        setChangePasswordStatus('Face verified. You can now set a new password.', 'success');
        stopChangePasswordCamera();
    } catch (error) {
        setChangePasswordStatus(error.message || 'Face verification failed.', 'error');
        btn.disabled = false;
    }
}

// Close modal on backdrop click
document.getElementById('addAdminModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAddAdminModal();
    }
});

// Toggle password visibility
function toggleNewPassword() {
    const input = document.getElementById('newAdminPassword');
    const icon = document.getElementById('newEyeIcon');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
        `;
    } else {
        input.type = 'password';
        icon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        `;
    }
}

// Add Admin Form Submit
document.getElementById('addAdminForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    // Validate passwords match
    if (formData.get('password') !== formData.get('confirm_password')) {
        Swal.fire({
            icon: 'error',
            title: 'Password Mismatch',
            text: 'Passwords do not match. Please try again.',
            confirmButtonColor: '#0056b3'
        });
        return;
    }
    
    const password = formData.get('password') || '';

    // Align with secure admin password policy.
    if (password.length < 12) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Password',
            text: 'Password must be at least 12 characters long.',
            confirmButtonColor: '#0056b3'
        });
        return;
    }

    if (!/[A-Z]/.test(password)) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Password',
            text: 'Password must include at least one uppercase letter.',
            confirmButtonColor: '#0056b3'
        });
        return;
    }

    if (!/[^A-Za-z0-9]/.test(password)) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Password',
            text: 'Password must include at least one special character.',
            confirmButtonColor: '#0056b3'
        });
        return;
    }
    
    try {
        const response = await fetch('start_admin_registration.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            closeAddAdminModal();
            Swal.fire({
                icon: 'success',
                title: 'Continue Registration',
                text: data.message,
                confirmButtonColor: '#0056b3',
                confirmButtonText: data.enrollment_url ? 'Start Face Enrollment' : 'OK',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                if (data.enrollment_url) {
                    window.location.href = data.enrollment_url;
                } else {
                    window.location.reload();
                }
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.error || 'Failed to create admin',
                confirmButtonColor: '#0056b3'
            });
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An unexpected error occurred. Please try again.',
            confirmButtonColor: '#0056b3'
        });
    }
});

document.getElementById('changePasswordModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeChangePasswordModal();
    }
});

document.getElementById('btnVerifyAdminFace').addEventListener('click', verifyAdminFaceForPasswordReset);

document.getElementById('changePasswordForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const newPassword = document.getElementById('resetNewPassword').value;
    const confirmPassword = document.getElementById('resetConfirmPassword').value;

    if (!adminPasswordResetState.faceVerified || !adminPasswordResetState.resetToken) {
        setChangePasswordStatus('Face verification is required before password reset.', 'error');
        return;
    }

    if (newPassword.length < 12) {
        setChangePasswordStatus('Password must be at least 12 characters.', 'error');
        return;
    }

    if (!/[A-Z]/.test(newPassword)) {
        setChangePasswordStatus('Password must include at least one uppercase letter.', 'error');
        return;
    }

    if (!/[^A-Za-z0-9]/.test(newPassword)) {
        setChangePasswordStatus('Password must include at least one special character.', 'error');
        return;
    }

    if (newPassword !== confirmPassword) {
        setChangePasswordStatus('Passwords do not match.', 'error');
        return;
    }

    const submitBtn = document.getElementById('btnSubmitPasswordChange');
    submitBtn.disabled = true;

    try {
        const result = await postJson('change_admin_password.php', {
            action: 'change_password',
            admin_user_id: adminPasswordResetState.adminId,
            reset_token: adminPasswordResetState.resetToken,
            new_password: newPassword,
            confirm_password: confirmPassword
        });

        await Swal.fire({
            icon: 'success',
            title: 'Password Updated',
            text: result.message || 'Admin password updated successfully.',
            confirmButtonColor: '#0056b3'
        });

        closeChangePasswordModal();
    } catch (error) {
        setChangePasswordStatus(error.message || 'Unable to change password.', 'error');
    } finally {
        submitBtn.disabled = false;
    }
});

// Toggle Admin Status
async function toggleAdminStatus(adminId, newStatus) {
    const actionText = newStatus === 'Active' ? 'activate' : 'suspend';
    
    const result = await Swal.fire({
        title: `${actionText.charAt(0).toUpperCase() + actionText.slice(1)} Admin?`,
        text: `Are you sure you want to ${actionText} this admin account?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: newStatus === 'Active' ? '#10b981' : '#f59e0b',
        cancelButtonColor: '#64748b',
        confirmButtonText: `Yes, ${actionText} it!`
    });
    
    if (result.isConfirmed) {
        try {
            const response = await fetch('update_admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    admin_id: adminId,
                    status: newStatus,
                    csrf_token: csrfToken
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    confirmButtonColor: '#0056b3'
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.error || 'Failed to update admin status',
                    confirmButtonColor: '#0056b3'
                });
            }
        } catch (error) {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An unexpected error occurred. Please try again.',
                confirmButtonColor: '#0056b3'
            });
        }
    }
}

// Confirm Delete Admin
async function confirmDeleteAdmin(adminId, adminName) {
    const result = await Swal.fire({
        title: 'Delete Admin?',
        text: `Are you sure you want to permanently delete ${adminName}? This action cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete it!'
    });
    
    if (result.isConfirmed) {
        try {
            const response = await fetch('remove_admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    admin_id: adminId,
                    csrf_token: csrfToken
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: data.message,
                    confirmButtonColor: '#0056b3'
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.error || 'Failed to delete admin',
                    confirmButtonColor: '#0056b3'
                });
            }
        } catch (error) {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An unexpected error occurred. Please try again.',
                confirmButtonColor: '#0056b3'
            });
        }
    }
}
</script>
</body>
</html>
