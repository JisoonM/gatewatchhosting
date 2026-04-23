<?php

/**
 * API: Get Face Descriptor Updates (Incremental Sync)
 * Endpoint: GET /api/get_face_updates.php?since_version=N
 * 
 * Security: Security guard OR Admin auth, CSRF protected, rate limited
 * Returns only descriptors changed since the given version number.
 * If since_version is 0 or missing, returns all active descriptors (full load).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/encryption_helper.php';

header('Content-Type: application/json');
send_api_security_headers();
require_same_origin_api_request();

// ---- AUTH CHECK (Security Guard OR Admin) ----
$isSecurityGuard = isset($_SESSION['security_logged_in']) && $_SESSION['security_logged_in'] === true;
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

if (!$isSecurityGuard && !$isAdmin) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$permissionKey = $isSecurityGuard ? 'face.verify' : 'audit.read';
$actorRoleForPermission = $isSecurityGuard ? 'security' : 'admin';
require_permission($permissionKey, [
    'actor_role' => $actorRoleForPermission,
    'response' => 'json',
    'message' => 'Forbidden: missing required permission for face sync access.',
]);

// ---- CSRF CHECK ----
$headers = getallheaders();
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// ---- METHOD CHECK ----
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---- RATE LIMITING ----
if (!check_rate_limit('face_sync', 30, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait.']);
    exit;
}

// ---- FEATURE CHECK ----
if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Face recognition is disabled']);
    exit;
}

try {
    $pdo = pdo();
    $sinceVersion = filter_var($_GET['since_version'] ?? 0, FILTER_VALIDATE_INT);
    if ($sinceVersion === false || $sinceVersion < 0) {
        $sinceVersion = 0;
    }

    // Get current latest version
    $latestVersion = 0;
    $vStmt = $pdo->query("SELECT current_version FROM face_descriptor_version_counter WHERE id = 1");
    if ($vStmt) {
        $latestVersion = (int)$vStmt->fetchColumn();
    }

    // If client is already up to date, return empty delta
    if ($sinceVersion > 0 && $sinceVersion >= $latestVersion) {
        echo json_encode([
            'success'            => true,
            'added'              => [],
            'removed'            => [],
            'latest_version'     => $latestVersion,
            'full_reload_needed' => false,
            'threshold'          => (float)env('FACE_MATCH_THRESHOLD', '0.6')
        ]);
        exit;
    }

    // If since_version is 0, client needs full load — redirect to get_face_descriptors.php logic
    // or if the gap is very large (>1000 changes), recommend full reload
    $gap = $latestVersion - $sinceVersion;
    if ($sinceVersion === 0 || $gap > 1000) {
        // Full load: return all active descriptors
        $stmt = $pdo->prepare("
            SELECT 
                fd.id AS descriptor_id,
                fd.user_id,
                fd.descriptor_data,
                fd.descriptor_iv,
                fd.descriptor_tag,
                fd.label,
                fd.quality_score,
                fd.version,
                u.name,
                u.student_id,
                u.course,
                u.profile_picture,
                u.violation_count
            FROM face_descriptors fd
            INNER JOIN users u ON fd.user_id = u.id
            WHERE fd.is_active = 1
              AND u.role = 'Student'
              AND u.status = 'Active'
                            AND u.deleted_at IS NULL
            ORDER BY fd.user_id, fd.label
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $added = [];
        $decryptionErrors = 0;

        foreach ($rows as $row) {
            try {
                $decryptedJson = decrypt_descriptor(
                    $row['descriptor_data'],
                    $row['descriptor_iv'],
                    $row['descriptor_tag']
                );
                $descriptorArray = json_decode($decryptedJson, true);
                if (!is_array($descriptorArray) || count($descriptorArray) < 1) {
                    $decryptionErrors++;
                    continue;
                }

                $added[] = [
                    'descriptor_id'   => (int)$row['descriptor_id'],
                    'user_id'         => (int)$row['user_id'],
                    'name'            => $row['name'],
                    'student_id'      => $row['student_id'],
                    'course'          => $row['course'],
                    'profile_picture' => $row['profile_picture'],
                    'violation_count' => (int)$row['violation_count'],
                    'label'           => $row['label'],
                    'descriptor'      => $descriptorArray
                ];
            } catch (RuntimeException $e) {
                error_log("Descriptor decryption failed for user {$row['user_id']}: " . $e->getMessage());
                $decryptionErrors++;
            }
        }

        echo json_encode([
            'success'            => true,
            'added'              => $added,
            'removed'            => [],
            'latest_version'     => $latestVersion,
            'full_reload_needed' => true,
            'total'              => count($added),
            'errors'             => $decryptionErrors,
            'threshold'          => (float)env('FACE_MATCH_THRESHOLD', '0.6')
        ]);
        exit;
    }

    // Delta sync: get descriptors changed since the given version
    // This includes both newly added (is_active=1) and deactivated (is_active=0) ones
    $stmt = $pdo->prepare("
        SELECT 
            fd.id AS descriptor_id,
            fd.user_id,
            fd.descriptor_data,
            fd.descriptor_iv,
            fd.descriptor_tag,
            fd.label,
            fd.quality_score,
            fd.is_active,
            fd.version,
            u.name,
            u.student_id,
            u.course,
            u.profile_picture,
            u.violation_count
        FROM face_descriptors fd
        INNER JOIN users u ON fd.user_id = u.id
                WHERE fd.version > ?
                    AND (fd.is_active = 0 OR (u.role = 'Student' AND u.status = 'Active' AND u.deleted_at IS NULL))
        ORDER BY fd.version ASC
    ");
    $stmt->execute([$sinceVersion]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $added = [];
    $removed = [];
    $decryptionErrors = 0;

    foreach ($rows as $row) {
        if ((int)$row['is_active'] === 0) {
            // Descriptor was deactivated — tell client to remove it
            $removed[] = (int)$row['descriptor_id'];
            continue;
        }

        // Active descriptor — decrypt and add
        try {
            $decryptedJson = decrypt_descriptor(
                $row['descriptor_data'],
                $row['descriptor_iv'],
                $row['descriptor_tag']
            );
            $descriptorArray = json_decode($decryptedJson, true);
            if (!is_array($descriptorArray) || count($descriptorArray) < 1) {
                $decryptionErrors++;
                continue;
            }

            $added[] = [
                'descriptor_id'   => (int)$row['descriptor_id'],
                'user_id'         => (int)$row['user_id'],
                'name'            => $row['name'],
                'student_id'      => $row['student_id'],
                'course'          => $row['course'],
                'profile_picture' => $row['profile_picture'],
                'violation_count' => (int)$row['violation_count'],
                'label'           => $row['label'],
                'descriptor'      => $descriptorArray
            ];
        } catch (RuntimeException $e) {
            error_log("Descriptor decryption failed for user {$row['user_id']}: " . $e->getMessage());
            $decryptionErrors++;
        }
    }

    echo json_encode([
        'success'            => true,
        'added'              => $added,
        'removed'            => $removed,
        'latest_version'     => $latestVersion,
        'full_reload_needed' => false,
        'errors'             => $decryptionErrors,
        'threshold'          => (float)env('FACE_MATCH_THRESHOLD', '0.6')
    ]);

} catch (Exception $e) {
    error_log('Get face updates error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error fetching descriptor updates']);
}
