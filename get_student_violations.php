<?php
/**
 * Student Self-View Violations API
 * Returns the authenticated student's own violation history (limited fields).
 * No admin notes, reparation details, or internal info exposed.
 *
 * GET ?school_year=...&semester=...&type=...&status=...
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/disciplinary_measure_helper.php';

header('Content-Type: application/json');

// Student authentication
require_student_auth();

$userId = (int) ($_SESSION['user']['id'] ?? 0);
if (!$userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

function api_offense_severity_label(int $offenseNumber): string {
    if ($offenseNumber <= 1) {
        return 'Low';
    }
    if ($offenseNumber === 2) {
        return 'Medium';
    }
    return 'High';
}

function api_violation_type_label(string $dbType): string {
    $type = strtolower(trim($dbType));
    if ($type === 'grave') {
        return 'Major';
    }
    if ($type === 'major') {
        return 'Moderate';
    }
    if ($type === 'minor') {
        return 'Minor';
    }
    return $type !== '' ? ucfirst($type) : 'Unspecified';
}

try {
    $pdo = pdo();

    // Optional filters
    $schoolYear = trim($_GET['school_year'] ?? '');
    $semester   = trim($_GET['semester'] ?? '');
    $type       = trim($_GET['type'] ?? '');
    $status     = trim($_GET['status'] ?? '');

    $query = "
        SELECT sv.id, sv.offense_number, sv.status, sv.school_year, sv.semester, sv.created_at,
               vc.name AS category_name, vc.type AS category_type,
               CASE WHEN sv.status = 'pending_reparation' THEN sv.reparation_type ELSE NULL END AS reparation_type,
               CASE WHEN sv.status = 'pending_reparation' THEN sv.reparation_notes ELSE NULL END AS reparation_notes
        FROM student_violations sv
        JOIN violation_categories vc ON sv.category_id = vc.id
        WHERE sv.user_id = ?
    ";
    $params = [$userId];

    if ($schoolYear !== '') {
        $query .= " AND sv.school_year = ?";
        $params[] = $schoolYear;
    }
    if ($semester !== '' && in_array($semester, ['1st', '2nd', 'summer'], true)) {
        $query .= " AND sv.semester = ?";
        $params[] = $semester;
    }
    if ($type !== '' && in_array($type, ['minor', 'major', 'grave'], true)) {
        $query .= " AND vc.type = ?";
        $params[] = $type;
    }
    if ($status !== '' && in_array($status, ['active', 'apprehended'], true)) {
        $query .= $status === 'active'
            ? " AND sv.status IN ('active', 'pending_reparation')"
            : " AND sv.status = 'apprehended'";
    }

    $query .= " ORDER BY sv.school_year DESC, sv.created_at DESC LIMIT 200";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $violations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Available school years for filter dropdown
    $syStmt = $pdo->prepare("SELECT DISTINCT school_year FROM student_violations WHERE user_id = ? ORDER BY school_year DESC");
    $syStmt->execute([$userId]);
    $schoolYears = $syStmt->fetchAll(PDO::FETCH_COLUMN);

    // Summary counts
    $summStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN sv.status IN ('active','pending_reparation') THEN 1 ELSE 0 END) AS total_active,
            SUM(CASE WHEN sv.status = 'apprehended' THEN 1 ELSE 0 END) AS total_apprehended,
            COUNT(*) AS total_all
        FROM student_violations sv
        WHERE sv.user_id = ?
    ");
    $summStmt->execute([$userId]);
    $summary = $summStmt->fetch(PDO::FETCH_ASSOC);

    $activeCount = (int) ($summary['total_active'] ?? 0);

    $latestActiveViolation = null;
    $latestActiveStmt = $pdo->prepare("\n        SELECT sv.id, sv.category_id, sv.offense_number, sv.status, sv.created_at,\n               vc.name AS category_name, vc.type AS category_type\n        FROM student_violations sv\n        JOIN violation_categories vc ON sv.category_id = vc.id\n        WHERE sv.user_id = ? AND sv.status IN ('active', 'pending_reparation')\n        ORDER BY sv.created_at DESC, sv.id DESC\n        LIMIT 1\n    ");
    $latestActiveStmt->execute([$userId]);
    $latestActive = $latestActiveStmt->fetch(PDO::FETCH_ASSOC);

    if ($latestActive) {
        $offenseNumber = max(1, (int)($latestActive['offense_number'] ?? 1));
        $markLevel = violation_mark_level_from_total_marks($offenseNumber);
        $disciplinaryNotice = build_disciplinary_intervention_message(
            $markLevel,
            $offenseNumber,
            (string)$latestActive['category_name'],
            (string)$latestActive['category_type']
        );

        if (is_array($disciplinaryNotice)) {
            $disciplinaryNotice['offense_number'] = $offenseNumber;
            $disciplinaryNotice['mark_level'] = $markLevel;
        }

        $latestActiveViolation = [
            'id' => (int)$latestActive['id'],
            'category_name' => (string)$latestActive['category_name'],
            'category_type' => api_violation_type_label((string)$latestActive['category_type']),
            'offense_number' => $offenseNumber,
            'mark_level' => $markLevel,
            'severity_label' => api_offense_severity_label($offenseNumber),
            'status' => (string)$latestActive['status'],
            'disciplinary_notice' => $disciplinaryNotice,
        ];
    }

    $offenseOnFile = null;
    $offenseHistoryStmt = $pdo->prepare("\n        SELECT sv.offense_number, sv.status, sv.created_at,\n               vc.name AS category_name, vc.type AS category_type\n        FROM student_violations sv\n        JOIN violation_categories vc ON sv.category_id = vc.id\n        WHERE sv.user_id = ?\n        ORDER BY sv.created_at DESC, sv.id DESC\n        LIMIT 1\n    ");
    $offenseHistoryStmt->execute([$userId]);
    $offenseHistory = $offenseHistoryStmt->fetch(PDO::FETCH_ASSOC);

    if ($offenseHistory) {
        $historyOffense = max(1, (int)($offenseHistory['offense_number'] ?? 1));
        $historyNotice = build_disciplinary_intervention_message(
            violation_mark_level_from_total_marks($historyOffense),
            $historyOffense,
            (string)$offenseHistory['category_name'],
            (string)$offenseHistory['category_type']
        );

        $offenseOnFile = [
            'category_name' => (string)$offenseHistory['category_name'],
            'category_type' => api_violation_type_label((string)$offenseHistory['category_type']),
            'offense_number' => $historyOffense,
            'severity_label' => api_offense_severity_label($historyOffense),
            'code' => is_array($historyNotice) ? (string)($historyNotice['code'] ?? '') : '',
            'title' => is_array($historyNotice) ? (string)($historyNotice['title'] ?? '') : '',
            'message' => is_array($historyNotice) ? (string)($historyNotice['message'] ?? '') : '',
        ];
    }

    echo json_encode([
        'success'      => true,
        'violations'   => $violations,
        'school_years' => $schoolYears,
        'summary'      => [
            'total_active'      => (int) ($summary['total_active'] ?? 0),
            'total_apprehended' => (int) ($summary['total_apprehended'] ?? 0),
            'total_all'         => (int) ($summary['total_all'] ?? 0),
            'active'            => (int) ($summary['total_active'] ?? 0),
            'apprehended'       => (int) ($summary['total_apprehended'] ?? 0),
            'all'               => (int) ($summary['total_all'] ?? 0),
        ],
        'live_status' => [
            'active_count' => $activeCount,
            'is_clear' => $activeCount === 0,
            'latest_active_violation' => $latestActiveViolation,
            'offense_on_file' => $offenseOnFile,
        ],
    ]);

} catch (\PDOException $e) {
    error_log('Student violations API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A server error occurred']);
}
