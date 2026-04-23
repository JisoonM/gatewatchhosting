<?php
/**
 * health.php — Lightweight health check endpoint.
 *
 * Returns HTTP 200 + JSON when the app and DB are responsive.
 * Returns HTTP 503 + JSON when the DB is unreachable.
 *
 * SECURITY: This endpoint intentionally reveals minimal information.
 * It does NOT expose version numbers, config values, or stack traces.
 * It is safe to leave accessible — it only confirms "up/down".
 */
declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Load only the DB connection — no session, no CSRF, no auth needed
require_once __DIR__ . '/db.php';

$status = [
    'status'    => 'ok',
    'timestamp' => date('c'),
    'checks'    => [],
];
$httpCode = 200;

// --- Check 1: Database connectivity ---
try {
    $pdo  = pdo();
    $stmt = $pdo->query('SELECT 1');
    $status['checks']['database'] = 'ok';
} catch (\Throwable $e) {
    $status['checks']['database'] = 'error';
    $status['status']             = 'degraded';
    $httpCode                     = 503;
    error_log('[PCU RFID] Health check: DB unreachable — ' . $e->getMessage());
}

// --- Check 2: Session storage writable (simple check) ---
try {
    $sessionSavePath = session_save_path() ?: sys_get_temp_dir();
    $status['checks']['session_storage'] = is_writable($sessionSavePath) ? 'ok' : 'warning';
} catch (\Throwable $e) {
    $status['checks']['session_storage'] = 'unknown';
}

// --- Check 3: Upload directory writable ---
$uploadDir = __DIR__ . '/assets/images/profiles/';
$status['checks']['upload_dir'] = (is_dir($uploadDir) && is_writable($uploadDir)) ? 'ok' : 'warning';

http_response_code($httpCode);
echo json_encode($status, JSON_PRETTY_PRINT);
