<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';

// [AGENT CHANGE — TASK 3]
// Check if admin or superadmin is logged in
$isAdminSession = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isSuperadminSession = isset($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true;
if (!$isAdminSession && !$isSuperadminSession) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$permissionRole = $isSuperadminSession ? 'superadmin' : 'admin';
require_permission('student.delete', [
    'actor_role' => $permissionRole,
    'response' => 'json',
    'message' => 'Forbidden: missing permission student.delete.',
]);
// [END TASK 3]

// CSRF protection for JSON API
$headers = getallheaders();
if (!isset($headers['X-CSRF-Token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $headers['X-CSRF-Token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Check if request is POST and has JSON content
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        throw new \Exception('Invalid request');
    }

    $data = json_decode($rawBody, true);
    if (!is_array($data)) {
        throw new \Exception('Invalid JSON payload');
    }
    
    if (!isset($data['student_id'])) {
        throw new \Exception('Missing student ID');
    }

    $studentId = filter_var($data['student_id'], FILTER_VALIDATE_INT);
    if (!$studentId || $studentId <= 0) {
        throw new \Exception('Invalid student ID');
    }

    $pdo = pdo();
    $hasArchivedCol = db_column_exists('users', 'is_archived');
    $hasArchivedAtCol = db_column_exists('users', 'archived_at');
    $hasArchivedByCol = db_column_exists('users', 'archived_by');
    $hasRfidStatusCol = db_column_exists('rfid_cards', 'status');
    $hasRfidActiveCol = db_column_exists('rfid_cards', 'is_active');
    $hasRfidUnregAtCol = db_column_exists('rfid_cards', 'unregistered_at');
    $hasRfidUnregByCol = db_column_exists('rfid_cards', 'unregistered_by');
    $hasRfidUserIdCol = db_column_exists('rfid_cards', 'user_id');

    $pdo->beginTransaction();
    try {
        // [AGENT CHANGE — TASK 3]
        // Fetch student info BEFORE archive (for audit log)
        $archivedSelectExpr = $hasArchivedCol ? 'COALESCE(is_archived, 0)' : '0';
        $infoStmt = $pdo->prepare('SELECT name, student_id, email, rfid_uid, course, deleted_at, ' . $archivedSelectExpr . ' AS is_archived FROM users WHERE id = ? AND role = "Student" LIMIT 1 FOR UPDATE');
        $infoStmt->execute([$studentId]);
        $studentInfo = $infoStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$studentInfo) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Student not found']);
            exit;
        }

        // Idempotency: if already archived or deleted, return success.
        if (!empty($studentInfo['deleted_at']) || (int)($studentInfo['is_archived'] ?? 0) === 1) {
            $pdo->rollBack();
            echo json_encode(['success' => true]);
            exit;
        }

        $studentName = $studentInfo['name'] ?? 'Unknown';
        $studentIdStr = $studentInfo['student_id'] ?? '';

        $actorId = (int)($_SESSION['admin_id'] ?? ($_SESSION['superadmin_id'] ?? 0));
        $archiveActorId = $actorId > 0 ? $actorId : null;

        if ($hasArchivedCol || $hasArchivedAtCol || $hasArchivedByCol) {
            $archiveSet = [];
            $archiveBind = [];
            if ($hasArchivedCol) {
                $archiveSet[] = 'is_archived = 1';
            }
            if ($hasArchivedAtCol) {
                $archiveSet[] = 'archived_at = NOW()';
            }
            if ($hasArchivedByCol) {
                $archiveSet[] = 'archived_by = ?';
                $archiveBind[] = $archiveActorId;
            }
            if (!$archiveSet) {
                $archiveSet[] = 'updated_at = NOW()';
            }
            $archiveSql = 'UPDATE users SET ' . implode(', ', $archiveSet) . ' WHERE id = ? AND role = "Student"';
            if ($hasArchivedCol) {
                $archiveSql .= ' AND COALESCE(is_archived, 0) = 0';
            }
            $archiveSql .= ' LIMIT 1';
            $archiveBind[] = $studentId;
            $archiveStmt = $pdo->prepare($archiveSql);
            $archiveStmt->execute($archiveBind);
        } else {
            // Fallback for deployments before archive columns: keep prior soft-delete behavior.
            $archiveStmt = $pdo->prepare('UPDATE users SET deleted_at = NOW(), status = "Locked" WHERE id = ? AND role = "Student" AND deleted_at IS NULL LIMIT 1');
            $archiveStmt->execute([$studentId]);
        }

        if ($archiveStmt->rowCount() < 1) {
            throw new \Exception('Student is already archived or unavailable.');
        }

        // Unlink RFID UID so it can be reassigned.
        $unlinkUserRfid = $pdo->prepare('UPDATE users SET rfid_uid = NULL, rfid_registered_at = NULL WHERE id = ? LIMIT 1');
        $unlinkUserRfid->execute([$studentId]);

        $rfidTableCheck = $pdo->query("SHOW TABLES LIKE 'rfid_cards'")->fetch();
        if ($rfidTableCheck) {
            $rfidSet = [];
            $rfidBind = [];
            if ($hasRfidActiveCol) {
                $rfidSet[] = 'is_active = 0';
            }
            if ($hasRfidStatusCol) {
                $rfidSet[] = 'status = "available"';
            }
            if ($hasRfidUnregAtCol) {
                $rfidSet[] = 'unregistered_at = COALESCE(unregistered_at, NOW())';
            }
            if ($hasRfidUnregByCol) {
                $rfidSet[] = 'unregistered_by = COALESCE(unregistered_by, ?)';
                $rfidBind[] = $archiveActorId;
            }
            if ($hasRfidUserIdCol) {
                $rfidSet[] = 'user_id = NULL';
            }

            if ($rfidSet) {
                $rfidSql = 'UPDATE rfid_cards SET ' . implode(', ', $rfidSet) . ' WHERE user_id = ?';
                if ($hasRfidActiveCol) {
                    $rfidSql .= ' AND is_active = 1';
                }
                $rfidBind[] = $studentId;
                $rfidStmt = $pdo->prepare($rfidSql);
                $rfidStmt->execute($rfidBind);
            }
        }
        // [END TASK 3]

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Audit log
    // [AGENT CHANGE — TASK 3]
    $adminId = $_SESSION['admin_id'] ?? ($_SESSION['superadmin_id'] ?? 0);
    $adminName = $_SESSION['admin_name'] ?? ($_SESSION['superadmin_name'] ?? 'System');
    logAuditAction($pdo, $adminId, $adminName, 'ARCHIVE_STUDENT', 'student', $studentId, $studentName,
        "Archived student account and unlinked RFID: {$studentName} ({$studentIdStr})",
        ['student_id' => $studentIdStr, 'email' => $studentInfo['email'] ?? '', 'rfid_uid' => $studentInfo['rfid_uid'] ?? '', 'course' => $studentInfo['course'] ?? '']
    );
    // [END TASK 3]

    rotate_csrf_after_critical_action();

    echo json_encode(['success' => true]);

} catch (\Exception $e) {
    error_log('Delete account error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
