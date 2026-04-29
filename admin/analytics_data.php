<?php
/**
 * Analytics Data Endpoint
 * GET /admin/analytics_data.php
 * Returns JSON data for the real-time analytics charts.
 * Requires active admin session + CSRF token header.
 */
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

require_permission('audit.read', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission audit.read.',
]);

// CSRF check (header sent by JS fetch)
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN']
    ?? (function_exists('getallheaders') ? (getallheaders()['X-CSRF-Token'] ?? getallheaders()['x-csrf-token'] ?? '') : '');

if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

// Period param
$allowed = ['today', 'week', 'month', 'year'];
$period  = in_array($_GET['period'] ?? '', $allowed) ? $_GET['period'] : 'month';

// [AGENT CHANGE — TASK 5]
$dateFromRaw = trim((string)($_GET['date_from'] ?? ''));
$dateToRaw = trim((string)($_GET['date_to'] ?? ''));
$dateFrom = null;
$dateTo = null;
if ($dateFromRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromRaw)) {
    $dateFrom = $dateFromRaw;
}
if ($dateToRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToRaw)) {
    $dateTo = $dateToRaw;
}
if ($dateFrom !== null && $dateTo !== null && strtotime($dateFrom) > strtotime($dateTo)) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
// [END TASK 5]

try {
    $pdo = pdo();
    // [AGENT CHANGE — TASK 5]
    $hasArchivedColumn = db_column_exists('users', 'is_archived');
    $archivedExprU = $hasArchivedColumn ? 'COALESCE(u.is_archived, 0)' : '0';
    // [END TASK 5]

    // Date conditions per period
    $auditCondParams = [];
    $svCondParams = [];
    $scanCondParams = [];
    switch ($period) {
        case 'today':
            $auditCond = "DATE(created_at) = ?";
            $svCond    = "DATE(created_at) = ?";
            $scanCond  = "DATE(scanned_at) = ?";
            $today = date('Y-m-d');
            $auditCondParams[] = $today;
            $svCondParams[] = $today;
            $scanCondParams[] = $today;
            break;
        case 'week':
            $from = date('Y-m-d', strtotime('-6 days'));
            $auditCond = "DATE(created_at) >= ?";
            $svCond    = "DATE(created_at) >= ?";
            $scanCond  = "DATE(scanned_at) >= ?";
            $auditCondParams[] = $from;
            $svCondParams[] = $from;
            $scanCondParams[] = $from;
            break;
        case 'year':
            $year = date('Y');
            $auditCond = "YEAR(created_at) = ?";
            $svCond    = "YEAR(created_at) = ?";
            $scanCond  = "YEAR(scanned_at) = ?";
            $auditCondParams[] = $year;
            $svCondParams[] = $year;
            $scanCondParams[] = $year;
            break;
        default: // month
            $year = date('Y');
            $month = date('m');
            $auditCond = "YEAR(created_at) = ? AND MONTH(created_at) = ?";
            $svCond    = "YEAR(created_at) = ? AND MONTH(created_at) = ?";
            $scanCond  = "YEAR(scanned_at) = ? AND MONTH(scanned_at) = ?";
            $auditCondParams[] = $year; $auditCondParams[] = $month;
            $svCondParams[] = $year; $svCondParams[] = $month;
            $scanCondParams[] = $year; $scanCondParams[] = $month;
            break;
    }

    // [AGENT CHANGE — TASK 5]
    if ($dateFrom !== null) {
        $auditCond .= " AND DATE(created_at) >= ?";
        $svCond .= " AND DATE(created_at) >= ?";
        $scanCond .= " AND DATE(scanned_at) >= ?";
        $auditCondParams[] = $dateFrom;
        $svCondParams[] = $dateFrom;
        $scanCondParams[] = $dateFrom;
    }
    if ($dateTo !== null) {
        $auditCond .= " AND DATE(created_at) <= ?";
        $svCond .= " AND DATE(created_at) <= ?";
        $scanCond .= " AND DATE(scanned_at) <= ?";
        $auditCondParams[] = $dateTo;
        $svCondParams[] = $dateTo;
        $scanCondParams[] = $dateTo;
    }
    // [END TASK 5]

    // ── Action type counts (last 30 days) ─────────────────────────────────
    $actionCounts = [];
    $stmt = $pdo->prepare("
        SELECT action_type, COUNT(*) AS cnt
        FROM audit_logs
        WHERE action_type != 'EXPORT_AUDIT_LOG'
          AND {$auditCond}
        GROUP BY action_type
        ORDER BY cnt DESC
    ");
    $stmt->execute($auditCondParams);
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $actionCounts[$row['action_type']] = (int)$row['cnt'];
    }

    // ── 14-day timeline ───────────────────────────────────────────────────
    $timelineLabels = [];
    $timelineCounts = [];
    $violCounts     = [];
    $timelineMap    = [];
    $violMap        = [];
    $tableCheck     = $pdo->query("SHOW TABLES LIKE 'violations'")->fetch();

    if ($period === 'today') {
        // Hourly buckets
        $stmt = $pdo->query("
            SELECT HOUR(created_at) AS h, COUNT(*) AS cnt
            FROM audit_logs WHERE DATE(created_at) = CURDATE()
            GROUP BY HOUR(created_at)
        ");
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $timelineMap[(int)$row['h']] = (int)$row['cnt'];
        }
        if ($tableCheck) {
            $stmt = $pdo->query("
                SELECT HOUR(scanned_at) AS h, COUNT(*) AS cnt
                FROM violations WHERE DATE(scanned_at) = CURDATE()
                GROUP BY HOUR(scanned_at)
            ");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $violMap[(int)$row['h']] = (int)$row['cnt'];
            }
        }
        for ($h = 0; $h <= 23; $h++) {
            $timelineLabels[] = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
            $timelineCounts[] = $timelineMap[$h] ?? 0;
            $violCounts[]     = $violMap[$h]     ?? 0;
        }
    } elseif ($period === 'week') {
        $stmt = $pdo->query("
            SELECT DATE(created_at) AS d, COUNT(*) AS cnt
            FROM audit_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(created_at)
        ");
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $timelineMap[$row['d']] = (int)$row['cnt'];
        }
        if ($tableCheck) {
            $stmt = $pdo->query("
                SELECT DATE(scanned_at) AS d, COUNT(*) AS cnt
                FROM violations WHERE scanned_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                GROUP BY DATE(scanned_at)
            ");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $violMap[$row['d']] = (int)$row['cnt'];
            }
        }
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $timelineLabels[] = date('M d', strtotime($d));
            $timelineCounts[] = $timelineMap[$d] ?? 0;
            $violCounts[]     = $violMap[$d]     ?? 0;
        }
    } elseif ($period === 'year') {
        $stmt = $pdo->query("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS mo, COUNT(*) AS cnt
            FROM audit_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ");
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $timelineMap[$row['mo']] = (int)$row['cnt'];
        }
        if ($tableCheck) {
            $stmt = $pdo->query("
                SELECT DATE_FORMAT(scanned_at, '%Y-%m') AS mo, COUNT(*) AS cnt
                FROM violations WHERE scanned_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
                GROUP BY DATE_FORMAT(scanned_at, '%Y-%m')
            ");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $violMap[$row['mo']] = (int)$row['cnt'];
            }
        }
        for ($i = 11; $i >= 0; $i--) {
            $mo = date('Y-m', strtotime("-{$i} months"));
            $timelineLabels[] = date('M Y', strtotime($mo . '-01'));
            $timelineCounts[] = $timelineMap[$mo] ?? 0;
            $violCounts[]     = $violMap[$mo]     ?? 0;
        }
    } else {
        // month - last 30 days
        $stmt = $pdo->query("
            SELECT DATE(created_at) AS d, COUNT(*) AS cnt
            FROM audit_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY DATE(created_at)
        ");
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $timelineMap[$row['d']] = (int)$row['cnt'];
        }
        if ($tableCheck) {
            $stmt = $pdo->query("
                SELECT DATE(scanned_at) AS d, COUNT(*) AS cnt
                FROM violations WHERE scanned_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                GROUP BY DATE(scanned_at)
            ");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $violMap[$row['d']] = (int)$row['cnt'];
            }
        }
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $timelineLabels[] = date('M d', strtotime($d));
            $timelineCounts[] = $timelineMap[$d] ?? 0;
            $violCounts[]     = $violMap[$d]     ?? 0;
        }
    }

    // ── Stat cards ────────────────────────────────────────────────────────
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE {$auditCond}");
    $countStmt->execute($auditCondParams);
    $actionsCount = (int)$countStmt->fetchColumn();

    $violStmt = $pdo->prepare("SELECT COUNT(*) FROM student_violations WHERE {$svCond}");
    $violStmt->execute($svCondParams);
    $violationsCount = (int)$violStmt->fetchColumn();

    $resolvedStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action_type IN ('RESOLVE_VIOLATION','RESOLVE_ALL_VIOLATIONS') AND {$auditCond}");
    $resolvedStmt->execute($auditCondParams);
    $resolvedCount = (int)$resolvedStmt->fetchColumn();

    $rfidStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action_type = 'REGISTER_RFID' AND {$auditCond}");
    $rfidStmt->execute($auditCondParams);
    $rfidCount = (int)$rfidStmt->fetchColumn();
    $totalPending    = (int)$pdo->query("SELECT COALESCE(SUM(active_violations_count), 0) FROM users WHERE role = 'Student'")->fetchColumn();

    // [AGENT CHANGE — TASK 5]
    $courseQuery = "
        SELECT COALESCE(NULLIF(TRIM(u.course), ''), 'Unassigned') AS course, COUNT(v.id) AS violation_count
        FROM violations v
        INNER JOIN users u ON v.user_id = u.id
        WHERE {$scanCond} AND {$archivedExprU} = 0
        GROUP BY COALESCE(NULLIF(TRIM(u.course), ''), 'Unassigned')
        ORDER BY violation_count DESC, course ASC
    ";
    $courseStmt = $pdo->prepare($courseQuery);
    $courseStmt->execute($scanCondParams);
    $courseViolations = $courseStmt->fetchAll(\PDO::FETCH_ASSOC);

    $rankingQuery = "
        SELECT u.id AS user_id, u.name AS full_name, u.student_id, COALESCE(NULLIF(TRIM(u.course), ''), 'Unassigned') AS course,
               'N/A' AS year_level, COUNT(v.id) AS violation_count
        FROM violations v
        INNER JOIN users u ON v.user_id = u.id
        WHERE {$scanCond} AND {$archivedExprU} = 0
        GROUP BY u.id, u.name, u.student_id, u.course
        ORDER BY violation_count DESC, u.name ASC
        LIMIT 500
    ";
    $rankingStmt = $pdo->prepare($rankingQuery);
    $rankingStmt->execute($scanCondParams);
    $studentRanking = $rankingStmt->fetchAll(\PDO::FETCH_ASSOC);
    // [END TASK 5]

    echo json_encode([
        'success'        => true,
        'period'         => $period,
        'actionCounts'   => (object)$actionCounts,
        'timeline'       => ['labels' => $timelineLabels, 'counts' => $timelineCounts],
        'violationTrend' => ['labels' => $timelineLabels, 'counts' => $violCounts],
        'courseViolations' => $courseViolations,
        'studentRanking' => $studentRanking,
        'stats'          => [
            'actionsToday'    => $actionsCount,
            'violationsMonth' => $violationsCount,
            'resolvedMonth'   => $resolvedCount,
            'rfidMonth'       => $rfidCount,
            'totalPending'    => $totalPending,
        ],
    ]);

} catch (\Exception $e) {
    error_log('Analytics data endpoint error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
