<?php
// admin/mark_lost_rfid.php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';

// PHPMailer for sending emails
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

require_permission('rfid.mark_lost', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission rfid.mark_lost.',
]);

// Verify CSRF token
verify_csrf();

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);
$cardId = (int)($data['card_id'] ?? 0);
$studentId = (int)($data['student_id'] ?? 0);
$rfidUid = trim($data['rfid_uid'] ?? '');
$action = trim($data['action'] ?? ''); // 'mark_lost' or 'mark_found'
$studentEmail = trim($data['student_email'] ?? '');
$studentName = trim($data['student_name'] ?? '');

if (!in_array($action, ['mark_lost', 'mark_found'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

// Get admin user ID
$adminId = $_SESSION['admin_id'] ?? 0;

if (!$adminId) {
    echo json_encode(['success' => false, 'error' => 'Admin ID not found in session']);
    exit;
}

try {
    $pdo = pdo();

    if ($cardId <= 0) {
        if ($studentId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Card ID is missing and student reference is invalid']);
            exit;
        }

        if ($rfidUid !== '') {
            $resolveStmt = $pdo->prepare("SELECT id FROM rfid_cards WHERE user_id = ? AND rfid_uid = ? ORDER BY id DESC LIMIT 1");
            $resolveStmt->execute([$studentId, $rfidUid]);
            $cardId = (int)($resolveStmt->fetchColumn() ?: 0);
        }

        if ($cardId <= 0) {
            $resolveStmt = $pdo->prepare("SELECT id FROM rfid_cards WHERE user_id = ? ORDER BY id DESC LIMIT 1");
            $resolveStmt->execute([$studentId]);
            $cardId = (int)($resolveStmt->fetchColumn() ?: 0);
        }

        if ($cardId <= 0 && $rfidUid !== '') {
            $insertStmt = $pdo->prepare("INSERT INTO rfid_cards (user_id, rfid_uid, registered_at, is_active) VALUES (?, ?, NOW(), 1)");
            $insertStmt->execute([$studentId, $rfidUid]);
            $cardId = (int)$pdo->lastInsertId();
        }

        if ($cardId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Unable to resolve RFID card record']);
            exit;
        }
    }

    // Fetch the student's actual student_id (school ID) for audit logging
    $actualStudentId = '';
    try {
        $sidStmt = $pdo->prepare('
            SELECT u.student_id
            FROM users u
            LEFT JOIN rfid_cards rc ON rc.user_id = u.id
            WHERE (u.id = :student_id AND :student_id > 0)
               OR (rc.id = :card_id AND :card_id > 0)
            ORDER BY rc.id DESC
            LIMIT 1
        ');
        $sidStmt->execute([
            'student_id' => $studentId,
            'card_id' => $cardId
        ]);
        $actualStudentId = (string)($sidStmt->fetchColumn() ?: '');
    } catch (\Exception $e) {
        error_log('Failed to fetch student_id for audit: ' . $e->getMessage());
    }

    if ($action === 'mark_lost') {
        // Mark as lost with automatic reason
        $reason = 'RFID card marked as lost by admin - Student notified';
        $result = mark_rfid_lost($cardId, $adminId, $reason);
        
        if ($result) {
            // Send email notification to student
            $emailSent = sendLostRfidEmail($studentEmail, $studentName);
            
            // Audit log
            $adminName = $_SESSION['admin_name'] ?? 'Admin';
            $auditPdo = pdo();
            logAuditAction($auditPdo, $adminId, $adminName, 'MARK_LOST', 'rfid_card', $cardId, $studentName,
                "Marked RFID card {$rfidUid} as lost for {$studentName} ({$actualStudentId})",
                ['rfid_uid' => $rfidUid, 'card_id' => $cardId, 'student_id' => $actualStudentId, 'email_sent' => $emailSent]
            );
            rotate_csrf_after_critical_action();
            
            echo json_encode([
                'success' => true,
                'message' => '✓ RFID card marked as lost successfully' . ($emailSent ? ' and email sent to student' : ' (email failed)'),
                'action' => 'marked_lost',
                'email_sent' => $emailSent
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to mark RFID as lost']);
        }
    } elseif ($action === 'mark_found') {
        $result = mark_rfid_found($cardId, $adminId);
        
        if ($result) {
            // Send email notification to student that card is re-enabled
            $emailSent = sendFoundRfidEmail($studentEmail, $studentName);
            
            // Audit log
            $adminName = $_SESSION['admin_name'] ?? 'Admin';
            $auditPdo = pdo();
            logAuditAction($auditPdo, $adminId, $adminName, 'MARK_FOUND', 'rfid_card', $cardId, $studentName,
                "Re-enabled RFID card {$rfidUid} for {$studentName} ({$actualStudentId})",
                ['rfid_uid' => $rfidUid, 'card_id' => $cardId, 'student_id' => $actualStudentId, 'email_sent' => $emailSent]
            );
            rotate_csrf_after_critical_action();
            
            echo json_encode([
                'success' => true,
                'message' => '✓ RFID card re-enabled successfully' . ($emailSent ? ' and email sent to student' : ' (email failed)'),
                'action' => 'marked_found',
                'email_sent' => $emailSent
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to re-enable RFID card']);
        }
    }
} catch (\Exception $e) {
    error_log('Error in mark_lost_rfid.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while processing your request'
    ]);
}

/**
 * Send email to student when RFID is marked as lost
 */
function sendLostRfidEmail($studentEmail, $studentName) {
    if (empty($studentEmail)) {
        error_log('Cannot send lost RFID email: No email address provided');
        return false;
    }
    
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($studentEmail, $studentName);
        
        // Content
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = '⚠️ RFID Card Temporarily Disabled - PCU GateWatch';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: linear-gradient(135deg, #DC2626 0%, #F59E0B 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                    <h1 style='margin: 0; font-size: 28px;'>⚠️ RFID Card Lost</h1>
                </div>
                
                <div style='background: #ffffff; padding: 30px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 10px 10px;'>
                    <p style='color: #1f2937; font-size: 16px; line-height: 1.6;'>
                        Dear <strong>{$studentName}</strong>,
                    </p>
                    
                    <div style='background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 15px; margin: 20px 0; border-radius: 4px;'>
                        <p style='color: #92400E; margin: 0; font-weight: bold;'>
                            Your RFID card has been marked as <span style='color: #DC2626;'>LOST</span> and temporarily disabled.
                        </p>
                    </div>
                    
                    <h3 style='color: #DC2626; margin-top: 25px;'>⚠️ Important Information:</h3>
                    <ul style='color: #374151; line-height: 1.8;'>
                        <li>Your RFID card is <strong>temporarily disabled</strong> and cannot be used for entry</li>
                        <li>Please use your <strong>Digital ID QR Code</strong> for gate entry until this is resolved</li>
                        <li>To access your Digital ID, log in to the GateWatch system</li>
                    </ul>
                    
                    <h3 style='color: #0056B3; margin-top: 25px;'>📧 Next Steps:</h3>
                    <div style='background: #EFF6FF; border-left: 4px solid #0056B3; padding: 15px; margin: 15px 0; border-radius: 4px;'>
                        <p style='color: #1E40AF; margin: 0;'>
                            <strong>Email Student Services Office</strong> regarding your lost RFID card:
                        </p>
                        <p style='color: #1E40AF; margin: 10px 0 0 0;'>
                            📧 Email: <a href='mailto:studentservices@pcu.edu.ph' style='color: #0056B3; text-decoration: none; font-weight: bold;'>studentservices@pcu.edu.ph</a>
                        </p>
                    </div>
                    
                    <h3 style='color: #059669; margin-top: 25px;'>✅ How to Use Digital ID:</h3>
                    <ol style='color: #374151; line-height: 1.8;'>
                        <li>Log in to <strong>GateWatch</strong> system</li>
                        <li>Access your <strong>Digital ID</strong> page</li>
                        <li>Show the <strong>QR Code</strong> to the security guard for scanning</li>
                        <li>Entry will be logged automatically</li>
                    </ol>
                    
                    <div style='background: #F3F4F6; padding: 15px; margin-top: 25px; border-radius: 6px; text-align: center;'>
                        <p style='color: #6B7280; margin: 0; font-size: 14px;'>
                            If you have found your RFID card, please contact the Student Services Office to have it re-enabled.
                        </p>
                    </div>
                    
                    <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;'>
                        <p style='color: #6B7280; font-size: 14px; margin: 5px 0;'>
                            This is an automated message from PCU GateWatch System.<br>
                            Please do not reply to this email.
                        </p>
                    </div>
                </div>
            </div>
        ";
        
        $mail->AltBody = "Dear {$studentName},\n\n" .
                        "Your RFID card has been marked as LOST and temporarily disabled.\n\n" .
                        "IMPORTANT:\n" .
                        "- Your RFID card cannot be used for entry\n" .
                        "- Use your Digital ID QR Code instead\n" .
                        "- Email studentservices@pcu.edu.ph about your lost card\n\n" .
                        "To use Digital ID: Log in to GateWatch → Access Digital ID → Show QR code to security\n\n" .
                        "PCU GateWatch System";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Failed to send lost RFID email: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send email to student when RFID is re-enabled (found)
 */
function sendFoundRfidEmail($studentEmail, $studentName) {
    if (empty($studentEmail)) {
        error_log('Cannot send found RFID email: No email address provided');
        return false;
    }
    
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($studentEmail, $studentName);
        
        // Content
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = '✅ RFID Card Re-Enabled - PCU GateWatch';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: linear-gradient(135deg, #059669 0%, #10B981 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                    <h1 style='margin: 0; font-size: 28px;'>✅ RFID Card Re-Enabled</h1>
                </div>
                
                <div style='background: #ffffff; padding: 30px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 10px 10px;'>
                    <p style='color: #1f2937; font-size: 16px; line-height: 1.6;'>
                        Dear <strong>{$studentName}</strong>,
                    </p>
                    
                    <div style='background: #D1FAE5; border-left: 4px solid #10B981; padding: 15px; margin: 20px 0; border-radius: 4px;'>
                        <p style='color: #065F46; margin: 0; font-weight: bold;'>
                            Good news! Your RFID card has been <span style='color: #059669;'>RE-ENABLED</span> and is now active.
                        </p>
                    </div>
                    
                    <h3 style='color: #059669; margin-top: 25px;'>✅ What This Means:</h3>
                    <ul style='color: #374151; line-height: 1.8;'>
                        <li>Your RFID card is <strong>now active</strong> and can be used for gate entry</li>
                        <li>You can tap your RFID card at the security gate as usual</li>
                        <li>Your Digital ID QR Code is still available if needed</li>
                        <li>All access privileges have been restored</li>
                    </ul>
                    
                    <div style='background: #EFF6FF; padding: 15px; margin: 20px 0; border-radius: 6px; border-left: 4px solid #0056B3;'>
                        <p style='color: #1E40AF; margin: 0;'>
                            <strong>📱 Reminder:</strong> Keep your RFID card in a safe place to avoid future issues.
                        </p>
                    </div>
                    
                    <div style='background: #F3F4F6; padding: 15px; margin-top: 25px; border-radius: 6px; text-align: center;'>
                        <p style='color: #6B7280; margin: 0; font-size: 14px;'>
                            If you experience any issues with your RFID card, please contact Student Services Office.
                        </p>
                    </div>
                    
                    <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;'>
                        <p style='color: #6B7280; font-size: 14px; margin: 5px 0;'>
                            This is an automated message from PCU GateWatch System.<br>
                            Please do not reply to this email.
                        </p>
                    </div>
                </div>
            </div>
        ";
        
        $mail->AltBody = "Dear {$studentName},\n\n" .
                        "Good news! Your RFID card has been RE-ENABLED and is now active.\n\n" .
                        "You can now:\n" .
                        "- Use your RFID card for gate entry\n" .
                        "- Tap your card at the security gate as usual\n" .
                        "- All access privileges restored\n\n" .
                        "Keep your RFID card safe to avoid future issues.\n\n" .
                        "PCU GateWatch System";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Failed to send found RFID email: ' . $mail->ErrorInfo);
        return false;
    }
}
