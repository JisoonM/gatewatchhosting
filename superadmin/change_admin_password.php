<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/encryption_helper.php';
require_once __DIR__ . '/includes/admin_face_helper.php';

header('Content-Type: application/json');
send_api_security_headers();
require_same_origin_api_request();

if (!isset($_SESSION['superadmin_logged_in']) || $_SESSION['superadmin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('admin.update', [
    'actor_role' => 'superadmin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission admin.update.',
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

if (!check_rate_limit('superadmin_change_admin_password', 40, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait before trying again.']);
    exit;
}

if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Face recognition is disabled']);
    exit;
}

/**
 * @throws Exception
 */
function parse_descriptor_array($descriptor): array {
    if (!is_array($descriptor) || count($descriptor) !== 128) {
        throw new Exception('Invalid query descriptor');
    }

    $parsed = [];
    foreach ($descriptor as $idx => $value) {
        if (!is_numeric($value)) {
            throw new Exception("Invalid descriptor value at index $idx");
        }
        $parsed[] = (float)$value;
    }

    return $parsed;
}

/**
 * @throws Exception
 */
function get_password_policy_error(string $password): ?string {
    if (strlen($password) < 12) {
        return 'Password must be at least 12 characters long.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include at least one special character.';
    }

    return null;
}

/**
 * @throws Exception
 */
function compute_min_distance_against_admin(PDO $pdo, int $adminUserId, array $queryDescriptor): ?float {
    ensure_admin_face_tables($pdo);

    $stmt = $pdo->prepare('
        SELECT descriptor_data, descriptor_iv, descriptor_tag
        FROM admin_face_descriptors
        WHERE user_id = ? AND is_active = 1
    ');
    $stmt->execute([$adminUserId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return null;
    }

    $minDistance = PHP_FLOAT_MAX;
    foreach ($rows as $row) {
        $decrypted = decrypt_descriptor(
            (string)$row['descriptor_data'],
            (string)$row['descriptor_iv'],
            (string)$row['descriptor_tag']
        );

        $storedDescriptor = json_decode($decrypted, true);
        if (!is_array($storedDescriptor) || count($storedDescriptor) !== 128) {
            continue;
        }

        $sum = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $diff = $queryDescriptor[$i] - (float)$storedDescriptor[$i];
            $sum += $diff * $diff;
        }

        $distance = sqrt($sum);
        if ($distance < $minDistance) {
            $minDistance = $distance;
        }
    }

    return $minDistance === PHP_FLOAT_MAX ? null : $minDistance;
}

try {
    $input = json_decode(get_raw_request_body(), true);
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $action = strtolower(trim((string)($input['action'] ?? '')));
    $adminUserId = filter_var($input['admin_user_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$adminUserId || $adminUserId <= 0) {
        throw new Exception('Invalid admin user ID');
    }

    $pdo = pdo();

    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND role = 'Admin' LIMIT 1");
    $stmt->execute([$adminUserId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Admin account not found']);
        exit;
    }

    $threshold = (float)env('ADMIN_FACE_MATCH_THRESHOLD', (string)env('FACE_MATCH_THRESHOLD', '0.45'));
    if ($threshold <= 0 || $threshold > 2) {
        $threshold = 0.45;
    }

    $challengeTtl = (int)env('ADMIN_PASSWORD_FACE_VERIFY_TTL_SECONDS', '300');
    if ($challengeTtl < 60 || $challengeTtl > 900) {
        $challengeTtl = 300;
    }

    if (!isset($_SESSION['admin_password_reset_challenges']) || !is_array($_SESSION['admin_password_reset_challenges'])) {
        $_SESSION['admin_password_reset_challenges'] = [];
    }

    if ($action === 'verify_face') {
        $descriptor = parse_descriptor_array($input['query_descriptor'] ?? null);
        $minDistance = compute_min_distance_against_admin($pdo, $adminUserId, $descriptor);

        if ($minDistance === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No enrolled admin face data found for this account']);
            exit;
        }

        if ($minDistance >= $threshold) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Face verification failed for this admin account']);
            exit;
        }

        $resetToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        $_SESSION['admin_password_reset_challenges'][(string)$adminUserId] = [
            'token_hash' => hash('sha256', $resetToken),
            'expires_at' => time() + $challengeTtl,
            'verified_at' => time(),
            'verified_by_superadmin_id' => (int)($_SESSION['superadmin_id'] ?? 0),
        ];

        echo json_encode([
            'success' => true,
            'verified' => true,
            'reset_token' => $resetToken,
            'expires_in_seconds' => $challengeTtl,
            'distance' => round($minDistance, 4),
            'threshold' => round($threshold, 4),
            'message' => 'Face verified successfully.',
        ]);
        exit;
    }

    if ($action === 'change_password') {
        $resetToken = trim((string)($input['reset_token'] ?? ''));
        $newPassword = (string)($input['new_password'] ?? '');
        $confirmPassword = (string)($input['confirm_password'] ?? '');

        if ($resetToken === '' || strlen($resetToken) < 20) {
            throw new Exception('Invalid face verification token');
        }

        if ($newPassword !== $confirmPassword) {
            throw new Exception('Passwords do not match');
        }

        $policyError = get_password_policy_error($newPassword);
        if ($policyError !== null) {
            throw new Exception($policyError);
        }

        $challenge = $_SESSION['admin_password_reset_challenges'][(string)$adminUserId] ?? null;
        if (!is_array($challenge)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Face verification is required before changing password']);
            exit;
        }

        $expectedHash = (string)($challenge['token_hash'] ?? '');
        $providedHash = hash('sha256', $resetToken);
        $expiresAt = (int)($challenge['expires_at'] ?? 0);
        $verifiedBy = (int)($challenge['verified_by_superadmin_id'] ?? 0);
        $currentSuperadmin = (int)($_SESSION['superadmin_id'] ?? 0);

        if ($expectedHash === '' || !hash_equals($expectedHash, $providedHash)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid face verification token']);
            exit;
        }

        if ($expiresAt <= time()) {
            unset($_SESSION['admin_password_reset_challenges'][(string)$adminUserId]);
            http_response_code(410);
            echo json_encode(['success' => false, 'error' => 'Face verification session expired. Verify face again.']);
            exit;
        }

        if ($verifiedBy <= 0 || $verifiedBy !== $currentSuperadmin) {
            unset($_SESSION['admin_password_reset_challenges'][(string)$adminUserId]);
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Verification session owner mismatch']);
            exit;
        }

        $argonHash = password_hash($newPassword, PASSWORD_ARGON2ID);
        if ($argonHash === false) {
            throw new Exception('Password hashing failed');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ? AND role = ? LIMIT 1');
            $stmt->execute([$argonHash, $adminUserId, 'Admin']);

            if ($stmt->rowCount() !== 1) {
                throw new Exception('Unable to update admin password');
            }

            $stmt = $pdo->prepare('SELECT id FROM admin_accounts WHERE user_id = ? LIMIT 1');
            $stmt->execute([$adminUserId]);
            $adminAccountId = $stmt->fetchColumn();

            $stmt = $pdo->prepare('
                INSERT INTO superadmin_audit_log (super_admin_id, action, target_admin_id, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $currentSuperadmin,
                'RESET_ADMIN_PASSWORD',
                $adminAccountId ?: null,
                json_encode([
                    'name' => (string)$admin['name'],
                    'email' => (string)$admin['email'],
                    'reset_method' => 'face_verification',
                ]),
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        unset($_SESSION['admin_password_reset_challenges'][(string)$adminUserId]);

        rotate_csrf_after_critical_action();
        apply_session_isolation_on_privilege_change([
            'target_user_id' => (int)$adminUserId,
            'target_role' => 'admin',
        ]);

        echo json_encode([
            'success' => true,
            'message' => "Password updated successfully for admin '{$admin['name']}'.",
        ]);
        exit;
    }

    throw new Exception('Invalid action');

} catch (Exception $e) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    error_log('change_admin_password error: ' . $e->getMessage());

    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Unable to process admin password change request']);
    }
}
