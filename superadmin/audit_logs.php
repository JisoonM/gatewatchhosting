<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

require_superadmin_auth();
send_security_headers();

require_permission('audit.read', [
    'actor_role' => 'superadmin',
    'response' => 'http',
    'message' => 'Forbidden: missing permission audit.read.',
]);

$pdo = pdo();

$action = trim((string)($_GET['action'] ?? ''));
$superAdminId = (int)($_GET['super_admin_id'] ?? 0);
$targetAdminId = (int)($_GET['target_admin_id'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$export = trim((string)($_GET['export'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$conditions = [];
$params = [];

if ($action !== '') {
    $conditions[] = 'sal.action = :action';
    $params[':action'] = $action;
}

if ($superAdminId > 0) {
    $conditions[] = 'sal.super_admin_id = :super_admin_id';
    $params[':super_admin_id'] = $superAdminId;
}

if ($targetAdminId > 0) {
    $conditions[] = 'aa.user_id = :target_admin_id';
    $params[':target_admin_id'] = $targetAdminId;
}

if ($dateFrom !== '') {
    $from = DateTime::createFromFormat('Y-m-d', $dateFrom);
    if ($from instanceof DateTime) {
        $conditions[] = 'sal.created_at >= :date_from';
        $params[':date_from'] = $from->format('Y-m-d 00:00:00');
    }
}

if ($dateTo !== '') {
    $to = DateTime::createFromFormat('Y-m-d', $dateTo);
    if ($to instanceof DateTime) {
        $to->modify('+1 day');
        $conditions[] = 'sal.created_at < :date_to';
        $params[':date_to'] = $to->format('Y-m-d 00:00:00');
    }
}

$whereSql = '';
if (!empty($conditions)) {
    $whereSql = 'WHERE ' . implode(' AND ', $conditions);
}

$fromSql = '
    FROM superadmin_audit_log sal
    LEFT JOIN super_admins sa ON sa.id = sal.super_admin_id
    LEFT JOIN admin_accounts aa ON aa.id = sal.target_admin_id
    LEFT JOIN users u ON u.id = aa.user_id
';

if ($export === 'csv') {
    require_permission('audit.export', [
        'actor_role' => 'superadmin',
        'response' => 'http',
        'message' => 'Forbidden: missing permission audit.export.',
    ]);

    $csvSql = '
        SELECT
            sal.id,
            sal.created_at,
            sal.action,
            COALESCE(sa.username, "Unknown") AS superadmin_name,
            COALESCE(u.name, "-") AS target_admin_name,
            COALESCE(u.email, "-") AS target_admin_email,
            sal.ip_address,
            sal.details
        ' . $fromSql . ' ' . $whereSql . '
        ORDER BY sal.created_at DESC
    ';

    $stmt = $pdo->prepare($csvSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    $filename = 'superadmin_audit_logs_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Created At', 'Action', 'Superadmin', 'Target Admin', 'Target Email', 'IP Address', 'Details']);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $details = (string)($row['details'] ?? '');
        $decoded = json_decode($details, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $details = json_encode($decoded, JSON_UNESCAPED_SLASHES);
        }

        fputcsv($out, [
            $row['id'],
            $row['created_at'],
            $row['action'],
            $row['superadmin_name'],
            $row['target_admin_name'],
            $row['target_admin_email'],
            $row['ip_address'],
            $details,
        ]);
    }

    fclose($out);
    exit;
}

$countSql = 'SELECT COUNT(*) ' . $fromSql . ' ' . $whereSql;
$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listSql = '
    SELECT
        sal.id,
        sal.created_at,
        sal.action,
        sal.details,
        sal.ip_address,
        COALESCE(sa.username, "Unknown") AS superadmin_name,
        COALESCE(u.name, "-") AS target_admin_name,
        COALESCE(u.email, "-") AS target_admin_email
    ' . $fromSql . ' ' . $whereSql . '
    ORDER BY sal.created_at DESC
    LIMIT :limit OFFSET :offset
';

$listStmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStmt->bindValue($key, $value);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$auditRows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$actions = $pdo->query('SELECT DISTINCT action FROM superadmin_audit_log ORDER BY action ASC')->fetchAll(PDO::FETCH_COLUMN);
$superAdmins = $pdo->query('SELECT id, username FROM super_admins ORDER BY username ASC')->fetchAll(PDO::FETCH_ASSOC);
$targetAdmins = $pdo->query("SELECT u.id, u.name, u.email FROM users u WHERE u.role = 'Admin' ORDER BY u.name ASC")->fetchAll(PDO::FETCH_ASSOC);

$queryBase = $_GET;
unset($queryBase['page'], $queryBase['export']);

function build_page_url(array $base, int $targetPage): string {
    $base['page'] = $targetPage;
    return 'audit_logs.php?' . http_build_query($base);
}

$page_title = 'Superadmin Audit Logs';
include __DIR__ . '/includes/header.php';
?>

<style>
.audit-panel {
    border: 1px solid rgba(186, 230, 253, 0.78);
    background: linear-gradient(140deg, rgba(255, 255, 255, 0.94), rgba(239, 246, 255, 0.86));
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    box-shadow: 0 20px 44px rgba(15, 23, 42, 0.2);
}

.audit-input {
    border: 1px solid rgba(148, 163, 184, 0.5);
    background: rgba(255, 255, 255, 0.85);
}

.audit-input:focus {
    border-color: rgba(14, 116, 214, 0.75);
    box-shadow: 0 0 0 3px rgba(14, 116, 214, 0.14);
}
</style>

<div class="space-y-6">
    <section class="audit-panel rounded-2xl p-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Superadmin Audit Logs</h1>
                <p class="text-sm text-slate-600 mt-1">Track superadmin actions with filterable history and secure export.</p>
            </div>
            <a
                href="audit_logs.php?<?php echo e(http_build_query(array_merge($queryBase, ['export' => 'csv']))); ?>"
                class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-gradient-to-r from-[#0056b3] to-[#003d82] text-white font-semibold hover:opacity-95 transition"
            >
                Export CSV
            </a>
        </div>

        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3 mt-5">
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Action</label>
                <select name="action" class="audit-input w-full rounded-lg px-3 py-2 text-sm">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $actionItem): ?>
                        <option value="<?php echo e((string)$actionItem); ?>" <?php echo $action === (string)$actionItem ? 'selected' : ''; ?>>
                            <?php echo e((string)$actionItem); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Superadmin</label>
                <select name="super_admin_id" class="audit-input w-full rounded-lg px-3 py-2 text-sm">
                    <option value="0">All Superadmins</option>
                    <?php foreach ($superAdmins as $item): ?>
                        <option value="<?php echo (int)$item['id']; ?>" <?php echo $superAdminId === (int)$item['id'] ? 'selected' : ''; ?>>
                            <?php echo e((string)$item['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Target Admin</label>
                <select name="target_admin_id" class="audit-input w-full rounded-lg px-3 py-2 text-sm">
                    <option value="0">All Admins</option>
                    <?php foreach ($targetAdmins as $item): ?>
                        <option value="<?php echo (int)$item['id']; ?>" <?php echo $targetAdminId === (int)$item['id'] ? 'selected' : ''; ?>>
                            <?php echo e((string)$item['name'] . ' (' . (string)$item['email'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Date From</label>
                <input type="date" name="date_from" value="<?php echo e($dateFrom); ?>" class="audit-input w-full rounded-lg px-3 py-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Date To</label>
                <input type="date" name="date_to" value="<?php echo e($dateTo); ?>" class="audit-input w-full rounded-lg px-3 py-2 text-sm">
            </div>

            <div class="sm:col-span-2 xl:col-span-5 flex flex-wrap gap-2">
                <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg bg-slate-800 text-white text-sm font-semibold hover:bg-slate-700 transition">
                    Apply Filters
                </button>
                <a href="audit_logs.php" class="inline-flex items-center px-4 py-2 rounded-lg bg-slate-200 text-slate-700 text-sm font-semibold hover:bg-slate-300 transition">
                    Reset
                </a>
            </div>
        </form>
    </section>

    <section class="audit-panel rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100/80 text-slate-700">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">Timestamp</th>
                        <th class="px-4 py-3 text-left font-semibold">Action</th>
                        <th class="px-4 py-3 text-left font-semibold">Superadmin</th>
                        <th class="px-4 py-3 text-left font-semibold">Target Admin</th>
                        <th class="px-4 py-3 text-left font-semibold">IP</th>
                        <th class="px-4 py-3 text-left font-semibold">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/70">
                    <?php if (empty($auditRows)): ?>
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-slate-500">No audit records found for the selected filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($auditRows as $row): ?>
                            <?php
                                $detailsRaw = (string)($row['details'] ?? '');
                                $detailsDisplay = $detailsRaw;
                                $decoded = json_decode($detailsRaw, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $detailsDisplay = json_encode($decoded, JSON_UNESCAPED_SLASHES);
                                }
                                if (strlen($detailsDisplay) > 180) {
                                    $detailsDisplay = substr($detailsDisplay, 0, 177) . '...';
                                }
                            ?>
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-4 py-3 text-slate-700"><?php echo e((string)$row['created_at']); ?></td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded bg-blue-50 text-blue-700 border border-blue-100">
                                        <?php echo e((string)$row['action']); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-700"><?php echo e((string)$row['superadmin_name']); ?></td>
                                <td class="px-4 py-3 text-slate-700">
                                    <?php echo e((string)$row['target_admin_name']); ?>
                                    <div class="text-xs text-slate-500"><?php echo e((string)$row['target_admin_email']); ?></div>
                                </td>
                                <td class="px-4 py-3 text-slate-700"><?php echo e((string)$row['ip_address']); ?></td>
                                <td class="px-4 py-3 text-slate-600" title="<?php echo e((string)$row['details']); ?>"><?php echo e($detailsDisplay); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-4 py-3 bg-slate-50/80 border-t border-slate-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-xs text-slate-600">
                Showing page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?> (<?php echo (int)$totalRows; ?> records)
            </div>
            <div class="flex items-center gap-2">
                <a
                    href="<?php echo e(build_page_url($queryBase, max(1, $page - 1))); ?>"
                    class="px-3 py-1.5 rounded-lg text-sm font-medium <?php echo $page <= 1 ? 'pointer-events-none bg-slate-200 text-slate-400' : 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-100'; ?>"
                >
                    Previous
                </a>
                <a
                    href="<?php echo e(build_page_url($queryBase, min($totalPages, $page + 1))); ?>"
                    class="px-3 py-1.5 rounded-lg text-sm font-medium <?php echo $page >= $totalPages ? 'pointer-events-none bg-slate-200 text-slate-400' : 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-100'; ?>"
                >
                    Next
                </a>
            </div>
        </div>
    </section>
</div>

</main>
</body>
</html>
