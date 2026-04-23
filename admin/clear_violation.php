<?php

// Start the session and include database connection
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';
require_once __DIR__ . '/../includes/email_templates.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_permission('violation.clear', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission violation.clear.',
]);

// CSRF protection for JSON API
$headers = getallheaders();
if (!isset($headers['X-CSRF-Token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $headers['X-CSRF-Token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['student_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Student ID is required']);
    exit();
}
$studentId = filter_var($input['student_id'], FILTER_VALIDATE_INT);
if (!$studentId || $studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid student ID']);
    exit();
}

try {
    $pdo = pdo();

    // Get optional reparation info from input
    $reparationType  = trim($input['reparation_type'] ?? 'resolved_by_admin');
    $reparationNotes = trim($input['reparation_notes'] ?? '');
    $sendNotification = (bool)($input['send_notification'] ?? true);

    // Verify the student exists first
    $stmt = $pdo->prepare('SELECT id, name, email, student_id, violation_count, active_violations_count FROM users WHERE id = ? AND role = "Student"');
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit();
    }
    
    $adminId   = (int)($_SESSION['admin_id'] ?? 0);
    $adminName = $_SESSION['admin_name'] ?? 'Admin';

    $pdo->beginTransaction();
    
    try {
        // 1. Resolve all active violations in student_violations → 'apprehended'
        $stmt = $pdo->prepare("
            UPDATE student_violations
            SET status = 'apprehended',
                reparation_type = ?,
                reparation_notes = ?,
                reparation_completed_at = NOW(),
                resolved_by = ?
            WHERE user_id = ? AND status IN ('active', 'pending_reparation')
        ");
        $stmt->execute([$reparationType, $reparationNotes, $adminId, $studentId]);
        $resolvedViolations = $stmt->rowCount();

        // 2. Count how many of those were "No Physical ID" (RFID) violations
        //    to know how much to decrement violation_count
        $remainingStmt = $pdo->prepare("SELECT COUNT(*) FROM student_violations WHERE user_id = ? AND status IN ('active', 'pending_reparation')");
        $remainingStmt->execute([$studentId]);
        $remainingActive = (int)$remainingStmt->fetchColumn();

        // Keep offense history. Reset gate mark only when nothing is pending/active.
        $pdo->prepare('UPDATE users SET active_violations_count = ?, gate_mark_count = CASE WHEN ? = 0 THEN 0 ELSE gate_mark_count END WHERE id = ?')
            ->execute([$remainingActive, $remainingActive, $studentId]);
        
        $pdo->commit();
        rotate_csrf_after_critical_action();
        
        // Log the action to audit trail
        try {
            logAuditAction(
                $pdo,
                $adminId,
                $adminName,
                'RESOLVE_ALL_VIOLATIONS',
                'student',
                (int)$studentId,
                $student['name'],
                "Resolved all active violations for {$student['name']}",
                [
                    'previous_violation_count' => (int)$student['violation_count'],
                    'previous_active_count' => (int)$student['active_violations_count'],
                    'violations_resolved' => $resolvedViolations,
                    'remaining_active_after_resolve' => $remainingActive,
                    'reparation_type' => $reparationType,
                ]
            );
        } catch (\PDOException $e) {
            error_log('Audit log error: ' . $e->getMessage());
        }

        // Send notification email to student that they can claim their documents
        if ($sendNotification && $resolvedViolations > 0) {
            try {
                $timestamp       = date('F j, Y g:i A');
                $reparationLabel = ucwords(str_replace('_', ' ', $reparationType));
                $subject         = 'All Violations Resolved - You May Claim Your Documents';
                $body            = emailAllViolationsResolved(
                    $student['name'],
                    $resolvedViolations,
                    $reparationLabel,
                    $timestamp
                );
                sendMail($student['email'], $subject, $body, true);
            } catch (\Exception $mailEx) {
                error_log('Failed to send resolve-all notification: ' . $mailEx->getMessage());
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'All violations resolved successfully (' . $resolvedViolations . ' violations apprehended)']);
    } catch (\Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (\PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (\Exception $e) {
    error_log('Error clearing violations: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to clear violations']);
}
