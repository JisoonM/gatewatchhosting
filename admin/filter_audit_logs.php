<?php

/**
 * Filter Audit Logs API
 * Returns filtered audit log entries based on criteria
 */

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Security check: Admin only
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('audit.read', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission audit.read.',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$headers = getallheaders();
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

try {
    $pdo = pdo();
    
    $query = "SELECT * FROM audit_logs WHERE action_type != 'EXPORT_AUDIT_LOG'";
    $params = [];
    
    // Filter by action type
    if (!empty($_GET['action_type'])) {
        $query .= " AND action_type = ?";
        $params[] = $_GET['action_type'];
    }
    
    // Filter by date from
    if (!empty($_GET['date_from'])) {
        $query .= " AND DATE(created_at) >= ?";
        $params[] = $_GET['date_from'];
    }
    
    // Filter by date to
    if (!empty($_GET['date_to'])) {
        $query .= " AND DATE(created_at) <= ?";
        $params[] = $_GET['date_to'];
    }
    
    // Filter by admin (optional)
    if (!empty($_GET['admin_id'])) {
        $query .= " AND admin_id = ?";
        $params[] = (int)$_GET['admin_id'];
    }
    
    $query .= " ORDER BY created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Ensure MARK_LOST / MARK_FOUND details show the real users.student_id (school ID)
    foreach ($logs as &$log) {
        if (!in_array($log['action_type'] ?? '', ['MARK_LOST', 'MARK_FOUND'], true)) {
            continue;
        }

        $details = json_decode($log['details'] ?? '', true);
        if (!is_array($details)) {
            continue;
        }

        $cardId = isset($details['card_id']) ? (int)$details['card_id'] : 0;
        if ($cardId <= 0) {
            continue;
        }

        $sidStmt = $pdo->prepare('
            SELECT u.student_id
            FROM rfid_cards rc
            INNER JOIN users u ON u.id = rc.user_id
            WHERE rc.id = ?
            LIMIT 1
        ');
        $sidStmt->execute([$cardId]);
        $resolvedStudentId = (string)($sidStmt->fetchColumn() ?: '');

        if ($resolvedStudentId !== '') {
            $details['student_id'] = $resolvedStudentId;
            $log['details'] = json_encode($details);
        }
    }
    unset($log);
    
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'count' => count($logs)
    ]);
    
} catch (\Exception $e) {
    error_log("Audit filter error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to filter audit logs'
    ]);
}
