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

require_permission('admin.update', [
    'actor_role' => 'superadmin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission admin.update.',
]);

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Verify CSRF token
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Get parameters
$adminId = intval($input['admin_id'] ?? 0);
$newStatus = $input['status'] ?? '';

// Validate input
if ($adminId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid admin ID']);
    exit;
}

if (!in_array($newStatus, ['Active', 'Inactive', 'Suspended'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid status value']);
    exit;
}

try {
    $pdo = pdo();
    
    // Check if admin exists and is actually an admin
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ? AND role = 'Admin'");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        echo json_encode(['success' => false, 'error' => 'Admin not found']);
        exit;
    }
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Check if admin_accounts record exists
    $stmt = $pdo->prepare("SELECT id, status FROM admin_accounts WHERE user_id = ?");
    $stmt->execute([$adminId]);
    $adminAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $adminAccountId = null;
    $oldStatus = 'Active';
    
    if ($adminAccount) {
        $adminAccountId = $adminAccount['id'];
        $oldStatus = $adminAccount['status'];
        
        // Update existing record
        $stmt = $pdo->prepare("UPDATE admin_accounts SET status = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$newStatus, $adminId]);
    } else {
        // Create new admin_accounts record
        $stmt = $pdo->prepare("INSERT INTO admin_accounts (user_id, created_by, status, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$adminId, $_SESSION['superadmin_id'], $newStatus]);
        $adminAccountId = $pdo->lastInsertId();
    }
    
    // Also update the users table status if suspending/activating
    if ($newStatus === 'Suspended') {
        $stmt = $pdo->prepare("UPDATE users SET status = 'Locked' WHERE id = ?");
        $stmt->execute([$adminId]);
    } elseif ($newStatus === 'Active') {
        $stmt = $pdo->prepare("UPDATE users SET status = 'Active' WHERE id = ?");
        $stmt->execute([$adminId]);
    }
    
    // Determine audit action
    $auditAction = 'UPDATE_ADMIN';
    if ($newStatus === 'Active' && $oldStatus !== 'Active') {
        $auditAction = 'ACTIVATE_ADMIN';
    } elseif ($newStatus === 'Suspended') {
        $auditAction = 'SUSPEND_ADMIN';
    }
    
    // Log the action
    $stmt = $pdo->prepare("
        INSERT INTO superadmin_audit_log (super_admin_id, action, target_admin_id, details, ip_address, user_agent) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $_SESSION['superadmin_id'],
        $auditAction,
        $adminAccountId,
        json_encode([
            'name' => $admin['name'],
            'email' => $admin['email'],
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]),
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);
    
    // Commit transaction
    $pdo->commit();
    rotate_csrf_after_critical_action();
    apply_session_isolation_on_privilege_change([
        'target_user_id' => (int)$adminId,
        'target_role' => 'admin',
    ]);
    
    $statusMessage = $newStatus === 'Active' ? 'activated' : ($newStatus === 'Suspended' ? 'suspended' : 'updated');
    
    echo json_encode([
        'success' => true,
        'message' => "Admin '{$admin['name']}' has been {$statusMessage} successfully."
    ]);
    
} catch (PDOException $e) {
    // Rollback on error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Update Admin error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred. Please try again.']);
}
