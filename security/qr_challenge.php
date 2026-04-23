<?php
/**
 * QR Gate Challenge API
 * Issues short-lived nonce challenges for QR anti-replay binding.
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

if (function_exists('check_rate_limit') && !check_rate_limit('qr_challenge_issue', 60, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'RATE_LIMITED', 'message' => 'Too many challenge requests']);
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

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $forceNew = !empty($input['force_new']);

    $enabled = filter_var(env('QR_CHALLENGE_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    if (!$enabled) {
        echo json_encode([
            'success' => true,
            'enabled' => false,
            'message' => 'QR challenge feature is disabled'
        ]);
        exit;
    }

    $pdo = pdo();
    ensure_qr_binding_tables($pdo);
    qr_binding_expire_stale_rows($pdo);

    $ttl = (int)env('QR_CHALLENGE_TTL_SECONDS', '45');
    if ($ttl < 10) {
        $ttl = 10;
    }
    if ($ttl > 120) {
        $ttl = 120;
    }

    $guardSessionHash = qr_guard_session_hash();
    $guardName = (string)($_SESSION['security_username'] ?? 'Unknown');

    $pdo->beginTransaction();

    if (!$forceNew) {
        $activeStmt = $pdo->prepare('SELECT challenge_id, expires_at FROM qr_scan_challenges WHERE status = "active" AND guard_session_hash = ? ORDER BY id DESC LIMIT 1');
        $activeStmt->execute([$guardSessionHash]);
        $active = $activeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($active) {
            $activeChallengeId = (string)($active['challenge_id'] ?? '');
            $activeExpiresAt = (string)($active['expires_at'] ?? '');
            $activeRemaining = strtotime($activeExpiresAt) - time();

            if ($activeChallengeId !== '' && $activeRemaining >= 8) {
                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'enabled' => true,
                    'challenge_id' => $activeChallengeId,
                    'expires_at' => $activeExpiresAt,
                    'ttl_seconds' => $ttl,
                    'reused' => true,
                    'state' => 'qr_challenge_active'
                ]);

                qr_binding_log_event($pdo, 'challenge_reused', null, null, $activeChallengeId, null, [
                    'expires_at' => $activeExpiresAt,
                    'remaining_seconds' => $activeRemaining
                ]);
                exit;
            }
        }
    }

    $pdo->prepare('UPDATE qr_scan_challenges SET status = "expired" WHERE status = "active" AND guard_session_hash = ?')->execute([$guardSessionHash]);

    $challengeId = bin2hex(random_bytes(16));
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

    $issueStmt = $pdo->prepare('INSERT INTO qr_scan_challenges (challenge_id, guard_session_hash, guard_username, status, expires_at) VALUES (?, ?, ?, "active", ?)');
    $issueStmt->execute([$challengeId, $guardSessionHash, $guardName, $expiresAt]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'enabled' => true,
        'challenge_id' => $challengeId,
        'expires_at' => $expiresAt,
        'ttl_seconds' => $ttl,
        'reused' => false,
        'state' => 'qr_challenge_required'
    ]);

    qr_binding_log_event($pdo, 'challenge_created', null, null, $challengeId, null, [
        'expires_at' => $expiresAt,
        'ttl_seconds' => $ttl
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('QR challenge issue error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'QR_CHALLENGE_ERROR',
        'message' => 'Unable to issue QR challenge'
    ]);
}
