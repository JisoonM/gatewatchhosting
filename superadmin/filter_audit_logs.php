<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

require_superadmin_auth();
send_security_headers();

header('Content-Type: application/json');

require_permission('audit.read', [
    'actor_role' => 'superadmin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission audit.read.',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$headers = getallheaders();
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$allowedActions = [
    'APPROVE_STUDENT', 'DENY_STUDENT', 'REGISTER_RFID', 'UNREGISTER_RFID',
    'MARK_LOST', 'MARK_FOUND', 'UPDATE_STUDENT', 'ARCHIVE_STUDENT', 'DELETE_STUDENT',
    'ADD_VIOLATION', 'RESOLVE_VIOLATION', 'RESOLVE_ALL_VIOLATIONS',
    'ASSIGN_REPARATION', 'EXPORT_AUDIT_LOG',
];

$actionType = trim((string)($_GET['action_type'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$isValidDate = static function (string $value): bool {
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $value));
    return checkdate($m, $d, $y);
};

if ($actionType !== '' && !in_array($actionType, $allowedActions, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid action type']);
    exit;
}

if ($dateFrom !== '' && !$isValidDate($dateFrom)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid date_from']);
    exit;
}

if ($dateTo !== '' && !$isValidDate($dateTo)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid date_to']);
    exit;
}

try {
    $pdo = pdo();

    $query = "SELECT * FROM audit_logs WHERE action_type != 'EXPORT_AUDIT_LOG'";
    $params = [];

    if ($actionType !== '') {
        $query .= ' AND action_type = ?';
        $params[] = $actionType;
    }
    if ($dateFrom !== '') {
        $query .= ' AND DATE(created_at) >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $query .= ' AND DATE(created_at) <= ?';
        $params[] = $dateTo;
    }

    $query .= ' ORDER BY created_at DESC LIMIT 100';

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logs as &$log) {
        if (!in_array($log['action_type'] ?? '', ['MARK_LOST', 'MARK_FOUND'], true)) {
            continue;
        }

        $details = json_decode($log['details'] ?? '', true);
        if (!is_array($details)) {
            continue;
        }

        $cardId = isset($details['card_id']) ? (int)$details['card_id'] : 0;
        if ($cardId <= 0) {
            continue;
        }

        $sidStmt = $pdo->prepare(
            'SELECT u.student_id
             FROM rfid_cards rc
             INNER JOIN users u ON u.id = rc.user_id
             WHERE rc.id = ?
             LIMIT 1'
        );
        $sidStmt->execute([$cardId]);
        $resolvedStudentId = (string)($sidStmt->fetchColumn() ?: '');

        if ($resolvedStudentId !== '') {
            $details['student_id'] = $resolvedStudentId;
            $log['details'] = json_encode($details);
        }
    }
    unset($log);

    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'count' => count($logs),
    ]);
} catch (Throwable $e) {
    error_log('Superadmin audit filter error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to filter audit logs',
    ]);
}
