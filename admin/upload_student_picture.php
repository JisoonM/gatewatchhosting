<?php

/**
 * Upload Student Profile Picture (Admin Only)
 * Allows admins to upload profile pictures for students
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

$headers = getallheaders();
$csrfToken = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Validate student_id parameter
$studentId = $_POST['student_id'] ?? null;

if (!$studentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student ID']);
    exit;
}

// Validate file upload
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['file'];

// Validate file type
$allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime_type, $allowed_types)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPG and PNG are allowed']);
    exit;
}

// Validate file size (5MB max)
$max_size = 5 * 1024 * 1024; // 5MB in bytes
if ($file['size'] > $max_size) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum size is 5MB']);
    exit;
}

try {
    $pdo = pdo();

    $mimeToExtension = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
    ];
    $extension = $mimeToExtension[$mime_type] ?? null;
    if ($extension === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unsupported image type']);
        exit;
    }
    
    // Verify student exists
    $stmt = $pdo->prepare('SELECT id, profile_picture FROM users WHERE id = ? AND role = "Student"');
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    
    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit;
    }
    
    // Generate unique filename from the validated MIME type, not the client filename.
    $filename = 'student_' . $studentId . '_' . time() . '.' . $extension;
    $upload_dir = __DIR__ . '/../assets/images/profiles/';
    $upload_path = $upload_dir . $filename;
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Delete old profile picture if exists
    if ($student['profile_picture']) {
        $old_file = $upload_dir . $student['profile_picture'];
        if (file_exists($old_file)) {
            unlink($old_file);
        }
    }
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
        exit;
    }
    
    // Update database
    $updateStmt = $pdo->prepare('UPDATE users SET profile_picture = ? WHERE id = ?');
    $updateStmt->execute([$filename, $studentId]);
    rotate_csrf_after_critical_action();
    
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture uploaded successfully',
        'filename' => $filename
    ]);
    
} catch (\PDOException $e) {
    error_log('Upload student picture error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (\Exception $e) {
    error_log('Upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An error occurred while uploading']);
}
?>
