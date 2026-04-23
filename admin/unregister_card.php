<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('rfid.unregister', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission rfid.unregister.',
]);

// CSRF protection for JSON API
$headers = getallheaders();
if (!isset($headers['X-CSRF-Token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $headers['X-CSRF-Token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Check if request is POST and has valid JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit;
}

// Validate required fields
if (empty($input['student_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student ID']);
    exit;
}

try {
    $pdo = pdo();
    
    // Fetch student info before unregistering (for audit log)
    $infoStmt = $pdo->prepare('SELECT name, student_id, rfid_uid FROM users WHERE id = ? AND role = "Student"');
    $infoStmt->execute([$input['student_id']]);
    $studentInfo = $infoStmt->fetch(\PDO::FETCH_ASSOC);
    $studentName = $studentInfo['name'] ?? 'Unknown';
    $oldRfidUid = $studentInfo['rfid_uid'] ?? '';
    $studentIdStr = $studentInfo['student_id'] ?? '';
    
    // Unregister RFID card
    $stmt = $pdo->prepare('
        UPDATE users 
        SET rfid_uid = NULL, rfid_registered_at = NULL 
        WHERE id = ? AND role = "Student"
    ');
    
    if ($stmt->execute([$input['student_id']])) {
        if ($stmt->rowCount() > 0) {
            // Audit log
            $adminId = $_SESSION['admin_id'] ?? 0;
            $adminName = $_SESSION['admin_name'] ?? 'Admin';
            logAuditAction($pdo, $adminId, $adminName, 'UNREGISTER_RFID', 'student', $input['student_id'], $studentName,
                "Unregistered RFID card {$oldRfidUid} from {$studentName} ({$studentIdStr})",
                ['rfid_uid' => $oldRfidUid, 'student_id' => $studentIdStr]
            );

            rotate_csrf_after_critical_action();
            
            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Student not found']);
        }
    } else {
        throw new \Exception('Failed to update database');
    }
    
} catch (\Exception $e) {
    error_log('Error unregistering RFID card: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to unregister RFID card']);
}