<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['superadmin_logged_in']) || $_SESSION['superadmin_logged_in'] !== true) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden: audit log export is now managed only by superadmin.';
    exit;
}

$queryString = $_SERVER['QUERY_STRING'] ?? '';
$target = '/pcurfid2/superadmin/export_audit_logs.php' . ($queryString !== '' ? ('?' . $queryString) : '');
header('Location: ' . $target, true, 307);
exit;
