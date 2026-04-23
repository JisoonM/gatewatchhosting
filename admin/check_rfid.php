<?php
// admin/check_rfid.php — RFID ID Checker API
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

require_permission('student.update', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission student.update.',
]);

// Verify CSRF token
verify_csrf();

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);
$rfidUid = trim($data['rfid_uid'] ?? '');

if ($rfidUid === '') {
    echo json_encode(['success' => false, 'error' => 'No RFID UID provided']);
    exit;
}

try {
    $pdo = pdo();

    // 1. Look up student by RFID UID in users table
    $stmt = $pdo->prepare("
        SELECT id, student_id, name, email, course, rfid_uid, status,
               violation_count, profile_picture, rfid_registered_at
        FROM users
        WHERE rfid_uid = ? AND role = 'Student'
        LIMIT 1
    ");
    $stmt->execute([$rfidUid]);
    $student = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode([
            'success' => false,
            'error' => 'This RFID UID is not registered to any student in the system.'
        ]);
        exit;
    }

    // 2. Look up rfid_cards record for lost/found status
    $cardInfo = null;
    try {
        $stmt = $pdo->prepare("
            SELECT id, is_lost, lost_at, lost_reason, lost_reported_by, found_at, is_active
            FROM rfid_cards
            WHERE rfid_uid = ? AND user_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$rfidUid, $student['id']]);
        $cardInfo = $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        // rfid_cards table may not have all columns yet — non-fatal
        error_log('check_rfid: rfid_cards query error: ' . $e->getMessage());
    }

    // 3. Get last gate scan date from violations table
    $lastScan = null;
    try {
        $stmt = $pdo->prepare("
            SELECT scanned_at
            FROM violations
            WHERE user_id = ?
            ORDER BY scanned_at DESC
            LIMIT 1
        ");
        $stmt->execute([$student['id']]);
        $lastScan = $stmt->fetchColumn() ?: null;
    } catch (\PDOException $e) {
        error_log('check_rfid: violations query error: ' . $e->getMessage());
    }

    // 4. Return combined result
    echo json_encode([
        'success' => true,
        'student' => [
            'id'                 => $student['id'],
            'student_id'         => $student['student_id'],
            'name'               => $student['name'],
            'email'              => $student['email'],
            'course'             => $student['course'],
            'rfid_uid'           => $student['rfid_uid'],
            'status'             => $student['status'],
            'violation_count'    => (int)$student['violation_count'],
            'profile_picture'    => $student['profile_picture'],
            'rfid_registered_at' => $student['rfid_registered_at']
        ],
        'card' => $cardInfo ? [
            'id'          => (int)$cardInfo['id'],
            'is_lost'     => (int)$cardInfo['is_lost'],
            'lost_at'     => $cardInfo['lost_at'],
            'lost_reason' => $cardInfo['lost_reason'],
            'is_active'   => (int)$cardInfo['is_active']
        ] : null,
        'last_scan' => $lastScan
    ]);

} catch (\PDOException $e) {
    error_log('check_rfid error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
