<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/disciplinary_measure_helper.php';
require_once __DIR__ . '/../includes/security_scan_token_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['security_logged_in']) || $_SESSION['security_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

require_permission('violation.record', [
    'actor_role' => 'security',
    'response' => 'json',
    'message' => 'Forbidden: missing permission violation.record.',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (function_exists('check_rate_limit') && !check_rate_limit('security_record_violation', 120, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request payload']);
    exit;
}

$providedToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (empty($sessionToken) || !hash_equals($sessionToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
$categoryId = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT);
$scanSource = strtolower(trim((string)($input['scan_source'] ?? 'rfid')));
$scanToken = trim((string)($input['scan_token'] ?? ''));
$notes = trim((string)($input['notes'] ?? ''));

$allowedSources = ['rfid', 'qr', 'face'];
if (!$userId || !$categoryId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'user_id and category_id are required']);
    exit;
}
if (!in_array($scanSource, $allowedSources, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid scan source']);
    exit;
}
if (!preg_match('/^[a-f0-9]{64}$/i', $scanToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'scan_token is required']);
    exit;
}
if (strlen($notes) > 1000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Notes exceed 1000 characters']);
    exit;
}

function security_current_school_year(): string {
    $month = (int)date('n');
    $year = (int)date('Y');
    return $month >= 6 ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);
}

function security_current_semester(): string {
    $month = (int)date('n');
    if ($month >= 6 && $month <= 10) {
        return '1st';
    }
    if ($month >= 11 || $month <= 3) {
        return '2nd';
    }
    return 'summer';
}

function security_mark_label(int $markLevel): string {
    $n = max(1, $markLevel);
    $mod100 = $n % 100;
    if ($mod100 >= 11 && $mod100 <= 13) {
        $suffix = 'th';
    } else {
        $mod10 = $n % 10;
        $suffix = $mod10 === 1 ? 'st' : ($mod10 === 2 ? 'nd' : ($mod10 === 3 ? 'rd' : 'th'));
    }

    return $n . $suffix . ' Offense';
}

function security_policy_category_type(string $fallbackDbType): string {
    if ($fallbackDbType === 'grave') {
        return 'major';
    }
    if ($fallbackDbType === 'major') {
        return 'moderate';
    }
    return 'minor';
}

try {
    $pdo = pdo();
    ensure_security_scan_tokens_table($pdo);
    ensure_violation_record_audit_table($pdo);

    $guardId = isset($_SESSION['security_id']) ? (int)$_SESSION['security_id'] : null;
    $guardUsername = (string)($_SESSION['security_username'] ?? 'Unknown');
    $guardSessionHash = security_scan_guard_session_hash();

    $categoryStmt = $pdo->prepare("\n        SELECT id, name, type, description, default_sanction, article_reference\n        FROM violation_categories\n        WHERE id = ? AND is_active = 1\n        LIMIT 1\n    ");
    $categoryStmt->execute([$categoryId]);
    $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);

    if (!$category) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Violation category not found']);
        exit;
    }

    $sourceLabels = [
        'rfid' => 'RFID Tap',
        'qr' => 'Digital ID QR',
        'face' => 'Face Recognition',
    ];
    $sourceLabel = $sourceLabels[$scanSource] ?? 'Gate Scan';

    $description = $notes !== ''
        ? $notes
        : ('Recorded by security via ' . $sourceLabel . '.');

    $schoolYear = security_current_school_year();
    $semester = security_current_semester();
    $recordedAt = date('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        $studentStmt = $pdo->prepare("\n            SELECT id, student_id, name, email, course,\n                   COALESCE(violation_count, 0) AS violation_count,\n                   COALESCE(active_violations_count, 0) AS active_violations_count,\n                   COALESCE(gate_mark_count, 0) AS gate_mark_count,\n                   profile_picture, status\n            FROM users\n            WHERE id = ? AND role = 'Student'\n            LIMIT 1\n            FOR UPDATE\n        ");
        $studentStmt->execute([$userId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            throw new RuntimeException('Student not found', 404);
        }
        if (($student['status'] ?? '') !== 'Active') {
            throw new RuntimeException('Student account is not active', 400);
        }

        $tokenMeta = consume_security_scan_token(
            $pdo,
            $scanToken,
            $guardSessionHash,
            (int)$userId,
            $scanSource,
            $guardId
        );

        // Progress offenses per violation category for each student.
        $countByCategoryStmt = $pdo->prepare('SELECT COUNT(*) FROM student_violations WHERE user_id = ? AND category_id = ?');
        $countByCategoryStmt->execute([$userId, $categoryId]);
        $totalOffensesForCategory = (int)$countByCategoryStmt->fetchColumn() + 1;

        $countByStudentStmt = $pdo->prepare('SELECT COUNT(*) FROM student_violations WHERE user_id = ?');
        $countByStudentStmt->execute([$userId]);
        $totalOffensesForStudent = (int)$countByStudentStmt->fetchColumn() + 1;

        $offenseNumber = violation_offense_number_from_total_marks($totalOffensesForCategory);
        $markLevel = violation_mark_level_from_total_marks($totalOffensesForCategory);
        $violationStatus = 'active';

        $insertStmt = $pdo->prepare("\n            INSERT INTO student_violations\n                (user_id, category_id, description, offense_number, status, school_year, semester, recorded_by)\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n        ");
        $insertStmt->execute([
            $userId,
            $categoryId,
            $description,
            $offenseNumber,
            $violationStatus,
            $schoolYear,
            $semester,
            $guardId,
        ]);
        $newViolationId = (int)$pdo->lastInsertId();
        // Rely on DB trigger to keep `users.active_violations_count` in sync.
        // Read authoritative values from `users` after the insert.
        $updatedStmt = $pdo->prepare('SELECT COALESCE(violation_count,0) AS violation_count, COALESCE(active_violations_count,0) AS active_violations_count, COALESCE(gate_mark_count,0) AS gate_mark_count FROM users WHERE id = ? LIMIT 1');
        $updatedStmt->execute([$userId]);
        $updated = $updatedStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'violation_count' => (int)$student['violation_count'],
            'active_violations_count' => (int)$student['active_violations_count'],
            'gate_mark_count' => (int)$student['gate_mark_count'],
        ];

        write_violation_record_audit($pdo, [
            'violation_id' => $newViolationId,
            'user_id' => (int)$userId,
            'category_id' => (int)$categoryId,
            'recorded_by' => $guardId,
            'scan_source' => $scanSource,
            'guard_session_hash' => $guardSessionHash,
            'scan_token_id' => (int)$tokenMeta['id'],
            'scan_token_hash' => (string)$tokenMeta['token_hash'],
            'notes_length' => strlen($notes),
        ]);

        $pdo->commit();
        rotate_csrf_after_critical_action();

        if (function_exists('send_guardian_entry_notification')) {
            send_guardian_entry_notification($userId, $recordedAt);
        }

        $markLabel = security_mark_label($markLevel);
        $severity = $offenseNumber <= 1 ? 'low' : ($offenseNumber === 2 ? 'medium' : ($offenseNumber === 3 ? 'high' : 'critical'));
        $displayCategoryType = security_policy_category_type((string)$category['type']);
        $disciplinaryNotice = build_disciplinary_intervention_message($markLevel, $offenseNumber, (string)$category['name'], (string)$category['type']);

        echo json_encode([
            'success' => true,
            'message' => 'Violation recorded successfully',
            'scan_source' => $scanSource,
            'recorded_at' => $recordedAt,
            'disciplinary_notice' => $disciplinaryNotice,
            'violation' => [
                'id' => $newViolationId,
                'category_id' => (int)$category['id'],
                'category_name' => $category['name'],
                'category_type' => $displayCategoryType,
                'category_db_type' => $category['type'],
                'default_sanction' => $category['default_sanction'],
                'article_reference' => $category['article_reference'],
                // Keep legacy keys for compatibility with existing clients.
                'total_marks_for_category' => $totalOffensesForCategory,
                'total_marks_for_student' => $totalOffensesForStudent,
                'offense_number' => $offenseNumber,
                'mark_level' => $markLevel,
                'mark_label' => $markLabel,
                'disciplinary_code' => is_array($disciplinaryNotice) ? (string)($disciplinaryNotice['code'] ?? '') : '',
                'disciplinary_title' => is_array($disciplinaryNotice) ? (string)($disciplinaryNotice['title'] ?? '') : '',
                'severity' => $severity,
                'status' => $violationStatus,
                'description' => $description,
                'school_year' => $schoolYear,
                'semester' => $semester,
            ],
            'student' => [
                'id' => (int)$student['id'],
                'name' => $student['name'],
                'student_id' => $student['student_id'],
                'email' => $student['email'],
                'course' => $student['course'],
                'profile_picture' => $student['profile_picture'],
                'violation_count' => (int)$updated['violation_count'],
                'active_violations_count' => (int)$updated['active_violations_count'],
                'gate_mark_count' => (int)$updated['gate_mark_count'],
            ],
            'audit' => [
                'recorded_by' => $guardId,
                'guard_username' => $guardUsername,
                'scan_token_id' => (int)$tokenMeta['id'],
            ],
        ]);

    } catch (RuntimeException $txEx) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $txEx->getCode();
        if (!is_int($status) || $status < 400 || $status > 499) {
            $status = 400;
        }
        http_response_code($status);
        echo json_encode(['success' => false, 'error' => $txEx->getMessage()]);
        exit;
    } catch (Exception $txEx) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $txEx;
    }

} catch (PDOException $e) {
    error_log('record_violation db error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error while recording violation']);
} catch (Exception $e) {
    error_log('record_violation error: ' . $e->getMessage());
    http_response_code(400);
    if (defined('APP_DEBUG') && APP_DEBUG) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid violation recording request']);
    }
}
