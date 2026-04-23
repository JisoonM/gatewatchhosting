<?php
/**
 * Secure profile picture proxy.
 * Serves profile pictures from outside the web root, enforcing authentication.
 * Only authenticated students, admins, security guards, and superadmins may view images.
 */
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}

$isAdminSession = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isSecuritySession = isset($_SESSION['security_logged_in']) && $_SESSION['security_logged_in'] === true;
$isSuperadminSession = isset($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true;
$isPrivilegedSession = $isAdminSession || $isSecuritySession || $isSuperadminSession;

$studentUserId = isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : 0;
$isStudentSession = $studentUserId > 0 && !$isPrivilegedSession;

if (!$isPrivilegedSession && !$isStudentSession) {
    http_response_code(403);
    exit;
}

// Validate filename: allow safe legacy names and extensions only.
// basename() prevents path traversal; regex enforces a constrained format.
$filename = basename($_GET['f'] ?? '');
if (!preg_match('/^[A-Za-z0-9._-]{1,190}\.(jpg|jpeg|png|gif|webp)$/', $filename)) {
    http_response_code(400);
    exit;
}

$profileStorageDir = defined('PROFILE_PICTURES_DIR')
    ? (string) constant('PROFILE_PICTURES_DIR')
    : (__DIR__ . '/assets/images/profiles/');
$profileStorageDir = rtrim($profileStorageDir, '/\\') . DIRECTORY_SEPARATOR;

if ($isStudentSession) {
    try {
        $pdo = pdo();
        $stmt = $pdo->prepare('SELECT profile_picture FROM users WHERE id = ? AND role = "Student" LIMIT 1');
        $stmt->execute([$studentUserId]);
        $ownPicture = (string) ($stmt->fetchColumn() ?: '');

        // Students can only view their own current profile picture.
        if ($ownPicture === '' || !hash_equals($ownPicture, $filename)) {
            http_response_code(403);
            exit;
        }
    } catch (\PDOException $e) {
        error_log('serve_picture auth check failed: ' . $e->getMessage());
        http_response_code(500);
        exit;
    }
}

$filepath = $profileStorageDir . $filename;

// Resolve the real path and confirm it stays inside the storage directory.
$realFile    = realpath($filepath);
$realStorage = realpath($profileStorageDir);

if ($realFile === false || $realStorage === false || strncmp($realFile, $realStorage, strlen($realStorage)) !== 0) {
    http_response_code(400);
    exit;
}

if (!is_file($realFile)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg'  => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($realFile));
readfile($realFile);
exit;
