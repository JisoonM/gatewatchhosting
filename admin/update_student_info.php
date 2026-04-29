<?php

/**
 * Update Student Information (Admin Only)
 * Allows admins to update student name and student ID
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';

header('Content-Type: application/json');

// [AGENT CHANGE — TASK 2]
// Check if user is logged in as admin or superadmin
$isAdminSession = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isSuperadminSession = isset($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true;
if (!$isAdminSession && !$isSuperadminSession) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$permissionRole = $isSuperadminSession ? 'superadmin' : 'admin';
require_permission('student.update', [
    'actor_role' => $permissionRole,
    'response' => 'json',
    'message' => 'Forbidden: missing permission student.update.',
]);
// [END TASK 2]

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
$dobRaw = trim((string)($data['dob'] ?? ''));

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

// [AGENT CHANGE — TASK 2]
$allowDobEdit = $isSuperadminSession;
if ($dobRaw !== '' && !$allowDobEdit) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'DOB is read-only for admins. Only superadmin can request DOB review updates.']);
    exit;
}
// [END TASK 2]

try {
    $pdo = pdo();
    $hasDobColumn = db_column_exists('users', 'dob');
    $hasComputedAgeColumn = db_column_exists('users', 'computed_age');
    $hasDobReviewColumn = db_column_exists('users', 'dob_review_required');
    
    // Check if student_id already exists for a different user
    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE student_id = ? AND id != ?');
    $checkStmt->execute([$studentId, $userId]);
    
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This Student ID is already in use by another student']);
        exit;
    }
    
    // Fetch old values for audit log
    $oldDobExpr = $hasDobColumn ? 'dob' : 'NULL AS dob';
    $oldStmt = $pdo->prepare('SELECT name, student_id, course, ' . $oldDobExpr . ' FROM users WHERE id = ?');
    $oldStmt->execute([$userId]);
    $oldData = $oldStmt->fetch(\PDO::FETCH_ASSOC);
    
    // [AGENT CHANGE — TASK 2]
    $setParts = ['name = ?', 'student_id = ?', 'course = ?'];
    $binds = [$name, $studentId, $course ?: null];

    if ($dobRaw !== '' && $allowDobEdit) {
        if (!$hasDobColumn) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'DOB column is not available yet. Run latest migration first.']);
            exit;
        }
        $dobObj = DateTime::createFromFormat('Y-m-d', $dobRaw);
        if (!$dobObj || $dobObj->format('Y-m-d') !== $dobRaw) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid DOB format. Use YYYY-MM-DD.']);
            exit;
        }
        $today = new DateTime('today');
        if ($dobObj > $today) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'DOB cannot be in the future.']);
            exit;
        }
        $newAge = (int)$dobObj->diff($today)->y;

        // Superadmin DOB updates must trigger review; consent log is intentionally not auto-mutated.
        $setParts[] = 'dob = ?';
        $binds[] = $dobRaw;
        if ($hasComputedAgeColumn) {
            $setParts[] = 'computed_age = ?';
            $binds[] = $newAge;
        }
        if ($hasDobReviewColumn) {
            $setParts[] = 'dob_review_required = 1';
        }
        $setParts[] = 'status = "Pending"';
    }

    $binds[] = $userId;
    $updateStmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $setParts) . ' WHERE id = ? AND role = "Student"');
    $updateStmt->execute($binds);
    // [END TASK 2]
    
    if ($updateStmt->rowCount() > 0) {
        // Audit log with changes detail
        $adminId = $_SESSION['admin_id'] ?? ($_SESSION['superadmin_id'] ?? 0);
        $adminName = $_SESSION['admin_name'] ?? ($_SESSION['superadmin_name'] ?? 'Admin');
        $changes = [];
        if ($oldData && $oldData['name'] !== $name) $changes['name'] = ['from' => $oldData['name'], 'to' => $name];
        if ($oldData && $oldData['student_id'] !== $studentId) $changes['student_id'] = ['from' => $oldData['student_id'], 'to' => $studentId];
        if ($oldData && ($oldData['course'] ?? '') !== ($course ?: '')) $changes['course'] = ['from' => $oldData['course'] ?? '', 'to' => $course];
        if ($dobRaw !== '' && $allowDobEdit) $changes['dob'] = ['from' => $oldData['dob'] ?? '', 'to' => $dobRaw];
        
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
