<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['superadmin_logged_in']) || $_SESSION['superadmin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Forbidden: audit logs are now managed only by superadmin.',
    ]);
    exit;
}

$queryString = $_SERVER['QUERY_STRING'] ?? '';
$target = '/pcurfid2/superadmin/filter_audit_logs.php' . ($queryString !== '' ? ('?' . $queryString) : '');
header('Location: ' . $target, true, 307);
exit;
