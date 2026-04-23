<?php
/**
 * QR Code Scan Verification API
 * Validates JWT tokens from Digital ID QR codes
 * Implements one-time use - tokens are invalidated after successful scan
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/qr_binding_helper.php';
require_once __DIR__ . '/../includes/security_scan_token_helper.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

header('Content-Type: application/json');
apply_cors_headers(['POST'], ['Content-Type', 'X-CSRF-Token']);
send_api_security_headers();
require_same_origin_api_request();

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

if (function_exists('check_rate_limit') && !check_rate_limit('qr_scan_verify', 180, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'RATE_LIMITED', 'message' => 'Too many scan attempts']);
    exit;
}

// Check if security guard is logged in
if (!isset($_SESSION['security_logged_in']) || $_SESSION['security_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

require_permission('qr.scan', [
    'actor_role' => 'security',
    'response' => 'json',
    'message' => 'Forbidden: missing permission qr.scan.',
]);

// CSRF Protection for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headers = getallheaders();
    $csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    
    if (empty($sessionToken) || !hash_equals($sessionToken, $csrfHeader)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// Get the JWT token from request
$data = get_json_input();
$jwt_token = trim($data['token'] ?? '');

function jwt_parts_are_valid(string $token): bool {
    if ($token === '' || strlen($token) > 4096) {
        return false;
    }

    if (!preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $token)) {
        return false;
    }

    return true;
}

if (empty($jwt_token)) {
    echo json_encode(['success' => false, 'error' => 'No QR code token provided']);
    exit;
}

if (!jwt_parts_are_valid($jwt_token)) {
    echo json_encode([
        'success' => false,
        'error' => 'INVALID_QR_FORMAT',
        'message' => 'Unsupported QR format',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

try {
    $pdo = pdo();
    ensure_security_scan_tokens_table($pdo);
    $jwt_secret = get_jwt_secret();
    $challengeEnabled = filter_var(env('QR_CHALLENGE_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    $faceBindingEnabled = qr_binding_enabled();
    $guardSessionHash = qr_guard_session_hash();

    if ($challengeEnabled || $faceBindingEnabled) {
        ensure_qr_binding_tables($pdo);
        qr_binding_expire_stale_rows($pdo);
    }
    
    // First check if this token has already been used
    $stmt = $pdo->prepare('SELECT id, used_at FROM used_qr_tokens WHERE token_hash = ? LIMIT 1');
    $token_hash = hash('sha256', $jwt_token);
    $stmt->execute([$token_hash]);
    $usedToken = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($usedToken) {
        echo json_encode([
            'success' => false,
            'error' => 'QR_ALREADY_USED',
            'message' => 'This QR code has already been scanned and verified',
            'used_at' => $usedToken['used_at'],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // Decode and verify JWT token
    try {
        $decoded = JWT::decode($jwt_token, new Key($jwt_secret, 'HS256'));
    } catch (ExpiredException $e) {
        echo json_encode([
            'success' => false,
            'error' => 'QR_EXPIRED',
            'message' => 'This QR code has expired. Please refresh your Digital ID.',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_QR',
            'message' => 'Invalid QR code',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // Strict claim validation - only institution-issued student QR tokens are accepted
    $issuer = (string) env('QR_TOKEN_ISSUER', env('APP_URL', 'gatewatch-local'));
    $audience = (string) env('QR_TOKEN_AUDIENCE', 'gatewatch-security');
    $now = time();

    $tokenIss = (string) ($decoded->iss ?? '');
    $tokenAud = $decoded->aud ?? '';
    $tokenPurpose = (string) ($decoded->purpose ?? '');
    $tokenIat = (int) ($decoded->iat ?? ($decoded->issued_at ?? 0));
    $tokenNbf = (int) ($decoded->nbf ?? $tokenIat);
    $tokenExp = (int) ($decoded->exp ?? ($decoded->expires_at ?? 0));

    $audMatches = false;
    if (is_array($tokenAud)) {
        $audMatches = in_array($audience, $tokenAud, true);
    } else {
        $audMatches = ((string) $tokenAud === $audience);
    }

    if (
        $tokenIss !== $issuer
        || !$audMatches
        || $tokenPurpose !== 'student_digital_id_qr'
        || $tokenIat <= 0
        || $tokenExp <= 0
        || $tokenNbf > $now + 10
        || $tokenExp <= $now
        || ($tokenExp - $tokenIat) > 600
    ) {
        echo json_encode([
            'success' => false,
            'error' => 'UNTRUSTED_QR',
            'message' => 'QR code is not recognized as an official student digital ID',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    $challengeId = '';
    $challengeRowId = 0;
    if ($challengeEnabled) {
        $challengeId = trim((string)($data['challenge_id'] ?? ''));
        $tokenChallengeId = trim((string)($decoded->challenge_id ?? ''));

        if ($challengeId === '') {
            if ($faceBindingEnabled) {
                qr_binding_log_event($pdo, 'qr_challenge_required', null, null, $challengeId ?: null, $token_hash, []);
            }
            echo json_encode([
                'success' => false,
                'error' => 'QR_CHALLENGE_REQUIRED',
                'qr_challenge_required' => true,
                'message' => 'This scan requires a fresh gate challenge. Ask the student to refresh their Digital ID.',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            exit;
        }

        $challengeStmt = $pdo->prepare('SELECT id, challenge_id, expires_at FROM qr_scan_challenges WHERE challenge_id = ? AND guard_session_hash = ? AND status = "active" LIMIT 1');
        $challengeStmt->execute([$challengeId, $guardSessionHash]);
        $challenge = $challengeStmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge || strtotime((string)$challenge['expires_at']) <= $now) {
            if ($challenge) {
                $pdo->prepare('UPDATE qr_scan_challenges SET status = "expired" WHERE id = ?')->execute([$challenge['id']]);
            }
            if ($faceBindingEnabled) {
                qr_binding_log_event($pdo, 'qr_expired_challenge', null, null, $challengeId, $token_hash, []);
            }
            echo json_encode([
                'success' => false,
                'error' => 'QR_EXPIRED_CHALLENGE',
                'qr_expired_challenge' => true,
                'message' => 'Gate challenge expired. Generate a new challenge and ask the student to refresh their QR.',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            exit;
        }

        $challengeExpTs = strtotime((string)($challenge['expires_at'] ?? ''));
        $challengeTtl = (int)env('QR_CHALLENGE_TTL_SECONDS', '45');
        if ($challengeTtl < 10) {
            $challengeTtl = 10;
        }
        if ($challengeTtl > 120) {
            $challengeTtl = 120;
        }
        $challengeCreatedTs = ($challengeExpTs !== false) ? ($challengeExpTs - $challengeTtl) : false;
        $challengeIssuedFresh = $challengeCreatedTs !== false && (($tokenIat + 5) >= $challengeCreatedTs);

        if ($tokenChallengeId === '') {
            if (!$challengeIssuedFresh) {
                if ($faceBindingEnabled) {
                    qr_binding_log_event($pdo, 'qr_challenge_required', null, null, $challengeId, $token_hash, [
                        'reason' => 'token_challenge_missing_or_stale',
                        'token_iat' => $tokenIat,
                        'challenge_created_at' => ($challengeCreatedTs !== false ? date('Y-m-d H:i:s', $challengeCreatedTs) : null),
                        'challenge_expires_at' => (string)($challenge['expires_at'] ?? '')
                    ]);
                }
                echo json_encode([
                    'success' => false,
                    'error' => 'QR_CHALLENGE_REQUIRED',
                    'qr_challenge_required' => true,
                    'message' => 'This scan requires a fresh gate challenge. Ask the student to refresh their Digital ID.',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                exit;
            }

            if ($faceBindingEnabled) {
                qr_binding_log_event($pdo, 'qr_challenge_timebound_accept', null, null, $challengeId, $token_hash, [
                    'token_iat' => $tokenIat,
                    'challenge_created_at' => ($challengeCreatedTs !== false ? date('Y-m-d H:i:s', $challengeCreatedTs) : null),
                    'challenge_expires_at' => (string)($challenge['expires_at'] ?? '')
                ]);
            }
        } else if (!hash_equals($tokenChallengeId, $challengeId)) {
            if ($faceBindingEnabled) {
                qr_binding_log_event($pdo, 'suspected_proxy_attempt', null, null, $challengeId, $token_hash, [
                    'reason' => 'challenge_mismatch',
                    'token_challenge_id' => $tokenChallengeId,
                    'request_challenge_id' => $challengeId
                ]);
            }
            echo json_encode([
                'success' => false,
                'error' => 'QR_REJECTED_PROXY',
                'qr_rejected_proxy' => true,
                'qr_challenge_mismatch' => true,
                'message' => 'Challenge mismatch detected. Rescan using a freshly refreshed Digital ID.',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            exit;
        }

        $challengeRowId = (int)$challenge['id'];
    }

    // Extract student info from token
    $student_id = $decoded->student_id ?? null;
    $name = $decoded->name ?? null;
    $email = $decoded->email ?? null;
    
    if (!$student_id || !$email) {
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_TOKEN_DATA',
            'message' => 'QR code contains invalid data',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // Verify student exists in database
    $stmt = $pdo->prepare('
        SELECT id, student_id, name, email, course,
               COALESCE(violation_count, 0)         AS violation_count,
               COALESCE(active_violations_count, 0) AS active_violations_count,
               COALESCE(gate_mark_count, 0)         AS gate_mark_count,
               profile_picture, status, created_at
        FROM users
        WHERE student_id = ? AND email = ? AND role = "Student"
        LIMIT 1
    ');
    $stmt->execute([$student_id, $email]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode([
            'success' => false,
            'error' => 'STUDENT_NOT_FOUND',
            'message' => 'Student not found in the system',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    // Check if student account is active
    if (($student['status'] ?? '') !== 'Active') {
        echo json_encode([
            'success' => false,
            'error' => 'ACCOUNT_INACTIVE',
            'message' => 'Student account is not active',
            'student' => [
                'name' => $student['name'],
                'student_id' => $student['student_id'],
                'status' => $student['status']
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    // Keep logic compatible with deployments that are missing gate_mark_count.
    try {
        $colChk = $pdo->query("SHOW COLUMNS FROM users LIKE 'gate_mark_count'");
        if (!$colChk->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN gate_mark_count INT NOT NULL DEFAULT 0");
        }
    } catch (\Exception $e) {
        error_log('gate_mark_count migration error (qr): ' . $e->getMessage());
    }

    // Re-fetch after migration check so gate mark fields are always reliable.
    $stFull = $pdo->prepare('
        SELECT id, student_id, name, email, course,
               COALESCE(violation_count, 0)         AS violation_count,
               COALESCE(active_violations_count, 0) AS active_violations_count,
               COALESCE(gate_mark_count, 0)         AS gate_mark_count,
               profile_picture, status
        FROM users
        WHERE id = ? AND role = "Student"
        LIMIT 1
    ');
    $stFull->execute([$student['id']]);
    $student = $stFull->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode([
            'success' => false,
            'error' => 'STUDENT_NOT_FOUND',
            'message' => 'Student not found in the system',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    $entryTime = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        if ($faceBindingEnabled) {
            $existingPendingStmt = $pdo->prepare("SELECT id, user_id, student_id, expires_at FROM qr_face_pending WHERE guard_session_hash = ? AND status = 'pending' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $existingPendingStmt->execute([$guardSessionHash]);
            $existingPending = $existingPendingStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingPending && qr_datetime_is_expired((string)($existingPending['expires_at'] ?? ''))) {
                $pdo->prepare("UPDATE qr_face_pending SET status = 'expired', resolved_at = NOW() WHERE id = ? AND status = 'pending'")
                    ->execute([(int)$existingPending['id']]);
                $existingPending = null;
            }

            if ($existingPending) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'error' => 'QR_PENDING_FACE',
                    'qr_pending_face' => true,
                    'qr_pending_blocked' => true,
                    'message' => 'A QR scan is already waiting for face confirmation. Complete or clear it before scanning another QR.',
                    'pending_user_id' => (int)$existingPending['user_id'],
                    'pending_student_id' => (string)$existingPending['student_id'],
                    'pending_expires_at' => (string)$existingPending['expires_at'],
                    'timestamp' => $entryTime
                ]);
                exit;
            }
        }

        if ($challengeEnabled) {
            $consumeChallenge = $pdo->prepare('UPDATE qr_scan_challenges SET status = "consumed", consumed_at = NOW(), consumed_by_user_id = ? WHERE id = ? AND status = "active"');
            $consumeChallenge->execute([$student['id'], $challengeRowId]);

            if ($consumeChallenge->rowCount() !== 1) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'error' => 'QR_EXPIRED_CHALLENGE',
                    'qr_expired_challenge' => true,
                    'message' => 'Gate challenge is no longer valid. Generate a new challenge and rescan.',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                exit;
            }

            if ($faceBindingEnabled) {
                qr_binding_log_event($pdo, 'challenge_consumed', (int)$student['id'], (string)$student['student_id'], $challengeId, $token_hash, []);
            }
        }

        if ($faceBindingEnabled) {
            $pendingTtl = (int)env('QR_FACE_BINDING_PENDING_TTL_SECONDS', '20');
            if ($pendingTtl < 10) {
                $pendingTtl = 10;
            }
            if ($pendingTtl > 90) {
                $pendingTtl = 90;
            }

            $pdo->prepare("UPDATE qr_face_pending SET status = 'expired', resolved_at = NOW(), reject_reason = 'replaced' WHERE guard_session_hash = ? AND status = 'pending'")
                ->execute([$guardSessionHash]);

            $expiresAt = date('Y-m-d H:i:s', time() + $pendingTtl);
            $pendingInsert = $pdo->prepare("INSERT INTO qr_face_pending
                (guard_session_hash, guard_username, challenge_id, token_hash, user_id, student_id, status, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)");
            $pendingInsert->execute([
                $guardSessionHash,
                (string)($_SESSION['security_username'] ?? 'Unknown'),
                $challengeId,
                $token_hash,
                (int)$student['id'],
                (string)$student['student_id'],
                $expiresAt
            ]);

            qr_binding_log_event($pdo, 'qr_pending_face', (int)$student['id'], (string)$student['student_id'], $challengeId, $token_hash, [
                'expires_at' => $expiresAt
            ]);

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'verified' => false,
                'qr_pending_face' => true,
                'state' => 'qr_pending_face',
                'message' => 'QR accepted. Waiting for same-student face confirmation.',
                'pending_expires_at' => $expiresAt,
                'student' => [
                    'id' => (int)$student['id'],
                    'name' => $student['name'],
                    'student_id' => $student['student_id'],
                    'email' => $student['email'],
                    'course' => $student['course'] ?? null,
                    'profile_picture' => $student['profile_picture'] ?? null,
                    'status' => $student['status'] ?? 'Active'
                ],
                'timestamp' => $entryTime
            ]);
            exit;
        }

        // ✅ One-time QR consumption (race-safe).
        try {
            $stmt = $pdo->prepare('
                INSERT INTO used_qr_tokens (token_hash, user_id, student_id, used_at, security_guard)
                VALUES (?, ?, ?, NOW(), ?)
            ');
            $stmt->execute([
                $token_hash,
                $student['id'],
                $student['student_id'],
                $_SESSION['security_username'] ?? 'Unknown'
            ]);
        } catch (PDOException $insertEx) {
            if ($insertEx->getCode() === '23000') {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'error' => 'QR_ALREADY_USED',
                    'message' => 'This QR code has already been scanned and verified',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                exit;
            }
            throw $insertEx;
        }

        // Always log raw scan for audit trail parity with RFID/Face.
        $pdo->prepare('INSERT INTO violations (user_id, rfid_uid, scanned_at, violation_type) VALUES (?, ?, NOW(), ?)')
            ->execute([$student['id'], 'QR_CODE', 'forgot_card']);

        // Keep QR log table up to date (for QR-specific reporting).
        $pdo->prepare('
            INSERT INTO qr_entry_logs (user_id, student_id, entry_type, scanned_at, security_guard)
            VALUES (?, ?, "QR_CODE", NOW(), ?)
        ')->execute([
            $student['id'],
            $student['student_id'],
            $_SESSION['security_username'] ?? 'Unknown'
        ]);

        // ACCESS CHECK — deny entry while any unresolved SSO case exists.
        $pendingSsoStmt = $pdo->prepare("SELECT COUNT(*) FROM student_violations WHERE user_id = ? AND status IN ('active', 'pending_reparation')");
        $pendingSsoStmt->execute([$student['id']]);
        $pendingSsoCases = (int)$pendingSsoStmt->fetchColumn();

        if ((int)$student['active_violations_count'] !== $pendingSsoCases) {
            $pdo->prepare('UPDATE users SET active_violations_count = ? WHERE id = ?')
                ->execute([$pendingSsoCases, $student['id']]);
            $student['active_violations_count'] = $pendingSsoCases;
        }

        if ($pendingSsoCases > 0) {
            $pdo->commit();
            echo json_encode([
                'success' => false,
                'access_denied' => true,
                'student' => [
                    'id' => $student['id'],
                    'name' => $student['name'],
                    'student_id' => $student['student_id'],
                    'email' => $student['email'],
                    'course' => $student['course'] ?? null,
                    'violation_count' => (int)$student['violation_count'],
                    'active_violations_count' => (int)$student['active_violations_count'],
                    'gate_mark' => (int)$student['gate_mark_count'],
                    'profile_picture' => $student['profile_picture'] ?? null,
                ],
                'sso_hold_count' => $pendingSsoCases,
                'message' => 'ACCESS DENIED — Student has unresolved SSO compliance. Entry is blocked until SSO clears the case.',
                'severity' => 'blocked',
                'timestamp' => $entryTime
            ]);
            exit;
        }

        $scanToken = issue_security_scan_token(
            $pdo,
            (int)$student['id'],
            'qr',
            isset($_SESSION['security_id']) ? (int)$_SESSION['security_id'] : null,
            (string)($_SESSION['security_username'] ?? 'Unknown'),
            security_scan_guard_session_hash()
        );

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'verified' => true,
            'awaiting_violation_selection' => true,
            'scan_source' => 'qr',
            'violation_selection_token' => $scanToken['token'],
            'violation_selection_expires_at' => $scanToken['expires_at'],
            'student' => [
                'id' => $student['id'],
                'name' => $student['name'],
                'student_id' => $student['student_id'],
                'email' => $student['email'],
                'course' => $student['course'] ?? null,
                'violation_count' => (int)$student['violation_count'],
                'active_violations_count' => (int)$student['active_violations_count'],
                'profile_picture' => $student['profile_picture'] ?? null,
                'status' => $student['status'] ?? 'Active'
            ],
            'message' => 'Student identified. Choose the violation type to record this incident.',
            'timestamp' => $entryTime
        ]);
        exit;
    } catch (\Throwable $txEx) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $txEx;
    }
    
} catch (PDOException $e) {
    error_log('QR scan error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'DATABASE_ERROR',
        'message' => 'A database error occurred',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
