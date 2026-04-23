<?php

/**
 * Update Student Information (Admin Only)
 * Allows admins to update student name and student ID
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';

header('Content-Type: application/json');

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('student.update', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission student.update.',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$rawBody = get_raw_request_body();
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request payload']);
    exit;
}

// Verify CSRF token
$headers = function_exists('getallheaders') ? getallheaders() : [];
$csrfToken = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Validate input
$userId = $data['user_id'] ?? null;
$name = trim($data['name'] ?? '');
$studentId = trim($data['student_id'] ?? '');
$course = trim($data['course'] ?? '');

if (!$userId || !$name || !$studentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

if (!preg_match('/^\d{9}$/', $studentId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Student ID must be exactly 9 digits (numbers only)']);
    exit;
}

try {
    $pdo = pdo();
    
    // Check if student_id already exists for a different user
    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE student_id = ? AND id != ?');
    $checkStmt->execute([$studentId, $userId]);
    
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This Student ID is already in use by another student']);
        exit;
    }
    
    // Fetch old values for audit log
    $oldStmt = $pdo->prepare('SELECT name, student_id, course FROM users WHERE id = ?');
    $oldStmt->execute([$userId]);
    $oldData = $oldStmt->fetch(\PDO::FETCH_ASSOC);
    
    // Update student information
    $updateStmt = $pdo->prepare('
        UPDATE users 
        SET name = ?, student_id = ?, course = ? 
        WHERE id = ? AND role = "Student"
    ');
    
    $updateStmt->execute([$name, $studentId, $course ?: null, $userId]);
    
    if ($updateStmt->rowCount() > 0) {
        // Audit log with changes detail
        $adminId = $_SESSION['admin_id'] ?? 0;
        $adminName = $_SESSION['admin_name'] ?? 'Admin';
        $changes = [];
        if ($oldData && $oldData['name'] !== $name) $changes['name'] = ['from' => $oldData['name'], 'to' => $name];
        if ($oldData && $oldData['student_id'] !== $studentId) $changes['student_id'] = ['from' => $oldData['student_id'], 'to' => $studentId];
        if ($oldData && ($oldData['course'] ?? '') !== ($course ?: '')) $changes['course'] = ['from' => $oldData['course'] ?? '', 'to' => $course];
        
        logAuditAction($pdo, $adminId, $adminName, 'UPDATE_STUDENT', 'student', $userId, $name,
            "Updated student info for {$name} ({$studentId})",
            ['changes' => $changes, 'student_id' => $studentId]
        );
        rotate_csrf_after_critical_action();
        
        echo json_encode([
            'success' => true,
            'message' => 'Student information updated successfully'
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student not found or no changes made']);
    }
    
} catch (\PDOException $e) {
    error_log('Update student info error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
?>
