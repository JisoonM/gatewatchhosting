<?php
/**
 * Violation Management API
 * Handles CRUD operations for the comprehensive student violation tracking system.
 *
 * GET  ?action=categories            — List all violation categories grouped by type
 * GET  ?action=history&user_id=N     — Full violation audit-log for a student
 * GET  ?action=summary&user_id=N     — Violation counts summary for a student
 * POST action=add                    — Record a new violation
 * POST action=resolve                — Resolve (apprehend) a single violation
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../includes/disciplinary_measure_helper.php';

header('Content-Type: application/json');

// Admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF protection for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headers = getallheaders();
    $token = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }
}

$pdo = pdo();

// Determine action from GET or POST
$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

$readActions = ['categories', 'history', 'search_students', 'summary'];
$writeActions = ['add', 'resolve', 'assign_reparation'];
if (in_array($action, $readActions, true)) {
    require_permission('audit.read', [
        'actor_role' => 'admin',
        'response' => 'json',
        'message' => 'Forbidden: missing permission audit.read.',
    ]);
} elseif (in_array($action, $writeActions, true)) {
    require_permission('violation.clear', [
        'actor_role' => 'admin',
        'response' => 'json',
        'message' => 'Forbidden: missing permission violation.clear.',
    ]);
}

/**
 * Calculate the current academic school year (June–May cycle).
 * e.g. In March 2026 → "2025-2026"; In August 2026 → "2026-2027"
 */
function getCurrentSchoolYear(): string {
    $month = (int) date('n');
    $year  = (int) date('Y');
    if ($month >= 6) {
        return $year . '-' . ($year + 1);
    }
    return ($year - 1) . '-' . $year;
}

/**
 * Estimate the current semester from the date.
 * 1st: June–October, 2nd: November–March, Summer: April–May
 */
function getCurrentSemester(): string {
    $month = (int) date('n');
    if ($month >= 6 && $month <= 10) return '1st';
    if ($month >= 11 || $month <= 3) return '2nd';
    return 'summer';
}

function admin_violation_type_label(string $dbType): string {
    $type = strtolower(trim($dbType));
    if ($type === 'minor') return 'Minor';
    if ($type === 'major') return 'Moderate';
    if ($type === 'grave') return 'Major';
    return $type !== '' ? ucfirst($type) : 'Unspecified';
}

function admin_reparation_label(string $value): string {
    $labels = [
        'written_apology' => 'Written Apology Letter',
        'community_service' => 'Community Service Hours',
        'counseling' => 'Counseling Session',
        'parent_conference' => 'Parent/Guardian Conference',
        'suspension_compliance' => 'Suspension Compliance',
        'restitution' => 'Restitution / Payment',
        'other' => 'Other',
    ];

    return $labels[$value] ?? ucwords(str_replace('_', ' ', $value));
}

function admin_recommended_reparation_type_from_dis_code(string $code): string {
    $normalized = strtoupper(trim($code));
    if ($normalized === 'DIS 1') return 'written_apology';
    if ($normalized === 'DIS 2') return 'community_service';
    if ($normalized === 'DIS 3') return 'suspension_compliance';
    if ($normalized === 'DIS 4') return 'other';
    return 'other';
}

/**
 * @return array<int,string>
 */
function admin_allowed_reparation_types_from_dis_code(string $code): array {
    $normalized = strtoupper(trim($code));

    if ($normalized === 'DIS 1') {
        return ['written_apology'];
    }

    if ($normalized === 'DIS 2') {
        return ['community_service', 'written_apology', 'parent_conference'];
    }

    if ($normalized === 'DIS 3') {
        return ['suspension_compliance', 'counseling', 'parent_conference', 'written_apology'];
    }

    if ($normalized === 'DIS 4') {
        return ['suspension_compliance', 'restitution', 'parent_conference', 'other'];
    }

    return ['other'];
}

/**
 * Handbook-driven allowed tasks by category type and offense level.
 *
 * @param array<string,mixed> $violation
 * @return array<int,string>
 */
function admin_allowed_reparation_types_for_violation(array $violation, string $code): array {
    $categoryType = strtolower(trim((string)($violation['category_type'] ?? '')));
    $offenseNumber = max(1, (int)($violation['offense_number'] ?? 1));
    $categorySlug = violation_policy_slug((string)($violation['category_name'] ?? ''));

    // MINOR OFFENSES (official handbook)
    // 1st: verbal reprimand + apology letter
    // 2nd: conference + apology letter
    // 3rd: conference + referral (guidance + chaplaincy)
    if ($categoryType === 'minor') {
        if ($offenseNumber === 1) {
            return ['written_apology'];
        }
        if ($offenseNumber === 2) {
            return ['parent_conference', 'written_apology'];
        }
        return ['counseling', 'parent_conference'];
    }

    // MODERATE OFFENSES (official handbook)
    // 1st: conference + apology
    // 2nd: conference + referral
    // 3rd: DIS 4 + conference
    if ($categoryType === 'major') {
        if ($offenseNumber === 1) {
            return ['parent_conference', 'written_apology'];
        }
        if ($offenseNumber === 2) {
            return ['counseling', 'parent_conference'];
        }
        return ['parent_conference', 'other'];
    }

    // MAJOR OFFENSES (official handbook)
    // DIS4 cases: conference + dismissal/withdrawal process
    if ($categoryType === 'grave') {
        if (strtoupper(trim($code)) === 'DIS 4' || $offenseNumber >= 2) {
            return ['parent_conference', 'other'];
        }

        // DIS3 first-offense major pattern: conference + referral,
        // with category-specific additions (apology/payment).
        $allowed = ['counseling', 'parent_conference'];

        if ($categorySlug === violation_policy_slug('Theft')) {
            $allowed[] = 'written_apology';
        }

        if ($categorySlug === violation_policy_slug('Destruction of school property')) {
            $allowed[] = 'restitution';
        }

        return array_values(array_unique($allowed));
    }

    return admin_allowed_reparation_types_from_dis_code($code);
}

/**
 * @param array<int,string> $allowedTypes
 * @param array<string,mixed> $violation
 */
function admin_recommended_reparation_type_for_violation(array $allowedTypes, array $violation, string $code): string {
    $categoryType = strtolower(trim((string)($violation['category_type'] ?? '')));
    $offenseNumber = max(1, (int)($violation['offense_number'] ?? 1));
    $categorySlug = violation_policy_slug((string)($violation['category_name'] ?? ''));

    $candidate = '';
    if ($categoryType === 'minor') {
        $candidate = $offenseNumber === 1
            ? 'written_apology'
            : ($offenseNumber === 2 ? 'parent_conference' : 'counseling');
    } elseif ($categoryType === 'major') {
        $candidate = $offenseNumber === 1
            ? 'parent_conference'
            : ($offenseNumber === 2 ? 'counseling' : 'parent_conference');
    } elseif ($categoryType === 'grave') {
        if (strtoupper(trim($code)) === 'DIS 4' || $offenseNumber >= 2) {
            $candidate = 'parent_conference';
        } elseif ($categorySlug === violation_policy_slug('Theft')) {
            $candidate = 'written_apology';
        } elseif ($categorySlug === violation_policy_slug('Destruction of school property')) {
            $candidate = 'restitution';
        } else {
            $candidate = 'counseling';
        }
    }

    if ($candidate !== '' && in_array($candidate, $allowedTypes, true)) {
        return $candidate;
    }

    if (count($allowedTypes) > 0) {
        return (string)$allowedTypes[0];
    }

    return admin_recommended_reparation_type_from_dis_code($code);
}

/**
 * @param array<string,mixed> $violation
 * @return array<string,mixed>
 */
function admin_build_disciplinary_payload(array $violation): array {
    $offenseNumber = max(1, (int)($violation['offense_number'] ?? 1));
    $categoryName = (string)($violation['category_name'] ?? 'Violation');
    $categoryType = (string)($violation['category_type'] ?? '');
    $markLevel = violation_mark_level_from_total_marks($offenseNumber);

    $notice = build_disciplinary_intervention_message(
        $markLevel,
        $offenseNumber,
        $categoryName,
        $categoryType
    );

    $code = is_array($notice) ? (string)($notice['code'] ?? '') : '';
    $allowedTypes = admin_allowed_reparation_types_for_violation($violation, $code);
    $recommendedType = admin_recommended_reparation_type_for_violation($allowedTypes, $violation, $code);

    return [
        'offense_number' => $offenseNumber,
        'mark_level' => $markLevel,
        'violation_type_label' => admin_violation_type_label($categoryType),
        'disciplinary_notice' => $notice,
        'disciplinary_code' => $code,
        'disciplinary_title' => is_array($notice) ? (string)($notice['title'] ?? '') : '',
        'disciplinary_action' => is_array($notice) ? (string)($notice['action'] ?? '') : '',
        'recommended_reparation_type' => $recommendedType,
        'recommended_reparation_label' => admin_reparation_label($recommendedType),
        'allowed_reparation_types' => $allowedTypes,
        'allowed_reparation_labels' => array_values(array_map('admin_reparation_label', $allowedTypes)),
    ];
}

function admin_ordinal_label(int $value): string {
    if ($value % 100 >= 11 && $value % 100 <= 13) {
        return $value . 'th';
    }

    $last = $value % 10;
    if ($last === 1) return $value . 'st';
    if ($last === 2) return $value . 'nd';
    if ($last === 3) return $value . 'rd';
    return $value . 'th';
}

try {
    switch ($action) {

        // ─── GET: List violation categories ──────────────────────────
        case 'categories':
            $stmt = $pdo->query("
                SELECT id, name, type, description, default_sanction, article_reference
                FROM violation_categories
                WHERE is_active = 1
                ORDER BY FIELD(type, 'minor', 'major', 'grave'), name ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $grouped = ['minor' => [], 'major' => [], 'grave' => []];
            foreach ($rows as $r) {
                $grouped[$r['type']][] = $r;
            }

            echo json_encode(['success' => true, 'categories' => $grouped]);
            break;

        // ─── GET: Full violation history for a student ───────────────
        case 'history':
            $userId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
            if (!$userId) {
                echo json_encode(['success' => false, 'error' => 'Valid user_id is required']);
                break;
            }

            // Optional filters
            $schoolYear = trim($_GET['school_year'] ?? '');
            $semester   = trim($_GET['semester'] ?? '');
            $type       = trim($_GET['type'] ?? '');
            $status     = trim($_GET['status'] ?? '');

            $query = "
                SELECT sv.*, vc.name AS category_name, vc.type AS category_type,
                       vc.article_reference,
                       resolver.name AS resolved_by_name,
                       recorder.name AS recorded_by_name
                FROM student_violations sv
                JOIN violation_categories vc ON sv.category_id = vc.id
                LEFT JOIN users resolver ON sv.resolved_by = resolver.id
                LEFT JOIN users recorder ON sv.recorded_by = recorder.id
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
            if ($status !== '' && in_array($status, ['active', 'pending_reparation', 'apprehended'], true)) {
                $query .= " AND sv.status = ?";
                $params[] = $status;
            }

            $query .= " ORDER BY sv.school_year DESC, sv.created_at DESC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $violations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($violations as &$violationRow) {
                $violationRow += admin_build_disciplinary_payload($violationRow);
            }
            unset($violationRow);

            $currentStatusStmt = $pdo->prepare("\n                SELECT sv.offense_number, sv.status,\n                       vc.name AS category_name, vc.type AS category_type\n                FROM student_violations sv\n                JOIN violation_categories vc ON sv.category_id = vc.id\n                WHERE sv.user_id = ? AND sv.status IN ('active', 'pending_reparation')\n                ORDER BY sv.created_at DESC, sv.id DESC\n                LIMIT 1\n            ");
            $currentStatusStmt->execute([$userId]);
            $currentDisciplinaryRow = $currentStatusStmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentDisciplinaryRow) {
                $latestAnyStmt = $pdo->prepare("\n                    SELECT sv.offense_number, sv.status,\n                           vc.name AS category_name, vc.type AS category_type\n                    FROM student_violations sv\n                    JOIN violation_categories vc ON sv.category_id = vc.id\n                    WHERE sv.user_id = ?\n                    ORDER BY sv.created_at DESC, sv.id DESC\n                    LIMIT 1\n                ");
                $latestAnyStmt->execute([$userId]);
                $currentDisciplinaryRow = $latestAnyStmt->fetch(PDO::FETCH_ASSOC);
            }

            $currentDisciplinaryStatus = null;
            if (is_array($currentDisciplinaryRow)) {
                $currentDisciplinaryStatus = $currentDisciplinaryRow + admin_build_disciplinary_payload($currentDisciplinaryRow);
            }

            // Also fetch available school years for filter dropdown
            $syStmt = $pdo->prepare("SELECT DISTINCT school_year FROM student_violations WHERE user_id = ? ORDER BY school_year DESC");
            $syStmt->execute([$userId]);
            $schoolYears = $syStmt->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode([
                'success' => true,
                'violations' => $violations,
                'school_years' => $schoolYears,
                'current_disciplinary_status' => $currentDisciplinaryStatus,
            ]);
            break;

        // ─── GET: Search students for violation assignment ─────────
        case 'search_students':
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) {
                echo json_encode(['success' => true, 'students' => []]);
                break;
            }
            $like = '%' . $q . '%';
            $stmt = $pdo->prepare("
                SELECT id, student_id, name 
                FROM users 
                WHERE role = 'Student' AND status = 'Approved' AND (name LIKE ? OR student_id LIKE ?)
                ORDER BY name ASC LIMIT 10
            ");
            $stmt->execute([$like, $like]);
            echo json_encode(['success' => true, 'students' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ─── GET: Violation summary counts ───────────────────────────
        case 'summary':
            $userId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
            if (!$userId) {
                echo json_encode(['success' => false, 'error' => 'Valid user_id is required']);
                break;
            }

            // Counts by type and status
            $stmt = $pdo->prepare("
                SELECT vc.type, sv.status, COUNT(*) AS cnt
                FROM student_violations sv
                JOIN violation_categories vc ON sv.category_id = vc.id
                WHERE sv.user_id = ?
                GROUP BY vc.type, sv.status
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $summary = [
                'active_minor' => 0, 'active_major' => 0, 'active_grave' => 0,
                'apprehended_minor' => 0, 'apprehended_major' => 0, 'apprehended_grave' => 0,
                'total_active' => 0, 'total_apprehended' => 0, 'total_all' => 0,
            ];
            foreach ($rows as $r) {
                $key = ($r['status'] === 'apprehended' ? 'apprehended' : 'active') . '_' . $r['type'];
                if (isset($summary[$key])) {
                    $summary[$key] += (int) $r['cnt'];
                }
                if ($r['status'] === 'active' || $r['status'] === 'pending_reparation') {
                    $summary['total_active'] += (int) $r['cnt'];
                } else {
                    $summary['total_apprehended'] += (int) $r['cnt'];
                }
                $summary['total_all'] += (int) $r['cnt'];
            }

            echo json_encode(['success' => true, 'summary' => $summary]);
            break;

        // ─── POST: Add a new violation ───────────────────────────────
        case 'add':
            $userId      = filter_var($input['user_id'] ?? 0, FILTER_VALIDATE_INT);
            $categoryId  = filter_var($input['category_id'] ?? 0, FILTER_VALIDATE_INT);
            $description = trim($input['description'] ?? '');
            $schoolYear  = trim($input['school_year'] ?? getCurrentSchoolYear());
            $semester    = trim($input['semester'] ?? getCurrentSemester());

            if (!$userId || !$categoryId) {
                echo json_encode(['success' => false, 'error' => 'user_id and category_id are required']);
                break;
            }
            if (!in_array($semester, ['1st', '2nd', 'summer'], true)) {
                echo json_encode(['success' => false, 'error' => 'Invalid semester value']);
                break;
            }

            // Verify student exists
            $stmt = $pdo->prepare('SELECT id, student_id, name, email, violation_count, active_violations_count FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                echo json_encode(['success' => false, 'error' => 'Student not found']);
                break;
            }

            // Verify category exists
            $stmt = $pdo->prepare('SELECT id, name, type, default_sanction FROM violation_categories WHERE id = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$categoryId]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$category) {
                echo json_encode(['success' => false, 'error' => 'Violation category not found']);
                break;
            }

            // Calculate offense number by category for this student.
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM student_violations WHERE user_id = ? AND category_id = ?');
            $stmt->execute([$userId, $categoryId]);
            $offenseNumber = (int) $stmt->fetchColumn() + 1;

            $pdo->beginTransaction();
            try {
                // Insert violation record
                $stmt = $pdo->prepare("
                    INSERT INTO student_violations
                        (user_id, category_id, description, offense_number, status, school_year, semester, recorded_by)
                    VALUES (?, ?, ?, ?, 'active', ?, ?, ?)
                ");
                $stmt->execute([$userId, $categoryId, $description, $offenseNumber, $schoolYear, $semester, (int)($_SESSION['admin_id'] ?? 0)]);
                $newViolationId = $pdo->lastInsertId();

                // Let the DB trigger keep `users.active_violations_count` accurate.
                // Read the authoritative active count for response or follow-up actions.
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM student_violations WHERE user_id = ? AND status = 'active'");
                $countStmt->execute([$userId]);
                $newActive = (int)$countStmt->fetchColumn();
                $pdo->prepare('UPDATE users SET active_violations_count = ? WHERE id = ?')->execute([$newActive, $userId]);

                $pdo->commit();
                rotate_csrf_after_critical_action();

                // Audit log
                logAuditAction(
                    $pdo,
                    (int) ($_SESSION['admin_id'] ?? 0),
                    $_SESSION['admin_name'] ?? 'Admin',
                    'ADD_VIOLATION',
                    'student',
                    $userId,
                    $student['name'],
                    "Added {$category['type']} violation: {$category['name']} (Offense #{$offenseNumber}) for {$student['name']}",
                    [
                        'violation_id'   => (int) $newViolationId,
                        'category_id'    => (int) $categoryId,
                        'category_name'  => $category['name'],
                        'category_type'  => $category['type'],
                        'offense_number' => $offenseNumber,
                        'school_year'    => $schoolYear,
                        'semester'       => $semester,
                        'description'    => $description,
                    ]
                );

                $isGrave   = $category['type'] === 'grave';
                $isMaxHit  = $offenseNumber >= 4 && !$isGrave;

                $ts = date('F j, Y g:i A');

                // Send student notification email based on offense number
                try {
                    if ($offenseNumber === 1) {
                        // First violation notice
                        $subject  = 'First Violation Notice - ' . $category['name'];
                        $emailHtml = emailFirstViolationNotice(
                            $student['name'],
                            $student['student_id'] ?? (string) $student['id'],
                            $category['name'],
                            $category['type'],
                            $offenseNumber,
                            $semester,
                            $schoolYear,
                            $ts
                        );
                        sendMail($student['email'], $subject, $emailHtml, true);
                    } elseif ($offenseNumber >= 3 || $isMaxHit) {
                        // Escalated warning for higher offenses
                        $subject  = 'DISCIPLINARY NOTICE - Offense #' . $offenseNumber;
                        $emailHtml = emailFinalWarning(
                            $student['name'],
                            $student['student_id'] ?? (string) $student['id'],
                            $category['name'],
                            $category['type'],
                            $offenseNumber,
                            $semester,
                            $schoolYear,
                            $ts
                        );
                        sendMail($student['email'], $subject, $emailHtml, true);
                    }
                } catch (\Exception $mailEx) {
                    error_log('Violation add email error: ' . $mailEx->getMessage());
                }

                // Send parent/guardian notification email (if a guardian email exists and notifications are enabled)
                try {
                    $violationContext = [
                        'offense_number' => $offenseNumber,
                        'category_name' => (string)($category['name'] ?? 'Violation'),
                        'category_type' => (string)($category['type'] ?? ''),
                    ];
                    $disciplinaryPayload = admin_build_disciplinary_payload($violationContext);
                    $notice = is_array($disciplinaryPayload['disciplinary_notice'] ?? null)
                        ? (array)$disciplinaryPayload['disciplinary_notice']
                        : [];

                    send_guardian_violation_notification($userId, [
                        'student_name' => (string)($student['name'] ?? 'Student'),
                        'student_id' => (string)($student['student_id'] ?? (string)$student['id']),
                        'student_email' => (string)($student['email'] ?? ''),
                        'violation_name' => (string)($category['name'] ?? 'Violation'),
                        'violation_type_label' => (string)($disciplinaryPayload['violation_type_label'] ?? admin_violation_type_label((string)($category['type'] ?? ''))),
                        'offense_number' => $offenseNumber,
                        'semester' => $semester,
                        'school_year' => $schoolYear,
                        'recorded_at' => $ts,
                        'description' => $description,
                        'disciplinary_code' => (string)($disciplinaryPayload['disciplinary_code'] ?? ''),
                        'disciplinary_title' => (string)($disciplinaryPayload['disciplinary_title'] ?? ''),
                        'disciplinary_message' => (string)($notice['message'] ?? ''),
                        'disciplinary_action' => (string)($disciplinaryPayload['disciplinary_action'] ?? ''),
                        'intervention_intent' => (string)($notice['intervention_intent'] ?? ''),
                        'category_rationale' => (string)($notice['category_rationale'] ?? ''),
                    ]);
                } catch (\Throwable $guardianMailEx) {
                    error_log('Guardian violation email error: ' . $guardianMailEx->getMessage());
                }

                echo json_encode([
                    'success'        => true,
                    'message'        => "Violation recorded: {$category['name']} — Offense #{$offenseNumber}",
                    'violation_id'   => (int) $newViolationId,
                    'offense_number' => $offenseNumber,
                    'is_grave'       => $isGrave,
                    'max_strike_hit' => $isMaxHit,
                ]);
            } catch (\Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ─── POST: Resolve (apprehend) a violation ───────────────────
        case 'resolve':
            $violationId    = filter_var($input['violation_id'] ?? 0, FILTER_VALIDATE_INT);
            $reparationType = trim($input['reparation_type'] ?? '');
            $reparationNotes = trim($input['reparation_notes'] ?? '');
            $sendNotification = (bool) ($input['send_notification'] ?? true);

            if (!$violationId) {
                echo json_encode(['success' => false, 'error' => 'violation_id is required']);
                break;
            }
            if ($reparationType === '') {
                echo json_encode(['success' => false, 'error' => 'Reparation type is required']);
                break;
            }

            // Fetch the violation with student info
            $stmt = $pdo->prepare("
                SELECT sv.*, vc.name AS category_name, vc.type AS category_type,
                       u.name AS student_name, u.email AS student_email, u.student_id AS student_number,
                       u.violation_count, u.active_violations_count
                FROM student_violations sv
                JOIN violation_categories vc ON sv.category_id = vc.id
                JOIN users u ON sv.user_id = u.id
                WHERE sv.id = ?
                LIMIT 1
            ");
            $stmt->execute([$violationId]);
            $violation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$violation) {
                echo json_encode(['success' => false, 'error' => 'Violation record not found']);
                break;
            }
            if ($violation['status'] === 'apprehended') {
                echo json_encode(['success' => false, 'error' => 'This violation has already been resolved']);
                break;
            }

            $disciplinaryPayload = admin_build_disciplinary_payload($violation);
            $recommendedReparationType = (string)($disciplinaryPayload['recommended_reparation_type'] ?? 'other');
            $allowedReparationTypes = $disciplinaryPayload['allowed_reparation_types'] ?? ['other'];
            if (!is_array($allowedReparationTypes) || count($allowedReparationTypes) === 0) {
                $allowedReparationTypes = ['other'];
            }

            if (!in_array($reparationType, $allowedReparationTypes, true)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Selected reparation type is not allowed for this offense level.',
                    'disciplinary_code' => (string)($disciplinaryPayload['disciplinary_code'] ?? ''),
                    'recommended_reparation_type' => $recommendedReparationType,
                    'allowed_reparation_types' => $allowedReparationTypes,
                    'allowed_reparation_labels' => array_values(array_map('admin_reparation_label', $allowedReparationTypes)),
                ]);
                break;
            }

            $adminId   = (int) ($_SESSION['admin_id'] ?? 0);
            $adminName = $_SESSION['admin_name'] ?? 'Admin';

            $pdo->beginTransaction();
            try {
                // Update violation status
                $stmt = $pdo->prepare("
                    UPDATE student_violations
                    SET status = 'apprehended',
                        reparation_type = ?,
                        reparation_notes = ?,
                        reparation_completed_at = NOW(),
                        resolved_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([$reparationType, $reparationNotes, $adminId, $violationId]);

                // Sync active count from source-of-truth table after resolving this violation.
                $remainingStmt = $pdo->prepare("SELECT COUNT(*) FROM student_violations WHERE user_id = ? AND status IN ('active', 'pending_reparation')");
                $remainingStmt->execute([$violation['user_id']]);
                $newActiveCount = (int)$remainingStmt->fetchColumn();

                $pdo->prepare('UPDATE users SET active_violations_count = ? WHERE id = ?')
                    ->execute([$newActiveCount, $violation['user_id']]);

                // Critical rule: keep gate mark while any violation is pending/active.
                // Reset gate mark only after admin has resolved all pending/active violations.
                if ($newActiveCount === 0) {
                    $pdo->prepare('UPDATE users SET gate_mark_count = 0 WHERE id = ?')
                        ->execute([$violation['user_id']]);
                }

                $pdo->commit();
                rotate_csrf_after_critical_action();

                // Audit log
                logAuditAction(
                    $pdo,
                    $adminId,
                    $adminName,
                    'RESOLVE_VIOLATION',
                    'student',
                    (int) $violation['user_id'],
                    $violation['student_name'],
                    "Resolved {$violation['category_type']} violation: {$violation['category_name']} (" . admin_ordinal_label((int)$violation['offense_number']) . " Offense) for {$violation['student_name']}",
                    [
                        'violation_id'    => $violationId,
                        'category_name'   => $violation['category_name'],
                        'category_type'   => $violation['category_type'],
                        'offense_number'  => (int) $violation['offense_number'],
                        'reparation_type' => $reparationType,
                        'reparation_notes' => $reparationNotes,
                    ]
                );

                // Send notification email to student
                if ($sendNotification) {
                    sendReparationNotificationEmail(
                        $violation['student_email'],
                        $violation['student_name'],
                        $violation['student_number'],
                        $violation['category_name'],
                        $reparationType
                    );
                }

                echo json_encode([
                    'success'                => true,
                    'message'               => "Violation resolved: {$violation['category_name']} for {$violation['student_name']}",
                    'all_cleared'           => $newActiveCount === 0,
                    'active_violations_count' => $newActiveCount,
                ]);
            } catch (\Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ─── POST: Assign reparation task (active → pending_reparation) ──────
        case 'assign_reparation':
            $violationId     = filter_var($input['violation_id'] ?? 0, FILTER_VALIDATE_INT);
            $reparationType  = trim($input['reparation_type'] ?? '');
            $reparationNotes = trim($input['reparation_notes'] ?? '');
            $sendNotification = (bool) ($input['send_notification'] ?? true);

            if (!$violationId) {
                echo json_encode(['success' => false, 'error' => 'violation_id is required']);
                break;
            }

            // Fetch the violation with student info
            $stmt = $pdo->prepare("
                SELECT sv.*, vc.name AS category_name, vc.type AS category_type,
                       u.name AS student_name, u.email AS student_email, u.student_id AS student_number
                FROM student_violations sv
                JOIN violation_categories vc ON sv.category_id = vc.id
                JOIN users u ON sv.user_id = u.id
                WHERE sv.id = ?
                LIMIT 1
            ");
            $stmt->execute([$violationId]);
            $violation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$violation) {
                echo json_encode(['success' => false, 'error' => 'Violation record not found']);
                break;
            }
            if ($violation['status'] !== 'active') {
                echo json_encode(['success' => false, 'error' => 'Violation is not in active status (may already have a reparation assigned or be resolved)']);
                break;
            }

            $disciplinaryPayload = admin_build_disciplinary_payload($violation);
            $recommendedReparationType = (string)($disciplinaryPayload['recommended_reparation_type'] ?? 'other');
            $allowedReparationTypes = $disciplinaryPayload['allowed_reparation_types'] ?? ['other'];
            if (!is_array($allowedReparationTypes) || count($allowedReparationTypes) === 0) {
                $allowedReparationTypes = ['other'];
            }

            // Default to recommended when no explicit task is selected.
            if ($reparationType === '' && $recommendedReparationType !== '') {
                $reparationType = $recommendedReparationType;
            }
            if ($reparationType === '') {
                $reparationType = 'other';
            }

            if (!in_array($reparationType, $allowedReparationTypes, true)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Selected reparation task is not allowed for this offense level.',
                    'disciplinary_code' => (string)($disciplinaryPayload['disciplinary_code'] ?? ''),
                    'allowed_reparation_types' => $allowedReparationTypes,
                    'allowed_reparation_labels' => array_values(array_map('admin_reparation_label', $allowedReparationTypes)),
                ]);
                break;
            }

            $adminId   = (int) ($_SESSION['admin_id'] ?? 0);
            $adminName = $_SESSION['admin_name'] ?? 'Admin';

            // Update to pending_reparation
            $pdo->prepare("
                UPDATE student_violations
                SET status = 'pending_reparation',
                    reparation_type = ?,
                    reparation_notes = ?
                WHERE id = ?
            ")->execute([$reparationType, $reparationNotes, $violationId]);
            rotate_csrf_after_critical_action();

            // Audit log
            logAuditAction(
                $pdo,
                $adminId,
                $adminName,
                'ASSIGN_REPARATION',
                'student',
                (int) $violation['user_id'],
                $violation['student_name'],
                "Assigned reparation task for {$violation['category_type']} violation: {$violation['category_name']} (" . admin_ordinal_label((int)$violation['offense_number']) . " Offense) — Task: " . ucwords(str_replace('_', ' ', $reparationType)),
                [
                    'violation_id'    => $violationId,
                    'category_name'   => $violation['category_name'],
                    'category_type'   => $violation['category_type'],
                    'offense_number'  => (int) $violation['offense_number'],
                    'reparation_type' => $reparationType,
                    'reparation_notes' => $reparationNotes,
                ]
            );

            // Send task assignment email to student
            if ($sendNotification) {
                sendReparationTaskEmail(
                    $violation['student_email'],
                    $violation['student_name'],
                    $violation['student_number'],
                    $violation['category_name'],
                    $reparationType,
                    $reparationNotes
                );

                // Also notify the primary guardian about the assigned reparation task
                try {
                    $notice = is_array($disciplinaryPayload['disciplinary_notice'] ?? null)
                        ? (array)$disciplinaryPayload['disciplinary_notice']
                        : [];

                    send_guardian_violation_notification((int)$violation['user_id'], [
                        'student_name' => (string)($violation['student_name'] ?? ''),
                        'student_id' => (string)($violation['student_number'] ?? ''),
                        'violation_name' => (string)($violation['category_name'] ?? ''),
                        'violation_type_label' => (string)($disciplinaryPayload['violation_type_label'] ?? admin_violation_type_label((string)($violation['category_type'] ?? ''))),
                        'offense_number' => (int)($violation['offense_number'] ?? 1),
                        'semester' => $semester,
                        'school_year' => $schoolYear,
                        'recorded_at' => date('F j, Y g:i A'),
                        'description' => $reparationNotes,
                        'reparation_type' => $reparationType,
                        'reparation_notes' => $reparationNotes,
                        'disciplinary_code' => (string)($disciplinaryPayload['disciplinary_code'] ?? ''),
                        'disciplinary_title' => (string)($disciplinaryPayload['disciplinary_title'] ?? ''),
                        'disciplinary_message' => (string)($notice['message'] ?? ''),
                        'disciplinary_action' => (string)($disciplinaryPayload['disciplinary_action'] ?? ''),
                        'intervention_intent' => (string)($notice['intervention_intent'] ?? ''),
                        'category_rationale' => (string)($notice['category_rationale'] ?? ''),
                    ]);
                } catch (\Throwable $e) {
                    error_log('Guardian reparation email error: ' . $e->getMessage());
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Reparation task assigned to {$violation['student_name']}: " . ucwords(str_replace('_', ' ', $reparationType)),
                'disciplinary_code' => (string)($disciplinaryPayload['disciplinary_code'] ?? ''),
                'disciplinary_title' => (string)($disciplinaryPayload['disciplinary_title'] ?? ''),
                'recommended_reparation_type' => $reparationType,
                'recommended_reparation_label' => admin_reparation_label($reparationType),
                'allowed_reparation_types' => $allowedReparationTypes,
                'allowed_reparation_labels' => array_values(array_map('admin_reparation_label', $allowedReparationTypes)),
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (\PDOException $e) {
    error_log('Manage violations DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A database error occurred']);
} catch (\Exception $e) {
    error_log('Manage violations error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An unexpected error occurred']);
}

/**
 * Send email to student notifying them their single violation has been resolved.
 */
function sendReparationNotificationEmail(string $email, string $name, string $studentId, string $violationName, string $reparationType): void {
    $timestamp      = date('F j, Y g:i A');
    $reparationLabel = ucwords(str_replace('_', ' ', $reparationType));

    $subject = 'Violation Resolved - You May Claim Your Documents';
    $body    = emailViolationResolved($name, $studentId, $violationName, $reparationLabel, $timestamp);

    $sent = sendMail($email, $subject, $body, true);
    if ($sent === true) {
        error_log("Reparation notification sent to {$email} for violation: {$violationName}");
    } else {
        error_log("Failed to send reparation notification to {$email}: " . ($sent ?: 'unknown error'));
    }
}

/**
 * Send email to student telling them WHAT they must do to resolve their active violation.
 * Sent when admin assigns a reparation task (active → pending_reparation).
 */
function sendReparationTaskEmail(string $email, string $name, string $studentId, string $violationName, string $reparationType, string $reparationNotes = ''): void {
    $timestamp       = date('F j, Y g:i A');
    $reparationLabel = ucwords(str_replace('_', ' ', $reparationType));

    $taskDescriptions = [
        'written_apology'       => 'Write a formal written apology letter addressed to the Student Services Office (SSO). The letter must be sincere, acknowledge the violation, and include your commitment to comply with school policies.',
        'community_service'     => 'Complete the assigned number of community service hours at a location designated by the Student Services Office. Bring your student ID and report to the SSO for work assignment details.',
        'counseling'            => 'Attend a counseling session with the school\'s registered guidance counselor. Schedule your appointment at the Student Services Office as soon as possible.',
        'parent_conference'     => 'A parent or guardian conference is required. Please have your parent or legal guardian visit the Student Services Office to meet with the school administrator. Bring a valid ID for your guardian.',
        'suspension_compliance' => 'You are required to comply with the terms of your suspension. Review the suspension notice for specific dates and conditions. Return to school only on the designated date with a written acknowledgment.',
        'restitution'           => 'You are required to make restitution (payment or replacement) for damages caused. Report to the Student Services Office for the specific amount or items to be replaced.',
        'other'                 => 'Please report to the Student Services Office immediately to receive specific instructions for your assigned reparation task.',
    ];

    $taskInstruction = $taskDescriptions[$reparationType] ?? $taskDescriptions['other'];

    $subject = 'Action Required - Violation Reparation Task Assigned';
    $body    = emailReparationTask($name, $studentId, $violationName, $reparationLabel, $taskInstruction, $reparationNotes, $timestamp);

    $sent = sendMail($email, $subject, $body, true);
    if ($sent === true) {
        error_log("Reparation task email sent to {$email} for violation: {$violationName}, task: {$reparationType}");
    } else {
        error_log("Failed to send reparation task email to {$email}: " . ($sent ?: 'unknown error'));
    }
}
