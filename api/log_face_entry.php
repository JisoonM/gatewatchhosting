<?php

/**
 * API: Log Face-Based Gate Identification
 * Endpoint: POST /api/log_face_entry.php
 *
 * Security: Security guard auth, CSRF protected, rate limited.
 * Identifies a student via face recognition and defers violation recording
 * to the security-side "Choose Violation" flow.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/qr_binding_helper.php';
require_once __DIR__ . '/../includes/security_scan_token_helper.php';

header('Content-Type: application/json');
send_api_security_headers();
require_same_origin_api_request();

if (!isset($_SESSION['security_logged_in']) || $_SESSION['security_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Security login required']);
    exit;
}

require_permission('face.verify', [
    'actor_role' => 'security',
    'response' => 'json',
    'message' => 'Forbidden: missing permission face.verify.',
]);

$headers = getallheaders();
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!check_rate_limit('face_entry', 120, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests']);
    exit;
}

if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Face recognition is disabled']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
    $confidenceScore = filter_var($input['confidence_score'] ?? null, FILTER_VALIDATE_FLOAT);
    $matchThreshold = filter_var($input['match_threshold'] ?? 0.6, FILTER_VALIDATE_FLOAT);

    $queryDescriptorHash = null;
    if (isset($input['query_descriptor']) && is_array($input['query_descriptor']) && count($input['query_descriptor']) === 128) {
        $queryDescriptorHash = hash('sha256', json_encode($input['query_descriptor']));
    }

    if (!$userId || $userId <= 0) {
        throw new Exception('Invalid user ID');
    }

    if ($confidenceScore === false || $confidenceScore < 0 || $confidenceScore > 1) {
        throw new Exception('Invalid confidence score');
    }

    if ($confidenceScore < 0.55) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Confidence too low to accept face entry']);
        exit;
    }

    $pdo = pdo();
    ensure_security_scan_tokens_table($pdo);
    $qrBindingEnabled = qr_binding_enabled();

    if ($qrBindingEnabled) {
        ensure_qr_binding_tables($pdo);
        qr_binding_expire_stale_rows($pdo);
    }

    $guardId = $_SESSION['security_id'] ?? null;

    $stmt = $pdo->prepare('
        SELECT id, student_id, name, email, course,
               COALESCE(violation_count, 0) AS violation_count,
               COALESCE(active_violations_count, 0) AS active_violations_count,
               COALESCE(gate_mark_count, 0) AS gate_mark_count,
               profile_picture, status
        FROM users
        WHERE id = ? AND role = "Student"
        LIMIT 1
    ');
    $stmt->execute([$userId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new Exception('Student not found');
    }

    if (($student['status'] ?? '') !== 'Active') {
        echo json_encode([
            'success' => false,
            'access_denied' => true,
            'error' => 'ACCOUNT INACTIVE',
            'student' => [
                'id' => $student['id'],
                'name' => $student['name'],
                'student_id' => $student['student_id']
            ],
            'message' => 'Student account is not active. Contact administration.',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    $pdo->beginTransaction();

    try {
        $entryTime = date('Y-m-d H:i:s');
        $qrPending = null;

        if ($qrBindingEnabled) {
            $guardSessionHash = qr_guard_session_hash();
            $pendingStmt = $pdo->prepare("SELECT * FROM qr_face_pending WHERE guard_session_hash = ? AND status = 'pending' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $pendingStmt->execute([$guardSessionHash]);
            $qrPending = $pendingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($qrPending && qr_datetime_is_expired((string)($qrPending['expires_at'] ?? ''))) {
                $pdo->prepare("UPDATE qr_face_pending SET status = 'expired', resolved_at = NOW() WHERE id = ? AND status = 'pending'")
                    ->execute([(int)$qrPending['id']]);
                $qrPending = null;
            }

            if ($qrPending && (int)$qrPending['user_id'] !== (int)$userId) {
                $pdo->prepare("UPDATE qr_face_pending SET status = 'rejected', reject_reason = 'face_user_mismatch', resolved_at = NOW(), resolved_by_user_id = ? WHERE id = ? AND status = 'pending'")
                    ->execute([$userId, (int)$qrPending['id']]);

                qr_binding_log_event(
                    $pdo,
                    'suspected_proxy_attempt',
                    (int)$qrPending['user_id'],
                    (string)$qrPending['student_id'],
                    (string)$qrPending['challenge_id'],
                    (string)$qrPending['token_hash'],
                    [
                        'reason' => 'face_user_mismatch',
                        'expected_user_id' => (int)$qrPending['user_id'],
                        'matched_user_id' => (int)$userId
                    ]
                );

                $pdo->commit();
                echo json_encode([
                    'success' => false,
                    'qr_rejected_proxy' => true,
                    'error' => 'QR_REJECTED_PROXY',
                    'message' => 'Face does not match the student who presented QR. Access denied.',
                    'timestamp' => $entryTime
                ]);
                exit;
            }

            if ($qrPending) {
                try {
                    $usedStmt = $pdo->prepare('INSERT INTO used_qr_tokens (token_hash, user_id, student_id, used_at, security_guard) VALUES (?, ?, ?, NOW(), ?)');
                    $usedStmt->execute([
                        (string)$qrPending['token_hash'],
                        (int)$userId,
                        (string)$qrPending['student_id'],
                        (string)($_SESSION['security_username'] ?? 'Unknown')
                    ]);
                } catch (PDOException $tokenEx) {
                    if ($tokenEx->getCode() === '23000') {
                        $pdo->prepare("UPDATE qr_face_pending SET status = 'rejected', reject_reason = 'token_already_used', resolved_at = NOW(), resolved_by_user_id = ? WHERE id = ?")
                            ->execute([$userId, (int)$qrPending['id']]);
                        $pdo->commit();
                        echo json_encode([
                            'success' => false,
                            'error' => 'QR_ALREADY_USED',
                            'message' => 'QR token was already consumed. Ask student to refresh QR and rescan.',
                            'timestamp' => $entryTime
                        ]);
                        exit;
                    }
                    throw $tokenEx;
                }

                $pdo->prepare("UPDATE qr_face_pending SET status = 'verified', resolved_at = NOW(), resolved_by_user_id = ? WHERE id = ? AND status = 'pending'")
                    ->execute([$userId, (int)$qrPending['id']]);

                qr_binding_log_event(
                    $pdo,
                    'qr_face_verified',
                    $userId,
                    (string)$qrPending['student_id'],
                    (string)$qrPending['challenge_id'],
                    (string)$qrPending['token_hash'],
                    ['confidence_score' => $confidenceScore]
                );
            }
        }

        $isQrFaceFlow = $qrPending ? true : false;

        $pdo->prepare("INSERT INTO violations (user_id, rfid_uid, scanned_at, violation_type) VALUES (?, ?, NOW(), 'forgot_card')")
            ->execute([$userId, $isQrFaceFlow ? 'QR_CODE' : 'FACE_RECOGNITION']);

        if ($isQrFaceFlow) {
            $pdo->prepare('INSERT INTO qr_entry_logs (user_id, student_id, entry_type, scanned_at, security_guard) VALUES (?, ?, ?, NOW(), ?)')
                ->execute([
                    $userId,
                    (string)$student['student_id'],
                    'QR_FACE',
                    (string)($_SESSION['security_username'] ?? 'Unknown')
                ]);
        }

        $pendingSsoStmt = $pdo->prepare("SELECT COUNT(*) FROM student_violations WHERE user_id = ? AND status IN ('active', 'pending_reparation')");
        $pendingSsoStmt->execute([$userId]);
        $pendingSsoCases = (int)$pendingSsoStmt->fetchColumn();

        if ((int)$student['active_violations_count'] !== $pendingSsoCases) {
            $pdo->prepare('UPDATE users SET active_violations_count = ? WHERE id = ?')
                ->execute([$pendingSsoCases, $userId]);
            $student['active_violations_count'] = $pendingSsoCases;
        }

        if ($pendingSsoCases > 0) {
            $pdo->prepare("INSERT INTO face_entry_logs (user_id, confidence_score, match_threshold, entry_type, security_guard_id, query_descriptor_hash) VALUES (?, ?, ?, 'face_access_denied', ?, ?)")
                ->execute([$userId, $confidenceScore, $matchThreshold, $guardId, $queryDescriptorHash]);

            $pdo->commit();

            echo json_encode([
                'success' => false,
                'access_denied' => true,
                'entry_type' => 'face_access_denied',
                'qr_face_verified' => $qrPending ? true : false,
                'scan_source' => $isQrFaceFlow ? 'qr' : 'face',
                'student' => [
                    'id' => $student['id'],
                    'name' => $student['name'],
                    'student_id' => $student['student_id'],
                    'email' => $student['email'],
                    'course' => $student['course'],
                    'violation_count' => (int)$student['violation_count'],
                    'active_violations_count' => (int)$student['active_violations_count'],
                    'gate_mark' => (int)$student['gate_mark_count'],
                    'profile_picture' => $student['profile_picture'],
                ],
                'sso_hold_count' => $pendingSsoCases,
                'message' => 'ACCESS DENIED — Student has unresolved SSO compliance. Entry is blocked until SSO clears the case.',
                'severity' => 'blocked',
                'timestamp' => $entryTime,
            ]);
            exit;
        }

        $pdo->prepare('INSERT INTO face_entry_logs (user_id, confidence_score, match_threshold, entry_type, security_guard_id, query_descriptor_hash) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                $userId,
                $confidenceScore,
                $matchThreshold,
                $isQrFaceFlow ? 'qr_face_identified' : 'face_identified',
                $guardId,
                $queryDescriptorHash
            ]);

        $scanToken = issue_security_scan_token(
            $pdo,
            (int)$userId,
            $isQrFaceFlow ? 'qr' : 'face',
            $guardId !== null ? (int)$guardId : null,
            (string)($_SESSION['security_username'] ?? 'Unknown'),
            security_scan_guard_session_hash()
        );

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'awaiting_violation_selection' => true,
            'entry_type' => $isQrFaceFlow ? 'qr_face_identified' : 'face_identified',
            'qr_face_verified' => $qrPending ? true : false,
            'scan_source' => $isQrFaceFlow ? 'qr' : 'face',
            'violation_selection_token' => $scanToken['token'],
            'violation_selection_expires_at' => $scanToken['expires_at'],
            'student' => [
                'id' => $student['id'],
                'name' => $student['name'],
                'student_id' => $student['student_id'],
                'email' => $student['email'],
                'course' => $student['course'],
                'violation_count' => (int)$student['violation_count'],
                'active_violations_count' => (int)$student['active_violations_count'],
                'gate_mark' => (int)$student['gate_mark_count'],
                'profile_picture' => $student['profile_picture'],
            ],
            'confidence' => $confidenceScore,
            'message' => 'Student identified. Choose the violation type to record this incident.',
            'timestamp' => $entryTime,
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Face entry log database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while logging entry. Please try again.']);
} catch (Exception $e) {
    error_log('Face entry log error: ' . $e->getMessage());
    http_response_code(400);
    if (APP_DEBUG) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid face entry request']);
    }
}
