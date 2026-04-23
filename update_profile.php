<?php

require_once 'db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

try {
    // Only allow updating name
    if (!isset($_POST['name']) || empty(trim($_POST['name']))) {
        throw new Exception('Name is required');
    }

    $name = trim($_POST['name']);
    
    // Validate name (letters, spaces, hyphens, apostrophes only)
    if (!preg_match("/^[a-zA-Z\s\-'\.]+$/", $name)) {
        throw new Exception('Name can only contain letters, spaces, hyphens, and apostrophes');
    }

    // Name length validation
    if (strlen($name) < 2 || strlen($name) > 100) {
        throw new Exception('Name must be between 2 and 100 characters');
    }

    $pdo = pdo();
    
    // Update user name
    $stmt = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
    $success = $stmt->execute([$name, $_SESSION['user']['id']]);
    
    if (!$success) {
        throw new Exception('Failed to update profile');
    }
    
    // Update session data
    $_SESSION['user']['name'] = $name;
    
    // Log the update
    error_log("User ID {$_SESSION['user']['id']} updated their name to: $name");
    
    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully',
        'name' => htmlspecialchars($name)
    ]);
    
} catch (Exception $e) {
    error_log('Profile update error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
