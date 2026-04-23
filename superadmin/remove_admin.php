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

require_permission('admin.delete', [
    'actor_role' => 'superadmin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission admin.delete.',
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

// Get admin ID
$adminId = intval($input['admin_id'] ?? 0);

if ($adminId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid admin ID']);
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

    // Best-effort cleanup: remove any enrolled admin face descriptors
    // (table is created lazily under /superadmin; ignore if missing)
    try {
        $fdCheck = $pdo->query("SHOW TABLES LIKE 'admin_face_descriptors'")->fetch();
        if ($fdCheck) {
            $pdo->prepare('DELETE FROM admin_face_descriptors WHERE user_id = ?')->execute([$adminId]);
        }
    } catch (Throwable $e) {
        // Ignore cleanup failures.
    }
    
    // Get admin_account ID for audit log
    $stmt = $pdo->prepare("SELECT id FROM admin_accounts WHERE user_id = ?");
    $stmt->execute([$adminId]);
    $adminAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    $adminAccountId = $adminAccount ? $adminAccount['id'] : null;
    
    // Delete from admin_accounts table first (if exists)
    $stmt = $pdo->prepare("DELETE FROM admin_accounts WHERE user_id = ?");
    $stmt->execute([$adminId]);
    
    // Delete from users table
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'Admin'");
    $stmt->execute([$adminId]);
    
    // Log the action
    $stmt = $pdo->prepare("
        INSERT INTO superadmin_audit_log (super_admin_id, action, target_admin_id, details, ip_address, user_agent) 
        VALUES (?, 'DELETE_ADMIN', ?, ?, ?, ?)
    ");
    $stmt->execute([
        $_SESSION['superadmin_id'],
        $adminAccountId,
        json_encode(['name' => $admin['name'], 'email' => $admin['email']]),
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
    
    echo json_encode([
        'success' => true,
        'message' => "Admin '{$admin['name']}' has been removed successfully."
    ]);
    
} catch (PDOException $e) {
    // Rollback on error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Remove Admin error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred. Please try again.']);
}
