<?php

/**
 * Admin endpoint to approve or deny student account verification
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('student.verify', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission student.verify.',
]);

// CSRF protection for JSON API
$headers = getallheaders();
if (!isset($headers['X-CSRF-Token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $headers['X-CSRF-Token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['student_id']) || !isset($data['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$student_id = (int)$data['student_id'];
$action = $data['action']; // 'approve' or 'deny'
$admin_id = (int)$_SESSION['admin_id'];

if (!in_array($action, ['approve', 'deny'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

try {
    $pdo = pdo();
    
    // Get student information
    $stmt = $pdo->prepare('SELECT id, student_id, name, email, status FROM users WHERE id = ? AND role = "Student" LIMIT 1');
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit;
    }
    
    if ($student['status'] !== 'Pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Account already processed']);
        exit;
    }
    
    if ($action === 'approve') {
        // Approve the account - DATABASE ONLY, email sent separately
        $stmt = $pdo->prepare('
            UPDATE users 
            SET status = "Active",
                last_login = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$student_id]);

        rotate_csrf_after_critical_action();
        apply_session_isolation_on_privilege_change([
            'target_user_id' => (int)$student_id,
            'target_role' => 'student',
        ]);
        
        // Log audit action
        logAuditAction(
            $pdo,
            $admin_id,
            $_SESSION['admin_name'] ?? 'Admin',
            'APPROVE_STUDENT',
            'student',
            $student_id,
            $student['name'],
            "Approved student account for {$student['name']} (ID: {$student['student_id']})",
            [
                'student_id' => $student['student_id'],
                'email' => $student['email'],
                'previous_status' => 'Pending',
                'new_status' => 'Active'
            ]
        );
        
        // Return success IMMEDIATELY - NO EMAIL HERE
        echo json_encode([
            'success' => true,
            'message' => 'Account approved successfully',
            'student_name' => $student['name'],
            'send_email' => true
        ]);
        exit;
        
    } else if ($action === 'deny') {
        // Deny and delete the account
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "Student" AND status = "Pending"');
        $stmt->execute([$student_id]);

        rotate_csrf_after_critical_action();
        apply_session_isolation_on_privilege_change([
            'target_user_id' => (int)$student_id,
            'target_role' => 'student',
        ]);
        
        // Log audit action
        logAuditAction(
            $pdo,
            $admin_id,
            $_SESSION['admin_name'] ?? 'Admin',
            'DENY_STUDENT',
            'student',
            $student_id,
            $student['name'],
            "Denied and deleted student account for {$student['name']} (ID: {$student['student_id']})",
            [
                'student_id' => $student['student_id'],
                'email' => $student['email'],
                'status' => 'Pending',
                'action' => 'Account deleted'
            ]
        );
        
        // Send denial email
        $subject = 'PCU RFID System - Account Verification Denied';
        $body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                <div style="background-color: #dc3545; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                    <h1 style="color: white; margin: 0; font-size: 28px;">Account Verification Update</h1>
                </div>
                <div style="background-color: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px;">
                    <h2 style="color: #dc3545; margin-top: 0;">Verification Not Approved</h2>
                    <p>Hello ' . htmlspecialchars($student['name']) . ',</p>
                    <p>We regret to inform you that your PCU RFID System account registration could not be verified at this time.</p>
                    
                    <div style="background-color: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin: 0; color: #721c24;"><strong>Verification Unsuccessful</strong></p>
                        <p style="margin: 10px 0 0 0; color: #721c24;">Your submitted credentials could not be verified against our enrolled student records.</p>
                    </div>
                    
                    <h3 style="color: #333; margin-top: 25px;">Possible Reasons:</h3>
                    <ul style="color: #555; line-height: 1.8;">
                        <li>Student ID does not match our records</li>
                        <li>Name spelling differs from official enrollment records</li>
                        <li>Email address is not associated with your student account</li>
                        <li>You are not currently enrolled at PCU</li>
                    </ul>
                    
                    <h3 style="color: #333; margin-top: 25px;">What to Do Next:</h3>
                    <p style="color: #555;">If you believe this is an error, please:</p>
                    <ol style="color: #555; line-height: 1.8;">
                        <li>Visit the Student Services Office in person</li>
                        <li>Bring your valid student ID and enrollment documents</li>
                        <li>Request assistance with account verification</li>
                        <li>Re-register with the correct information provided by the office</li>
                    </ol>
                    
                    <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin: 0; color: #856404;"><strong>Account Removed</strong></p>
                        <p style="margin: 10px 0 0 0; color: #856404;">Your account has been removed from our system. You may create a new account with verified information.</p>
                    </div>
                    
                    <p style="color: #6c757d; font-size: 12px; text-align: center; margin-top: 30px;">
                        PCU Student Services Office<br>
                        Email: studentservices@pcu.edu.ph<br>
                        This is an automated message from the PCU RFID System.
                    </p>
                </div>
            </div>';
        
        sendMail($student['email'], $subject, $body);
        
        echo json_encode([
            'success' => true,
            'message' => 'Account denied and removed successfully',
            'student_name' => $student['name']
        ]);
    }
    
} catch (\PDOException $e) {
    error_log('Verification error: ' . $e->getMessage());
    error_log('SQL Error Code: ' . $e->getCode());
    error_log('SQL State: ' . ($e->errorInfo[0] ?? 'N/A'));
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
