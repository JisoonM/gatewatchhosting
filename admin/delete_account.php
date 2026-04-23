<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('student.delete', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission student.delete.',
]);

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

    $pdo->beginTransaction();
    try {
        // Fetch student info BEFORE soft-delete (for audit log)
        $infoStmt = $pdo->prepare('SELECT name, student_id, email, rfid_uid, course, deleted_at FROM users WHERE id = ? AND role = "Student" LIMIT 1 FOR UPDATE');
        $infoStmt->execute([$studentId]);
        $studentInfo = $infoStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$studentInfo) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Student not found']);
            exit;
        }

        // Idempotency: if already deleted, return success.
        if (!empty($studentInfo['deleted_at'])) {
            $pdo->rollBack();
            echo json_encode(['success' => true]);
            exit;
        }

        $studentName = $studentInfo['name'] ?? 'Unknown';
        $studentIdStr = $studentInfo['student_id'] ?? '';

        $adminId = (int)($_SESSION['admin_id'] ?? 0);

        // Deactivate any active RFID cards (also clears users.rfid_uid via trigger)
        try {
            $rfidTableCheck = $pdo->query("SHOW TABLES LIKE 'rfid_cards'")->fetch();
            if ($rfidTableCheck) {
                $rfidStmt = $pdo->prepare('UPDATE rfid_cards SET is_active = 0, unregistered_at = COALESCE(unregistered_at, NOW()), unregistered_by = COALESCE(unregistered_by, ?) WHERE user_id = ? AND is_active = 1');
                $rfidStmt->execute([$adminId ?: null, $studentId]);
            }
        } catch (\PDOException $e) {
            // Best-effort cleanup; do not block deletion.
            error_log('Delete account RFID cleanup skipped: ' . $e->getMessage());
        }

        // Deactivate face descriptors (preserve audit logs, remove active biometrics)
        try {
            $fdCheck = $pdo->query("SHOW TABLES LIKE 'face_descriptors'")->fetch();
            if ($fdCheck) {
                $descriptorCount = 0;
                $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM face_descriptors WHERE user_id = ? AND is_active = 1');
                $cntStmt->execute([$studentId]);
                $descriptorCount = (int)$cntStmt->fetchColumn();

                if ($descriptorCount > 0) {
                    $vcCheck = $pdo->query("SHOW TABLES LIKE 'face_descriptor_version_counter'")->fetch();
                    $newVersion = 0;
                    if ($vcCheck) {
                        $pdo->exec('UPDATE face_descriptor_version_counter SET current_version = current_version + 1 WHERE id = 1');
                        $newVersion = (int)$pdo->query('SELECT current_version FROM face_descriptor_version_counter WHERE id = 1')->fetchColumn();
                    }

                    if ($newVersion > 0) {
                        $fdStmt = $pdo->prepare('UPDATE face_descriptors SET is_active = 0, updated_at = NOW(), version = ? WHERE user_id = ? AND is_active = 1');
                        $fdStmt->execute([$newVersion, $studentId]);
                    } else {
                        $fdStmt = $pdo->prepare('UPDATE face_descriptors SET is_active = 0, updated_at = NOW() WHERE user_id = ? AND is_active = 1');
                        $fdStmt->execute([$studentId]);
                    }

                    $pdo->prepare('UPDATE users SET face_registered = 0, face_registered_at = NULL WHERE id = ?')->execute([$studentId]);

                    $frlCheck = $pdo->query("SHOW TABLES LIKE 'face_registration_log'")->fetch();
                    if ($frlCheck) {
                        $frlStmt = $pdo->prepare("INSERT INTO face_registration_log (user_id, action, descriptor_count, performed_by, ip_address, user_agent) VALUES (?, 'deleted', ?, ?, ?, ?)");
                        $frlStmt->execute([
                            $studentId,
                            $descriptorCount,
                            $adminId ?: null,
                            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
                        ]);
                    }
                }
            }
        } catch (\PDOException $e) {
            // Best-effort cleanup; do not block deletion.
            error_log('Delete account face cleanup skipped: ' . $e->getMessage());
        }

        // Remove profile picture metadata (best-effort)
        try {
            $spCheck = $pdo->query("SHOW TABLES LIKE 'student_profiles'")->fetch();
            if ($spCheck) {
                $pdo->prepare('UPDATE student_profiles SET profile_picture = NULL, profile_picture_uploaded_at = NULL WHERE user_id = ?')->execute([$studentId]);
            }
        } catch (\PDOException $e) {
            error_log('Delete account profile cleanup skipped: ' . $e->getMessage());
        }

        // Soft-delete user: preserve FK integrity (audit tables are ON DELETE RESTRICT)
        // Also anonymize unique identifiers to prevent re-login and reduce retained PII.
        $idTail = substr(str_pad((string)$studentId, 6, '0', STR_PAD_LEFT), -6);
        $deletedStudentId = 'DEL-' . $idTail . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $deletedEmail = 'deleted+' . $studentId . '.' . substr(bin2hex(random_bytes(4)), 0, 8) . '@example.invalid';
        $newPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID);

        $upd = $pdo->prepare('UPDATE users SET deleted_at = NOW(), status = "Locked", locked_until = NULL, failed_attempts = 0, google_id = NULL, email = ?, student_id = ?, name = ?, password = ?, course = NULL, profile_picture = NULL, profile_picture_uploaded_at = NULL WHERE id = ? AND role = "Student" AND deleted_at IS NULL');
        $upd->execute([$deletedEmail, $deletedStudentId, 'Deleted Student', $newPassword, $studentId]);

        if ($upd->rowCount() < 1) {
            throw new \Exception('Failed to delete user record');
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Audit log
    $adminId = $_SESSION['admin_id'] ?? 0;
    $adminName = $_SESSION['admin_name'] ?? 'Admin';
    logAuditAction($pdo, $adminId, $adminName, 'DELETE_STUDENT', 'student', $studentId, $studentName,
        "Soft-deleted student account: {$studentName} ({$studentIdStr})",
        ['student_id' => $studentIdStr, 'email' => $studentInfo['email'] ?? '', 'rfid_uid' => $studentInfo['rfid_uid'] ?? '', 'course' => $studentInfo['course'] ?? '']
    );

    rotate_csrf_after_critical_action();

    echo json_encode(['success' => true]);

} catch (\Exception $e) {
    error_log('Delete account error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}