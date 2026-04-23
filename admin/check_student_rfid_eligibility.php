<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_permission('student.update', [
    'actor_role' => 'admin',
    'response' => 'json',
    'message' => 'Forbidden: missing permission student.update.',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$csrfHeader = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$rawBody = get_raw_request_body();
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request payload']);
    exit;
}

$studentCode = trim((string)($data['student_id'] ?? ''));
if ($studentCode === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Student ID is required']);
    exit;
}

if (!preg_match('/^\d{9}$/', $studentCode)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Student ID must be exactly 9 digits (numbers only)']);
    exit;
}

$expectedUserId = filter_var($data['expected_user_id'] ?? null, FILTER_VALIDATE_INT);

try {
    $pdo = pdo();

    $stmt = $pdo->prepare('
        SELECT id, student_id, name, course, status, role, rfid_uid, deleted_at
        FROM users
        WHERE student_id = ? AND role = "Student"
        LIMIT 1
    ');
    $stmt->execute([$studentCode]);
    $student = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$student) {
        // If admin opened registration from a specific student record (usually TEMP-*),
        // allow a brand-new 9-digit ID that is not yet used to proceed.
        if ($expectedUserId) {
            $selectedStmt = $pdo->prepare('SELECT id, student_id, name, course, status, role, rfid_uid, deleted_at FROM users WHERE id = ? AND role = "Student" LIMIT 1');
            $selectedStmt->execute([(int)$expectedUserId]);
            $selectedStudent = $selectedStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$selectedStudent) {
                echo json_encode([
                    'success' => true,
                    'eligible' => false,
                    'code' => 'not_found',
                    'message' => 'Student record not found. Please refresh and try again.'
                ]);
                exit;
            }

            if (!empty($selectedStudent['deleted_at'])) {
                echo json_encode([
                    'success' => true,
                    'eligible' => false,
                    'code' => 'deleted',
                    'message' => 'This student account is archived and cannot be used for RFID registration.'
                ]);
                exit;
            }

            if (($selectedStudent['status'] ?? '') !== 'Active') {
                echo json_encode([
                    'success' => true,
                    'eligible' => false,
                    'code' => 'not_active',
                    'message' => 'Student account is not yet verified/active. Approve the account before RFID registration.'
                ]);
                exit;
            }

            if (!empty($selectedStudent['rfid_uid'])) {
                echo json_encode([
                    'success' => true,
                    'eligible' => false,
                    'code' => 'already_registered',
                    'message' => 'This student already has an RFID card registered.'
                ]);
                exit;
            }

            $selectedStudentCode = (string)($selectedStudent['student_id'] ?? '');
            $isTemporaryStudentCode = strncmp($selectedStudentCode, 'TEMP-', 5) === 0;
            if (!$isTemporaryStudentCode) {
                echo json_encode([
                    'success' => true,
                    'eligible' => false,
                    'code' => 'mismatch',
                    'message' => 'Student ID does not match the selected student record.'
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'eligible' => true,
                'code' => 'eligible_new_id',
                'message' => 'Student ID is available and will replace the temporary ID after registration.',
                'resolved_student_id' => $studentCode,
                'student' => [
                    'id' => (int)$selectedStudent['id'],
                    'student_id' => (string)$selectedStudent['student_id'],
                    'name' => (string)$selectedStudent['name'],
                    'course' => (string)($selectedStudent['course'] ?? ''),
                    'status' => (string)$selectedStudent['status']
                ],
                'account' => [
                    'has_login_account' => true,
                    'is_verified' => true
                ]
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'eligible' => false,
            'code' => 'not_found',
            'message' => 'Student ID not found. Please verify enrollment details first.'
        ]);
        exit;
    }

    if (!empty($student['deleted_at'])) {
        echo json_encode([
            'success' => true,
            'eligible' => false,
            'code' => 'deleted',
            'message' => 'This student account is archived and cannot be used for RFID registration.'
        ]);
        exit;
    }

    // Priority Check 1: See if this student ID is already associated with a registered card.
    // This is important for providing a clear message when an admin accidentally types
    // an ID that belongs to another student who is already registered.
    if (!empty($student['rfid_uid'])) {
        echo json_encode([
            'success' => true,
            'eligible' => false,
            'code' => 'already_registered',
            'message' => 'This student ID is already taken.'
        ]);
        exit;
    }

    // Priority Check 2: If the check was initiated from a specific student's record,
    // ensure the typed ID matches that record. This prevents accidental registrations.
    if ($expectedUserId && (int)$student['id'] !== (int)$expectedUserId) {
        echo json_encode([
            'success' => true,
            'eligible' => false,
            'code' => 'mismatch',
            'message' => 'Student ID does not match the selected student record.'
        ]);
        exit;
    }

    if (($student['status'] ?? '') !== 'Active') {
        echo json_encode([
            'success' => true,
            'eligible' => false,
            'code' => 'not_active',
            'message' => 'Student account is not yet verified/active. Approve the account before RFID registration.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'eligible' => true,
        'code' => 'eligible',
        'message' => 'Student is eligible for RFID registration.',
        'resolved_student_id' => (string)$student['student_id'],
        'student' => [
            'id' => (int)$student['id'],
            'student_id' => (string)$student['student_id'],
            'name' => (string)$student['name'],
            'course' => (string)($student['course'] ?? ''),
            'status' => (string)$student['status']
        ],
        'account' => [
            'has_login_account' => true,
            'is_verified' => true
        ]
    ]);
} catch (\PDOException $e) {
    error_log('RFID eligibility check DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while checking student eligibility']);
}
