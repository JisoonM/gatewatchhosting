<?php
/**
 * QR Pending Reset API
 * Clears pending QR->Face state for current guard session.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/qr_binding_helper.php';

header('Content-Type: application/json');
apply_cors_headers(['POST'], ['Content-Type', 'X-CSRF-Token']);
send_api_security_headers();
require_same_origin_api_request();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

if (!isset($_SESSION['security_logged_in']) || $_SESSION['security_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

require_permission('qr.scan', [
    'actor_role' => 'security',
    'response' => 'json',
    'message' => 'Forbidden: missing permission qr.scan.',
]);

$headers = getallheaders();
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (empty($sessionToken) || !hash_equals($sessionToken, $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (function_exists('check_rate_limit') && !check_rate_limit('qr_pending_reset', 40, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'RATE_LIMITED', 'message' => 'Too many reset requests']);
    exit;
}

try {
    $pdo = pdo();
    ensure_qr_binding_tables($pdo);
    qr_binding_expire_stale_rows($pdo);

    $guardSessionHash = qr_guard_session_hash();
    $cleared = qr_binding_clear_pending($pdo, $guardSessionHash, 'manual_guard_reset');

    if ($cleared > 0) {
        qr_binding_log_event($pdo, 'qr_pending_cleared', null, null, null, null, ['cleared_count' => $cleared]);
    }

    echo json_encode([
        'success' => true,
        'cleared' => $cleared,
        'message' => $cleared > 0
            ? 'Pending QR state cleared. You can scan a new QR.'
            : 'No pending QR state to clear.'
    ]);
} catch (Throwable $e) {
    error_log('QR pending reset error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'QR_PENDING_RESET_FAILED',
        'message' => 'Unable to clear pending QR state'
    ]);
}
