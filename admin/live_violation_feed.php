<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Check if admin is logged in (JSON endpoint should not redirect)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('audit.read', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission audit.read.',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = pdo();

// Ensure academic profile columns exist (safe for DBs imported from database.sql).
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS year_level VARCHAR(20) NULL AFTER course");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS current_semester ENUM('1st','2nd') NULL AFTER year_level");
} catch (\PDOException $e) {
    // Non-fatal: endpoint can still work without these fields (they will be NULL/empty).
    error_log('live_violation_feed ensure academic columns warning: ' . $e->getMessage());
}

$afterId = filter_input(INPUT_GET, 'after_id', FILTER_VALIDATE_INT);
if (!$afterId) {
    $afterId = 0;
}

$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
if (!$limit || $limit <= 0) {
    $limit = 500;
}
$limit = min($limit, 2000);

try {
    $latestId = (int)($pdo->query('SELECT COALESCE(MAX(id), 0) FROM student_violations')->fetchColumn() ?: 0);

    $order = $afterId > 0 ? 'ASC' : 'DESC';

    $sql = "
        SELECT
            sv.id,
            sv.created_at,
            sv.offense_number,
            vc.name AS violation_name,
            vc.type AS offense_category,
            u.id AS user_id,
            u.name AS student_name,
            u.email,
            u.student_id,
            u.rfid_uid,
            u.course,
            u.year_level,
            u.current_semester
        FROM student_violations sv
        JOIN violation_categories vc ON sv.category_id = vc.id
        JOIN users u ON sv.user_id = u.id
        WHERE u.role = 'Student'
          AND u.deleted_at IS NULL
          AND sv.id > ?
        ORDER BY sv.id {$order}
        LIMIT ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$afterId, $limit]);

    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'latest_id' => $latestId,
        'rows' => $rows,
    ]);
} catch (\PDOException $e) {
    error_log('live_violation_feed error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while fetching violations.']);
}
