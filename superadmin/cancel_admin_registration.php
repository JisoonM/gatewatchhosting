<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/admin_face_helper.php';

header('Content-Type: application/json');
send_api_security_headers();
require_same_origin_api_request();

if (!isset($_SESSION['superadmin_logged_in']) || $_SESSION['superadmin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('admin.create', [
    'actor_role' => 'superadmin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission admin.create.',
]);

$headers = getallheaders();
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(get_raw_request_body(), true);
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $token = trim((string)($input['token'] ?? ''));
    if ($token === '' || strlen($token) < 20) {
        throw new Exception('Invalid registration token');
    }

    $pdo = pdo();
    ensure_admin_face_registration_tables($pdo);

    $stmt = $pdo->prepare('SELECT created_by_superadmin_id FROM admin_face_registration_tokens WHERE token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => true, 'message' => 'Registration already cleared']);
        exit;
    }

    if ((int)$row['created_by_superadmin_id'] !== (int)($_SESSION['superadmin_id'] ?? 0)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM admin_face_registration_faces WHERE token = ?')->execute([$token]);
        $pdo->prepare('DELETE FROM admin_face_registration_tokens WHERE token = ?')->execute([$token]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    echo json_encode(['success' => true, 'message' => 'Registration canceled']);

} catch (Exception $e) {
    http_response_code(400);
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Unable to cancel registration']);
    }
}
