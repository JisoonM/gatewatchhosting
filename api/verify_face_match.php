<?php

/**
 * API: Server-Side Face Match Verification
 * Endpoint: POST /api/verify_face_match.php
 * 
 * Security: Security guard auth, CSRF protected, rate limited
 * Re-verifies a client-side face match on the server by comparing
 * the query descriptor against the claimed user's stored descriptors.
 * This prevents tampered client-side code from logging false matches.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/encryption_helper.php';
require_once __DIR__ . '/../includes/qr_binding_helper.php';

header('Content-Type: application/json');
send_api_security_headers();
require_same_origin_api_request();

// ---- AUTH CHECK (Security Guard only) ----
if (!isset($_SESSION['security_logged_in']) || $_SESSION['security_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('face.verify', [
    'actor_role' => 'security',
    'response' => 'json',
    'message' => 'Forbidden: missing permission face.verify.',
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---- RATE LIMITING ----
if (!check_rate_limit('face_verify', 120, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests']);
    exit;
}

// ---- FEATURE CHECK ----
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
    $queryDescriptor = $input['query_descriptor'] ?? null;
    $clientConfidence = filter_var($input['confidence'] ?? ($input['confidence_score'] ?? 0), FILTER_VALIDATE_FLOAT);

    if (!$userId || $userId <= 0) {
        throw new Exception('Invalid user ID');
    }

    if (!is_array($queryDescriptor) || count($queryDescriptor) !== 128) {
        throw new Exception('Invalid query descriptor');
    }

    // Validate descriptor values
    foreach ($queryDescriptor as $i => $val) {
        if (!is_numeric($val)) {
            throw new Exception("Invalid descriptor value at index $i");
        }
        $queryDescriptor[$i] = (float) $val;
    }

    $pdo = pdo();
    $threshold = (float) env('FACE_MATCH_THRESHOLD', '0.45');
    $qrBindingEnabled = qr_binding_enabled();

    if ($qrBindingEnabled) {
        ensure_qr_binding_tables($pdo);
        qr_binding_expire_stale_rows($pdo);
    }

    // Fetch stored descriptors for the claimed user
    $stmt = $pdo->prepare("
        SELECT descriptor_data, descriptor_iv, descriptor_tag 
        FROM face_descriptors 
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([$userId]);
    $storedDescriptors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($storedDescriptors)) {
        echo json_encode([
            'success' => true,
            'verified' => false,
            'reason' => 'no_stored_descriptors'
        ]);
        exit;
    }

    // Compare query descriptor against all stored descriptors; accept if the
    // closest one is within threshold (the server is the final gatekeeper).
    $minDistance = PHP_FLOAT_MAX;
    foreach ($storedDescriptors as $stored) {
        $decrypted = decrypt_descriptor(
            $stored['descriptor_data'],
            $stored['descriptor_iv'],
            $stored['descriptor_tag']
        );
        $storedArray = json_decode($decrypted, true);

        if (!is_array($storedArray) || count($storedArray) !== 128) {
            continue;
        }

        // Euclidean distance
        $sum = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $diff = $queryDescriptor[$i] - (float) $storedArray[$i];
            $sum += $diff * $diff;
        }
        $distance = sqrt($sum);

        if ($distance < $minDistance) {
            $minDistance = $distance;
        }
    }

    $verified = $minDistance < $threshold;
    $serverConfidence = max(0, 1 - $minDistance);

    // Log verification attempt for audit trail
    $descriptorHash = hash('sha256', json_encode($queryDescriptor));

    if ($qrBindingEnabled) {
        $pending = qr_binding_get_pending($pdo, qr_guard_session_hash());

        if ($pending) {
            $pendingUserId = (int)$pending['user_id'];
            if ($pendingUserId !== (int)$userId) {
                $pdo->prepare("UPDATE qr_face_pending SET status = 'rejected', reject_reason = 'face_user_mismatch', resolved_at = NOW(), resolved_by_user_id = ? WHERE id = ? AND status = 'pending'")
                    ->execute([(int)$userId, (int)$pending['id']]);

                qr_binding_log_event(
                    $pdo,
                    'suspected_proxy_attempt',
                    $pendingUserId,
                    (string)$pending['student_id'],
                    (string)$pending['challenge_id'],
                    (string)$pending['token_hash'],
                    [
                        'reason' => 'face_user_mismatch',
                        'expected_user_id' => $pendingUserId,
                        'matched_user_id' => (int)$userId,
                        'distance' => round($minDistance, 4)
                    ]
                );

                echo json_encode([
                    'success' => true,
                    'verified' => false,
                    'qr_rejected_proxy' => true,
                    'state' => 'qr_rejected_proxy',
                    'message' => 'Face does not match the student who presented QR. Possible proxy attempt blocked.',
                    'descriptor_hash' => $descriptorHash,
                    'distance' => round($minDistance, 4)
                ]);
                exit;
            }

            if ($verified) {
                qr_binding_log_event(
                    $pdo,
                    'qr_face_match_success',
                    (int)$userId,
                    (string)$pending['student_id'],
                    (string)$pending['challenge_id'],
                    (string)$pending['token_hash'],
                    [
                        'distance' => round($minDistance, 4),
                        'server_confidence' => round($serverConfidence, 4)
                    ]
                );
            }

            echo json_encode([
                'success' => true,
                'verified' => $verified,
                'qr_face_verified' => $verified,
                'state' => $verified ? 'qr_face_verified' : 'qr_pending_face',
                'server_confidence' => round($serverConfidence, 4),
                'client_confidence' => round($clientConfidence, 4),
                'distance' => round($minDistance, 4),
                'descriptor_hash' => $descriptorHash
            ]);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'verified' => $verified,
        'server_confidence' => round($serverConfidence, 4),
        'client_confidence' => round($clientConfidence, 4),
        'distance' => round($minDistance, 4),
        'descriptor_hash' => $descriptorHash
    ]);

} catch (Exception $e) {
    http_response_code(400);
    error_log('verify_face_match error: ' . $e->getMessage());
    if (APP_DEBUG) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid verification request']);
    }
}
