<?php

/**
 * Send approval email - called asynchronously after approval
 */

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Check if user is logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

require_permission('student.verify', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission student.verify.',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

$headers = getallheaders();
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

// Get JSON input
$data = get_json_input();

if (!$data || !isset($data['student_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$student_id = (int)$data['student_id'];

try {
    $pdo = pdo();
    
    // Get student information
    $stmt = $pdo->prepare('SELECT id, student_id, name, email FROM users WHERE id = ? AND role = "Student" LIMIT 1');
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    if (!$student) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    // Send approval email
    $subject = 'PCU RFID System - Account Approved! ✅';
    $body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background-color: #28a745; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                <h1 style="color: white; margin: 0; font-size: 28px;">✅ Account Approved!</h1>
            </div>
            <div style="background-color: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px;">
                <h2 style="color: #28a745; margin-top: 0;">Welcome to PCU RFID System!</h2>
                <p>Hello ' . htmlspecialchars($student['name']) . ',</p>
                <p>Great news! Your account has been verified and approved. You can now log in and start using the PCU RFID System.</p>
                
                <div style="background-color: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0; color: #155724;"><strong>✓ Your Account is Active</strong></p>
                </div>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . htmlspecialchars(app_url('login.php')) . '" 
                       style="display: inline-block; background-color: #0056b3; color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; font-weight: bold;">
                        Log In Now
                    </a>
                </div>
                
                <h3 style="color: #333;">Your Account Details:</h3>
                <ul style="color: #555; line-height: 1.8;">
                    <li><strong>Student ID:</strong> ' . htmlspecialchars($student['student_id']) . '</li>
                    <li><strong>Name:</strong> ' . htmlspecialchars($student['name']) . '</li>
                    <li><strong>Email:</strong> ' . htmlspecialchars($student['email']) . '</li>
                </ul>
            </div>
        </div>';
    
    sendMail($student['email'], $subject, $body);
    
    echo json_encode(['success' => true]);
    
} catch (\Exception $e) {
    error_log('Email error: ' . $e->getMessage());
    echo json_encode(['success' => false]);
}
