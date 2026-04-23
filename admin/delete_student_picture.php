<?php

/**
 * Delete Student Profile Picture (Admin Only)
 * Allows admins to delete student profile pictures
 */

require_once __DIR__ . '/../db.php';

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

// Get JSON input
$data = get_json_input();

// Verify CSRF token
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Validate input
$userId = $data['user_id'] ?? null;

if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing user ID']);
    exit;
}

try {
    $pdo = pdo();
    
    // Get student's current profile picture
    $stmt = $pdo->prepare('SELECT profile_picture FROM users WHERE id = ? AND role = "Student"');
    $stmt->execute([$userId]);
    $student = $stmt->fetch();
    
    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit;
    }
    
    // Delete file from filesystem if exists
    if ($student['profile_picture']) {
        $file_path = __DIR__ . '/../assets/images/profiles/' . $student['profile_picture'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    
    // Update database - set profile_picture to NULL
    $updateStmt = $pdo->prepare('UPDATE users SET profile_picture = NULL WHERE id = ?');
    $updateStmt->execute([$userId]);
    rotate_csrf_after_critical_action();
    
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture deleted successfully'
    ]);
    
} catch (\PDOException $e) {
    error_log('Delete student picture error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (\Exception $e) {
    error_log('Delete error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An error occurred while deleting']);
}
?>
