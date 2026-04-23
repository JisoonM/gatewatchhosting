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

require_permission('admin.create', [
    'actor_role' => 'superadmin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission admin.create.',
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

if (!check_rate_limit('superadmin_admin_registration_finalize', 20, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait before trying again.']);
    exit;
}

if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Face recognition is disabled']);
    exit;
}

try {
    $input = json_decode(get_raw_request_body(), true);
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $token = trim((string)($input['token'] ?? ''));
    if ($token === '' || strlen($token) < 20) {
        throw new Exception('Invalid registration token');
    }

    $pdo = pdo();
    ensure_admin_face_tables($pdo);
    ensure_admin_face_registration_tables($pdo);
    admin_face_cleanup_expired_registrations($pdo);

    $stmt = $pdo->prepare('SELECT id, name, email, student_id, password_hash, created_by_superadmin_id FROM admin_face_registration_tokens WHERE token = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([$token]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        http_response_code(410);
        echo json_encode(['success' => false, 'error' => 'Registration token expired or invalid']);
        exit;
    }

    if ((int)$reg['created_by_superadmin_id'] !== (int)($_SESSION['superadmin_id'] ?? 0)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    $required = ['front', 'left', 'right'];
    $stmt = $pdo->prepare('SELECT label, descriptor_data, descriptor_iv, descriptor_tag, quality_score FROM admin_face_registration_faces WHERE token = ?');
    $stmt->execute([$token]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byLabel = [];
    foreach ($rows as $r) {
        $byLabel[strtolower((string)$r['label'])] = $r;
    }

    foreach ($required as $label) {
        if (empty($byLabel[$label])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Face enrollment is incomplete. Capture front, left, and right first.']);
            exit;
        }
    }

    // Safety re-check right before creation:
    // block if staged face matches any active STUDENT descriptor.
    $crossUserBlockThreshold = (float)env('FACE_ENROLL_CROSS_USER_BLOCK_THRESHOLD', (string)env('FACE_MATCH_THRESHOLD', '0.45'));
    if ($crossUserBlockThreshold <= 0 || $crossUserBlockThreshold > 2) {
        $crossUserBlockThreshold = 0.45;
    }

    $queryDescriptors = [];
    foreach ($required as $label) {
        $r = $byLabel[$label];
        $decrypted = decrypt_descriptor(
            (string)$r['descriptor_data'],
            (string)$r['descriptor_iv'],
            (string)$r['descriptor_tag']
        );
        $arr = json_decode($decrypted, true);
        if (!is_array($arr) || count($arr) !== 128) {
            throw new Exception('Invalid staged descriptor');
        }
        $queryDescriptors[$label] = array_map('floatval', $arr);
    }

    $stmt = $pdo->prepare("\
        SELECT fd.user_id, fd.descriptor_data, fd.descriptor_iv, fd.descriptor_tag, u.name, u.student_id\
        FROM face_descriptors fd\
        INNER JOIN users u ON fd.user_id = u.id\
        WHERE fd.is_active = 1\
          AND u.role = 'Student'\
    ");
    $stmt->execute();
    $studentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $closestDistance = PHP_FLOAT_MAX;
    $closestMatch = null;

    foreach ($studentRows as $row) {
        try {
            $decrypted = decrypt_descriptor(
                (string)$row['descriptor_data'],
                (string)$row['descriptor_iv'],
                (string)$row['descriptor_tag']
            );
            $existing = json_decode($decrypted, true);

            if (!is_array($existing) || count($existing) !== 128) {
                continue;
            }

            $existing = array_map('floatval', $existing);

            foreach ($queryDescriptors as $label => $query) {
                $sum = 0.0;
                for ($i = 0; $i < 128; $i++) {
                    $diff = $query[$i] - $existing[$i];
                    $sum += $diff * $diff;
                }
                $distance = sqrt($sum);

                if ($distance < $closestDistance) {
                    $closestDistance = $distance;
                    $closestMatch = $row;
                }
            }
        } catch (RuntimeException $e) {
            continue;
        }
    }

    if ($closestDistance < $crossUserBlockThreshold && is_array($closestMatch)) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'This face is already enrolled to a student account. Registration blocked.',
            'conflict' => [
                'student_name' => (string)($closestMatch['name'] ?? ''),
                'student_id' => (string)($closestMatch['student_id'] ?? ''),
                'distance' => $closestDistance,
                'threshold' => $crossUserBlockThreshold,
            ],
        ]);
        exit;
    }

    $name = (string)$reg['name'];
    $email = (string)$reg['email'];
    $studentId = (string)$reg['student_id'];
    $passwordHash = (string)$reg['password_hash'];

    // Final duplicate checks (race-safe)
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Email already exists']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE student_id = ? LIMIT 1');
    $stmt->execute([$studentId]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Admin ID already exists']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("\
            INSERT INTO users (student_id, name, email, password, role, status, created_at) 
            VALUES (?, ?, ?, ?, 'Admin', 'Active', NOW())
        ");
        $stmt->execute([$studentId, $name, $email, $passwordHash]);
        $newAdminId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("\
            INSERT INTO admin_accounts (user_id, created_by, status, created_at) 
            VALUES (?, ?, 'Active', NOW())
        ");
        $stmt->execute([$newAdminId, (int)$_SESSION['superadmin_id']]);
        $adminAccountId = (int)$pdo->lastInsertId();

        foreach ($required as $label) {
            $r = $byLabel[$label];
            $stmt = $pdo->prepare('INSERT INTO admin_face_descriptors (user_id, descriptor_data, descriptor_iv, descriptor_tag, label, quality_score, registered_by_superadmin_id, is_active, descriptor_dimension) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 128)');
            $stmt->execute([
                $newAdminId,
                (string)$r['descriptor_data'],
                (string)$r['descriptor_iv'],
                (string)$r['descriptor_tag'],
                $label,
                $r['quality_score'] === null ? null : (float)$r['quality_score'],
                (int)($_SESSION['superadmin_id'] ?? 0) ?: null,
            ]);
        }

        // Audit log (no biometrics)
        try {
            $stmt = $pdo->prepare("\
                INSERT INTO superadmin_audit_log (super_admin_id, action, target_admin_id, details, ip_address, user_agent) 
                VALUES (?, 'CREATE_ADMIN', ?, ?, ?, ?)
            ");
            $stmt->execute([
                (int)$_SESSION['superadmin_id'],
                $adminAccountId,
                json_encode(['name' => $name, 'email' => $email, 'admin_id' => $studentId, 'face_enrolled' => true]),
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
        } catch (Throwable $e) {
            // Ignore audit failures.
        }

        // Cleanup staging
        $pdo->prepare('DELETE FROM admin_face_registration_faces WHERE token = ?')->execute([$token]);
        $pdo->prepare('DELETE FROM admin_face_registration_tokens WHERE token = ?')->execute([$token]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    rotate_csrf_after_critical_action();
    apply_session_isolation_on_privilege_change([
        'target_user_id' => (int)$newAdminId,
        'target_role' => 'admin',
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Admin account for '{$name}' has been created successfully.",
        'admin_user_id' => (int)$newAdminId,
        'admin_account_id' => (int)$adminAccountId,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    error_log('finalize_admin_registration error: ' . $e->getMessage());
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Unable to finalize admin registration']);
    }
}
