<?php

// admin/toggle_guardian_notifications.php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

require_permission('student.update', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission student.update.',
]);

// Verify CSRF token
verify_csrf();

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);
$enabled = isset($data['enabled']) ? (bool)$data['enabled'] : false;

// Get admin user ID
$adminId = $_SESSION['admin_id'] ?? 0;

try {
    $pdo = pdo();
    
    $value = $enabled ? '1' : '0';
    
    // Update or insert system setting
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, value, description, updated_by)
        VALUES ('guardian_notifications_enabled', ?, 'Enable/disable guardian entry notifications globally', ?)
        ON DUPLICATE KEY UPDATE 
            value = VALUES(value),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP
    ");
    
    $stmt->execute([$value, $adminId]);
    rotate_csrf_after_critical_action();
    
    echo json_encode([
        'success' => true,
        'enabled' => $enabled,
        'message' => $enabled ? 'Guardian notifications enabled' : 'Guardian notifications disabled'
    ]);
    
} catch (\PDOException $e) {
    error_log('Error toggling guardian notifications: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}
