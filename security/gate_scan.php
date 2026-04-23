<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security_scan_token_helper.php';

header('Content-Type: application/json');
ob_start(); // Enable output buffering for deferred email sending

// Security: Prevent caching of sensitive data
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Check if security guard is logged in
if (!isset($_SESSION['security_logged_in']) || $_SESSION['security_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

require_permission('gate.scan.rfid', [
    'actor_role' => 'security',
    'response' => 'json',
    'message' => 'Forbidden: missing permission gate.scan.rfid.',
]);

// Read input once and reuse
$data = json_decode(file_get_contents('php://input'), true);

// CSRF Protection - Validate CSRF token for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $providedToken = $data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    
    if (empty($sessionToken) || !hash_equals($sessionToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// Receive RFID tap from scanner
$rfid_uid = trim($data['rfid_uid'] ?? '');

if (!$rfid_uid) {
    echo json_encode(['success' => false, 'error' => 'No RFID UID provided']);
    exit;
}

// Helper: flush JSON response to client immediately, then continue for background work
function flushJsonResponse($data) {
    $json = json_encode($data);
    ignore_user_abort(true);
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    while (ob_get_level() > 0) { ob_end_flush(); }
    echo $json;
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

try {
    $pdo = pdo();
    ensure_security_scan_tokens_table($pdo);
    
    // Find student with this RFID card - single fast query with LIMIT 1
    $stmt = $pdo->prepare('
        SELECT id, student_id, name, email, rfid_uid, violation_count, profile_picture, course
        FROM users 
        WHERE rfid_uid = ? AND role = "Student"
        LIMIT 1
    ');
    $stmt->execute([$rfid_uid]);
    $student = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode([
            'success' => false, 
            'error' => 'Unknown RFID card',
            'message' => 'This card is not registered in the system'
        ]);
        exit;
    }
    
    // 🔍 PHASE 1: CHECK IF RFID CARD IS MARKED AS LOST
    $lostCard = is_rfid_lost($rfid_uid);
    if ($lostCard) {
        echo json_encode([
            'success' => false,
            'is_lost' => true,
            'error' => 'RFID CARD REPORTED AS LOST',
            'student' => [
                'id' => $student['id'],
                'name' => $student['name'],
                'student_id' => $student['student_id']
            ],
            'lost_info' => [
                'lost_at' => $lostCard['lost_at'],
                'lost_reason' => $lostCard['lost_reason'],
                'reported_by' => $lostCard['reported_by_name'] ?? ''
            ],
            'message' => 'This RFID card has been reported as LOST. Please contact the administration office.',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    // Re-fetch student row so we always have gate_mark_count
    $stFull = $pdo->prepare('
        SELECT id, student_id, name, email, rfid_uid,
               COALESCE(violation_count, 0)        AS violation_count,
               COALESCE(active_violations_count, 0) AS active_violations_count,
               COALESCE(gate_mark_count, 0)         AS gate_mark_count,
               profile_picture, course
        FROM users WHERE id = ? LIMIT 1
    ');
    $stFull->execute([$student['id']]);
    $student = $stFull->fetch(\PDO::FETCH_ASSOC);

    $entryTime   = date('Y-m-d H:i:s');

    // ✅ Always log the raw scan in the legacy violations table (audit trail)
    $pdo->prepare('INSERT INTO violations (user_id, rfid_uid, scanned_at) VALUES (?, ?, NOW())')
        ->execute([$student['id'], $rfid_uid]);

    // ──────────────────────────────────────────────────────────────────────
    // ACCESS CHECK — deny entry if student has any unresolved SSO case.
    // Under direct-offense policy, any active/pending case keeps entry blocked
    // until the case is resolved by admin/SSO.
    // ──────────────────────────────────────────────────────────────────────
    // Treat every non-apprehended violation as unresolved and entry-blocking.
    $pendingSsoStmt = $pdo->prepare("SELECT COUNT(*) FROM student_violations WHERE user_id = ? AND status <> 'apprehended'");
    $pendingSsoStmt->execute([$student['id']]);
    $pendingSsoCases = (int)$pendingSsoStmt->fetchColumn();

    // Keep cached counter aligned in case previous updates/triggers drifted.
    if ((int)$student['active_violations_count'] !== $pendingSsoCases) {
        $pdo->prepare('UPDATE users SET active_violations_count = ? WHERE id = ?')
            ->execute([$pendingSsoCases, $student['id']]);
        $student['active_violations_count'] = $pendingSsoCases;
    }

    if ($pendingSsoCases > 0) {
        flushJsonResponse([
            'success'      => false,
            'access_denied' => true,
            'student' => [
                'id'                      => $student['id'],
                'name'                    => $student['name'],
                'student_id'              => $student['student_id'],
                'email'                   => $student['email'],
                'violation_count'         => (int)$student['violation_count'],
                'active_violations_count' => (int)$student['active_violations_count'],
                'profile_picture'         => $student['profile_picture'] ?? null,
                'course'                  => $student['course'] ?? null,
            ],
            'sso_hold_count' => $pendingSsoCases,
            'message'   => 'ACCESS DENIED - Student has an active/pending violation task assigned by admin. RFID entry remains blocked until the case is resolved.',
            'severity'  => 'blocked',
            'timestamp' => $entryTime,
        ]);
        exit;
    }

    $scanToken = issue_security_scan_token(
        $pdo,
        (int)$student['id'],
        'rfid',
        isset($_SESSION['security_id']) ? (int)$_SESSION['security_id'] : null,
        (string)($_SESSION['security_username'] ?? 'Unknown'),
        security_scan_guard_session_hash()
    );

    flushJsonResponse([
        'success'                      => true,
        'awaiting_violation_selection' => true,
        'scan_source'                  => 'rfid',
        'violation_selection_token'    => $scanToken['token'],
        'violation_selection_expires_at' => $scanToken['expires_at'],
        'student' => [
            'id'                      => $student['id'],
            'name'                    => $student['name'],
            'student_id'              => $student['student_id'],
            'email'                   => $student['email'],
            'rfid_uid'                => $rfid_uid,
            'violation_count'         => (int)$student['violation_count'],
            'active_violations_count' => (int)$student['active_violations_count'],
            'profile_picture'         => $student['profile_picture'] ?? null,
            'course'                  => $student['course'] ?? null,
        ],
        'message'   => 'Student identified. Choose the violation type to record this incident.',
        'timestamp' => $entryTime,
    ]);
    exit;
    
} catch (\PDOException $e) {
    error_log('Gate scan error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'A server error occurred. Please try again or contact support.'
    ]);
}

