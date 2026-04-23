<?php
/**
 * Audit Log Helper Functions
 * Records all admin actions for accountability and tracking
 */

/**
 * Log admin actions to audit_logs table
 * 
 * @param PDO $pdo Database connection
 * @param int $adminId Admin user ID
 * @param string $adminName Admin name
 * @param string $actionType Action performed (e.g., 'APPROVE_STUDENT', 'REGISTER_RFID')
 * @param string $targetType Type of target (e.g., 'student', 'rfid_card')
 * @param int|null $targetId Target record ID
 * @param string|null $targetName Target name/identifier
 * @param string $description Human-readable description
 * @param array|null $details Additional details (stored as JSON)
 * @return bool Success status
 */
function logAuditAction($pdo, $adminId, $adminName, $actionType, $targetType, $targetId, $targetName, $description, $details = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                admin_id, admin_name, action_type, target_type, target_id, 
                target_name, description, details, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $adminId,
            $adminName,
            $actionType,
            $targetType,
            $targetId,
            $targetName,
            $description,
            $details ? json_encode($details) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
        return false;
    }
}
