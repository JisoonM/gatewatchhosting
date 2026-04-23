<?php
/**
 * Export Audit Logs — Modern XLSX (Office Open XML)
 * Columns: #, Timestamp, Admin, Action, Student Name, Student ID,
 *          Access Method, Description, Details
 */
ob_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/audit_helper.php';
require_admin_auth();
require_permission('audit.export', [
    'actor_role' => 'admin',
    'response' => 'http',
    'message' => 'Forbidden: missing permission audit.export.',
]);

// ── Input sanitization ───────────────────────────────────────────────────────
$actionType = trim($_GET['action_type'] ?? '');
$dateFrom   = trim($_GET['date_from']   ?? '');
$dateTo     = trim($_GET['date_to']     ?? '');

$allowedActions = [
    'APPROVE_STUDENT', 'DENY_STUDENT', 'REGISTER_RFID', 'UNREGISTER_RFID',
    'MARK_LOST', 'MARK_FOUND', 'UPDATE_STUDENT', 'DELETE_STUDENT',
    'ADD_VIOLATION', 'RESOLVE_VIOLATION', 'RESOLVE_ALL_VIOLATIONS',
    'ASSIGN_REPARATION', 'EXPORT_AUDIT_LOG',
];

// ── Database query ───────────────────────────────────────────────────────────
try {
    $pdo    = pdo();
    $where  = [];
    $params = [];

    if ($actionType !== '' && in_array($actionType, $allowedActions, true)) {
        $where[]  = 'a.action_type = ?';
        $params[] = $actionType;
    }
    if ($dateFrom !== '') {
        $where[]  = 'DATE(a.created_at) >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[]  = 'DATE(a.created_at) <= ?';
        $params[] = $dateTo;
    }

    // Always exclude internal export-tracking entries from the export
    $where[] = "a.action_type != 'EXPORT_AUDIT_LOG'";

    // JOIN users for student-type entries to get school ID + entry method
    $sql = '
        SELECT a.*,
               u.student_id  AS u_school_id,
               u.rfid_uid    AS u_rfid_uid,
               u.face_registered AS u_face_reg
        FROM audit_logs a
        LEFT JOIN users u
               ON a.target_type = \'student\'
              AND a.target_id = u.id'
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY a.created_at DESC LIMIT 5000';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pre-fetch student school IDs for rfid_card entries (details.student_id = DB user id)
    $rfidDbIds = [];
    foreach ($logs as $log) {
        if ($log['target_type'] === 'rfid_card' && !empty($log['details'])) {
            $d = json_decode($log['details'], true);
            $sid = $d['student_id'] ?? null;
            if ($sid !== null && $sid !== '' && is_numeric($sid)) {
                $rfidDbIds[(int)$sid] = true;
            }
        }
    }
    $rfidSchoolIdMap = [];
    if ($rfidDbIds) {
        $ids = array_keys($rfidDbIds);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $st2 = $pdo->prepare("SELECT id, student_id FROM users WHERE id IN ($ph)");
        $st2->execute($ids);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rfidSchoolIdMap[(int)$row['id']] = $row['student_id'];
        }
    }

} catch (Exception $e) {
    error_log('Audit export error: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo 'Export failed: database error.';
    exit;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function xEsc(string $v): string
{
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function actionLabel(string $a): string
{
    return [
        'APPROVE_STUDENT'        => 'Approve Student',
        'DENY_STUDENT'           => 'Deny Student',
        'REGISTER_RFID'          => 'Register RFID',
        'UNREGISTER_RFID'        => 'Unregister RFID',
        'MARK_LOST'              => 'Mark Lost',
        'MARK_FOUND'             => 'Mark Found',
        'UPDATE_STUDENT'         => 'Update Student',
        'DELETE_STUDENT'         => 'Delete Student',
        'ADD_VIOLATION'          => 'Add Violation',
        'RESOLVE_VIOLATION'      => 'Resolve Violation',
        'RESOLVE_ALL_VIOLATIONS' => 'Resolve All Violations',
        'ASSIGN_REPARATION'      => 'Assign Reparation',
        'EXPORT_AUDIT_LOG'       => 'Export Audit Log',
    ][$a] ?? ucwords(strtolower(str_replace('_', ' ', $a)));
}

// XF index map: action type → cell style index
function actionXf(string $a): int
{
    return [
        'APPROVE_STUDENT'        => 5,  // green
        'DENY_STUDENT'           => 6,  // red
        'REGISTER_RFID'          => 8,  // blue
        'UNREGISTER_RFID'        => 7,  // amber
        'MARK_LOST'              => 6,  // red
        'MARK_FOUND'             => 5,  // green
        'UPDATE_STUDENT'         => 8,  // blue
        'DELETE_STUDENT'         => 6,  // red
        'ADD_VIOLATION'          => 6,  // red
        'RESOLVE_VIOLATION'      => 9,  // teal
        'RESOLVE_ALL_VIOLATIONS' => 9,  // teal
        'ASSIGN_REPARATION'      => 7,  // amber
        'EXPORT_AUDIT_LOG'       => 10, // purple
    ][$a] ?? 8;
}

function colLetter(int $idx): string   // 0-based
{
    $s = '';
    $n = $idx;
    while (true) {
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26) - 1;
        if ($n < 0) break;
    }
    return $s;
}

function xlCell(int $col, int $row, $value, int $style): string
{
    $ref = colLetter($col) . $row;
    if ($value === null || $value === '') {
        return '<c r="' . $ref . '" s="' . $style . '"/>';
    }
    if (is_int($value) || is_float($value)) {
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
    }
    $v = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $v = str_replace(["\r\n", "\r", "\n"], '&#10;', $v);
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $v . '</t></is></c>';
}

function formatDetails(?string $json): string
{
    if (!$json) return '—';
    $d = json_decode($json, true);
    if (!is_array($d) || empty($d)) return '—';

    $reparationLabels = [
        'written_apology'       => 'Written Apology Letter',
        'community_service'     => 'Community Service',
        'counseling'            => 'Counseling Session',
        'parent_conference'     => 'Parent/Guardian Conference',
        'suspension_compliance' => 'Suspension Compliance',
        'restitution'           => 'Restitution / Payment',
        'suspension_served'     => 'Suspension Period Served',
        'batch_resolution'      => 'Batch Resolution (All Violations)',
        'other'                 => 'Other',
    ];

    // Human-readable labels matching the "View Details" modal
    $keyLabels = [
        'violation_id'      => 'Violation ID',
        'category_name'     => 'Category Name',
        'category_type'     => 'Category Type',
        'offense_number'    => 'Offense Number',
        'reparation_type'   => 'Reparation Type',
        'reparation_notes'  => 'Reparation Notes',
        'student_id'        => 'Student ID',
        'email'             => 'Email',
        'previous_status'   => 'Previous Status',
        'new_status'        => 'New Status',
        'rfid_uid'          => 'RFID UID',
        'card_id'           => 'Card ID',
        'email_sent'        => 'Email Sent',
        'records_exported'  => 'Records Exported',
        'filters_applied'   => 'Filters Applied',
        'exported_at'       => 'Exported At',
        'filename'          => 'Filename',
    ];

    $lines = [];
    foreach ($d as $key => $val) {
        $label = $keyLabels[$key] ?? strtoupper(str_replace('_', ' ', $key));

        if ($key === 'changes' && is_array($val)) {
            $lines[] = 'Changes:';
            foreach ($val as $field => $change) {
                $fl   = strtoupper(str_replace('_', ' ', $field));
                $from = ($change['from'] ?? null) !== null ? $change['from'] : '—';
                $to   = ($change['to']   ?? null) !== null ? $change['to']   : '—';
                $lines[] = '  ' . $fl . ': ' . $from . ' → ' . $to;
            }
            continue;
        }

        if ($key === 'reparation_type' && is_string($val) && $val !== '') {
            $val = $reparationLabels[$val] ?? ucwords(str_replace('_', ' ', $val));
        }

        if (is_bool($val))       { $val = $val ? 'Yes' : 'No'; }
        elseif (is_array($val))  { $val = json_encode($val); }
        elseif ($val === null || $val === '') { $val = '—'; }

        $lines[] = $label . ': ' . $val;
    }
    return implode("\n", $lines);
}

// ── Resolve row-level derived data ────────────────────────────────────────────
function resolveRow(array $log, array $rfidSchoolIdMap): array
{
    // Student school ID
    $schoolId = '—';
    if (!empty($log['u_school_id'])) {
        $schoolId = $log['u_school_id'];
    } else {
        // For rfid_card entries — look up via pre-fetched map
        $d = !empty($log['details']) ? json_decode($log['details'], true) : null;
        if ($d && isset($d['student_id'])) {
            $sid = $d['student_id'];
            if (is_numeric($sid) && isset($rfidSchoolIdMap[(int)$sid])) {
                $schoolId = $rfidSchoolIdMap[(int)$sid];
            } elseif (!is_numeric($sid) && $sid !== '') {
                // Already a school ID string (e.g., from APPROVE_STUDENT details)
                $schoolId = $sid;
            }
        }
    }

    // Access method
    $type = $log['target_type'] ?? '';
    if ($type === 'rfid_card') {
        $method = 'RFID Card';
    } elseif ($type === 'student') {
        $hasRfid = !empty($log['u_rfid_uid']);
        $hasFace = !empty($log['u_face_reg']);
        if ($hasRfid && $hasFace)   { $method = 'RFID + Face Recognition'; }
        elseif ($hasRfid)           { $method = 'RFID Card'; }
        elseif ($hasFace)           { $method = 'Face Recognition'; }
        else                        { $method = 'None'; }
    } else {
        $method = ucfirst(str_replace('_', ' ', $type));
    }

    return ['school_id' => $schoolId, 'method' => $method];
}

// ── Build summary ─────────────────────────────────────────────────────────────
$exportedAt    = date('F j, Y g:i A');
$filterParts   = [];
if ($actionType !== '') $filterParts[] = 'Action: ' . actionLabel($actionType);
if ($dateFrom   !== '') $filterParts[] = 'From: '   . $dateFrom;
if ($dateTo     !== '') $filterParts[] = 'To: '     . $dateTo;
$filterSummary = $filterParts
    ? implode('   |   ', $filterParts)
    : 'No filters applied — showing all records';
$totalRecords  = count($logs);

// ── XLSX XML builders ──────────────────────────────────────────────────────---

function xl_content_types(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
}

function xl_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

function xl_workbook(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<bookViews><workbookView xWindow="360" yWindow="360" windowWidth="22260" windowHeight="12090"/></bookViews>'
        . '<sheets><sheet name="Audit Log" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
}

function xl_workbook_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
}

function xl_styles(): string
{
    // Helper: solid fill element
    $sf = fn(string $rgb) =>
        '<fill><patternFill patternType="solid"><fgColor rgb="FF' . ltrim($rgb, '#') . '"/><bgColor indexed="64"/></patternFill></fill>';

    // Helper: thin border on all sides
    $tb = fn(string $rgb) =>
        '<border><left style="thin"><color rgb="FF' . ltrim($rgb, '#') . '"/></left>'
        . '<right style="thin"><color rgb="FF' . ltrim($rgb, '#') . '"/></right>'
        . '<top style="thin"><color rgb="FF' . ltrim($rgb, '#') . '"/></top>'
        . '<bottom style="thin"><color rgb="FF' . ltrim($rgb, '#') . '"/></bottom>'
        . '<diagonal/></border>';

    $fonts = [
        /* 0 normal    */ '<font><sz val="11"/><color rgb="FF1e293b"/><name val="Calibri"/></font>',
        /* 1 hdr bold  */ '<font><b/><sz val="11"/><color rgb="FFffffff"/><name val="Calibri"/></font>',
        /* 2 title     */ '<font><b/><sz val="16"/><color rgb="FF0f172a"/><name val="Calibri"/></font>',
        /* 3 subtitle  */ '<font><i/><sz val="10"/><color rgb="FF475569"/><name val="Calibri"/></font>',
        /* 4 gr badge  */ '<font><b/><sz val="10"/><color rgb="FF14532d"/><name val="Calibri"/></font>',
        /* 5 red badge */ '<font><b/><sz val="10"/><color rgb="FF7f1d1d"/><name val="Calibri"/></font>',
        /* 6 amb badge */ '<font><b/><sz val="10"/><color rgb="FF78350f"/><name val="Calibri"/></font>',
        /* 7 blu badge */ '<font><b/><sz val="10"/><color rgb="FF1e3a8a"/><name val="Calibri"/></font>',
        /* 8 teal badge*/ '<font><b/><sz val="10"/><color rgb="FF134e4a"/><name val="Calibri"/></font>',
        /* 9 purp badge*/ '<font><b/><sz val="10"/><color rgb="FF4c1d95"/><name val="Calibri"/></font>',
    ];

    $fills = [
        '<fill><patternFill patternType="none"/></fill>',
        '<fill><patternFill patternType="gray125"/></fill>',
        $sf('ffffff'), // 2 normal row
        $sf('f1f5f9'), // 3 alt row
        $sf('1e40af'), // 4 header bg
        $sf('eff6ff'), // 5 title bg
        $sf('f8fafc'), // 6 subtitle bg
        $sf('dcfce7'), // 7 green badge bg
        $sf('fee2e2'), // 8 red badge bg
        $sf('fef3c7'), // 9 amber badge bg
        $sf('dbeafe'), // 10 blue badge bg
        $sf('ccfbf1'), // 11 teal badge bg
        $sf('ede9fe'), // 12 purple badge bg
    ];

    $borders = [
        '<border><left/><right/><top/><bottom/><diagonal/></border>',
        $tb('e2e8f0'),
        '<border>'
        . '<left style="thin"><color rgb="FF3b82f6"/></left>'
        . '<right style="thin"><color rgb="FF3b82f6"/></right>'
        . '<top style="thin"><color rgb="FF1e3a8a"/></top>'
        . '<bottom style="medium"><color rgb="FF1e3a8a"/></bottom>'
        . '<diagonal/></border>',
    ];

    // cellXfs — each references fontId/fillId/borderId (0-based from arrays above)
    // Index: 0 normal, 1 rows alt, 2 header, 3 title, 4 subtitle,
    //        5 green, 6 red, 7 amber, 8 blue, 9 teal, 10 purple,
    //        11 normal+wrap, 12 alt+wrap
    $xfs = [
        '<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0"><alignment vertical="center"/></xf>',
        '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0"><alignment vertical="center"/></xf>',
        '<xf numFmtId="0" fontId="1" fillId="4" borderId="2" xfId="0"><alignment horizontal="center" vertical="center"/></xf>',
        '<xf numFmtId="0" fontId="2" fillId="5" borderId="0" xfId="0"><alignment vertical="center"/></xf>',
        '<xf numFmtId="0" fontId="3" fillId="6" borderId="0" xfId="0"><alignment vertical="center" wrapText="1"/></xf>',
        '<xf numFmtId="0" fontId="4" fillId="7"  borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>',
        '<xf numFmtId="0" fontId="5" fillId="8"  borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>',
        '<xf numFmtId="0" fontId="6" fillId="9"  borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>',
        '<xf numFmtId="0" fontId="7" fillId="10" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>',
        '<xf numFmtId="0" fontId="8" fillId="11" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>',
        '<xf numFmtId="0" fontId="9" fillId="12" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>',
        '<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0"><alignment vertical="top" wrapText="1"/></xf>',
        '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0"><alignment vertical="top" wrapText="1"/></xf>',
    ];

    $out  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $out .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $out .= '<numFmts count="0"/>';
    $out .= '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>';
    $out .= '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>';
    $out .= '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>';
    $out .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
    $out .= '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>';
    $out .= '</styleSheet>';
    return $out;
}

function xl_sheet(array $logs, array $rfidSchoolIdMap, string $exportedAt, string $filterSummary, int $totalRecords): string
{
    // Columns: A=#, B=Timestamp, C=Admin, D=Action, E=Student Name, F=Student ID, G=Access Method, H=Description, I=Details
    $COLS = 9;
    $lastCol = colLetter($COLS - 1); // I

    $s  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $s .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    // Freeze top 4 rows
    $s .= '<sheetViews><sheetView workbookViewId="0">'
        . '<pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft"/>'
        . '</sheetView></sheetViews>';

    $s .= '<sheetFormatPr defaultRowHeight="18"/>';

    // Column widths (chars): A=6, B=18, C=21, D=22, E=25, F=16, G=22, H=44, I=50
    $widths = [6, 18, 21, 22, 25, 16, 22, 44, 50];
    $s .= '<cols>';
    foreach ($widths as $i => $w) {
        $n = $i + 1;
        $s .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"/>';
    }
    $s .= '</cols>';

    $s .= '<sheetData>';

    // ── Row 1: Title ──────────────────────────────────────────────────────────
    $s .= '<row r="1" ht="32" customHeight="1">';
    $s .= xlCell(0, 1, 'PCU GateWatch - Audit Log Export', 3);
    for ($c = 1; $c < $COLS; $c++) {
        $s .= xlCell($c, 1, '', 3);
    }
    $s .= '</row>';

    // ── Row 2: Metadata ───────────────────────────────────────────────────────
    $meta = 'Exported: ' . $exportedAt
          . '   |   ' . $filterSummary
          . '   |   Total records: ' . $totalRecords;
    $s .= '<row r="2" ht="20" customHeight="1">';
    $s .= xlCell(0, 2, $meta, 4);
    for ($c = 1; $c < $COLS; $c++) {
        $s .= xlCell($c, 2, '', 4);
    }
    $s .= '</row>';

    // ── Row 3: Spacer ─────────────────────────────────────────────────────────
    $s .= '<row r="3" ht="8" customHeight="1"/>';

    // ── Row 4: Column headers ─────────────────────────────────────────────────
    $headers = ['#', 'Timestamp', 'Admin', 'Action', 'Student Name', 'Student ID', 'Access Method', 'Description', 'Details'];
    $s .= '<row r="4" ht="22" customHeight="1">';
    foreach ($headers as $ci => $h) {
        $s .= xlCell($ci, 4, $h, 2);
    }
    $s .= '</row>';

    // ── Data rows ─────────────────────────────────────────────────────────────
    if (empty($logs)) {
        $s .= '<row r="5" ht="20" customHeight="1">';
        $s .= xlCell(0, 5, 'No audit log records found for the selected filters.', 4);
        for ($c = 1; $c < $COLS; $c++) {
            $s .= xlCell($c, 5, '', 4);
        }
        $s .= '</row>';
    } else {
        foreach ($logs as $i => $log) {
            $rowNum   = $i + 5;
            $isEven   = ($i % 2 !== 0);
            $baseXf   = $isEven ? 1 : 0;    // normal / alt
            $wrapXf   = $isEven ? 12 : 11;  // normal_wrap / alt_wrap
            $aXf      = actionXf($log['action_type'] ?? '');

            $resolved = resolveRow($log, $rfidSchoolIdMap);

            $ts = !empty($log['created_at'])
                ? date('M j, Y g:i A', strtotime($log['created_at']))
                : '—';

            $s .= '<row r="' . $rowNum . '">';
            $s .= xlCell(0, $rowNum, $i + 1, $baseXf);
            $s .= xlCell(1, $rowNum, $ts, $baseXf);
            $s .= xlCell(2, $rowNum, $log['admin_name']  ?? 'System', $baseXf);
            $s .= xlCell(3, $rowNum, actionLabel($log['action_type'] ?? ''), $aXf);
            $s .= xlCell(4, $rowNum, $log['target_name'] ?? '—', $baseXf);
            $s .= xlCell(5, $rowNum, $resolved['school_id'], $baseXf);
            $s .= xlCell(6, $rowNum, $resolved['method'], $baseXf);
            $s .= xlCell(7, $rowNum, $log['description'] ?? '', $wrapXf);
            $s .= xlCell(8, $rowNum, formatDetails($log['details'] ?? null), $wrapXf);
            $s .= '</row>';
        }
    }

    $s .= '</sheetData>';

    // Merged cells: title, metadata, spacer rows span all columns
    $s .= '<mergeCells count="2">'
        . '<mergeCell ref="A1:' . $lastCol . '1"/>'
        . '<mergeCell ref="A2:' . $lastCol . '2"/>'
        . '</mergeCells>';

    // Page setup: A4 landscape, fit to 1 page wide
    $s .= '<printOptions headings="0" gridLines="0"/>';
    $s .= '<pageMargins left="0.6" right="0.6" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>';
    $s .= '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9" scale="85"/>';

    $s .= '</worksheet>';
    return $s;
}

// ── Generate XLSX via ZipArchive ──────────────────────────────────────────────
if (!class_exists('ZipArchive')) {
    ob_end_clean();
    http_response_code(500);
    echo 'Export failed: PHP zip extension is not enabled. Please enable extension=zip in php.ini and restart Apache.';
    exit;
}

$tmpFile = tempnam(sys_get_temp_dir(), 'pcu_xlsx_');
$zip = new ZipArchive();

if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    ob_end_clean();
    http_response_code(500);
    echo 'Export failed: cannot create archive.';
    exit;
}

$zip->addFromString('[Content_Types].xml',          xl_content_types());
$zip->addFromString('_rels/.rels',                  xl_rels());
$zip->addFromString('xl/workbook.xml',              xl_workbook());
$zip->addFromString('xl/_rels/workbook.xml.rels',   xl_workbook_rels());
$zip->addFromString('xl/styles.xml',                xl_styles());
$zip->addFromString('xl/worksheets/sheet1.xml',
    xl_sheet($logs, $rfidSchoolIdMap, $exportedAt, $filterSummary, $totalRecords));

$zip->close();

// ── Log the export as an audit entry ─────────────────────────────────────────
$filename = 'AuditLog_' . date('Ymd_His') . '.xlsx';
try {
    logAuditAction(
        $pdo,
        $_SESSION['admin_id']   ?? 0,
        $_SESSION['admin_name'] ?? 'Admin',
        'EXPORT_AUDIT_LOG',
        'audit_log',
        null,
        'Audit Log Export',
        'Exported audit log to Excel (' . $totalRecords . ' records)',
        [
            'filename'         => $filename,
            'records_exported' => $totalRecords,
            'filters_applied'  => $filterSummary,
            'exported_at'      => $exportedAt,
        ]
    );
} catch (Exception $e) {
    error_log('Failed to log audit export: ' . $e->getMessage());
}

// ── Stream download ───────────────────────────────────────────────────────────
ob_end_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: max-age=0');

readfile($tmpFile);
unlink($tmpFile);
