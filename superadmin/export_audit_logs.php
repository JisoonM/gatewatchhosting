<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';

require_superadmin_auth();
send_security_headers();

require_permission('audit.export', [
    'actor_role' => 'superadmin',
    'response' => 'http',
    'message' => 'Forbidden: missing permission audit.export.',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

$csrfToken = trim((string)($_GET['csrf_token'] ?? ''));
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo 'Invalid security token';
    exit;
}

$allowedActions = [
    'APPROVE_STUDENT', 'DENY_STUDENT', 'REGISTER_RFID', 'UNREGISTER_RFID',
    'MARK_LOST', 'MARK_FOUND', 'UPDATE_STUDENT', 'ARCHIVE_STUDENT', 'DELETE_STUDENT',
    'ADD_VIOLATION', 'RESOLVE_VIOLATION', 'RESOLVE_ALL_VIOLATIONS',
    'ASSIGN_REPARATION', 'EXPORT_AUDIT_LOG',
];

$actionType = trim((string)($_GET['action_type'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$isValidDate = static function (string $value): bool {
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $value));
    return checkdate($m, $d, $y);
};

if ($actionType !== '' && !in_array($actionType, $allowedActions, true)) {
    http_response_code(422);
    echo 'Invalid action type';
    exit;
}
if ($dateFrom !== '' && !$isValidDate($dateFrom)) {
    http_response_code(422);
    echo 'Invalid date_from';
    exit;
}
if ($dateTo !== '' && !$isValidDate($dateTo)) {
    http_response_code(422);
    echo 'Invalid date_to';
    exit;
}

function action_label(string $actionType): string
{
    return [
        'APPROVE_STUDENT' => 'Approve Student',
        'DENY_STUDENT' => 'Deny Student',
        'REGISTER_RFID' => 'Register RFID',
        'UNREGISTER_RFID' => 'Unregister RFID',
        'MARK_LOST' => 'Mark Lost',
        'MARK_FOUND' => 'Mark Found',
        'UPDATE_STUDENT' => 'Update Student',
        'ARCHIVE_STUDENT' => 'Archive Student',
        'DELETE_STUDENT' => 'Delete Student',
        'ADD_VIOLATION' => 'Add Violation',
        'RESOLVE_VIOLATION' => 'Resolve Violation',
        'RESOLVE_ALL_VIOLATIONS' => 'Resolve All Violations',
        'ASSIGN_REPARATION' => 'Assign Reparation',
        'EXPORT_AUDIT_LOG' => 'Export Audit Log',
    ][$actionType] ?? ucwords(strtolower(str_replace('_', ' ', $actionType)));
}

function format_details(?string $json): string
{
    if (!$json) {
        return 'N/A';
    }
    $details = json_decode($json, true);
    if (!is_array($details) || empty($details)) {
        return 'N/A';
    }

    $reparationLabels = [
        'written_apology' => 'Written Apology Letter',
        'community_service' => 'Community Service',
        'counseling' => 'Counseling Session',
        'parent_conference' => 'Parent/Guardian Conference',
        'suspension_compliance' => 'Suspension Compliance',
        'restitution' => 'Restitution / Payment',
        'suspension_served' => 'Suspension Period Served',
        'batch_resolution' => 'Batch Resolution (All Violations)',
        'other' => 'Other',
    ];

    $keyLabels = [
        'violation_id' => 'Violation ID',
        'category_name' => 'Category Name',
        'category_type' => 'Category Type',
        'offense_number' => 'Offense Number',
        'reparation_type' => 'Reparation Type',
        'reparation_notes' => 'Reparation Notes',
        'student_id' => 'Student ID',
        'email' => 'Email',
        'previous_status' => 'Previous Status',
        'new_status' => 'New Status',
        'rfid_uid' => 'RFID UID',
        'card_id' => 'Card ID',
        'email_sent' => 'Email Sent',
        'records_exported' => 'Records Exported',
        'filters_applied' => 'Filters Applied',
        'exported_at' => 'Exported At',
        'filename' => 'Filename',
    ];

    $lines = [];
    foreach ($details as $key => $value) {
        $label = $keyLabels[$key] ?? strtoupper(str_replace('_', ' ', (string)$key));

        if ($key === 'changes' && is_array($value)) {
            $changeParts = [];
            foreach ($value as $field => $change) {
                $from = $change['from'] ?? 'N/A';
                $to = $change['to'] ?? 'N/A';
                $fieldLabel = ucwords(str_replace('_', ' ', (string)$field));
                $changeParts[] = $fieldLabel . ' (' . $from . ' => ' . $to . ')';
            }
            $lines[] = 'Changes: ' . implode('; ', $changeParts);
            continue;
        }

        if ($key === 'reparation_type' && is_string($value) && $value !== '') {
            $value = $reparationLabels[$value] ?? ucwords(str_replace('_', ' ', $value));
        }

        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        } elseif (is_array($value)) {
            $value = json_encode($value);
        } elseif ($value === null || $value === '') {
            $value = 'N/A';
        }

        $lines[] = $label . ': ' . $value;
    }

    return implode('; ', $lines);
}

function sanitize_csv_cell($value): string
{
    if ($value === null) {
        return 'N/A';
    }

    if (is_bool($value)) {
        $value = $value ? 'Yes' : 'No';
    } elseif (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $text = trim((string)$value);
    $text = preg_replace('/\s+/u', ' ', $text ?? '') ?? '';
    if ($text === '') {
        return 'N/A';
    }

    // Prevent CSV formula injection when opened in spreadsheet apps.
    if (preg_match('/^[=\-+@]/', $text) === 1) {
        $text = "'" . $text;
    }

    // Preserve numeric identifiers with leading zeros / very long numbers in Excel.
    if (preg_match('/^\d+$/', $text) === 1) {
        if ((strlen($text) > 1 && $text[0] === '0') || strlen($text) >= 15) {
            $text = "'" . $text;
        }
    }

    return $text;
}

function parse_detail_fields(?string $json): array
{
    $detail = [
        'violation_id' => 'N/A',
        'category_name' => 'N/A',
        'category_type' => 'N/A',
        'offense_number' => 'N/A',
        'reparation_type' => 'N/A',
        'previous_status' => 'N/A',
        'new_status' => 'N/A',
        'rfid_uid' => 'N/A',
        'card_id' => 'N/A',
        'email' => 'N/A',
        'email_sent' => 'N/A',
        'notes' => 'N/A',
    ];

    if (!$json) {
        return $detail;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $detail;
    }

    $detail['violation_id'] = (string)($data['violation_id'] ?? 'N/A');
    $detail['category_name'] = (string)($data['category_name'] ?? 'N/A');
    $detail['category_type'] = (string)($data['category_type'] ?? 'N/A');
    $detail['offense_number'] = (string)($data['offense_number'] ?? 'N/A');
    $detail['previous_status'] = (string)($data['previous_status'] ?? ($data['status'] ?? 'N/A'));
    $detail['new_status'] = (string)($data['new_status'] ?? 'N/A');
    $detail['rfid_uid'] = (string)($data['rfid_uid'] ?? 'N/A');
    $detail['card_id'] = (string)($data['card_id'] ?? 'N/A');
    $detail['email'] = (string)($data['email'] ?? 'N/A');
    $detail['email_sent'] = isset($data['email_sent']) ? ((bool)$data['email_sent'] ? 'Yes' : 'No') : 'N/A';

    $reparationMap = [
        'written_apology' => 'Written Apology Letter',
        'community_service' => 'Community Service',
        'counseling' => 'Counseling Session',
        'parent_conference' => 'Parent/Guardian Conference',
        'suspension_compliance' => 'Suspension Compliance',
        'restitution' => 'Restitution / Payment',
        'suspension_served' => 'Suspension Period Served',
        'batch_resolution' => 'Batch Resolution (All Violations)',
        'other' => 'Other',
    ];
    $rep = (string)($data['reparation_type'] ?? 'N/A');
    $detail['reparation_type'] = $reparationMap[$rep] ?? $rep;

    $notes = [];
    if (!empty($data['reparation_notes'])) {
        $notes[] = 'Reparation Notes: ' . (string)$data['reparation_notes'];
    }
    if (!empty($data['description'])) {
        $notes[] = 'Description: ' . (string)$data['description'];
    }
    if (!empty($data['changes']) && is_array($data['changes'])) {
        $changeNotes = [];
        foreach ($data['changes'] as $field => $change) {
            $from = $change['from'] ?? 'N/A';
            $to = $change['to'] ?? 'N/A';
            $changeNotes[] = ucwords(str_replace('_', ' ', (string)$field)) . ' (' . $from . ' => ' . $to . ')';
        }
        if ($changeNotes) {
            $notes[] = 'Changes: ' . implode('; ', $changeNotes);
        }
    }
    if ($notes) {
        $detail['notes'] = implode(' | ', $notes);
    }

    foreach ($detail as $key => $value) {
        $detail[$key] = sanitize_csv_cell($value);
    }

    return $detail;
}

function build_status_flow(array $detail): string
{
    $previous = trim((string)($detail['previous_status'] ?? 'N/A'));
    $next = trim((string)($detail['new_status'] ?? 'N/A'));
    if ($previous !== '' && $previous !== 'N/A' && $next !== '' && $next !== 'N/A') {
        return sanitize_csv_cell($previous . ' -> ' . $next);
    }
    return 'N/A';
}

function build_reference_ids(array $detail): string
{
    $parts = [];
    if (($detail['violation_id'] ?? 'N/A') !== 'N/A') {
        $parts[] = 'Violation #' . $detail['violation_id'];
    }
    if (($detail['card_id'] ?? 'N/A') !== 'N/A') {
        $parts[] = 'Card #' . $detail['card_id'];
    }
    if (($detail['rfid_uid'] ?? 'N/A') !== 'N/A') {
        $parts[] = 'RFID ' . $detail['rfid_uid'];
    }
    return $parts ? sanitize_csv_cell(implode(' | ', $parts)) : 'N/A';
}

function build_additional_details(?string $json): string
{
    if (!$json) {
        return 'N/A';
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data)) {
        return 'N/A';
    }

    $coveredKeys = [
        'violation_id',
        'category_name',
        'category_type',
        'offense_number',
        'reparation_type',
        'reparation_notes',
        'previous_status',
        'new_status',
        'status',
        'rfid_uid',
        'card_id',
        'email',
        'email_sent',
        'description',
        'changes',
    ];

    $parts = [];
    foreach ($data as $key => $value) {
        if (in_array((string)$key, $coveredKeys, true)) {
            continue;
        }

        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($value === null || $value === '') {
            $value = 'N/A';
        }

        $label = ucwords(str_replace('_', ' ', (string)$key));
        $parts[] = $label . ': ' . (string)$value;
    }

    if (empty($parts)) {
        return 'N/A';
    }

    return sanitize_csv_cell(implode(' | ', $parts));
}

function csv_write_row($stream, array $row, string $delimiter = ','): void
{
    $encoded = [];
    foreach ($row as $cell) {
        $text = (string)$cell;
        $text = str_replace('"', '""', $text);
        $encoded[] = '"' . $text . '"';
    }
    fwrite($stream, implode($delimiter, $encoded) . "\r\n");
}

function resolve_row(array $log, array $rfidSchoolIdMap): array
{
    $schoolId = '-';

    if (!empty($log['u_school_id'])) {
        $schoolId = (string)$log['u_school_id'];
    } else {
        $details = !empty($log['details']) ? json_decode($log['details'], true) : null;
        if (is_array($details) && isset($details['student_id'])) {
            $sid = $details['student_id'];
            if (is_numeric($sid) && isset($rfidSchoolIdMap[(int)$sid])) {
                $schoolId = (string)$rfidSchoolIdMap[(int)$sid];
            } elseif (!is_numeric($sid) && $sid !== '') {
                $schoolId = (string)$sid;
            }
        }
    }

    $targetType = (string)($log['target_type'] ?? '');
    if ($targetType === 'rfid_card') {
        $method = 'RFID Card';
    } elseif ($targetType === 'student') {
        $hasRfid = !empty($log['u_rfid_uid']);
        $hasFace = !empty($log['u_face_reg']);
        if ($hasRfid && $hasFace) {
            $method = 'RFID + Face Recognition';
        } elseif ($hasRfid) {
            $method = 'RFID Card';
        } elseif ($hasFace) {
            $method = 'Face Recognition';
        } else {
            $method = 'None';
        }
    } else {
        $method = ucfirst(str_replace('_', ' ', $targetType));
    }

    return ['school_id' => $schoolId, 'method' => $method];
}

try {
    $pdo = pdo();
    $where = ["a.action_type != 'EXPORT_AUDIT_LOG'"];
    $params = [];

    if ($actionType !== '') {
        $where[] = 'a.action_type = ?';
        $params[] = $actionType;
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(a.created_at) >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(a.created_at) <= ?';
        $params[] = $dateTo;
    }

    $sql = '
        SELECT a.*,
               u.student_id AS u_school_id,
               u.rfid_uid AS u_rfid_uid,
               u.face_registered AS u_face_reg
        FROM audit_logs a
        LEFT JOIN users u
               ON a.target_type = "student"
              AND a.target_id = u.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY a.created_at DESC
        LIMIT 5000';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rfidDbIds = [];
    foreach ($logs as $log) {
        if (($log['target_type'] ?? '') === 'rfid_card' && !empty($log['details'])) {
            $details = json_decode($log['details'], true);
            $sid = $details['student_id'] ?? null;
            if ($sid !== null && $sid !== '' && is_numeric($sid)) {
                $rfidDbIds[(int)$sid] = true;
            }
        }
    }

    $rfidSchoolIdMap = [];
    if ($rfidDbIds) {
        $ids = array_keys($rfidDbIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $mapStmt = $pdo->prepare("SELECT id, student_id FROM users WHERE id IN ($placeholders)");
        $mapStmt->execute($ids);
        foreach ($mapStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rfidSchoolIdMap[(int)$row['id']] = (string)$row['student_id'];
        }
    }
} catch (Throwable $e) {
    error_log('Superadmin audit export query error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Export failed: database error';
    exit;
}

$filename = 'AuditLog_' . date('Ymd_His') . '.csv';
$exportedAt = date('F j, Y g:i A');
$filterParts = [];
if ($actionType !== '') {
    $filterParts[] = 'Action=' . action_label($actionType);
}
if ($dateFrom !== '') {
    $filterParts[] = 'From=' . $dateFrom;
}
if ($dateTo !== '') {
    $filterParts[] = 'To=' . $dateTo;
}
$filterSummary = $filterParts ? implode(' | ', $filterParts) : 'No filters applied';
$totalRecords = count($logs);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
if ($out === false) {
    http_response_code(500);
    echo 'Export failed: unable to open output stream';
    exit;
}

// UTF-8 BOM for better Excel compatibility.
fwrite($out, "\xEF\xBB\xBF");
csv_write_row($out, [
    '#',
    'Date',
    'Time',
    'Admin',
    'Action',
    'Target Type',
    'Student Name',
    'Student ID',
    'Access Method',
    'Summary',
    'Status Flow',
    'Reference IDs',
    'Notes',
    'Additional Details',
    'Exported At'
]);

foreach ($logs as $i => $log) {
    $resolved = resolve_row($log, $rfidSchoolIdMap);
    $dateStr = 'N/A';
    $timeStr = 'N/A';
    if (!empty($log['created_at'])) {
        $ts = strtotime((string)$log['created_at']);
        if ($ts !== false) {
            $dateStr = date('Y-m-d', $ts);
            $timeStr = date('h:i:s A', $ts);
        }
    }
    $detailFields = parse_detail_fields($log['details'] ?? null);
    $summary = sanitize_csv_cell($log['description'] ?? 'N/A');
    $statusFlow = build_status_flow($detailFields);
    $referenceIds = build_reference_ids($detailFields);
    $notes = sanitize_csv_cell($detailFields['notes'] ?? 'N/A');
    $additionalDetails = build_additional_details($log['details'] ?? null);

    csv_write_row($out, [
        sanitize_csv_cell($i + 1),
        sanitize_csv_cell("'" . $dateStr),
        sanitize_csv_cell("'" . $timeStr),
        sanitize_csv_cell($log['admin_name'] ?? 'System'),
        sanitize_csv_cell(action_label((string)($log['action_type'] ?? ''))),
        sanitize_csv_cell(ucfirst(str_replace('_', ' ', (string)($log['target_type'] ?? 'N/A')))),
        sanitize_csv_cell($log['target_name'] ?? 'N/A'),
        sanitize_csv_cell($resolved['school_id']),
        sanitize_csv_cell($resolved['method']),
        $summary,
        $statusFlow,
        $referenceIds,
        $notes,
        $additionalDetails,
        sanitize_csv_cell($exportedAt),
    ]);
}

csv_write_row($out, []);
csv_write_row($out, ['Filters Applied', sanitize_csv_cell($filterSummary)]);
csv_write_row($out, ['Total Records', sanitize_csv_cell((string)$totalRecords)]);

fclose($out);

try {
    logAuditAction(
        $pdo,
        (int)($_SESSION['superadmin_id'] ?? 0),
        (string)($_SESSION['superadmin_name'] ?? 'Super Admin'),
        'EXPORT_AUDIT_LOG',
        'audit_log',
        null,
        'Audit Log Export',
        'Exported audit log to CSV (' . $totalRecords . ' records)',
        [
            'filename' => $filename,
            'records_exported' => $totalRecords,
            'filters_applied' => $filterSummary,
            'exported_at' => $exportedAt,
            'exported_by_role' => 'superadmin',
        ]
    );
} catch (Throwable $e) {
    error_log('Superadmin audit export logging error: ' . $e->getMessage());
}
