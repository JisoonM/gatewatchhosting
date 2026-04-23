<?php

// Start the session and include database connection
require_once __DIR__ . '/../db.php';

require_admin_auth();

$page_title = 'Student Management';

// Get all students
try {
    $pdo = pdo();
    
    // Get all students WITHOUT registered RFID cards (for Student Management panel)
    // Only show Active students (exclude Pending students awaiting verification)
    $query = '
        SELECT id, student_id, name, email, course, status, role, rfid_uid, rfid_registered_at, profile_picture
        FROM users 
        WHERE role = "Student" AND rfid_uid IS NULL AND status = "Active" AND deleted_at IS NULL
        ORDER BY created_at DESC
    ';
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $students = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    // Get ALL students including those with RFID (for Registered Cards panel)
    // Only show Active students (exclude Pending students awaiting verification)
    $hasGuardianTables = false;
    try {
        $hasStudentGuardians = (bool)$pdo->query("SHOW TABLES LIKE 'student_guardians'")->fetch();
        $hasGuardians = (bool)$pdo->query("SHOW TABLES LIKE 'guardians'")->fetch();
        $hasGuardianTables = $hasStudentGuardians && $hasGuardians;
    } catch (\PDOException $e) {
        $hasGuardianTables = false;
    }

    if ($hasGuardianTables) {
        $queryAll = '
            SELECT u.id, u.student_id, u.name, u.email, u.course, u.status, u.role, u.rfid_uid, u.rfid_registered_at, u.profile_picture, u.face_registered, u.face_registered_at,
                   COALESCE((
                        SELECT TRIM(CONCAT(COALESCE(g.first_name, ""), " ", COALESCE(g.last_name, "")))
                        FROM student_guardians sg
                        JOIN guardians g ON g.id = sg.guardian_id
                        WHERE sg.student_id = u.id
                        ORDER BY sg.is_primary DESC, sg.id ASC
                        LIMIT 1
                   ), "") AS guardian_name,
                   COALESCE((
                        SELECT COALESCE(g.phone_number, "")
                        FROM student_guardians sg
                        JOIN guardians g ON g.id = sg.guardian_id
                        WHERE sg.student_id = u.id
                        ORDER BY sg.is_primary DESC, sg.id ASC
                        LIMIT 1
                   ), "") AS guardian_contact
            FROM users u
            WHERE u.role = "Student" AND u.status = "Active" AND u.deleted_at IS NULL
            ORDER BY u.created_at DESC
        ';
    } else {
        $queryAll = '
            SELECT id, student_id, name, email, course, status, role, rfid_uid, rfid_registered_at, profile_picture, face_registered, face_registered_at,
                   "" AS guardian_name,
                   "" AS guardian_contact
            FROM users 
            WHERE role = "Student" AND status = "Active" AND deleted_at IS NULL
            ORDER BY created_at DESC
        ';
    }
    $stmtAll = $pdo->prepare($queryAll);
    $stmtAll->execute();
    $allStudents = $stmtAll->fetchAll(\PDO::FETCH_ASSOC);
    
    // Get registered cards count (only Active students)
    $stmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "Student" AND rfid_uid IS NOT NULL AND status = "Active" AND deleted_at IS NULL');
    $registeredCount = $stmt->fetchColumn();
    
    // Auto-populate rfid_cards table with existing RFID registrations
    // Use a safer approach to avoid trigger conflicts
    try {
        // First, get all users who need entries in rfid_cards
        $stmt = $pdo->query("
            SELECT u.id, u.rfid_uid, u.rfid_registered_at
            FROM users u
            LEFT JOIN rfid_cards rc ON u.id = rc.user_id
            WHERE u.role = 'Student' 
            AND u.deleted_at IS NULL
            AND u.rfid_uid IS NOT NULL 
            AND rc.user_id IS NULL
        ");
        $missingCards = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Insert each missing entry individually to avoid trigger conflicts
        if (!empty($missingCards)) {
            $insertStmt = $pdo->prepare("
                INSERT INTO rfid_cards (user_id, rfid_uid, registered_at, status)
                VALUES (?, ?, ?, 'active')
            ");
            
            foreach ($missingCards as $card) {
                try {
                    $insertStmt->execute([
                        $card['id'],
                        $card['rfid_uid'],
                        $card['rfid_registered_at'] ?? date('Y-m-d H:i:s')
                    ]);
                } catch (\PDOException $e) {
                    // Skip duplicates or conflicts
                    error_log("Skipping rfid_cards insert for user {$card['id']}: " . $e->getMessage());
                }
            }
        }
    } catch (\PDOException $e) {
        // Log but don't fail - table population is optional
        error_log("Failed to auto-populate rfid_cards: " . $e->getMessage());
    }
    
    // Get students with active (unresolved) violations only
    // Students whose violations are all resolved (apprehended) are excluded automatically
    $maxViolationLimit = 3;
    $stmt = $pdo->prepare('SELECT id, student_id, name, email, rfid_uid, violation_count, 
                           COALESCE(active_violations_count, 0) as active_violations_count
                           FROM users 
                           WHERE role = "Student" AND deleted_at IS NULL AND COALESCE(active_violations_count, 0) > 0
                           ORDER BY COALESCE(active_violations_count, 0) DESC, violation_count DESC, name ASC');
    $stmt->execute([]);
    $violationAlerts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $violationAlertCount = count($violationAlerts);

    // Get violation type summary counts for the notifications section
    $violationTypeSummary = ['minor' => 0, 'major' => 0, 'grave' => 0];
    try {
        $svTableCheck = $pdo->query("SHOW TABLES LIKE 'student_violations'")->fetch();
        if ($svTableCheck) {
            $typeSumStmt = $pdo->query("SELECT vc.offense_type, COUNT(*) as cnt 
                FROM student_violations sv 
                JOIN violation_categories vc ON sv.category_id = vc.id 
                WHERE sv.status = 'active' 
                GROUP BY vc.offense_type");
            foreach ($typeSumStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $violationTypeSummary[$row['offense_type']] = (int)$row['cnt'];
            }
        }
    } catch (\PDOException $e) {
        error_log('Violation type summary error: ' . $e->getMessage());
    }
    $totalActiveViolations = array_sum($violationTypeSummary);
    
    // Get violation analytics
    $dailyViolations = 0;
    $weeklyViolations = 0;
    $monthlyViolations = 0;
    $yearlyViolations = 0;
    
    // Check if violations table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'violations'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM violations WHERE DATE(scanned_at) = CURDATE()");
        $dailyViolations = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM violations WHERE YEARWEEK(scanned_at, 1) = YEARWEEK(CURDATE(), 1)");
        $weeklyViolations = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM violations WHERE MONTH(scanned_at) = MONTH(CURDATE()) AND YEAR(scanned_at) = YEAR(CURDATE())");
        $monthlyViolations = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM violations WHERE YEAR(scanned_at) = YEAR(CURDATE())");
        $yearlyViolations = $stmt->fetchColumn();
    }

    // ── Analytics enriched data (only fetched when analytics section is active) ──
    $analyticsActionCounts  = [];
    $analyticsTimeline      = ['labels' => [], 'counts' => []];
    $analyticsViolationTrend = ['labels' => [], 'counts' => []];
    $analyticsStats = ['actionsToday' => 0, 'violationsMonth' => 0, 'resolvedMonth' => 0, 'rfidMonth' => 0, 'totalPending' => 0];

    if (($_GET['section'] ?? 'students') === 'analytics') {
        try {
            // Action type counts — current calendar month
            $stmt = $pdo->query("
                SELECT action_type, COUNT(*) AS cnt
                FROM audit_logs
                WHERE action_type != 'EXPORT_AUDIT_LOG'
                  AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
                GROUP BY action_type
                ORDER BY cnt DESC
            ");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $analyticsActionCounts[$row['action_type']] = (int)$row['cnt'];
            }

            // 30-day timeline (admin actions)
            $timelineMap = [];
            $violMap     = [];
            $stmt = $pdo->query("
                SELECT DATE(created_at) AS d, COUNT(*) AS cnt
                FROM audit_logs
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                GROUP BY DATE(created_at)
            ");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $timelineMap[$row['d']] = (int)$row['cnt'];
            }

            if ($tableCheck) {
                $stmt = $pdo->query("
                    SELECT DATE(scanned_at) AS d, COUNT(*) AS cnt
                    FROM violations
                    WHERE scanned_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                    GROUP BY DATE(scanned_at)
                ");
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $violMap[$row['d']] = (int)$row['cnt'];
                }
            }

            for ($i = 29; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $analyticsTimeline['labels'][]       = date('M d', strtotime($d));
                $analyticsTimeline['counts'][]       = $timelineMap[$d] ?? 0;
                $analyticsViolationTrend['labels'][] = date('M d', strtotime($d));
                $analyticsViolationTrend['counts'][] = $violMap[$d]     ?? 0;
            }

            $analyticsStats['actionsToday']    = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn();
            $analyticsStats['violationsMonth'] = (int)$pdo->query("SELECT COUNT(*) FROM student_violations WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn();
            $analyticsStats['resolvedMonth']   = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type IN ('RESOLVE_VIOLATION','RESOLVE_ALL_VIOLATIONS') AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn();
            $analyticsStats['rfidMonth']       = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type = 'REGISTER_RFID' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn();
            $analyticsStats['totalPending']    = (int)$pdo->query("SELECT COALESCE(SUM(active_violations_count), 0) FROM users WHERE role = 'Student'")->fetchColumn();
        } catch (\Exception $analyticsEx) {
            error_log('Analytics data error: ' . $analyticsEx->getMessage());
        }
    }

    // Get pending verification students (using status = 'Pending')
    $stmt = $pdo->query('
        SELECT id, student_id, name, email, created_at, profile_picture 
        FROM users 
        WHERE role = "Student" AND status = "Pending"
        ORDER BY created_at DESC
    ');
    $pendingStudents = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $pendingCount = count($pendingStudents);
    
} catch (\PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    $error = 'Database error: ' . $e->getMessage();
} catch (\Exception $e) {
    error_log('Error fetching students: ' . $e->getMessage());
    $error = 'Failed to load student data: ' . $e->getMessage();
}

// Get audit logs (only for audit section)
$auditLogs = [];
if (($_GET['section'] ?? 'students') === 'audit') {
    try {
        $auditStmt = $pdo->query("SELECT * FROM audit_logs WHERE action_type != 'EXPORT_AUDIT_LOG' ORDER BY created_at DESC LIMIT 100");
        $auditLogs = $auditStmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        error_log('Audit logs fetch error: ' . $e->getMessage());
        $auditLogs = [];
    }
}

// Determine active section
$activeSection = $_GET['section'] ?? 'students';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GateWatch Admin | <?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../assets/images/gatewatch-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <!-- Dropzone.js for image upload -->
    <link rel="stylesheet" href="https://unpkg.com/dropzone@5/dist/min/dropzone.min.css" type="text/css" />
    <script src="https://unpkg.com/dropzone@5/dist/min/dropzone.min.js"></script>
    <script>Dropzone.autoDiscover = false;</script>
    <script src="../assets/js/digital-id-card.js?v=11"></script>
    <?php if (($activeSection ?? 'students') === 'analytics'): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <?php endif; ?>
    <style type="text/tailwindcss">
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .btn-hover {
            transition: all 0.3s ease;
        }
        .btn-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 86, 179, 0.1), 0 2px 4px -1px rgba(0, 86, 179, 0.06);
        }
        
        /* Desktop sidebar - always visible */
        @media (min-width: 769px) {
            #sidebarToggle {
                display: none !important;
            }
            aside {
                transform: translateX(0) !important;
            }
            .main-content {
                margin-left: 17.5rem !important;
            }
        }
        
        /* Mobile sidebar toggle styles */
        @media (max-width: 768px) {
            aside {
                transition: transform 0.3s ease-in-out;
                z-index: 1000;
            }
            aside.sidebar-hidden {
                transform: translateX(calc(-100% - 0.75rem));
            }
            .main-content {
                transition: margin-left 0.3s ease-in-out;
                margin-left: 0 !important;
                padding-bottom: 2rem !important;
                padding-top: 1rem !important;
            }
            .toggle-btn {
                transition: all 0.3s ease;
                position: fixed !important;
                bottom: 1.5rem !important;
                right: 1.5rem !important;
                top: auto !important;
                left: auto !important;
                z-index: 1001 !important;
                box-shadow: 0 4px 12px rgba(0, 86, 179, 0.3) !important;
                width: 3.5rem !important;
                height: 3.5rem !important;
                min-width: 3.5rem !important;
                min-height: 3.5rem !important;
                border-radius: 50% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0 !important;
            }
            .toggle-btn:hover {
                background-color: #0056b3;
            }
            
            /* Overlay when sidebar is open on mobile */
            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 999;
                transition: opacity 0.3s ease-in-out;
            }
            .sidebar-overlay.active {
                display: block;
            }
        }
    </style>
    <style>
        :root {
            --sky-50: #f0f9ff;
            --sky-100: #e0f2fe;
            --sky-600: #0284c7;
            --slate-900: #0f172a;
            --glass: rgba(255, 255, 255, 0.78);
        }

        html {
            margin: 0;
            padding: 0;
            height: 100%;
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
            background: #e0f2ff;
            background: radial-gradient(circle at 20% 20%, rgba(2, 132, 199, 0.07), transparent 30%),
                        radial-gradient(circle at 80% 0%, rgba(14, 165, 233, 0.08), transparent 28%),
                        radial-gradient(circle at 0% 80%, rgba(2, 132, 199, 0.06), transparent 25%),
                        linear-gradient(135deg, #e0f2ff 0%, #f8fbff 100%);
            background-attachment: fixed;
            overflow-x: hidden;
        }

        .page-shell {
            position: relative;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .hero-photo {
            position: fixed;
            inset: 0;
            background-image: url('../assets/images/pcu-building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            filter: saturate(1.05);
            opacity: 0.55;
            z-index: 0;
        }

        .hero-gradient {
            position: fixed;
            inset: 0;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.14), rgba(255, 255, 255, 0.7));
            z-index: 0;
        }

        .floating-blob {
            position: fixed;
            filter: blur(40px);
            opacity: 0.35;
            mix-blend-mode: multiply;
            z-index: 1;
            transform: scale(1.1);
            pointer-events: none;
        }

        .blob-1 {
            top: -120px;
            left: -160px;
            width: 360px;
            height: 360px;
            background: radial-gradient(circle, rgba(2, 132, 199, 0.55), rgba(2, 132, 199, 0.25));
        }

        .blob-2 {
            bottom: 0;
            right: -120px;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(14, 165, 233, 0.45), rgba(14, 165, 233, 0.18));
        }

        .glass-card {
            background: var(--glass);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.14);
        }

        .nav-blur {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .sidebar-glass {
            background: rgba(255, 255, 255, 0.72);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(255, 255, 255, 0.55);
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.12), 0 0 0 1px rgba(255,255,255,0.18);
        }

        .sidebar-calendar-card {
            margin-top: 0.9rem;
            border-radius: 0.95rem;
            padding: 0.8rem;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.72), rgba(224, 242, 254, 0.5));
            border: 1px solid rgba(125, 211, 252, 0.45);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.68), 0 10px 24px rgba(15, 23, 42, 0.09);
        }

        .sidebar-calendar-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.65rem;
            margin-bottom: 0.6rem;
        }

        .sidebar-calendar-month {
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            color: #0369a1;
            line-height: 1.2;
        }

        .sidebar-calendar-date {
            margin-top: 0.2rem;
            font-size: 0.7rem;
            font-weight: 500;
            color: #64748b;
            line-height: 1.2;
        }

        .sidebar-calendar-clock {
            border-radius: 0.7rem;
            padding: 0.32rem 0.5rem;
            background: linear-gradient(140deg, rgba(2, 132, 199, 0.18), rgba(14, 165, 233, 0.1));
            border: 1px solid rgba(2, 132, 199, 0.28);
            color: #0f172a;
            font-weight: 700;
            font-size: 0.75rem;
            white-space: nowrap;
            min-width: 3.45rem;
            text-align: center;
        }

        .sidebar-calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 0.22rem;
        }

        .sidebar-calendar-cell {
            height: 1.42rem;
            border-radius: 0.45rem;
            font-size: 0.67rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #334155;
            background: rgba(255, 255, 255, 0.56);
            border: 1px solid rgba(226, 232, 240, 0.68);
            user-select: none;
        }

        .sidebar-calendar-head {
            height: auto;
            background: transparent;
            border: none;
            color: #64748b;
            font-weight: 700;
            font-size: 0.58rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 0.05rem;
        }

        .sidebar-calendar-cell.is-muted {
            color: #94a3b8;
            background: rgba(248, 250, 252, 0.46);
        }

        .sidebar-calendar-cell.is-today {
            background: linear-gradient(145deg, rgba(2, 132, 199, 0.9), rgba(14, 165, 233, 0.86));
            color: #ffffff;
            border-color: rgba(2, 132, 199, 0.95);
            box-shadow: 0 6px 14px rgba(2, 132, 199, 0.26);
            font-weight: 700;
        }

        .toast-glass-panel {
            position: relative;
            width: min(430px, calc(100vw - 2rem));
            max-width: calc(100vw - 2rem);
            border-radius: 18px;
            overflow: hidden;
            background: linear-gradient(140deg, rgba(255, 255, 255, 0.84), rgba(255, 255, 255, 0.66));
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.66);
            box-shadow: 0 20px 46px rgba(15, 23, 42, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.75);
        }

        .toast-glass-sheen {
            position: absolute;
            top: -35%;
            right: -18%;
            width: 58%;
            height: 140%;
            border-radius: 999px;
            pointer-events: none;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.55), rgba(255, 255, 255, 0));
            opacity: 0.8;
        }

        .toast-accent-success { border-left: 3px solid #16a34a; }
        .toast-accent-error { border-left: 3px solid #dc2626; }
        .toast-accent-warning { border-left: 3px solid #d97706; }
        .toast-accent-info { border-left: 3px solid #0284c7; }

        .toast-icon-wrap {
            width: 2.4rem;
            height: 2.4rem;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.5);
            flex-shrink: 0;
        }

        .toast-icon-success {
            color: #047857;
            background: linear-gradient(145deg, rgba(209, 250, 229, 0.9), rgba(167, 243, 208, 0.6));
        }

        .toast-icon-error {
            color: #b91c1c;
            background: linear-gradient(145deg, rgba(254, 226, 226, 0.92), rgba(252, 165, 165, 0.52));
        }

        .toast-icon-warning {
            color: #b45309;
            background: linear-gradient(145deg, rgba(254, 243, 199, 0.92), rgba(253, 230, 138, 0.58));
        }

        .toast-icon-info {
            color: #0369a1;
            background: linear-gradient(145deg, rgba(224, 242, 254, 0.92), rgba(186, 230, 253, 0.6));
        }

        .toast-close-btn {
            color: #64748b;
            transition: color 0.2s ease;
        }

        .toast-close-btn:hover {
            color: #0f172a;
        }

        .toast-progress {
            height: 2px;
            background: rgba(148, 163, 184, 0.25);
            overflow: hidden;
        }

        .toast-progress > span {
            display: block;
            width: 100%;
            height: 100%;
            transform-origin: left center;
            animation-name: toastProgressShrink;
            animation-timing-function: linear;
            animation-fill-mode: forwards;
        }

        .toast-progress-success { background: linear-gradient(90deg, #22c55e, #16a34a); }
        .toast-progress-error { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-progress-warning { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .toast-progress-info { background: linear-gradient(90deg, #38bdf8, #0284c7); }

        @keyframes toastProgressShrink {
            from { transform: scaleX(1); }
            to { transform: scaleX(0); }
        }

        .sidebar-button {
            transition: all 0.2s ease;
        }

        .sidebar-button:hover,
        .sidebar-button:focus {
            background-color: #0284c7;
            color: white;
        }

        .rfid-scan-orb {
            position: relative;
            width: 170px;
            height: 170px;
            border-radius: 999px;
            background: radial-gradient(circle at 30% 28%, rgba(255,255,255,0.95), rgba(14,165,233,0.18));
            border: 1px solid rgba(148, 163, 184, 0.35);
            box-shadow: inset 0 0 0 10px rgba(255,255,255,0.45), 0 12px 35px rgba(15, 23, 42, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            overflow: hidden;
        }

        .rfid-scan-ring,
        .rfid-scan-ring::before {
            position: absolute;
            border-radius: 999px;
            border: 2px solid rgba(56, 189, 248, 0.45);
            content: '';
            inset: 16px;
        }

        .rfid-scan-ring::before {
            inset: -14px;
            border-color: rgba(148, 163, 184, 0.26);
        }

        .rfid-scan-logo {
            width: 92px;
            height: 92px;
            border-radius: 999px;
            object-fit: cover;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.24);
            position: relative;
            z-index: 2;
        }

        .rfid-input-glass {
            width: 100%;
            padding: 0.8rem 1rem;
            border-radius: 0.95rem;
            border: 2px solid rgba(56, 189, 248, 0.45);
            background: rgba(255, 255, 255, 0.75);
            color: #334155;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 1.05rem;
            text-align: center;
            letter-spacing: 0.2em;
            transition: all 0.2s ease;
        }

        .rfid-input-glass:focus {
            outline: none;
            border-color: rgba(2, 132, 199, 0.75);
            box-shadow: 0 0 0 5px rgba(14, 165, 233, 0.18);
            background: rgba(255, 255, 255, 0.88);
        }

        .rfid-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.4rem 0.9rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.68);
            border: 1px solid rgba(148, 163, 184, 0.35);
            color: #64748b;
            font-weight: 500;
        }

        .rfid-status-dot {
            width: 0.6rem;
            height: 0.6rem;
            border-radius: 999px;
            background: #60a5fa;
            animation: pulse 1.5s ease-in-out infinite;
        }

        .rfid-secondary-btn {
            margin-top: 0.95rem;
            padding: 0.55rem 1rem;
            border-radius: 0.8rem;
            background: #0284c7;
            color: #fff;
            font-size: 0.9rem;
            font-weight: 600;
            transition: background-color 0.2s ease;
        }

        .rfid-secondary-btn:hover {
            background: #0369a1;
        }

        .rfid-detail-panel {
            background: rgba(255, 255, 255, 0.58);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 0.95rem;
            padding: 1rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.38);
        }

        .rfid-detail-avatar {
            width: 8rem;
            height: 8rem;
            border-radius: 999px;
            object-fit: cover;
            border: 4px solid rgba(125, 211, 252, 0.65);
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.2);
        }

        .rfid-detail-avatar-fallback {
            width: 8rem;
            height: 8rem;
            border-radius: 999px;
            border: 4px solid rgba(125, 211, 252, 0.65);
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.8rem;
            font-weight: 700;
        }
    </style>
</head>
<body class="text-slate-900" data-csrf-token="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES); ?>" data-active-section="<?php echo htmlspecialchars($activeSection, ENT_QUOTES); ?>">
    <div class="page-shell">
        <div class="hero-photo"></div>
        <div class="hero-gradient"></div>
        <span class="floating-blob blob-1"></span>
        <span class="floating-blob blob-2"></span>

        <div class="relative z-10">

<!-- Main Layout with Sidebar -->
<div class="flex min-h-screen">
    <!-- Overlay for mobile when sidebar is open -->
    <div id="sidebarOverlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>
    
    <!-- Sidebar Toggle Button (Mobile Only) -->
    <button 
        id="sidebarToggle" 
        class="toggle-btn fixed top-4 left-4 z-50 bg-[#0056b3] text-white shadow-lg hover:shadow-xl"
        onclick="toggleSidebar()"
        title="Toggle Menu"
    >
        <svg id="toggleIcon" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
    </button>

    <!-- Sidebar -->
    <aside id="sidebar" class="w-64 sidebar-glass fixed top-3 left-3 rounded-2xl overflow-y-auto z-40 sidebar-hidden" style="height:calc(100vh - 1.5rem)">
        <div class="p-4">
            <a href="?section=students" class="flex items-center gap-3 mb-6 hover:opacity-80 transition-opacity">
                <img src="../pcu-logo.png" alt="PCU Logo" class="w-10 h-10">
                <div>
                    <h2 class="font-semibold text-sky-700">Admin Panel</h2>
                    <p class="text-xs text-slate-500">Philippine Christian University</p>
                </div>
            </a>

            <!-- Navigation -->
            <nav class="space-y-1">
                <a href="?section=verify" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo $activeSection === 'verify' ? 'bg-sky-100/70 text-sky-700 font-semibold' : 'text-slate-600 hover:bg-white/60'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div class="flex-1 flex items-center justify-between">
                        <span class="font-medium">Verify Students</span>
                        <?php if ($pendingCount > 0): ?>
                        <span class="bg-amber-500 text-white text-xs px-2 py-1 rounded-full animate-pulse"><?php echo $pendingCount; ?></span>
                        <?php endif; ?>
                    </div>
                </a>
                
                <a href="?section=students" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo $activeSection === 'students' ? 'bg-sky-100/70 text-sky-700 font-semibold' : 'text-slate-600 hover:bg-white/60'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    <span class="font-medium">All Students</span>
                </a>

                <a href="?section=registered" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo $activeSection === 'registered' ? 'bg-sky-100/70 text-sky-700 font-semibold' : 'text-slate-600 hover:bg-white/60'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                    </svg>
                    <div class="flex-1 flex items-center justify-between">
                        <span class="font-medium">Registered Cards</span>
                        <span class="bg-green-100 text-green-700 text-xs px-2 py-1 rounded-full"><?php echo $registeredCount; ?></span>
                    </div>
                </a>

                <a href="?section=analytics" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo $activeSection === 'analytics' ? 'bg-sky-100/70 text-sky-700 font-semibold' : 'text-slate-600 hover:bg-white/60'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <span class="font-medium">Analytics</span>
                </a>

                <a href="?section=notifications" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo $activeSection === 'notifications' ? 'bg-sky-100/70 text-sky-700 font-semibold' : 'text-slate-600 hover:bg-white/60'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div class="flex-1 flex items-center justify-between">
                        <span class="font-medium">Violations</span>
                        <?php if ($violationAlertCount > 0): ?>
                        <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full animate-pulse"><?php echo $violationAlertCount; ?></span>
                        <?php endif; ?>
                    </div>
                </a>

                <a href="?section=audit" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo $activeSection === 'audit' ? 'bg-sky-100/70 text-sky-700 font-semibold' : 'text-slate-600 hover:bg-white/60'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span class="font-medium">Audit Log</span>
                </a>

                <a href="?section=rfid_checker" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo $activeSection === 'rfid_checker' ? 'bg-sky-100/70 text-sky-700 font-semibold' : 'text-slate-600 hover:bg-white/60'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <span class="font-medium">RFID Checker</span>
                </a>

                <?php if (filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)): ?>
                <?php
                // Count students eligible for face enrollment (have RFID but no face registered)
                $faceEnrollCount = 0;
                foreach ($allStudents as $s) {
                    if (!empty($s['rfid_uid']) && empty($s['face_registered'])) $faceEnrollCount++;
                }
                ?>
                <a href="?section=face_enroll" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo $activeSection === 'face_enroll' ? 'bg-sky-100/70 text-sky-700 font-semibold' : 'text-slate-600 hover:bg-white/60'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                    </svg>
                    <span class="font-medium">Face Enroll</span>
                    <?php if ($faceEnrollCount > 0): ?>
                        <span class="ml-auto bg-[#0056b3] text-white text-xs w-6 h-6 rounded-full flex items-center justify-center font-bold"><?php echo $faceEnrollCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="?section=face" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo $activeSection === 'face' ? 'bg-sky-100/70 text-sky-700 font-semibold' : 'text-slate-600 hover:bg-white/60'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    <span class="font-medium">Face Management</span>
                </a>
                <?php endif; ?>
            </nav>

            <!-- Gate Monitor Link -->
            <div class="mt-4 pt-4 border-t border-white/40">
                <a href="../security/gate_monitor.php" target="_blank" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors bg-green-50/60 text-green-700 hover:bg-green-100/70 border border-green-200/50">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <div class="flex-1">
                        <span class="font-medium">Gate Monitor</span>
                        <p class="text-xs text-green-600">Security Access</p>
                    </div>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                </a>

                <div class="sidebar-calendar-card" aria-live="polite">
                    <div class="sidebar-calendar-top">
                        <div>
                            <p id="sidebarCalendarMonth" class="sidebar-calendar-month">Month Year</p>
                            <p id="sidebarCalendarDate" class="sidebar-calendar-date">Day, Mon 00</p>
                        </div>
                        <div id="sidebarCalendarClock" class="sidebar-calendar-clock">00:00</div>
                    </div>
                    <div id="sidebarCalendarGrid" class="sidebar-calendar-grid" aria-label="Mini calendar"></div>
                </div>
            </div>

            <!-- Logout Button -->
            <div class="mt-8 pt-6 border-t border-white/40">
                <a href="logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 hover:bg-red-50/60 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    <span class="font-medium">Logout</span>
                </a>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main id="mainContent" class="main-content flex-1 ml-64 p-8">
        <?php if (isset($error)): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-lg mb-6">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($activeSection === 'verify'): ?>
            <!-- Verify Students Section -->
            <div class="glass-card rounded-2xl px-6 py-5 mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Verify Student Accounts</h1>
                <p class="text-slate-600 mt-1">Review and approve student registration requests</p>
            </div>

            <?php if (empty($pendingStudents)): ?>
                <div class="glass-card rounded-2xl overflow-hidden">
                    <div class="p-12 text-center">
                        <svg class="w-16 h-16 mx-auto text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <h3 class="text-lg font-semibold text-slate-800 mb-2">All Caught Up!</h3>
                        <p class="text-slate-600">No pending student verifications at this time.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid gap-6">
                    <?php foreach ($pendingStudents as $student): ?>
                        <div class="glass-card rounded-2xl overflow-hidden fade-in">
                            <div class="p-6">
                                <div class="flex items-start gap-6">
                                    <!-- Profile Picture or Avatar -->
                                    <div class="flex-shrink-0">
                                        <?php if (!empty($student['profile_picture'])): ?>
                                            <img src="../assets/images/profiles/<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                                 alt="Profile" 
                                                 class="w-20 h-20 rounded-full object-cover border-4 border-slate-100">
                                        <?php else: ?>
                                            <?php
                                            $firstLetter = strtoupper(substr($student['name'], 0, 1));
                                            $colors = ['bg-blue-500', 'bg-green-500', 'bg-yellow-500', 'bg-red-500', 'bg-purple-500', 'bg-pink-500'];
                                            $colorIndex = ord($firstLetter) % count($colors);
                                            $bgColor = $colors[$colorIndex];
                                            ?>
                                            <div class="w-20 h-20 rounded-full <?php echo $bgColor; ?> flex items-center justify-center text-white text-2xl font-bold border-4 border-slate-100">
                                                <?php echo $firstLetter; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Student Info -->
                                    <div class="flex-1">
                                        <div class="flex items-start justify-between mb-4">
                                            <div>
                                                <h3 class="text-xl font-semibold text-slate-800 mb-1">
                                                    <?php echo htmlspecialchars($student['name']); ?>
                                                </h3>
                                                <div class="space-y-1">
                                                    <p class="text-sm text-slate-600 flex items-center gap-2">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                                                        </svg>
                                                        <strong>Student ID:</strong> 
                                                        <code class="bg-slate-100 px-2 py-0.5 rounded text-slate-800"><?php echo htmlspecialchars($student['student_id']); ?></code>
                                                    </p>
                                                    <p class="text-sm text-slate-600 flex items-center gap-2">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                                        </svg>
                                                        <strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?>
                                                    </p>
                                                    <p class="text-sm text-slate-600 flex items-center gap-2">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                        </svg>
                                                        <strong>Registered:</strong> <?php echo date('M d, Y • g:i A', strtotime($student['created_at'])); ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <span class="bg-amber-100 text-amber-800 text-xs font-semibold px-3 py-1 rounded-full">
                                                Pending Verification
                                            </span>
                                        </div>
                                        
                                        <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded-r-lg mb-4">
                                            <p class="text-sm text-amber-800">
                                                <strong>Action Required:</strong> Please verify this student's credentials against enrolled student records before approving their account.
                                            </p>
                                        </div>
                                        
                                        <!-- Action Buttons -->
                                        <div class="flex gap-3 mt-4">
                                            <button 
                                                onclick="approveStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['name'], ENT_QUOTES); ?>')"
                                                class="flex-1 px-6 py-3 bg-green-600 text-white rounded-lg font-semibold transition-all duration-150
                                                       hover:bg-green-700 hover:shadow-lg active:scale-[0.98]
                                                       flex items-center justify-center gap-2">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                </svg>
                                                Approve Account
                                            </button>
                                            <button 
                                                onclick="denyStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['name'], ENT_QUOTES); ?>')"
                                                class="flex-1 px-6 py-3 bg-red-600 text-white rounded-lg font-semibold transition-all duration-150
                                                       hover:bg-red-700 hover:shadow-lg active:scale-[0.98]
                                                       flex items-center justify-center gap-2">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                                Deny & Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($activeSection === 'students'): ?>
            <!-- All Students Section -->
            <div class="glass-card rounded-2xl px-6 py-5 mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Student Management</h1>
                <p class="text-slate-600 mt-1">Manage student accounts and RFID registrations</p>
            </div>

            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="p-6">
                    <?php if (empty($students)): ?>
                        <p class="text-slate-600 text-center py-8">No students found.</p>
                    <?php else: ?>
                        <div class="grid gap-4">
                        <?php foreach ($students as $student): ?>
                            <div class="bg-slate-50 rounded-lg p-4 fade-in flex items-center justify-between">
                                <div>
                                    <h3 class="font-medium text-slate-800">
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </h3>
                                    <p class="text-sm text-slate-600">
                                        Student ID: <?php echo htmlspecialchars($student['student_id']); ?>
                                    </p>
                                    <p class="text-sm text-slate-500">
                                        <?php echo htmlspecialchars($student['email']); ?>
                                    </p>
                                    <?php if ($student['rfid_uid']): ?>
                                    <p class="text-sm text-green-600 mt-1">
                                        <span class="inline-flex items-center">
                                            <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                            </svg>
                                            RFID Registered
                                        </span>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <div class="flex gap-2">
                                    <?php if (!$student['rfid_uid']): ?>
                                        <button 
                                            onclick="openCardRegistration(<?php echo (int)$student['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$student['student_id']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string)$student['name']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string)($student['course'] ?? '')), ENT_QUOTES); ?>)"
                                            class="px-4 py-2 bg-[#0056b3] text-white rounded-lg btn-hover text-sm"
                                        >
                                            Register Card
                                        </button>
                                    <?php else: ?>
                                        <button 
                                            onclick="unregisterCard('<?php echo htmlspecialchars($student['id']); ?>')"
                                            class="px-4 py-2 bg-yellow-500 text-white rounded-lg btn-hover text-sm"
                                        >
                                            Unregister Card
                                        </button>
                                    <?php endif; ?>
                                    <button 
                                        onclick="confirmDelete('<?php echo htmlspecialchars($student['id']); ?>')"
                                        class="px-4 py-2 bg-red-500 text-white rounded-lg btn-hover text-sm"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($activeSection === 'registered'): ?>
            <!-- Registered Cards Section -->
            <div class="glass-card rounded-2xl px-6 py-5 mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Registered RFID Cards</h1>
                <p class="text-slate-600 mt-1">View and manage students with registered cards</p>
            </div>

            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="p-6">
                    <!-- Search Bar -->
                    <div class="mb-6">
                        <div class="relative">
                            <input 
                                type="text" 
                                id="registeredCardSearch" 
                                placeholder="Search by Student ID..." 
                                class="w-full px-4 py-3 pl-12 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
                                autocomplete="off"
                            >
                            <svg class="w-5 h-5 text-slate-400 absolute left-4 top-1/2 transform -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <p class="text-xs text-slate-500 mt-2" id="searchResultCount"></p>
                    </div>

                    <?php 
                    $registeredStudents = array_filter($allStudents, function($s) { return !empty($s['rfid_uid']); });
                    if (empty($registeredStudents)): 
                    ?>
                        <div class="text-center py-12">
                            <svg class="w-16 h-16 mx-auto text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"/>
                            </svg>
                            <p class="text-slate-600">No registered cards yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid gap-4">
                        <?php foreach ($registeredStudents as $student): 
                            // Get RFID card details including lost status
                            $cardInfo = null;
                            $isLost = false;
                            
                            try {
                                $cardStmt = $pdo->prepare("
                                    SELECT rc.id AS card_id, rc.is_lost, rc.lost_at, rc.lost_reason,
                                           '' AS reported_by_name
                                    FROM rfid_cards rc
                                    WHERE rc.user_id = ? AND rc.rfid_uid = ?
                                    ORDER BY rc.id DESC
                                    LIMIT 1
                                ");
                                $cardStmt->execute([$student['id'], $student['rfid_uid']]);
                                $cardInfo = $cardStmt->fetch();
                                
                                // If card not found in rfid_cards table, create it now
                                if (!$cardInfo && !empty($student['rfid_uid'])) {
                                    $insertStmt = $pdo->prepare("
                                        INSERT INTO rfid_cards (user_id, rfid_uid, registered_at, is_active)
                                        VALUES (?, ?, ?, 1)
                                    ");
                                    $insertStmt->execute([
                                        $student['id'],
                                        $student['rfid_uid'],
                                        $student['rfid_registered_at'] ?? date('Y-m-d H:i:s')
                                    ]);
                                    
                                    // Fetch the newly created card
                                    $cardStmt->execute([$student['id'], $student['rfid_uid']]);
                                    $cardInfo = $cardStmt->fetch();
                                }

                                // Fallback: if UID-specific row not found, use latest card row for this student
                                if (!$cardInfo) {
                                    $fallbackStmt = $pdo->prepare("
                                        SELECT rc.id AS card_id,
                                               COALESCE(rc.is_lost, 0) AS is_lost,
                                               rc.lost_at,
                                               rc.lost_reason,
                                               '' AS reported_by_name
                                        FROM rfid_cards rc
                                        WHERE rc.user_id = ?
                                        ORDER BY rc.id DESC
                                        LIMIT 1
                                    ");
                                    $fallbackStmt->execute([$student['id']]);
                                    $cardInfo = $fallbackStmt->fetch();
                                }
                                
                                $isLost = $cardInfo && $cardInfo['is_lost'] == 1;
                            } catch (\PDOException $e) {
                                // If rfid_cards table doesn't exist or query fails, use basic info from users table
                                error_log("RFID card query error: " . $e->getMessage());

                                // Minimal fallback query for legacy/partial schemas
                                try {
                                    $fallbackStmt = $pdo->prepare("
                                        SELECT id AS card_id,
                                               0 AS is_lost,
                                               NULL AS lost_at,
                                               NULL AS lost_reason,
                                               '' AS reported_by_name
                                        FROM rfid_cards
                                        WHERE user_id = ?
                                        ORDER BY id DESC
                                        LIMIT 1
                                    ");
                                    $fallbackStmt->execute([$student['id']]);
                                    $cardInfo = $fallbackStmt->fetch();
                                } catch (\PDOException $fallbackErr) {
                                    error_log("RFID fallback query error: " . $fallbackErr->getMessage());
                                    $cardInfo = ['card_id' => 0, 'is_lost' => 0, 'lost_at' => null, 'lost_reason' => null, 'reported_by_name' => ''];
                                }

                                $isLost = false;
                            }
                        ?>
                            <div class="border <?php echo $isLost ? 'border-red-300 bg-red-50/50' : 'border-green-200 bg-green-50/50'; ?> rounded-lg p-4 fade-in registered-card-item" 
                                 data-student-id="<?php echo strtolower(htmlspecialchars($student['student_id'])); ?>" 
                                 data-student-name="<?php echo strtolower(htmlspecialchars($student['name'])); ?>">
                                <!-- Desktop: side-by-side layout, Mobile: centered layout -->
                                <div class="flex flex-col md:flex-row items-center md:items-stretch md:justify-between text-center md:text-left">
                                    <div class="w-full md:flex-1 flex flex-col">
                                        <div class="flex items-center justify-center md:justify-start gap-2 mb-2">
                                            <h3 class="font-semibold text-slate-800">
                                                <?php echo htmlspecialchars($student['name']); ?>
                                            </h3>
                                            <?php if ($isLost): ?>
                                                <span class="bg-red-100 text-red-700 text-xs px-2 py-1 rounded-full">🔴 LOST</span>
                                            <?php else: ?>
                                                <span class="bg-green-100 text-green-700 text-xs px-2 py-1 rounded-full">Active</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm text-slate-600 mb-1">
                                            Student ID: <?php echo htmlspecialchars($student['student_id']); ?>
                                        </p>
                                        <p class="text-sm text-slate-500 mb-2">
                                            <?php echo htmlspecialchars($student['email']); ?>
                                        </p>
                                        <div class="bg-white rounded-md p-3 mt-3 max-w-md mx-auto md:mx-0 flex-1 flex flex-col justify-between">
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-slate-600">RFID UID:</span>
                                                <code class="bg-slate-100 px-3 py-1 rounded font-mono text-slate-800"><?php echo htmlspecialchars($student['rfid_uid']); ?></code>
                                            </div>
                                            <div class="flex items-center justify-between text-sm mt-2">
                                                <span class="text-slate-600">Registered:</span>
                                                <span class="text-slate-700"><?php echo date('M d, Y h:i A', strtotime($student['rfid_registered_at'])); ?></span>
                                            </div>
                                            <?php if ($isLost): ?>
                                                <div class="mt-3 p-2 bg-red-100 border border-red-200 rounded-md">
                                                    <div class="flex items-center justify-between text-xs">
                                                        <span class="text-red-600 font-semibold">Lost Date:</span>
                                                        <span class="text-red-700"><?php echo date('M d, Y h:i A', strtotime($cardInfo['lost_at'])); ?></span>
                                                    </div>
                                                    <?php if (!empty($cardInfo['lost_reason'])): ?>
                                                    <div class="mt-1 text-xs text-red-600">
                                                        <strong>Reason:</strong> <?php echo htmlspecialchars($cardInfo['lost_reason']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($cardInfo['reported_by_name'])): ?>
                                                    <div class="mt-1 text-xs text-red-500">
                                                        <strong>Reported by:</strong> <?php echo htmlspecialchars($cardInfo['reported_by_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex flex-row md:flex-col gap-2 mt-4 md:mt-0 md:ml-4">
                                        <button 
                                            onclick="openEditStudentModal('<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['student_id']); ?>', '<?php echo htmlspecialchars($student['email']); ?>', '<?php echo htmlspecialchars($student['profile_picture'] ?? ''); ?>', '<?php echo htmlspecialchars($student['course'] ?? ''); ?>')"
                                            class="px-4 py-2 bg-indigo-500 text-white rounded-lg btn-hover text-sm whitespace-nowrap"
                                        >
                                            Edit
                                        </button>
                                        <button 
                                            onclick="viewCardDetails(
                                                <?php echo htmlspecialchars(json_encode((string)($student['id'] ?? '')), ENT_QUOTES); ?>,
                                                <?php echo htmlspecialchars(json_encode((string)($student['name'] ?? '')), ENT_QUOTES); ?>,
                                                <?php echo htmlspecialchars(json_encode((string)($student['rfid_uid'] ?? '')), ENT_QUOTES); ?>,
                                                <?php echo htmlspecialchars(json_encode((string)($student['rfid_registered_at'] ?? '')), ENT_QUOTES); ?>,
                                                <?php echo htmlspecialchars(json_encode((string)($student['profile_picture'] ?? '')), ENT_QUOTES); ?>,
                                                <?php echo htmlspecialchars(json_encode((string)($student['email'] ?? '')), ENT_QUOTES); ?>,
                                                <?php echo htmlspecialchars(json_encode((string)($student['guardian_name'] ?? '')), ENT_QUOTES); ?>,
                                                <?php echo htmlspecialchars(json_encode((string)($student['guardian_contact'] ?? '')), ENT_QUOTES); ?>
                                            )"
                                            class="px-4 py-2 bg-blue-500 text-white rounded-lg btn-hover text-sm whitespace-nowrap"
                                        >
                                            View Details
                                        </button>
                                        <?php if ($isLost): ?>
                                            <button 
                                                onclick="toggleRfidLostStatus(<?php echo (int)($cardInfo['card_id'] ?? 0); ?>, '<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['rfid_uid']); ?>', '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['email']); ?>', false)"
                                                class="px-4 py-2 bg-green-600 text-white rounded-lg btn-hover text-sm whitespace-nowrap flex items-center gap-2"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                                Disable Mark Lost ID
                                            </button>
                                        <?php else: ?>
                                            <button 
                                                onclick="toggleRfidLostStatus(<?php echo (int)($cardInfo['card_id'] ?? 0); ?>, '<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['rfid_uid']); ?>', '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['email']); ?>', true)"
                                                class="px-4 py-2 bg-orange-600 text-white rounded-lg btn-hover text-sm whitespace-nowrap flex items-center gap-2"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                                </svg>
                                                Enable Mark Lost ID
                                            </button>
                                        <?php endif; ?>
                                        <button 
                                            onclick="unregisterCard('<?php echo htmlspecialchars($student['id']); ?>')"
                                            class="px-4 py-2 bg-yellow-500 text-white rounded-lg btn-hover text-sm whitespace-nowrap"
                                        >
                                            Unregister
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($activeSection === 'notifications'): ?>
            <!-- Violation Management Section -->
            <div class="glass-card rounded-2xl px-6 py-5 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-800">Violation Management</h1>
                        <p class="text-slate-600 mt-1">Track, record, and resolve student violations from 1st year to present</p>
                    </div>
                </div>
            </div>

            <!-- Violation Type Summary Cards -->
            <?php if ($totalActiveViolations > 0): ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="glass-card rounded-2xl p-5 border-l-4 border-amber-400">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-amber-700">Minor Offenses</p>
                            <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo $violationTypeSummary['minor']; ?></p>
                            <p class="text-xs text-slate-500">Active violations</p>
                        </div>
                        <div class="bg-amber-100 p-3 rounded-full">
                            <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                    </div>
                </div>
                <div class="glass-card rounded-2xl p-5 border-l-4 border-orange-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-orange-700">Major Offenses</p>
                            <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo $violationTypeSummary['major']; ?></p>
                            <p class="text-xs text-slate-500">Active violations</p>
                        </div>
                        <div class="bg-orange-100 p-3 rounded-full">
                            <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                            </svg>
                        </div>
                    </div>
                </div>
                <div class="glass-card rounded-2xl p-5 border-l-4 border-red-600">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-red-700">Grave Offenses</p>
                            <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo $violationTypeSummary['grave']; ?></p>
                            <p class="text-xs text-slate-500">Active violations</p>
                        </div>
                        <div class="bg-red-100 p-3 rounded-full">
                            <svg class="w-6 h-6 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="p-6">
                    <?php if (empty($violationAlerts)): ?>
                        <div class="text-center py-12">
                            <svg class="w-16 h-16 mx-auto text-green-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="text-slate-600 font-medium">No violation alerts at this time</p>
                            <p class="text-slate-500 text-sm mt-1">All students are within acceptable violation limits</p>
                        </div>
                    <?php else: ?>
                        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                <span class="text-red-800 font-semibold"><?php echo $violationAlertCount; ?> student<?php echo $violationAlertCount !== 1 ? 's' : ''; ?> with active violations</span>
                                <span class="text-red-600 text-sm ml-2">(<?php echo $totalActiveViolations; ?> total active offenses)</span>
                            </div>
                        </div>

                        <div class="grid gap-4">
                        <?php foreach ($violationAlerts as $student): ?>
                            <div class="border-2 <?php echo $student['active_violations_count'] > 0 ? 'border-red-300 bg-red-50/50' : 'border-amber-300 bg-amber-50/50'; ?> rounded-lg p-5 fade-in">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-3">
                                            <div class="<?php echo $student['active_violations_count'] > 0 ? 'bg-red-100' : 'bg-amber-100'; ?> p-2 rounded-full">
                                                <svg class="w-6 h-6 <?php echo $student['active_violations_count'] > 0 ? 'text-red-600' : 'text-amber-600'; ?>" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                                </svg>
                                            </div>
                                            <div>
                                                <h3 class="font-bold text-slate-800 text-lg">
                                                    <?php echo htmlspecialchars($student['name']); ?>
                                                </h3>
                                                <p class="text-sm text-slate-600">Student ID: <?php echo htmlspecialchars($student['student_id']); ?></p>
                                            </div>
                                            <div class="flex gap-2">
                                                <?php if ($student['active_violations_count'] > 0): ?>
                                                <span class="bg-red-500 text-white text-xs font-bold px-3 py-1 rounded-full">
                                                    <?php echo $student['active_violations_count']; ?> Active
                                                </span>
                                                <?php endif; ?>
                                                <!-- Gate Strikes removed: system no longer uses "strikes" terminology -->
                                            </div>
                                        </div>
                                        
                                        <div class="bg-white rounded-lg p-4 border <?php echo $student['active_violations_count'] > 0 ? 'border-red-200' : 'border-amber-200'; ?>">
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <p class="text-xs text-slate-500 mb-1">Email Address</p>
                                                    <p class="text-sm font-medium text-slate-700"><?php echo htmlspecialchars($student['email']); ?></p>
                                                </div>
                                                <div>
                                                    <p class="text-xs text-slate-500 mb-1">RFID Card UID</p>
                                                    <code class="text-sm bg-slate-100 px-2 py-1 rounded font-mono">
                                                        <?php echo $student['rfid_uid'] ? htmlspecialchars($student['rfid_uid']) : 'Not Registered'; ?>
                                                    </code>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex flex-col gap-2 ml-4">
                                        <button 
                                            onclick="openViolationHistoryModal('<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['name']); ?>', '<?php echo htmlspecialchars($student['student_id']); ?>')"
                                            class="px-4 py-2 bg-blue-500 text-white rounded-lg btn-hover text-sm whitespace-nowrap flex items-center gap-2"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            View History
                                        </button>
                                        <?php if ($student['active_violations_count'] > 0): ?>
                                        <button 
                                            onclick="confirmResolveAll('<?php echo htmlspecialchars($student['id']); ?>', '<?php echo htmlspecialchars($student['name']); ?>')"
                                            class="px-4 py-2 bg-teal-500 text-white rounded-lg btn-hover text-sm whitespace-nowrap flex items-center gap-2"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            Resolve All
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="glass-card rounded-2xl overflow-hidden mt-6">
                <div class="p-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                        <div>
                            <h2 class="text-lg font-bold text-slate-800">Live Violation History</h2>
                            <p class="text-sm text-slate-500">Realtime list of recorded student violations</p>
                        </div>
                        <div class="flex-1 sm:max-w-md">
                            <input
                                type="text"
                                id="liveViolationSearch"
                                placeholder="Search name, student ID, email, RFID, course, year, semester, violation..."
                                class="w-full h-11 px-4 rounded-lg border border-slate-200 bg-white/80 text-slate-800 focus:border-sky-400 focus:ring-2 focus:ring-sky-100"
                                autocomplete="off"
                            >
                            <p id="liveViolationMeta" class="text-xs text-slate-400 mt-2">Loading...</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-white/60 bg-white/50">
                        <table class="min-w-full text-sm">
                            <thead class="bg-white/70 text-slate-600">
                                <tr>
                                    <th class="text-left px-4 py-3 font-semibold cursor-pointer select-none" data-sort-key="created_at">Date</th>
                                    <th class="text-left px-4 py-3 font-semibold cursor-pointer select-none" data-sort-key="student_name">Student</th>
                                    <th class="text-left px-4 py-3 font-semibold cursor-pointer select-none" data-sort-key="student_id">Student ID</th>
                                    <th class="text-left px-4 py-3 font-semibold cursor-pointer select-none" data-sort-key="email">Email</th>
                                    <th class="text-left px-4 py-3 font-semibold cursor-pointer select-none" data-sort-key="rfid_uid">RFID UID</th>
                                    <th class="text-left px-4 py-3 font-semibold cursor-pointer select-none" data-sort-key="course">Course</th>
                                    <th class="text-left px-4 py-3 font-semibold cursor-pointer select-none" data-sort-key="year_level">Year</th>
                                    <th class="text-left px-4 py-3 font-semibold cursor-pointer select-none" data-sort-key="current_semester">Semester</th>
                                    <th class="text-left px-4 py-3 font-semibold cursor-pointer select-none" data-sort-key="violation_name">Violation</th>
                                    <th class="text-left px-4 py-3 font-semibold cursor-pointer select-none" data-sort-key="offense_category">Offense Category</th>
                                </tr>
                            </thead>
                            <tbody id="liveViolationTbody" class="divide-y divide-slate-100 bg-white/40"></tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($activeSection === 'analytics'): ?>
        <!-- ═══ ANALYTICS SECTION ══════════════════════════════════════════════ -->

        <!-- Header -->
        <div class="glass-card rounded-2xl px-6 py-5 mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Analytics</h1>
                <p class="text-slate-500 mt-1 text-sm">Real-time overview of admin activity &amp; gate violations</p>
            </div>
            <div>
                <select id="analyticsPeriod" class="text-sm font-medium text-slate-700 bg-white border border-slate-200 rounded-xl px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer" style="padding-right:2.2rem;background-image:url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 fill=%22none%22 viewBox=%220 0 24 24%22 stroke=%22%2394a3b8%22 stroke-width=%222%22%3E%3Cpath stroke-linecap=%22round%22 stroke-linejoin=%22round%22 d=%22M19 9l-7 7-7-7%22/%3E%3C/svg%3E');background-repeat:no-repeat;background-position:right 0.6rem center;background-size:1rem;appearance:none;-webkit-appearance:none;">
                    <option value="today">Today</option>
                    <option value="week">This Week</option>
                    <option value="month" selected>This Month</option>
                    <option value="year">This Year</option>
                </select>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Actions Today -->
            <div class="glass-card rounded-2xl p-5">
                <div class="w-9 h-9 bg-sky-100 rounded-xl flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
                <p class="text-3xl font-bold text-slate-800 mb-1" id="statActionsToday"><?= number_format($analyticsStats['actionsToday']) ?></p>
                <p class="text-sm font-semibold text-slate-700" id="statActionsTitle">Actions This Month</p>
                <p class="text-xs text-slate-400 mt-0.5">All admin operations</p>
            </div>
            <!-- Violations Added -->
            <div class="glass-card rounded-2xl p-5">
                <div class="w-9 h-9 bg-amber-100 rounded-xl flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <p class="text-3xl font-bold text-slate-800 mb-1" id="statViolationsMonth"><?= number_format($analyticsStats['violationsMonth']) ?></p>
                <p class="text-sm font-semibold text-slate-700">Violations Added</p>
                <p class="text-xs text-slate-400 mt-0.5" id="statViolationsSubtitle">This Month</p>
            </div>
            <!-- Violations Resolved -->
            <div class="glass-card rounded-2xl p-5">
                <div class="w-9 h-9 bg-emerald-100 rounded-xl flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <p class="text-3xl font-bold text-slate-800 mb-1" id="statResolvedMonth"><?= number_format($analyticsStats['resolvedMonth']) ?></p>
                <p class="text-sm font-semibold text-slate-700">Violations Resolved</p>
                <p class="text-xs text-slate-400 mt-0.5" id="statResolvedSubtitle">This Month</p>
            </div>
            <!-- RFID Registered -->
            <div class="glass-card rounded-2xl p-5">
                <div class="w-9 h-9 bg-violet-100 rounded-xl flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2v-4M9 21H5a2 2 0 01-2-2v-4m0 0h18"/></svg>
                </div>
                <p class="text-3xl font-bold text-slate-800 mb-1" id="statRfidMonth"><?= number_format($analyticsStats['rfidMonth']) ?></p>
                <p class="text-sm font-semibold text-slate-700">RFID Registered</p>
                <p class="text-xs text-slate-400 mt-0.5" id="statRfidSubtitle">This Month</p>
            </div>
        </div>

        <!-- Charts Row 1: Actions by Type + Violation Events -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Horizontal Bar: Actions by Type -->
            <div class="lg:col-span-2 glass-card rounded-2xl p-6">
                <h2 class="text-base font-semibold text-slate-800 mb-1">Actions by Type</h2>
                <p class="text-xs text-slate-400 mb-5" id="chartActionSubtitle">This Month &mdash; all admin operations</p>
                <div style="position:relative;height:300px;">
                    <canvas id="chartActionTypes"></canvas>
                </div>
            </div>
            <!-- Doughnut: Violation Events -->
            <div class="glass-card rounded-2xl p-6 flex flex-col">
                <h2 class="text-base font-semibold text-slate-800 mb-1">Violation Events</h2>
                <p class="text-xs text-slate-400 mb-5" id="chartDoughnutSubtitle">This Month</p>
                <div class="flex-1 flex items-center justify-center" style="position:relative;min-height:180px;">
                    <canvas id="chartViolationSummary"></canvas>
                </div>
                <div class="mt-4 space-y-2" id="violationSummaryLegend"></div>
            </div>
        </div>

        <!-- Charts Row 2: Activity Timeline -->
        <div class="glass-card rounded-2xl p-6 mb-1">
            <h2 class="text-base font-semibold text-slate-800 mb-1">Activity Timeline</h2>
            <p class="text-xs text-slate-400 mb-5">Last 14 days &mdash; admin actions vs gate scans</p>
            <div style="position:relative;height:220px;">
                <canvas id="chartTimeline"></canvas>
            </div>
        </div>

        <input type="hidden" id="analyticsInitData" value="<?php echo htmlspecialchars(json_encode([
            'actionCounts' => (object)$analyticsActionCounts,
            'timeline' => $analyticsTimeline,
            'violationTrend' => $analyticsViolationTrend,
            'stats' => $analyticsStats
        ]), ENT_QUOTES); ?>">
        <script>
        (function () {
            const _csrf = document.body?.dataset?.csrfToken || '';

            const initData = JSON.parse(document.getElementById('analyticsInitData')?.value || '{}');

            const ACTION_LABELS = {
                APPROVE_STUDENT:        'Approve Student',
                DENY_STUDENT:           'Deny Student',
                REGISTER_RFID:          'Register RFID',
                UNREGISTER_RFID:        'Unregister RFID',
                MARK_LOST:              'Mark Lost',
                MARK_FOUND:             'Mark Found',
                UPDATE_STUDENT:         'Update Student',
                DELETE_STUDENT:         'Delete Student',
                RESOLVE_VIOLATION:      'Resolve Violation',
                RESOLVE_ALL_VIOLATIONS: 'Resolve All Violations',
                ASSIGN_REPARATION:      'Assign Reparation',
            };
            const ACTION_COLORS = {
                APPROVE_STUDENT:        'rgba(34,197,94,0.82)',
                DENY_STUDENT:           'rgba(239,68,68,0.82)',
                REGISTER_RFID:          'rgba(14,165,233,0.82)',
                UNREGISTER_RFID:        'rgba(100,116,139,0.82)',
                MARK_LOST:              'rgba(249,115,22,0.82)',
                MARK_FOUND:             'rgba(20,184,166,0.82)',
                UPDATE_STUDENT:         'rgba(99,102,241,0.82)',
                DELETE_STUDENT:         'rgba(244,63,94,0.82)',
                RESOLVE_VIOLATION:      'rgba(16,185,129,0.82)',
                RESOLVE_ALL_VIOLATIONS: 'rgba(5,150,105,0.82)',
                ASSIGN_REPARATION:      'rgba(168,85,247,0.82)',
            };

            // Global Chart.js defaults
            Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
            Chart.defaults.font.size   = 12;
            Chart.defaults.color       = '#94a3b8';
            Chart.defaults.plugins.legend.display             = false;
            Chart.defaults.plugins.tooltip.backgroundColor    = 'rgba(15,23,42,0.92)';
            Chart.defaults.plugins.tooltip.titleColor         = '#f1f5f9';
            Chart.defaults.plugins.tooltip.bodyColor          = '#cbd5e1';
            Chart.defaults.plugins.tooltip.cornerRadius       = 8;
            Chart.defaults.plugins.tooltip.padding            = 10;
            Chart.defaults.plugins.tooltip.displayColors      = false;

            function buildActionData(counts) {
                const types  = Object.keys(ACTION_LABELS).filter(t => counts[t] !== undefined);
                const sorted = types.sort((a, b) => (counts[b] || 0) - (counts[a] || 0));
                return {
                    labels: sorted.map(t => ACTION_LABELS[t] || t),
                    data:   sorted.map(t => counts[t] || 0),
                    colors: sorted.map(t => ACTION_COLORS[t] || 'rgba(148,163,184,0.8)'),
                };
            }

            // ── Chart 1: Actions by Type (horizontal bar) ────────────────────
            const actD    = buildActionData(initData.actionCounts);
            const chartBar = new Chart(document.getElementById('chartActionTypes'), {
                type: 'bar',
                data: {
                    labels: actD.labels,
                    datasets: [{ data: actD.data, backgroundColor: actD.colors, borderRadius: 5, borderSkipped: false, barThickness: 16 }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: { callbacks: { label: c => ' ' + c.parsed.x + ' action' + (c.parsed.x !== 1 ? 's' : '') } }
                    },
                    scales: {
                        x: { grid: { color: 'rgba(148,163,184,0.1)' }, border: { display: false }, ticks: { precision: 0 } },
                        y: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 12 } } }
                    }
                }
            });

            // ── Chart 2: Violation Events (doughnut) ─────────────────────────
            function renderDoughnutLegend(added, resolved, pending) {
                const el = document.getElementById('violationSummaryLegend');
                if (!el) return;
                el.innerHTML = [
                    ['Violations Added',    added,    'bg-amber-400'],
                    ['Violations Resolved', resolved, 'bg-emerald-400'],
                    ['Pending',             pending,  'bg-red-400'],
                ].map(([lbl, val, cls]) =>
                    '<div class="flex items-center justify-between text-sm">' +
                    '<div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full ' + cls + ' shrink-0"></span>' +
                    '<span class="text-slate-600">' + lbl + '</span></div>' +
                    '<span class="font-semibold text-slate-800">' + val + '</span></div>'
                ).join('');
            }
            const dAdded    = initData.stats.violationsMonth;
            const dResolved = initData.stats.resolvedMonth;
            const dPending  = initData.stats.totalPending;
            const chartDoughnut = new Chart(document.getElementById('chartViolationSummary'), {
                type: 'doughnut',
                data: {
                    labels: ['Added', 'Resolved', 'Pending'],
                    datasets: [{
                        data: [dAdded, dResolved, dPending],
                        backgroundColor: ['rgba(245,158,11,0.82)', 'rgba(16,185,129,0.82)', 'rgba(239,68,68,0.72)'],
                        borderColor:     ['rgba(245,158,11,1)',    'rgba(16,185,129,1)',    'rgba(239,68,68,1)'],
                        borderWidth: 2, hoverOffset: 6,
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: true, cutout: '68%',
                    plugins: { tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + c.parsed } } }
                }
            });
            renderDoughnutLegend(dAdded, dResolved, dPending);

            // ── Chart 3: Activity Timeline (line) ────────────────────────────
            function makeGrad(ctx, color) {
                const g = ctx.createLinearGradient(0, 0, 0, 200);
                g.addColorStop(0, color.replace('1)', '0.20)'));
                g.addColorStop(1, color.replace('1)', '0.01)'));
                return g;
            }
            const ctxLine   = document.getElementById('chartTimeline').getContext('2d');
            const chartLine = new Chart(ctxLine, {
                type: 'line',
                data: {
                    labels: initData.timeline.labels,
                    datasets: [
                        {
                            label: 'Admin Actions',
                            data: initData.timeline.counts,
                            borderColor: 'rgba(14,165,233,1)',
                            backgroundColor: makeGrad(ctxLine, 'rgba(14,165,233,1)'),
                            borderWidth: 2.5, pointRadius: 3, tension: 0.4, fill: true,
                        },
                        {
                            label: 'Gate Scans',
                            data: initData.violationTrend.counts,
                            borderColor: 'rgba(245,158,11,1)',
                            backgroundColor: makeGrad(ctxLine, 'rgba(245,158,11,1)'),
                            borderWidth: 2.5, pointRadius: 3, tension: 0.4, fill: true,
                        }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            display: true, position: 'top', align: 'end',
                            labels: { boxWidth: 12, boxHeight: 12, borderRadius: 3, color: '#64748b', padding: 16 }
                        },
                        tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + c.parsed.y } }
                    },
                    scales: {
                        x: { grid: { color: 'rgba(148,163,184,0.1)' }, border: { display: false }, ticks: { maxRotation: 0 } },
                        y: { grid: { color: 'rgba(148,163,184,0.1)' }, border: { display: false }, ticks: { precision: 0 }, beginAtZero: true }
                    }
                }
            });

            // ── Stat card updater ─────────────────────────────────────────────
            function updateStatCards(s) {
                document.getElementById('statActionsToday').textContent    = s.actionsToday.toLocaleString();
                document.getElementById('statViolationsMonth').textContent = s.violationsMonth.toLocaleString();
                document.getElementById('statResolvedMonth').textContent   = s.resolvedMonth.toLocaleString();
                document.getElementById('statRfidMonth').textContent       = s.rfidMonth.toLocaleString();
            }

            // ── Period subtitle updater ───────────────────────────────────────
            const PERIOD_LABELS = { today: 'Today', week: 'This Week', month: 'This Month', year: 'This Year' };
            const TIMELINE_DESC = { today: 'Today (hourly)', week: 'Last 7 days', month: 'Last 30 days', year: 'Last 12 months' };
            function updateSubtitles(period) {
                const lbl = PERIOD_LABELS[period] || 'This Month';
                const set = (id, text) => { const e = document.getElementById(id); if (e) e.textContent = text; };
                set('statActionsTitle',       period === 'today' ? 'Actions Today' : 'Actions ' + lbl);
                set('statViolationsSubtitle', lbl);
                set('statResolvedSubtitle',   lbl);
                set('statRfidSubtitle',       lbl);
                set('chartActionSubtitle',    lbl + ' \u2014 all admin operations');
                set('chartDoughnutSubtitle',  lbl);
                set('chartTimelineSubtitle',  (TIMELINE_DESC[period] || 'Last 30 days') + ' \u2014 admin actions vs gate scans');
            }

            let currentPeriod = 'month';

            // ── Real-time refresh ─────────────────────────────────────────────
            async function refreshAnalytics(period) {
                period = period || currentPeriod;
                try {
                    const r = await fetch('analytics_data.php?period=' + period, { headers: { 'X-CSRF-Token': _csrf } });
                    if (!r.ok) return;
                    const d = await r.json();
                    if (!d.success) return;

                    updateStatCards(d.stats);

                    const na = buildActionData(d.actionCounts);
                    chartBar.data.labels = na.labels;
                    chartBar.data.datasets[0].data = na.data;
                    chartBar.data.datasets[0].backgroundColor = na.colors;
                    chartBar.update('none');

                    const nA = d.stats.violationsMonth, nR = d.stats.resolvedMonth, nP = d.stats.totalPending;
                    chartDoughnut.data.datasets[0].data = [nA, nR, nP];
                    chartDoughnut.update('none');
                    renderDoughnutLegend(nA, nR, nP);

                    chartLine.data.labels = d.timeline.labels;
                    chartLine.data.datasets[0].data = d.timeline.counts;
                    chartLine.data.datasets[1].data = d.violationTrend.counts;
                    chartLine.update('active');
                } catch (e) { console.warn('Analytics refresh:', e); }
            }

            document.getElementById('analyticsPeriod').addEventListener('change', function () {
                currentPeriod = this.value;
                updateSubtitles(this.value);
                refreshAnalytics(this.value);
            });

            updateSubtitles('month');
            setInterval(() => refreshAnalytics(currentPeriod), 30000);
        })();
        </script>

        <?php elseif ($activeSection === 'audit'): ?>
            <!-- Audit Log Section -->
            <div class="glass-card rounded-2xl px-6 py-5 mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800">Audit Log</h1>
                    <p class="text-slate-600 mt-1">Track all administrative actions and changes</p>
                </div>
                <div class="flex items-center gap-3">
                    <span id="auditLiveIndicator" class="hidden" aria-hidden="true">
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
                        </span>
                        Live
                    </span>
                    <button id="auditLiveToggle" onclick="toggleAuditLiveRefresh()" class="hidden" aria-hidden="true">
                        <span id="auditLiveToggleText">Enable Live</span>
                    </button>
                </div>
            </div>

            <!-- Filter Options -->
            <div class="glass-card rounded-2xl p-6 mb-6">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Action Type</label>
                        <select id="filterActionType" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Actions</option>
                            <option value="APPROVE_STUDENT">Approve Student</option>
                            <option value="DENY_STUDENT">Deny Student</option>
                            <option value="REGISTER_RFID">Register RFID</option>
                            <option value="UNREGISTER_RFID">Unregister RFID</option>
                            <option value="MARK_LOST">Mark Lost</option>
                            <option value="MARK_FOUND">Mark Found</option>
                            <option value="UPDATE_STUDENT">Update Student</option>
                            <option value="DELETE_STUDENT">Delete Student</option>
                            <option value="RESOLVE_VIOLATION">Resolve Violation</option>
                            <option value="RESOLVE_ALL_VIOLATIONS">Resolve All Violations</option>
                            <option value="ASSIGN_REPARATION">Assign Reparation</option>
                            <option value="EXPORT_AUDIT_LOG">Export Audit Log</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Date From</label>
                        <input type="date" id="filterDateFrom" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Date To</label>
                        <input type="date" id="filterDateTo" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div class="flex items-end">
                        <button onclick="applyAuditFilters()" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                            Apply Filters
                        </button>
                    </div>

                    <div class="flex items-end">
                        <button onclick="exportAuditToExcel()" class="w-full px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors font-medium flex items-center justify-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                            </svg>
                            Export to Excel
                        </button>
                    </div>
                </div>
            </div>

            <!-- Audit Log Table -->
            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-white/40 border-b border-white/50">
                            <tr>
                                <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Timestamp</th>
                                <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Admin</th>
                                <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Action</th>
                                <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Target</th>
                                <th class="text-left py-3 px-4 text-sm font-semibold text-slate-700">Description</th>
                                <th class="text-center py-3 px-4 text-sm font-semibold text-slate-700">Details</th>
                            </tr>
                        </thead>
                        <tbody id="auditLogTableBody">
                            <?php
                            if (empty($auditLogs)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-12 text-slate-600">
                                        <svg class="w-16 h-16 mx-auto text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                        <p class="font-medium text-slate-700 mb-1">No audit logs yet</p>
                                        <p class="text-sm text-slate-500">Administrative actions will appear here</p>
                                    </td>
                                </tr>
                            <?php else:
                                foreach ($auditLogs as $log):
                                    // Color-code action types
                                    $actionColors = [
                                        'APPROVE_STUDENT' => 'bg-green-100 text-green-800',
                                        'DENY_STUDENT' => 'bg-red-100 text-red-800',
                                        'REGISTER_RFID' => 'bg-blue-100 text-blue-800',
                                        'UNREGISTER_RFID' => 'bg-yellow-100 text-yellow-800',
                                        'MARK_LOST' => 'bg-orange-100 text-orange-800',
                                        'MARK_FOUND' => 'bg-emerald-100 text-emerald-800',
                                        'UPDATE_STUDENT' => 'bg-indigo-100 text-indigo-800',
                                        'DELETE_STUDENT' => 'bg-red-100 text-red-800',
                                        'ADD_VIOLATION' => 'bg-rose-100 text-rose-800',
                                        'RESOLVE_VIOLATION' => 'bg-teal-100 text-teal-800',
                                        'RESOLVE_ALL_VIOLATIONS' => 'bg-emerald-100 text-emerald-800',
                                        'ASSIGN_REPARATION' => 'bg-amber-100 text-amber-800',
                                        'EXPORT_AUDIT_LOG' => 'bg-purple-100 text-purple-800',
                                    ];
                                    $actionColor = $actionColors[$log['action_type']] ?? 'bg-slate-100 text-slate-800';
                            ?>
                                <tr class="border-b border-slate-100 hover:bg-slate-50 transition-colors">
                                    <td class="py-3 px-4 text-sm text-slate-600">
                                        <?php echo date('M d, Y', strtotime($log['created_at'])); ?><br>
                                        <span class="text-xs text-slate-500"><?php echo date('h:i A', strtotime($log['created_at'])); ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-sm font-medium text-slate-800">
                                        <?php echo htmlspecialchars($log['admin_name']); ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="<?php echo $actionColor; ?> text-xs font-semibold px-2 py-1 rounded-full">
                                            <?php echo str_replace('_', ' ', $log['action_type']); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-slate-700">
                                        <?php if ($log['target_name']): ?>
                                            <strong><?php echo htmlspecialchars($log['target_name']); ?></strong><br>
                                            <span class="text-xs text-slate-500"><?php echo ucfirst($log['target_type']); ?> ID: <?php echo $log['target_id']; ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-500">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-slate-600">
                                        <?php
                                        $normalizedDescription = preg_replace_callback(
                                            '/Strike\s*#\s*(\d+)/i',
                                            static function (array $matches): string {
                                                $n = max(1, (int)($matches[1] ?? 1));
                                                if ($n % 100 >= 11 && $n % 100 <= 13) {
                                                    $suffix = 'th';
                                                } else {
                                                    $last = $n % 10;
                                                    $suffix = $last === 1 ? 'st' : ($last === 2 ? 'nd' : ($last === 3 ? 'rd' : 'th'));
                                                }

                                                return $n . $suffix . ' Offense';
                                            },
                                            (string)($log['description'] ?? '')
                                        );
                                        echo htmlspecialchars($normalizedDescription ?? (string)($log['description'] ?? ''));
                                        ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <?php if ($log['details']): ?>
                                            <button onclick='showAuditDetails(<?php echo htmlspecialchars(json_encode($log), ENT_QUOTES); ?>)' 
                                                    class="text-blue-600 hover:text-blue-800 text-sm font-medium transition-colors">
                                                View Details
                                            </button>
                                        <?php else: ?>
                                            <span class="text-slate-500 text-sm">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($activeSection === 'face_enroll'): ?>
            <!-- Face Enrollment Section -->
            <div class="glass-card rounded-2xl px-6 py-5 mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Face Enrollment</h1>
                <p class="text-slate-600 mt-1">Enroll student faces for gate recognition. Only students with a registered RFID card are shown.</p>
            </div>

            <?php if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
                    <p class="text-yellow-800 font-medium">Face recognition is currently disabled.</p>
                    <p class="text-yellow-600 text-sm mt-1">Set FACE_RECOGNITION_ENABLED=true in your .env file.</p>
                </div>
            <?php else: ?>

            <?php
            // Get students with RFID registered but face NOT yet enrolled
            $enrollEligible = [];
            foreach ($allStudents as $s) {
                if (!empty($s['rfid_uid']) && empty($s['face_registered'])) {
                    $enrollEligible[] = $s;
                }
            }
            // Also get recently enrolled (last 7 days) for the "recently enrolled" section
            try {
                $recentEnrolled = $pdo->query("
                    SELECT u.id, u.name, u.student_id, u.email, u.profile_picture, u.face_registered_at,
                           (SELECT COUNT(*) FROM face_descriptors fd WHERE fd.user_id = u.id AND fd.is_active = 1) AS descriptor_count
                    FROM users u
                                        WHERE u.role = 'Student' AND u.status = 'Active' AND u.deleted_at IS NULL AND u.face_registered = 1
                      AND u.face_registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ORDER BY u.face_registered_at DESC
                    LIMIT 10
                ")->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                $recentEnrolled = [];
            }
            ?>

            <!-- Stats -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="glass-card rounded-2xl p-6 border-l-4 border-[#0056b3]">
                    <p class="text-sm font-medium text-slate-600">Pending Enrollment</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo count($enrollEligible); ?></p>
                    <p class="text-xs text-slate-500 mt-1">Students with RFID, no face</p>
                </div>
                <div class="glass-card rounded-2xl p-6 border-l-4 border-green-500">
                    <p class="text-sm font-medium text-slate-600">Recently Enrolled</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo count($recentEnrolled); ?></p>
                    <p class="text-xs text-slate-500 mt-1">Last 7 days</p>
                </div>
                <div class="glass-card rounded-2xl p-6 border-l-4 border-purple-500">
                    <p class="text-sm font-medium text-slate-600">Total RFID Registered</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo $registeredCount; ?></p>
                    <p class="text-xs text-slate-500 mt-1">Active students with cards</p>
                </div>
            </div>

            <!-- Pending Enrollment Table -->
            <div class="glass-card rounded-2xl overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-white/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800">📷 Pending Face Enrollment</h3>
                        <p class="text-sm text-slate-600">Students who have RFID cards but no face registered yet</p>
                    </div>
                    <input type="text" id="enrollSearchInput" placeholder="Search by name or ID..." 
                           class="px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           onkeyup="filterEnrollStudents()">
                </div>

                <?php if (empty($enrollEligible)): ?>
                    <div class="text-center py-16 px-6">
                        <svg class="w-20 h-20 mx-auto text-green-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <h4 class="text-xl font-semibold text-slate-700 mb-2">All Caught Up!</h4>
                        <p class="text-slate-500">All students with RFID cards have been enrolled in face recognition.</p>
                        <p class="text-slate-500 text-sm mt-1">New students will appear here after their RFID card is registered.</p>
                    </div>
                <?php else: ?>
                    <div class="grid gap-4 p-6" id="enrollStudentGrid">
                        <?php foreach ($enrollEligible as $es): ?>
                        <div class="border border-slate-200 rounded-xl p-5 hover:border-[#0056b3] hover:shadow-md transition-all enroll-student-card"
                             data-search="<?php echo strtolower(e($es['name']) . ' ' . e($es['student_id']) . ' ' . e($es['email'])); ?>">
                            <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4">
                                <!-- Profile Picture -->
                                <div class="flex-shrink-0">
                                    <?php if (!empty($es['profile_picture'])): ?>
                                        <img src="../assets/images/profiles/<?php echo e($es['profile_picture']); ?>" 
                                             class="w-16 h-16 rounded-full object-cover border-2 border-slate-200">
                                    <?php else: ?>
                                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-bold text-xl">
                                            <?php echo strtoupper(substr($es['name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Student Info -->
                                <div class="flex-1 text-center sm:text-left">
                                    <h4 class="font-semibold text-slate-800 text-lg"><?php echo e($es['name']); ?></h4>
                                    <p class="text-sm text-slate-600">ID: <?php echo e($es['student_id']); ?></p>
                                    <p class="text-sm text-slate-500"><?php echo e($es['email']); ?></p>
                                    <div class="flex items-center gap-2 mt-2 justify-center sm:justify-start">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                            RFID: <?php echo e($es['rfid_uid']); ?>
                                        </span>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700">
                                            ⏳ Face Not Enrolled
                                        </span>
                                    </div>
                                </div>

                                <!-- Enroll Button -->
                                <div class="flex-shrink-0">
                                    <button onclick="openFaceEnrollModal(<?php echo (int)$es['id']; ?>, <?php echo e(json_encode($es['name'])); ?>, <?php echo e(json_encode($es['student_id'])); ?>)" 
                                            class="px-5 py-2.5 bg-[#0056b3] hover:bg-blue-700 text-white rounded-xl transition-colors font-medium text-sm shadow-sm hover:shadow-md flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        </svg>
                                        Enroll Face
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($recentEnrolled)): ?>
            <!-- Recently Enrolled -->
            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="px-6 py-4 border-b border-white/50">
                    <h3 class="text-lg font-semibold text-slate-800">✅ Recently Enrolled</h3>
                    <p class="text-sm text-slate-600">Faces enrolled in the last 7 days</p>
                </div>
                <div class="divide-y divide-white/30">
                    <?php foreach ($recentEnrolled as $re): ?>
                    <div class="px-6 py-4 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <?php if (!empty($re['profile_picture'])): ?>
                                <img src="../assets/images/profiles/<?php echo e($re['profile_picture']); ?>" class="w-10 h-10 rounded-full object-cover">
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center text-green-700 font-bold">
                                    <?php echo strtoupper(substr($re['name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <p class="font-medium text-slate-800"><?php echo e($re['name']); ?></p>
                                <p class="text-xs text-slate-500"><?php echo e($re['student_id']); ?> • <?php echo e($re['email']); ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                ✅ <?php echo (int)$re['descriptor_count']; ?> face(s)
                            </span>
                            <p class="text-xs text-slate-500 mt-1"><?php echo date('M d, Y h:i A', strtotime($re['face_registered_at'])); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>

        <?php elseif ($activeSection === 'face'): ?>
            <!-- Face Recognition Management Section -->
            <div class="glass-card rounded-2xl px-6 py-5 mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Face Recognition Management</h1>
                <p class="text-slate-600 mt-1">Review and manage student face recognition data</p>
            </div>

            <?php if (!filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
                    <p class="text-yellow-800 font-medium">Face recognition is currently disabled.</p>
                    <p class="text-yellow-600 text-sm mt-1">Set FACE_RECOGNITION_ENABLED=true in your .env file.</p>
                </div>
            <?php else: ?>

            <!-- Face Stats -->
            <?php
            try {
                $faceRegCount = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM face_descriptors WHERE is_active = 1")->fetchColumn();
                $faceTotalDesc = $pdo->query("SELECT COUNT(*) FROM face_descriptors WHERE is_active = 1")->fetchColumn();
                $faceEntriesToday = $pdo->query("SELECT COUNT(*) FROM face_entry_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
            } catch (\PDOException $e) {
                $faceRegCount = 0;
                $faceTotalDesc = 0;
                $faceEntriesToday = 0;
            }
            ?>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="glass-card rounded-2xl p-6">
                    <p class="text-sm font-medium text-slate-600">Students with Face ID</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo $faceRegCount; ?></p>
                </div>
                <div class="glass-card rounded-2xl p-6">
                    <p class="text-sm font-medium text-slate-600">Total Descriptors</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo $faceTotalDesc; ?></p>
                </div>
                <div class="glass-card rounded-2xl p-6">
                    <p class="text-sm font-medium text-slate-600">Face Entries Today</p>
                    <p class="text-3xl font-bold text-slate-800 mt-1"><?php echo $faceEntriesToday; ?></p>
                </div>
            </div>

            <!-- Student List for Face Registration -->
            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="px-6 py-4 border-b border-white/50 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800">Active Students</h3>
                        <p class="text-sm text-slate-600">View registration status and remove existing face data when needed</p>
                    </div>
                    <input type="text" id="faceSearchInput" placeholder="Search students..." 
                           class="px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           onkeyup="filterFaceStudents()">
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full" id="faceStudentTable">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Student ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Face Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Descriptors</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <?php
                            // Get all active students with face registration status
                            try {
                                $faceStudents = $pdo->query("
                                    SELECT u.id, u.student_id, u.name, u.email, u.profile_picture, u.face_registered,
                                           (SELECT COUNT(*) FROM face_descriptors fd WHERE fd.user_id = u.id AND fd.is_active = 1) as descriptor_count
                                    FROM users u
                                     WHERE u.role = 'Student' AND u.status = 'Active' AND u.deleted_at IS NULL
                                    ORDER BY u.face_registered ASC, u.name ASC
                                ")->fetchAll(\PDO::FETCH_ASSOC);
                            } catch (\PDOException $e) {
                                $faceStudents = [];
                            }
                            $faceDescriptorLimit = (int)env('FACE_MAX_DESCRIPTORS_PER_STUDENT', '3');
                            if ($faceDescriptorLimit <= 0 || $faceDescriptorLimit > 3) {
                                $faceDescriptorLimit = 3;
                            }
                            
                            if (empty($faceStudents)): ?>
                                <tr><td colspan="5" class="px-6 py-8 text-center text-slate-500">No active students found.</td></tr>
                            <?php else:
                                foreach ($faceStudents as $fs): ?>
                                <tr class="hover:bg-slate-50 face-student-row">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <?php if (!empty($fs['profile_picture'])): ?>
                                                <img src="../assets/images/profiles/<?php echo e($fs['profile_picture']); ?>" class="w-10 h-10 rounded-full object-cover">
                                            <?php else: ?>
                                                <div class="w-10 h-10 rounded-full bg-slate-200 flex items-center justify-center text-slate-600 font-bold">
                                                    <?php echo strtoupper(substr($fs['name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <p class="font-medium text-slate-800"><?php echo e($fs['name']); ?></p>
                                                <p class="text-xs text-slate-500"><?php echo e($fs['email']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-600"><?php echo e($fs['student_id']); ?></td>
                                    <td class="px-6 py-4">
                                        <?php if ($fs['face_registered']): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                                Registered
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-500">
                                                Not Registered
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-600"><?php echo (int)$fs['descriptor_count']; ?> / <?php echo $faceDescriptorLimit; ?></td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <?php if ($fs['face_registered']): ?>
                                            <button onclick="deleteFaceData(<?php echo $fs['id']; ?>, <?php echo e(json_encode($fs['name'])); ?>)" 
                                                    class="px-3 py-1.5 bg-red-500 hover:bg-red-600 text-white text-xs rounded-lg transition-colors font-medium">
                                                🗑 Delete
                                            </button>
                                            <?php else: ?>
                                            <span class="text-xs text-slate-400">-</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif ($activeSection === 'rfid_checker'): ?>
            <!-- RFID ID Checker Section -->
            <div class="glass-card rounded-2xl px-6 py-5 mb-6">
                <h1 class="text-2xl font-bold text-slate-800">RFID ID Checker</h1>
                <p class="text-slate-600 mt-1">Scan or enter an RFID UID to look up card status, student info, violations, and last scan</p>
            </div>

            <div class="max-w-3xl">
                <!-- Scan Input Area -->
                <div class="glass-card rounded-2xl p-6 mb-6">
                    <div class="flex flex-col sm:flex-row gap-4">
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-slate-700 mb-2">RFID Card UID</label>
                            <input type="text" id="rfidCheckerInput" placeholder="Tap RFID card or type UID here..." 
                                   class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-lg font-mono tracking-wider"
                                   autocomplete="off" autofocus>
                        </div>
                        <div class="flex items-end">
                            <button onclick="lookupRfidCard()" class="px-6 py-3 bg-[#0056b3] text-white rounded-lg hover:bg-blue-700 transition-colors font-medium flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                Check
                            </button>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500 mt-2">Tip: Place cursor in the field and tap the RFID card on the reader. The UID will auto-populate.</p>
                </div>

                <!-- Result Area -->
                <div id="rfidCheckerResult"></div>
            </div>

        <?php endif; ?>
    </main>
</div>

<!-- Card Registration Modal -->
<div id="cardModal" class="fixed inset-0 bg-slate-900/35 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
    <div class="glass-card rounded-[2rem] p-6 sm:p-8 max-w-md w-full fade-in border border-white/60 shadow-[0_26px_65px_rgba(15,23,42,0.24)]">
        <div>
            <div class="rfid-scan-orb mb-4">
                <span class="rfid-scan-ring" aria-hidden="true"></span>
                <img src="../assets/images/gatewatch-logo.png" alt="Gatewatch" class="rfid-scan-logo">
            </div>
            <h3 class="text-3xl sm:text-4xl font-black tracking-tight text-slate-700 text-center">RFID Registration</h3>
            <p class="text-slate-600 mt-2 mb-6 text-center">Step 1: Confirm student details before scanning card.</p>

            <div id="rfidPrecheckPanel" class="space-y-4">
                <div>
                    <label for="rfidCourseInput" class="block text-sm font-medium text-slate-700 mb-1">Course</label>
                    <div class="relative">
                        <select
                            id="rfidCourseInput"
                            onchange="debouncedValidateRfidEligibility()"
                            class="w-full appearance-none px-4 py-2.5 pr-10 border border-slate-300 rounded-lg bg-white text-slate-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        >
                            <option value="">-- Select Course --</option>
                            <option value="BS Computer Science">BS Computer Science</option>
                            <option value="BS Information Technology">BS Information Technology</option>
                            <option value="BS Computer Engineering">BS Computer Engineering</option>
                            <option value="BS Accountancy">BS Accountancy</option>
                            <option value="BS Business Administration">BS Business Administration</option>
                            <option value="BS Hospitality Management">BS Hospitality Management</option>
                            <option value="BS Tourism Management">BS Tourism Management</option>
                            <option value="BS Education">BS Education</option>
                            <option value="BS Psychology">BS Psychology</option>
                            <option value="BS Nursing">BS Nursing</option>
                            <option value="BS Criminology">BS Criminology</option>
                            <option value="BS Social Work">BS Social Work</option>
                        </select>
                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400" aria-hidden="true">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.51a.75.75 0 0 1-1.08 0l-4.25-4.51a.75.75 0 0 1 .02-1.06z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    </div>
                </div>
                <div>
                    <label for="rfidYearInput" class="block text-sm font-medium text-slate-700 mb-1">Year</label>
                    <input
                        type="text"
                        id="rfidYearInput"
                        oninput="debouncedValidateRfidEligibility()"
                        class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        placeholder="Enter year (e.g., 1st Year)"
                        autocomplete="off"
                    >
                </div>
                <div>
                    <label for="rfidSemesterInput" class="block text-sm font-medium text-slate-700 mb-1">Semester</label>
                    <div class="relative">
                        <select
                            id="rfidSemesterInput"
                            onchange="debouncedValidateRfidEligibility()"
                            class="w-full appearance-none px-4 py-2.5 pr-10 border border-slate-300 rounded-lg bg-white text-slate-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        >
                            <option value="">-- Select Semester --</option>
                            <option value="1st">1st Semester</option>
                            <option value="2nd">2nd Semester</option>
                        </select>
                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400" aria-hidden="true">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.51a.75.75 0 0 1-1.08 0l-4.25-4.51a.75.75 0 0 1 .02-1.06z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    </div>
                </div>
                <div>
                    <label for="rfidStudentIdInput" class="block text-sm font-medium text-slate-700 mb-1">Student ID</label>
                    <input
                        type="text"
                        id="rfidStudentIdInput"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="9"
                        oninput="enforceNumericStudentId(this); debouncedValidateRfidEligibility()"
                        class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        placeholder="Enter student ID"
                        autocomplete="off"
                    >
                    <div id="rfidStudentIdRealtimeResult" class="text-xs mt-2 px-3 py-2 rounded-lg bg-slate-100 text-slate-600">
                        Enter Student ID to check enrollment and avoid duplicate registration.
                    </div>
                </div>
                <div>
                    <label for="rfidFullNameInput" class="block text-sm font-medium text-slate-700 mb-1">Full Name</label>
                    <input
                        type="text"
                        id="rfidFullNameInput"
                        oninput="debouncedValidateRfidEligibility()"
                        class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        placeholder="Enter full name"
                        autocomplete="off"
                    >
                </div>

                <div id="rfidStudentCheckResult" class="hidden" aria-hidden="true"></div>

                <button
                    id="rfidProceedBtn"
                    onclick="proceedToRfidScan()"
                    disabled
                    class="w-full px-4 py-2.5 bg-[#0056b3] text-white rounded-lg font-medium disabled:opacity-50 disabled:cursor-not-allowed hover:bg-blue-700 transition-colors"
                >
                    Proceed to Tap Card
                </button>
            </div>

            <div id="rfidScannerPanel" class="hidden">
                <p class="text-slate-600 mt-2 mb-4 text-center">Step 2: Tap RFID card to complete registration.</p>
                <div class="text-center" id="rfidStatus"></div>
                <div class="flex items-center justify-center gap-3 mt-6">
                    <button onclick="showRfidPrecheckPanel()" class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg hover:bg-slate-200 font-medium transition-colors">
                        Back
                    </button>
                    <button onclick="closeCardModal()" class="px-4 py-2 text-slate-600 hover:text-slate-800 font-medium">
                        Cancel
                    </button>
                </div>
            </div>

            <button id="rfidPrecheckCancelBtn" onclick="closeCardModal()" class="mt-6 px-4 py-2 text-slate-600 hover:text-slate-800 font-medium w-full">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- Card Details Modal -->
<div id="cardDetailsModal" class="fixed inset-0 bg-slate-900/35 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
    <div class="glass-card rounded-[2rem] p-6 sm:p-8 max-w-lg w-full fade-in border border-white/60 shadow-[0_26px_65px_rgba(15,23,42,0.24)]">
        <h3 class="text-3xl font-black tracking-tight text-slate-700 mb-1">RFID Card Details</h3>
        <p class="text-slate-500 text-sm mb-5">Registered card profile</p>
        <div id="cardDetailsContent"></div>
        <button onclick="closeCardDetailsModal()" class="mt-6 px-4 py-2 bg-white/75 border border-slate-200 text-slate-700 rounded-xl hover:bg-white transition-colors font-medium">
            Close
        </button>
    </div>
</div>

<!-- Violation History Modal -->
<div id="violationHistoryModal" class="fixed inset-0 bg-slate-900/35 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
    <div class="glass-card rounded-[2rem] p-6 sm:p-7 max-w-4xl w-full mx-4 fade-in border border-white/60 shadow-[0_26px_65px_rgba(15,23,42,0.24)] max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-xl font-semibold text-slate-800">Violation History</h3>
                <p id="violationHistorySubtitle" class="text-sm text-slate-500"></p>
            </div>
            <button onclick="closeViolationHistoryModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <!-- Filters -->
        <div class="flex flex-wrap gap-3 mb-4">
            <select id="vhFilterSchoolYear" onchange="filterViolationHistory()" class="h-9 px-3 rounded-lg border border-white/70 text-sm bg-white/80 text-slate-700 focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                <option value="">All School Years</option>
            </select>
            <select id="vhFilterType" onchange="filterViolationHistory()" class="h-9 px-3 rounded-lg border border-white/70 text-sm bg-white/80 text-slate-700 focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                <option value="">All Types</option>
                <option value="minor">Minor</option>
                <option value="major">Moderate</option>
                <option value="grave">Major</option>
            </select>
            <select id="vhFilterStatus" onchange="filterViolationHistory()" class="h-9 px-3 rounded-lg border border-white/70 text-sm bg-white/80 text-slate-700 focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="pending_reparation">Pending Reparation</option>
                <option value="apprehended">Apprehended</option>
            </select>
        </div>
        <!-- Summary -->
        <div id="vhSummaryBar" class="flex gap-3 mb-4"></div>
        <!-- History Content -->
        <div id="violationHistoryContent">
            <div class="text-center py-8 text-slate-400">Loading...</div>
        </div>
    </div>
</div>

<!-- Add Violation Modal -->
<div id="addViolationModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-6 max-w-2xl w-full mx-4 fade-in max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-xl font-semibold text-slate-800">Add Violation</h3>
                <p id="addViolationSubtitle" class="text-sm text-slate-500"></p>
            </div>
            <button onclick="closeAddViolationModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form id="addViolationForm" onsubmit="submitNewViolation(event)" class="space-y-4">
            <input type="hidden" id="avStudentId" value="" />
            
            <!-- Student search (shown when no pre-selected student) -->
            <div id="avStudentSearchGroup">
                <label class="block text-sm font-medium text-slate-700 mb-1">Student</label>
                <input type="text" id="avStudentSearch" placeholder="Search by name or student ID..." 
                    class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none"
                    oninput="searchStudentsForViolation(this.value)" />
                <div id="avStudentResults" class="mt-2 max-h-40 overflow-y-auto rounded-lg border border-slate-200 hidden"></div>
            </div>
            <div id="avSelectedStudent" class="hidden p-3 bg-indigo-50 border border-indigo-200 rounded-lg items-center justify-between">
                <span id="avSelectedStudentName" class="font-medium text-indigo-800"></span>
                <button type="button" onclick="clearSelectedViolationStudent()" class="text-indigo-400 hover:text-indigo-600 text-sm">Change</button>
            </div>

            <!-- Category -->
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Violation Category <span class="text-red-500">*</span></label>
                <select id="avCategory" required class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none" onchange="updateStrikeIndicator()">
                    <option value="">Select a category...</option>
                </select>
                <div id="avStrikeIndicator" class="mt-2 hidden"></div>
            </div>

            <!-- Description -->
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Description / Notes</label>
                <textarea id="avDescription" rows="3" placeholder="Details about the violation..."
                    class="w-full px-4 py-3 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none resize-none"></textarea>
            </div>

            <!-- School Year & Semester -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">School Year</label>
                    <input type="text" id="avSchoolYear" placeholder="e.g. 2024-2025" 
                        class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Semester</label>
                    <select id="avSemester" class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none">
                        <option value="1st">1st Semester</option>
                        <option value="2nd">2nd Semester</option>
                        <option value="Summer">Summer</option>
                    </select>
                </div>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" id="avSubmitBtn" class="flex-1 h-11 bg-rose-600 text-white rounded-lg font-semibold btn-hover flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                    Record Violation
                </button>
                <button type="button" onclick="closeAddViolationModal()" class="px-6 h-11 bg-slate-200 text-slate-700 rounded-lg font-medium hover:bg-slate-300 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Resolve Violation Modal -->
<div id="resolveViolationModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-6 max-w-lg w-full mx-4 fade-in">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-semibold text-slate-800">Resolve Violation</h3>
            <button onclick="closeResolveViolationModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <p id="resolveViolationInfo" class="text-sm text-slate-600 mb-4"></p>
        <form id="resolveViolationForm" onsubmit="submitResolveViolation(event)" class="space-y-4">
            <input type="hidden" id="rvViolationId" value="" />
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Reparation Type <span class="text-red-500">*</span></label>
                <select id="rvReparationType" required class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none">
                    <option value="">Loading policy tasks...</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
                <textarea id="rvNotes" rows="3" placeholder="Details about the reparation..."
                    class="w-full px-4 py-3 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none resize-none"></textarea>
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" id="rvSendNotification" checked class="w-4 h-4 text-teal-600 border-slate-300 rounded focus:ring-teal-500" />
                <label for="rvSendNotification" class="text-sm text-slate-700">Send email notification to student</label>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" id="rvSubmitBtn" class="flex-1 h-11 bg-teal-600 text-white rounded-lg font-semibold btn-hover flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Mark as Apprehended
                </button>
                <button type="button" onclick="closeResolveViolationModal()" class="px-6 h-11 bg-slate-200 text-slate-700 rounded-lg font-medium hover:bg-slate-300 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Assign Reparation Task Modal -->
<div id="assignReparationModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-6 max-w-lg w-full mx-4 fade-in">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-semibold text-slate-800">Assign Reparation Task</h3>
            <button onclick="closeAssignReparationModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4">
            <p class="text-sm text-amber-800 font-medium">Assigning a task will set the violation to <strong>Pending Reparation</strong> and notify the student by email of what they must do.</p>
        </div>
        <p id="assignReparationInfo" class="text-sm text-slate-600 mb-4"></p>
        <div id="arPolicySummary" class="hidden mb-4 p-3 rounded-lg border border-sky-200 bg-sky-50/80">
            <p class="text-xs font-semibold uppercase tracking-[0.08em] text-sky-700 mb-1">Policy Guidance</p>
            <p id="arPolicySummaryTitle" class="text-sm font-semibold text-slate-800"></p>
            <p id="arPolicySummaryAction" class="text-xs text-slate-600 mt-1"></p>
            <p id="arPolicySummaryTask" class="text-xs text-sky-800 mt-2 font-medium"></p>
        </div>
        <form id="assignReparationForm" onsubmit="submitAssignReparation(event)" class="space-y-4">
            <input type="hidden" id="arViolationId" value="" />
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Reparation Task <span class="text-red-500">*</span></label>
                <select id="arReparationType" required class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-amber-500 focus:ring-4 focus:ring-amber-100 focus:outline-none">
                    <option value="">Loading policy tasks...</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Additional Instructions <span class="text-slate-400 font-normal">(optional)</span></label>
                <textarea id="arNotes" rows="3" placeholder="e.g. 8 hours of service at the library, complete by end of semester..."
                    class="w-full px-4 py-3 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-amber-500 focus:ring-4 focus:ring-amber-100 focus:outline-none resize-none"></textarea>
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" id="arSendNotification" checked class="w-4 h-4 text-amber-600 border-slate-300 rounded focus:ring-amber-500" />
                <label for="arSendNotification" class="text-sm text-slate-700">Send email to student explaining the required task</label>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" id="arSubmitBtn" class="flex-1 h-11 bg-amber-500 text-white rounded-lg font-semibold btn-hover flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    Assign Reparation Task
                </button>
                <button type="button" onclick="closeAssignReparationModal()" class="px-6 h-11 bg-slate-200 text-slate-700 rounded-lg font-medium hover:bg-slate-300 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Student Modal -->
<div id="editStudentModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-8 max-w-5xl w-full mx-4 fade-in max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-semibold text-slate-800">Edit Student Information</h3>
            <button onclick="closeEditStudentModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Left Column: Edit Form -->
            <div id="editStudentForm">
                <input type="hidden" id="editStudentUserId" />
                
                <!-- Student Information -->
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Full Name</label>
                        <input type="text" id="editStudentName" oninput="updateDigitalIdPreview()"
                               class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Student ID</label>
                        <input type="text" id="editStudentId" inputmode="numeric" pattern="[0-9]*" maxlength="9" oninput="enforceNumericStudentId(this); updateDigitalIdPreview(); debouncedValidateEditStudentId()"
                               class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none">
                        <p class="text-xs text-slate-500 mt-1">Replace temporary ID with the real ID. Student ID must be exactly 9 digits.</p>
                        <div id="editStudentIdRealtimeResult" class="text-xs mt-2 px-3 py-2 rounded-lg bg-slate-100 text-slate-600">Student ID must be exactly 9 digits and not already taken.</div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Course / Program</label>
                        <select id="editStudentCourse" onchange="updateDigitalIdPreview()"
                                class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-white text-slate-800 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none">
                            <option value="">-- Select Course --</option>
                            <option value="BS Computer Science">BS Computer Science</option>
                            <option value="BS Information Technology">BS Information Technology</option>                
                            <option value="BS Computer Engineering">BS Computer Engineering</option>
                            <option value="BS Accountancy">BS Accountancy</option>
                            <option value="BS Business Administration">BS Business Administration</option>
                            <option value="BS Hospitality Management">BS Hospitality Management</option>
                            <option value="BS Tourism Management">BS Tourism Management</option>
                            <option value="BS Education">BS Education</option>
                            <option value="BS Psychology">BS Psychology</option>
                            <option value="BS Nursing">BS Nursing</option>
                            <option value="BS Criminology">BS Criminology</option>
                            <option value="BS Social Work">BS Social Work</option>                          
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Email (Read-only)</label>
                        <input type="email" id="editStudentEmail" readonly
                               class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 bg-slate-100 text-slate-600 cursor-not-allowed">
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex gap-3 mt-6">
                    <button type="button" id="editStudentSaveBtn" onclick="saveStudentInfo()" 
                            class="flex-1 h-11 bg-indigo-600 text-white text-base font-medium rounded-lg shadow-md hover:bg-indigo-700 transition-colors">
                        Save Changes
                    </button>
                    <button type="button" onclick="closeEditStudentModal()" 
                            class="flex-1 h-11 bg-slate-200 text-slate-700 text-base font-medium rounded-lg shadow-md hover:bg-slate-300 transition-colors">
                        Cancel
                    </button>
                </div>
            </div>
            
            <!-- Right Column: Live Digital ID Preview -->
            <div class="flex flex-col items-center">
                <h4 class="text-lg font-semibold text-slate-700 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                    </svg>
                    Digital ID Preview
                    <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-normal">Live</span>
                </h4>
                <div id="digitalIdPreviewContainer" class="w-full"></div>
                <p class="text-xs text-slate-400 mt-3 text-center">Drag & drop a photo onto the ID card, or click the photo area to upload</p>
                <button type="button" id="deletePhotoBtn" onclick="removeIdCardPhoto()" style="display:none;" class="mt-2 px-4 py-1.5 bg-red-500 text-white rounded-lg hover:bg-red-600 text-xs font-medium transition-colors">Remove Photo</button>
            </div>
        </div>
    </div>
</div>

<!-- Custom Confirmation Modal -->
<div id="customConfirmModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-[60]" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 transform transition-all scale-95 opacity-0" id="customConfirmContent">
        <div class="p-6">
            <div class="flex items-start gap-4">
                <div id="confirmIcon" class="flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center"></div>
                <div class="flex-1">
                    <h3 id="confirmTitle" class="text-lg font-semibold text-slate-800 mb-2"></h3>
                    <div id="confirmMessage" class="text-sm text-slate-600 space-y-1"></div>
                </div>
            </div>
        </div>
        <div class="border-t border-slate-200 p-4 flex gap-3">
            <button id="confirmCancelBtn" class="flex-1 h-11 px-4 bg-slate-100 text-slate-700 rounded-lg font-medium hover:bg-slate-200 transition-colors">
                Cancel
            </button>
            <button id="confirmOkBtn" class="flex-1 h-11 px-4 bg-sky-600 text-white rounded-lg font-medium hover:bg-sky-700 transition-colors shadow-sm">
                OK
            </button>
        </div>
    </div>
</div>

<!-- Toast Notification Container -->
<div id="toastContainer" class="fixed top-4 left-4 right-4 sm:left-auto sm:right-4 z-[70] space-y-3 pointer-events-none"></div>

<script>
// CSRF Token for JavaScript fetch requests
const csrfToken = document.body?.dataset?.csrfToken || '';

// ========================================
// CUSTOM MODAL & NOTIFICATION SYSTEM
// ========================================

// Custom Confirm Dialog
function showCustomConfirm(title, message, options = {}) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customConfirmModal');
        const content = document.getElementById('customConfirmContent');
        const titleEl = document.getElementById('confirmTitle');
        const messageEl = document.getElementById('confirmMessage');
        const iconEl = document.getElementById('confirmIcon');
        const okBtn = document.getElementById('confirmOkBtn');
        const cancelBtn = document.getElementById('confirmCancelBtn');
        
        // Set content
        titleEl.textContent = title;
        
        // Handle message (can be string or array of items)
        if (Array.isArray(message)) {
            messageEl.innerHTML = message.map(item => `<div class="flex items-start gap-2"><span>${item}</span></div>`).join('');
        } else {
            // Check if message contains HTML tags, if so use innerHTML, otherwise textContent for safety
            if (message.includes('<') && message.includes('>')) {
                messageEl.innerHTML = message;
            } else {
                messageEl.textContent = message;
            }
        }
        
        // Set icon based on type
        const type = options.type || 'warning';
        if (type === 'warning') {
            iconEl.className = 'flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center bg-orange-100';
            iconEl.innerHTML = '<svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
            okBtn.className = 'flex-1 h-11 px-4 bg-orange-600 text-white rounded-lg font-medium hover:bg-orange-700 transition-colors shadow-sm';
        } else if (type === 'success') {
            iconEl.className = 'flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center bg-green-100';
            iconEl.innerHTML = '<svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
            okBtn.className = 'flex-1 h-11 px-4 bg-green-600 text-white rounded-lg font-medium hover:bg-green-700 transition-colors shadow-sm';
        } else if (type === 'danger') {
            iconEl.className = 'flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center bg-red-100';
            iconEl.innerHTML = '<svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
            okBtn.className = 'flex-1 h-11 px-4 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition-colors shadow-sm';
        } else {
            iconEl.className = 'flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center bg-sky-100';
            iconEl.innerHTML = '<svg class="w-6 h-6 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
            okBtn.className = 'flex-1 h-11 px-4 bg-sky-600 text-white rounded-lg font-medium hover:bg-sky-700 transition-colors shadow-sm';
        }
        
        // Set button text
        okBtn.textContent = options.okText || 'OK';
        const showCancelButton = options.cancelText !== null;
        if (showCancelButton) {
            cancelBtn.textContent = options.cancelText || 'Cancel';
            cancelBtn.classList.remove('hidden');
            okBtn.classList.remove('w-full');
            okBtn.classList.add('flex-1');
        } else {
            cancelBtn.classList.add('hidden');
            okBtn.classList.remove('flex-1');
            okBtn.classList.add('w-full');
        }
        
        // Show modal with animation
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.classList.remove('hidden');
            content.classList.remove('scale-95', 'opacity-0');
            content.classList.add('scale-100', 'opacity-100');
        }, 10);
        
        // Handle buttons
        const handleOk = () => {
            closeCustomConfirm();
            resolve(true);
        };
        
        const handleCancel = () => {
            closeCustomConfirm();
            resolve(false);
        };
        
        okBtn.onclick = handleOk;
        cancelBtn.onclick = showCancelButton ? handleCancel : null;
        
        // Close on backdrop click
        modal.onclick = (e) => {
            if (e.target === modal) handleCancel();
        };
    });
}

function closeCustomConfirm() {
    const modal = document.getElementById('customConfirmModal');
    const content = document.getElementById('customConfirmContent');
    
    content.classList.remove('scale-100', 'opacity-100');
    content.classList.add('scale-95', 'opacity-0');
    
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }, 200);
}

// Toast Notification System
function showToast(message, type = 'success', duration = 5000, options = {}) {
    const container = document.getElementById('toastContainer');
    if (!container) return null;

    const escapeHtml = (value) => String(value || '').replace(/[&<>'"]/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[char]));

    const variants = {
        success: {
            accentClass: 'toast-accent-success',
            iconClass: 'toast-icon-success',
            progressClass: 'toast-progress-success',
            defaultTitle: 'Success',
            defaultTag: 'Completed',
            iconSvg: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>'
        },
        error: {
            accentClass: 'toast-accent-error',
            iconClass: 'toast-icon-error',
            progressClass: 'toast-progress-error',
            defaultTitle: 'Request Failed',
            defaultTag: 'Action Required',
            iconSvg: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>'
        },
        warning: {
            accentClass: 'toast-accent-warning',
            iconClass: 'toast-icon-warning',
            progressClass: 'toast-progress-warning',
            defaultTitle: 'Attention Needed',
            defaultTag: 'Validation',
            iconSvg: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M10.29 3.86l-7.2 12.47A2 2 0 004.82 19h14.36a2 2 0 001.73-3.01l-7.2-12.47a2 2 0 00-3.46 0z"/></svg>'
        },
        info: {
            accentClass: 'toast-accent-info',
            iconClass: 'toast-icon-info',
            progressClass: 'toast-progress-info',
            defaultTitle: 'Notice',
            defaultTag: 'Info',
            iconSvg: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
        }
    };

    const variant = variants[type] || variants.info;
    const toastTitle = escapeHtml(options.title || variant.defaultTitle);
    const toastTag = escapeHtml(options.tag || variant.defaultTag);
    const toastMessage = escapeHtml(message);

    const toast = document.createElement('div');
    toast.className = 'pointer-events-auto transform transition-all duration-300 translate-x-10 opacity-0 scale-95';
    toast.innerHTML = `
        <div class="toast-glass-panel ${variant.accentClass}">
            <div class="toast-glass-sheen"></div>
            <div class="relative p-4 flex items-start gap-3">
                <div class="toast-icon-wrap ${variant.iconClass}">
                    ${variant.iconSvg}
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-[11px] uppercase tracking-[0.16em] font-semibold text-slate-500">${toastTag}</p>
                    <p class="mt-1 text-sm font-semibold text-slate-800">${toastTitle}</p>
                    <p class="mt-1 text-sm leading-relaxed text-slate-700">${toastMessage}</p>
                </div>
                <button type="button" class="toast-close-btn p-0.5 flex-shrink-0" aria-label="Dismiss notification">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            ${duration > 0 ? `<div class="toast-progress"><span class="${variant.progressClass}" style="animation-duration:${duration}ms"></span></div>` : ''}
        </div>
    `;

    const dismissToast = () => {
        if (!toast.parentElement) return;
        toast.classList.add('translate-x-10', 'opacity-0', 'scale-95');
        toast.classList.remove('translate-x-0', 'opacity-100', 'scale-100');
        setTimeout(() => {
            if (toast.parentElement) toast.remove();
        }, 220);
    };

    const closeBtn = toast.querySelector('.toast-close-btn');
    if (closeBtn) {
        closeBtn.addEventListener('click', dismissToast);
    }

    container.appendChild(toast);

    // Trigger entrance animation
    setTimeout(() => {
        toast.classList.remove('translate-x-10', 'opacity-0', 'scale-95');
        toast.classList.add('translate-x-0', 'opacity-100', 'scale-100');
    }, 10);

    if (duration > 0) {
        setTimeout(dismissToast, duration);
    }

    return toast;
}

let currentStudentId = null;
let currentStudentContext = null;
let rfidInputListener = null;
let rfidEligibilityTimer = null;
let rfidEligibilityAbortController = null;
let latestEligibilityRequestId = 0;
let currentRfidEligibility = { eligible: false, studentUserId: null, resolvedStudentId: null };
let rfidStudentLookupAbortController = null;
let latestStudentLookupRequestId = 0;
let cachedStudentLookupKey = '';
let cachedStudentLookupData = null;

function normalizeRfidField(value) {
    return String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
}

function normalizeRfidUid(value) {
    return String(value || '').trim();
}

function stopRfidScannerListener() {
    if (rfidInputListener) {
        document.removeEventListener('keydown', rfidInputListener);
        rfidInputListener = null;
    }
}

function setRfidCheckMessage(message, type = 'info') {
    const el = document.getElementById('rfidStudentCheckResult');
    if (!el) return;
    el.className = 'hidden';
    el.setAttribute('aria-hidden', 'true');
    el.textContent = '';
}

function setRfidStudentIdRealtimeMessage(message, type = 'info') {
    const el = document.getElementById('rfidStudentIdRealtimeResult');
    if (!el) return;

    const styles = {
        info: 'bg-slate-100 text-slate-600',
        checking: 'bg-blue-50 text-blue-700',
        success: 'bg-emerald-50 text-emerald-700',
        warning: 'bg-amber-50 text-amber-700',
        error: 'bg-red-50 text-red-700'
    };

    el.className = `text-xs mt-2 px-3 py-2 rounded-lg ${styles[type] || styles.info}`;
    el.textContent = message;
}

function resetRfidStudentLookupCache() {
    cachedStudentLookupKey = '';
    cachedStudentLookupData = null;
}

async function fetchRfidStudentLookup(studentCode) {
    const normalizedStudentCode = String(studentCode || '').trim();

    if (normalizedStudentCode === '') {
        return {
            ok: false,
            type: 'warning',
            message: 'Enter Student ID to check enrollment and duplication.'
        };
    }

    if (cachedStudentLookupKey === normalizedStudentCode && cachedStudentLookupData) {
        return {
            ok: true,
            data: cachedStudentLookupData
        };
    }

    if (rfidStudentLookupAbortController) {
        rfidStudentLookupAbortController.abort();
    }

    const requestId = ++latestStudentLookupRequestId;
    rfidStudentLookupAbortController = new AbortController();

    try {
        setRfidStudentIdRealtimeMessage('Checking Student ID enrollment and duplication...', 'checking');

        const response = await fetch('check_student_rfid_eligibility.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                student_id: normalizedStudentCode,
                expected_user_id: currentStudentId
            }),
            signal: rfidStudentLookupAbortController.signal
        });

        if (requestId !== latestStudentLookupRequestId) {
            return { aborted: true };
        }

        let data = null;
        try {
            data = await response.json();
        } catch (parseError) {
            return {
                ok: false,
                type: 'error',
                message: 'Unable to validate Student ID right now. Please try again.'
            };
        }

        if (!response.ok || !data.success) {
            return {
                ok: false,
                type: 'error',
                message: data?.error || 'Student ID validation failed. Please retry.'
            };
        }

        cachedStudentLookupKey = normalizedStudentCode;
        cachedStudentLookupData = data;

        return {
            ok: true,
            data
        };
    } catch (error) {
        if (error.name === 'AbortError') {
            return { aborted: true };
        }
        return {
            ok: false,
            type: 'error',
            message: 'Unable to validate Student ID right now. Please check your connection.'
        };
    }
}

function showRfidPrecheckPanel() {
    stopRfidScannerListener();

    const scannerPanel = document.getElementById('rfidScannerPanel');
    const precheckPanel = document.getElementById('rfidPrecheckPanel');
    const precheckCancelBtn = document.getElementById('rfidPrecheckCancelBtn');
    const status = document.getElementById('rfidStatus');

    if (scannerPanel) scannerPanel.classList.add('hidden');
    if (precheckPanel) precheckPanel.classList.remove('hidden');
    if (precheckCancelBtn) precheckCancelBtn.classList.remove('hidden');
    if (status) status.innerHTML = '';
}

function openCardRegistration(studentId, studentCode = '', studentName = '', studentCourse = '') {
    currentStudentId = parseInt(studentId, 10) || 0;
    currentStudentContext = {
        studentId: String(studentCode || '').trim(),
        fullName: String(studentName || '').trim(),
        course: String(studentCourse || '').trim()
    };

    currentRfidEligibility = { eligible: false, studentUserId: null, resolvedStudentId: null };
    resetRfidStudentLookupCache();

    const courseInput = document.getElementById('rfidCourseInput');
    const yearInput = document.getElementById('rfidYearInput');
    const semesterInput = document.getElementById('rfidSemesterInput');
    const studentIdInput = document.getElementById('rfidStudentIdInput');
    const fullNameInput = document.getElementById('rfidFullNameInput');
    const proceedBtn = document.getElementById('rfidProceedBtn');

    if (courseInput) {
        ensureRfidCourseOption(courseInput, currentStudentContext.course);
        courseInput.value = currentStudentContext.course || '';
    }
    if (yearInput) yearInput.value = '';
    if (semesterInput) semesterInput.value = '';
    if (studentIdInput) studentIdInput.value = currentStudentContext.studentId;
    if (fullNameInput) fullNameInput.value = currentStudentContext.fullName;
    if (proceedBtn) proceedBtn.disabled = true;

    showRfidPrecheckPanel();
    setRfidCheckMessage('Enter Course, Year, Semester, Student ID, and Full Name to validate this account.', 'info');
    setRfidStudentIdRealtimeMessage('Enter Student ID to check enrollment and avoid duplicate registration.', 'info');

    document.getElementById('cardModal').classList.remove('hidden');
    document.getElementById('cardModal').classList.add('flex');

    debouncedValidateRfidEligibility();
}

function ensureRfidCourseOption(selectEl, courseValue) {
    if (!selectEl || !courseValue) return;

    const normalized = String(courseValue).trim();
    if (!normalized) return;

    const hasOption = Array.from(selectEl.options).some((opt) => String(opt.value).trim() === normalized);
    if (hasOption) return;

    const customOption = document.createElement('option');
    customOption.value = normalized;
    customOption.textContent = normalized;
    selectEl.appendChild(customOption);
}

function closeCardModal() {
    stopRfidScannerListener();

    if (rfidEligibilityTimer) {
        clearTimeout(rfidEligibilityTimer);
        rfidEligibilityTimer = null;
    }

    if (rfidEligibilityAbortController) {
        rfidEligibilityAbortController.abort();
        rfidEligibilityAbortController = null;
    }

    if (rfidStudentLookupAbortController) {
        rfidStudentLookupAbortController.abort();
        rfidStudentLookupAbortController = null;
    }

    document.getElementById('cardModal').classList.add('hidden');
    document.getElementById('cardModal').classList.remove('flex');

    const proceedBtn = document.getElementById('rfidProceedBtn');
    if (proceedBtn) proceedBtn.disabled = true;

    showRfidPrecheckPanel();

    currentStudentId = null;
    currentStudentContext = null;
    currentRfidEligibility = { eligible: false, studentUserId: null, resolvedStudentId: null };
    resetRfidStudentLookupCache();
    setRfidCheckMessage('Enter Course, Year, Semester, Student ID, and Full Name to validate this account.', 'info');
    setRfidStudentIdRealtimeMessage('Enter Student ID to check enrollment and avoid duplicate registration.', 'info');
}

function debouncedValidateRfidEligibility() {
    if (rfidEligibilityTimer) {
        clearTimeout(rfidEligibilityTimer);
    }
    rfidEligibilityTimer = setTimeout(validateRfidEligibility, 250);
}

async function validateRfidEligibility() {
    const courseInput = document.getElementById('rfidCourseInput');
    const yearInput = document.getElementById('rfidYearInput');
    const semesterInput = document.getElementById('rfidSemesterInput');
    const studentIdInput = document.getElementById('rfidStudentIdInput');
    const fullNameInput = document.getElementById('rfidFullNameInput');
    const proceedBtn = document.getElementById('rfidProceedBtn');

    if (!courseInput || !yearInput || !semesterInput || !studentIdInput || !fullNameInput || !proceedBtn) {
        return;
    }

    const course = courseInput.value.trim();
    const yearLevel = yearInput.value.trim();
    const currentSemester = semesterInput.value.trim();
    const studentCode = studentIdInput.value.trim();
    const fullName = fullNameInput.value.trim();

    proceedBtn.disabled = true;
    currentRfidEligibility = { eligible: false, studentUserId: null, resolvedStudentId: null };

    if (!studentCode) {
        setRfidStudentIdRealtimeMessage('Enter Student ID to check enrollment and duplication.', 'warning');
        setRfidCheckMessage('Complete all fields before proceeding to RFID scan.', 'warning');
        return;
    }

    if (!/^\d{9}$/.test(studentCode)) {
        setRfidStudentIdRealtimeMessage('Student ID must be exactly 9 digits (numbers only).', 'warning');
        setRfidCheckMessage('Complete all fields with a valid 9-digit Student ID to proceed.', 'warning');
        return;
    }

    const studentLookup = await fetchRfidStudentLookup(studentCode);
    if (studentLookup.aborted) {
        return;
    }

    if (!studentLookup.ok) {
        setRfidStudentIdRealtimeMessage(studentLookup.message, studentLookup.type || 'error');
        setRfidCheckMessage(studentLookup.message, 'error');
        return;
    }

    const data = studentLookup.data;

    if (!data.eligible) {
        setRfidStudentIdRealtimeMessage(data.message || 'Student ID is not eligible for registration.', 'error');
        setRfidCheckMessage(data.message || 'Student is not eligible for RFID registration.', 'error');
        return;
    }

    if (data.code === 'eligible_new_id') {
        setRfidStudentIdRealtimeMessage('PASS: Student ID is available and will replace the temporary ID after registration.', 'success');
    } else {
        setRfidStudentIdRealtimeMessage('PASS: Student ID is enrolled and no duplicate RFID registration exists.', 'success');
    }

    if (!course || !fullName) {
        setRfidCheckMessage('Student ID verified. Complete Course and Full Name to proceed.', 'warning');
        return;
    }

    if (!yearLevel || !currentSemester) {
        setRfidCheckMessage('Student ID verified. Complete Year and Semester to proceed.', 'warning');
        return;
    }

    const expectedName = normalizeRfidField(data.student?.name || '');
    const expectedCourse = normalizeRfidField(data.student?.course || '');
    const enteredName = normalizeRfidField(fullName);
    const enteredCourse = normalizeRfidField(course);

    if (expectedName && enteredName !== expectedName) {
        setRfidCheckMessage('Full Name does not match the enrolled student record.', 'warning');
        return;
    }

    if (expectedCourse && enteredCourse !== expectedCourse) {
        setRfidCheckMessage('Course does not match the enrolled student record.', 'warning');
        return;
    }

    currentRfidEligibility = {
        eligible: true,
        studentUserId: data.student?.id || currentStudentId,
        resolvedStudentId: data.resolved_student_id || studentCode
    };
    proceedBtn.disabled = false;
    if (data.code === 'eligible_new_id') {
        setRfidCheckMessage('PASS: Student account is verified. This new Student ID will be saved after card registration.', 'success');
    } else {
        setRfidCheckMessage('PASS: Student ID is enrolled, verified, and has an active login account.', 'success');
    }
}

function proceedToRfidScan() {
    const proceedBtn = document.getElementById('rfidProceedBtn');
    if (!currentRfidEligibility.eligible) {
        if (proceedBtn) proceedBtn.disabled = true;
        setRfidCheckMessage('Validate student details successfully before scanning RFID.', 'warning');
        return;
    }

    const scannerPanel = document.getElementById('rfidScannerPanel');
    const precheckPanel = document.getElementById('rfidPrecheckPanel');
    const precheckCancelBtn = document.getElementById('rfidPrecheckCancelBtn');

    if (scannerPanel) scannerPanel.classList.remove('hidden');
    if (precheckPanel) precheckPanel.classList.add('hidden');
    if (precheckCancelBtn) precheckCancelBtn.classList.add('hidden');

    pollRFIDReader();
}

function pollRFIDReader() {
    document.getElementById('rfidStatus').innerHTML = `
        <div class="space-y-4">
            <div class="rfid-status-pill">
                <span class="rfid-status-dot"></span>
                <span>Waiting for card tap...</span>
            </div>
            <input 
                type="text" 
                id="rfidInput" 
                autofocus 
                autocomplete="off"
                placeholder="Tap card on scanner..."
                class="rfid-input-glass"
            >
            <p class="text-xs text-slate-500">R20XC-USB Scanner Ready - Accepts 10-digit card IDs</p>
        </div>
    `;
    
    const input = document.getElementById('rfidInput');
    input.focus();
    
    // Buffer for card data (R20XC sends UID followed by Enter)
    let cardBuffer = '';
    let bufferTimeout = null;
    
    // Listen for keyboard input from RFID reader - Use keydown instead of keypress
    rfidInputListener = function(e) {
        // Enter key signals end of card scan - process buffer FIRST
        if (e.key === 'Enter') {
            e.preventDefault();
            
            const uid = normalizeRfidUid(cardBuffer);
            console.log('Admin RFID Scan - Buffer:', cardBuffer, 'Trimmed:', uid, 'Length:', uid.length);
            
            // Strict scanner validation: UID must be exactly 10 digits.
            if (/^\d{10}$/.test(uid)) {
                document.getElementById('rfidStatus').innerHTML = `
                    <div class="text-emerald-600 font-semibold">
                        Card detected: ${escapeHtml(uid)}
                    </div>
                    <div class="text-sm text-slate-500 mt-2">Processing...</div>
                `;
                
                // Remove listener to prevent duplicate scans
                document.removeEventListener('keydown', rfidInputListener);
                rfidInputListener = null;
                
                // Register the card (keep original format - don't convert to uppercase for decimal numbers)
                const targetStudentUserId = currentRfidEligibility.studentUserId || currentStudentId;
                registerCard(targetStudentUserId, uid, currentRfidEligibility.resolvedStudentId);
            } else {
                document.getElementById('rfidStatus').innerHTML = `
                    <div class="text-red-600">Invalid scan. RFID UID must be exactly 10 digits.</div>
                    <div class="text-xs text-slate-500 mt-1">Scanned: "${escapeHtml(uid)}" (${uid.length} chars)</div>
                `;
                cardBuffer = '';
                input.value = '';
                setTimeout(() => pollRFIDReader(), 2000);
            }
            
            cardBuffer = '';
            clearTimeout(bufferTimeout);
            return;
        }
        
        // Accumulate all other single-character keys (numbers)
        if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            cardBuffer += e.key;
            input.value = cardBuffer;
            console.log('Admin - Character captured:', e.key, 'Buffer now:', cardBuffer);
            
            // Reset timeout (R20XC sends data quickly, within ~100ms)
            clearTimeout(bufferTimeout);
            bufferTimeout = setTimeout(() => {
                // If buffer doesn't complete to expected fixed length, reset.
                if (cardBuffer.length > 0 && cardBuffer.length !== 10) {
                    console.warn('Admin - Buffer timeout, clearing:', cardBuffer);
                    cardBuffer = '';
                    input.value = '';
                }
            }, 500);
        }
    };
    
    document.addEventListener('keydown', rfidInputListener);
    
    // Also handle direct input field submission (manual entry fallback)
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const uid = normalizeRfidUid(this.value);
            if (/^\d{10}$/.test(uid)) {
                document.removeEventListener('keydown', rfidInputListener);
                rfidInputListener = null;
                const targetStudentUserId = currentRfidEligibility.studentUserId || currentStudentId;
                registerCard(targetStudentUserId, uid, currentRfidEligibility.resolvedStudentId);
            } else {
                document.getElementById('rfidStatus').innerHTML = `
                    <div class="text-red-600">Invalid manual input. RFID UID must be exactly 10 digits.</div>
                    <button onclick="pollRFIDReader()" class="rfid-secondary-btn">
                        Try Again
                    </button>
                `;
            }
        }
    });
}

function registerCard(studentId, uid, resolvedStudentId = null) {
    document.getElementById('rfidStatus').innerHTML = `
        <div class="flex items-center justify-center">
            <svg class="animate-spin h-8 w-8 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="ml-3 text-slate-600">Registering card...</span>
        </div>
    `;
    
    fetch('register_card.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            student_id: studentId,
            rfid_uid: uid,
            student_code: resolvedStudentId,
            course: document.getElementById('rfidCourseInput')?.value?.trim() || '',
            year_level: document.getElementById('rfidYearInput')?.value?.trim() || '',
            current_semester: document.getElementById('rfidSemesterInput')?.value?.trim() || ''
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('rfidStatus').innerHTML = `
                <div class="text-emerald-600 font-semibold text-lg">
                    Card registered successfully.
                </div>
            `;
            setTimeout(() => {
                closeCardModal();
                location.reload();
            }, 1500);
        } else {
            document.getElementById('rfidStatus').innerHTML = `
                <div class="text-red-600 font-medium">${escapeHtml(data.error || 'Registration failed.')}</div>
                <button onclick="pollRFIDReader()" class="rfid-secondary-btn">
                    Try Again
                </button>
            `;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('rfidStatus').innerHTML = `
            <div class="text-red-600">Network error. Please check your connection.</div>
            <button onclick="pollRFIDReader()" class="rfid-secondary-btn">
                Try Again
            </button>
        `;
    });
}

function unregisterCard(studentId) {
    if (confirm('Are you sure you want to unregister this RFID card?')) {
        fetch('unregister_card.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                student_id: studentId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.error || 'Failed to unregister card');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to unregister card');
        });
    }
}

// Toggle RFID Lost Status (Enable/Disable Mark Lost ID)
async function toggleRfidLostStatus(cardId, studentId, rfidUid, studentName, studentEmail, markAsLost) {
    console.log('toggleRfidLostStatus called:', { cardId, studentId, rfidUid, studentName, studentEmail, markAsLost });
    
    if (!cardId && !studentId) {
        showToast('Card reference is missing. Please refresh the page and try again.', 'error');
        return;
    }
    
    let confirmTitle, confirmMessages, actionText;
    
    if (markAsLost) {
        confirmTitle = `Enable Mark Lost ID for ${studentName}?`;
        confirmMessages = [
            '✉️ Send email notification to student',
            '🚫 Temporarily disable their RFID card',
            '📱 Student must use Digital ID QR code for entry',
            `📧 Student must email Student Services about lost card`,
            '',
            `<strong>Student Email:</strong> ${studentEmail}`
        ];
        actionText = 'mark_lost';
    } else {
        confirmTitle = `Disable Mark Lost ID for ${studentName}?`;
        confirmMessages = [
            '✅ Re-enable their RFID card',
            '✉️ Send confirmation email to student',
            '📱 RFID card can be used for entry again'
        ];
        actionText = 'mark_found';
    }
    
    const confirmed = await showCustomConfirm(confirmTitle, confirmMessages, {
        type: markAsLost ? 'warning' : 'success',
        okText: markAsLost ? 'Enable Mark Lost ID' : 'Disable Mark Lost ID',
        cancelText: 'Cancel'
    });
    
    if (!confirmed) return;
    
    // Show loading toast
    const loadingToast = showToast(
        markAsLost ? 'Marking RFID as lost...' : 'Re-enabling RFID card...',
        'info',
        0 // Don't auto-dismiss
    );
    
    const payload = {
        card_id: cardId,
        student_id: studentId,
        rfid_uid: rfidUid,
        action: actionText,
        student_email: studentEmail,
        student_name: studentName,
        csrf_token: csrfToken
    };
    
    console.log('Sending request:', payload);
    
    try {
        const response = await fetch('mark_lost_rfid.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });
        
        console.log('Response status:', response.status);
        const data = await response.json();
        console.log('Response data:', data);
        
        // Remove loading toast
        const toastContainer = document.getElementById('toastContainer');
        toastContainer.innerHTML = '';
        
        if (data.success) {
            showToast(
                data.message || '✓ Status updated successfully',
                'success',
                3000
            );
            
            // Reload after toast is visible
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(
                'Error: ' + (data.error || 'Unknown error occurred'),
                'error'
            );
        }
    } catch (error) {
        console.error('Error:', error);
        
        // Remove loading toast
        const toastContainer = document.getElementById('toastContainer');
        toastContainer.innerHTML = '';
        
        showToast('Network error. Please try again.', 'error');
    }
}

// PHASE 2: Toggle Guardian Notifications
function toggleGuardianNotifications(enabled) {
    fetch('toggle_guardian_notifications.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            enabled: enabled,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const status = enabled ? 'ENABLED' : 'DISABLED';
            alert(`✓ Guardian notifications ${status} successfully`);
        } else {
            alert('Error: ' + (data.error || 'Failed to update settings'));
            // Revert toggle on error
            document.getElementById('guardianNotificationsToggle').checked = !enabled;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
        // Revert toggle on error
        document.getElementById('guardianNotificationsToggle').checked = !enabled;
    });
}

function confirmDelete(studentId) {
    // Get student name from the DOM for better UX
    const studentCard = event.target.closest('.bg-white');
    const studentName = studentCard?.querySelector('h3')?.textContent || 'this student';
    
    showConfirmModal({
        title: `Delete account for ${studentName}?`,
        message: '⚠️ WARNING: This action cannot be undone!',
        items: [
            'Permanently delete account',
            'Remove all student data',
            'Cannot be recovered'
        ],
        confirmText: 'Delete',
        cancelText: 'Cancel',
        confirmClass: 'bg-red-600 hover:bg-red-700',
        onConfirm: () => executeDelete(studentId, studentName)
    });
}

function executeDelete(studentId, studentName) {
    showToast('Deleting account...', 'warning', 0);
    
    fetch('delete_account.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            student_id: studentId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Account deleted successfully', 'success', 600);
            setTimeout(() => {
                location.reload();
            }, 600);
        } else {
            showToast(data.error || 'Failed to delete account', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to delete account', 'error');
    });
}

function viewCardDetails(studentId, name, uid, registeredAt, profilePicture, email, guardianName, guardianContact) {
    const modal = document.getElementById('cardDetailsModal');
    const content = document.getElementById('cardDetailsContent');

    const safeName = String(name || 'N/A');
    const safeUid = String(uid || 'N/A');
    const safeEmail = String(email || 'N/A');
    const safeGuardianName = String(guardianName || '').trim() || 'Not assigned';
    const safeGuardianContact = String(guardianContact || '').trim() || 'Not provided';

    let registeredOnText = 'Not available';
    if (registeredAt) {
        const registeredDate = new Date(registeredAt);
        if (!Number.isNaN(registeredDate.getTime())) {
            registeredOnText = registeredDate.toLocaleString();
        }
    }
    
    // Get first letter for fallback avatar
    const firstLetter = safeName.charAt(0).toUpperCase();
    const colors = ['bg-blue-500', 'bg-green-500', 'bg-yellow-500', 'bg-red-500', 'bg-purple-500', 'bg-pink-500'];
    const colorIndex = firstLetter.charCodeAt(0) % colors.length;
    const bgColor = colors[colorIndex];

    const profileFile = String(profilePicture || '').trim();
    const profileSrc = profileFile ? `../assets/images/profiles/${encodeURIComponent(profileFile)}` : '';
    
    content.innerHTML = `
        <div class="space-y-4">
            ${profileSrc ? `
                <div class="flex justify-center mb-4">
                    <img src="${profileSrc}" 
                         alt="${escapeHtml(safeName)}" 
                         class="rfid-detail-avatar">
                </div>
            ` : `
                <div class="flex justify-center mb-4">
                    <div class="rfid-detail-avatar-fallback ${bgColor}">
                        ${firstLetter}
                    </div>
                </div>
            `}
            <div class="rfid-detail-panel">
                <p class="text-xs uppercase tracking-wider text-slate-500 mb-1">Student Name</p>
                <p class="font-semibold text-slate-800 text-lg">${escapeHtml(safeName)}</p>
            </div>
            <div class="rfid-detail-panel">
                <p class="text-xs uppercase tracking-wider text-slate-500 mb-1">RFID UID</p>
                <code class="font-mono text-lg tracking-widest text-slate-800">${escapeHtml(safeUid)}</code>
            </div>
            <div class="rfid-detail-panel">
                <p class="text-xs uppercase tracking-wider text-slate-500 mb-1">Registered On</p>
                <p class="font-semibold text-slate-800">${escapeHtml(registeredOnText)}</p>
            </div>
            <div class="rfid-detail-panel">
                <p class="text-xs uppercase tracking-wider text-slate-500 mb-1">Email</p>
                <p class="font-semibold text-slate-800 break-all">${escapeHtml(safeEmail)}</p>
            </div>
            <div class="rfid-detail-panel">
                <p class="text-xs uppercase tracking-wider text-slate-500 mb-1">Parent/Guardian Name</p>
                <p class="font-semibold text-slate-800">${escapeHtml(safeGuardianName)}</p>
            </div>
            <div class="rfid-detail-panel">
                <p class="text-xs uppercase tracking-wider text-slate-500 mb-1">Parent Guardian Contact</p>
                <p class="font-semibold text-slate-800">${escapeHtml(safeGuardianContact)}</p>
            </div>
        </div>
    `;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeCardDetailsModal() {
    document.getElementById('cardDetailsModal').classList.add('hidden');
    document.getElementById('cardDetailsModal').classList.remove('flex');
}

// ===== VIOLATION MANAGEMENT FUNCTIONS =====

// Cache for violation categories
let violationCategories = null;
let currentHistoryStudentId = null;
let currentHistoryData = [];

const reparationTaskLabels = {
    written_apology: 'Written Apology Letter',
    community_service: 'Community Service Hours',
    counseling: 'Counseling Session',
    parent_conference: 'Parent/Guardian Conference',
    suspension_compliance: 'Suspension Compliance',
    restitution: 'Restitution / Payment',
    other: 'Other'
};

function adminViolationTypeLabel(type) {
    const value = String(type || '').toLowerCase().trim();
    if (value === 'minor') return 'Minor';
    if (value === 'major') return 'Moderate';
    if (value === 'grave') return 'Major';
    if (!value) return 'Unspecified';
    return value.charAt(0).toUpperCase() + value.slice(1);
}

function formatDisciplineCode(code) {
    const value = String(code || '').trim();
    return value || 'DIS N/A';
}

function reparationTaskLabel(value) {
    const key = String(value || '').trim();
    if (!key) return 'Other';
    return reparationTaskLabels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function ordinalLabel(value) {
    const n = Number(value || 0);
    if (n % 100 >= 11 && n % 100 <= 13) return n + 'th';
    const last = n % 10;
    if (last === 1) return n + 'st';
    if (last === 2) return n + 'nd';
    if (last === 3) return n + 'rd';
    return n + 'th';
}

function normalizeStrikeLabel(text) {
    return String(text || '').replace(/strike\s*#\s*(\d+)/ig, (_, num) => `${ordinalLabel(Number(num))} Offense`);
}

function applyAssignReparationChoices(selectEl, allowedTypes, recommendedType) {
    if (!selectEl) return;

    const validAllowed = Array.isArray(allowedTypes) && allowedTypes.length
        ? allowedTypes.filter(t => String(t || '').trim() !== '')
        : ['other'];

    const optionsHtml = validAllowed
        .map(type => `<option value="${escapeHtml(type)}">${escapeHtml(reparationTaskLabel(type))}</option>`)
        .join('');

    selectEl.innerHTML = optionsHtml;
    const preferred = validAllowed.includes(recommendedType) ? recommendedType : validAllowed[0];
    selectEl.value = preferred;
    // Keep the dropdown enabled for UX consistency, even with one allowed option.
    selectEl.disabled = false;
}

// Load violation categories from API
async function loadViolationCategories() {
    if (violationCategories) return violationCategories;
    try {
        const res = await fetch('manage_violations.php?action=categories');
        const data = await res.json();
        if (data.success) {
            violationCategories = data.categories;
            return violationCategories;
        }
    } catch (e) {
        console.error('Failed to load categories:', e);
    }
    return {};
}

// Search students for violation assignment
let avSearchTimeout = null;
function searchStudentsForViolation(query) {
    clearTimeout(avSearchTimeout);
    const resultsDiv = document.getElementById('avStudentResults');
    if (query.length < 2) {
        resultsDiv.classList.add('hidden');
        return;
    }
    avSearchTimeout = setTimeout(async () => {
        try {
            const res = await fetch('manage_violations.php?action=search_students&q=' + encodeURIComponent(query));
            const data = await res.json();
            if (data.success && data.students.length > 0) {
                resultsDiv.innerHTML = data.students.map(s => `
                    <div onclick="selectViolationStudent('${s.id}', '${escapeHtml(s.name)}', '${escapeHtml(s.student_id)}')" 
                         class="px-4 py-2 hover:bg-indigo-50 cursor-pointer border-b border-slate-100 last:border-0">
                        <span class="font-medium text-slate-800">${escapeHtml(s.name)}</span>
                        <span class="text-sm text-slate-500 ml-2">${escapeHtml(s.student_id)}</span>
                    </div>
                `).join('');
                resultsDiv.classList.remove('hidden');
            } else {
                resultsDiv.innerHTML = '<div class="px-4 py-2 text-slate-400 text-sm">No students found</div>';
                resultsDiv.classList.remove('hidden');
            }
        } catch (e) {
            console.error('Search error:', e);
        }
    }, 300);
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function selectViolationStudent(id, name, studentId) {
    document.getElementById('avStudentId').value = id;
    document.getElementById('avSelectedStudentName').textContent = name + ' (' + studentId + ')';
    document.getElementById('avSelectedStudent').classList.remove('hidden');
    document.getElementById('avSelectedStudent').classList.add('flex');
    document.getElementById('avStudentSearchGroup').classList.add('hidden');
    document.getElementById('avStudentResults').classList.add('hidden');
}

function clearSelectedViolationStudent() {
    document.getElementById('avStudentId').value = '';
    document.getElementById('avSelectedStudent').classList.add('hidden');
    document.getElementById('avSelectedStudent').classList.remove('flex');
    document.getElementById('avStudentSearchGroup').classList.remove('hidden');
    document.getElementById('avStudentSearch').value = '';
}

// Open Add Violation Modal
async function openAddViolationModal(studentId = null, studentName = null) {
    const modal = document.getElementById('addViolationModal');
    const categories = await loadViolationCategories();
    
    // Populate category dropdown grouped by type
    const select = document.getElementById('avCategory');
    select.innerHTML = '<option value="">Select a category...</option>';
    const typeLabels = { minor: 'Minor Offenses', major: 'Major Offenses', grave: 'Grave Offenses' };
    const typeColors = { minor: '#f59e0b', major: '#f97316', grave: '#dc2626' };
    for (const [type, cats] of Object.entries(categories)) {
        const group = document.createElement('optgroup');
        group.label = typeLabels[type] || type;
        cats.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name;
            opt.dataset.type = type;
            opt.dataset.defaultSanction = c.default_sanction || '';
            group.appendChild(opt);
        });
        select.appendChild(group);
    }
    
    // Pre-select student if provided
    if (studentId && studentName) {
        document.getElementById('avStudentId').value = studentId;
        document.getElementById('avSelectedStudentName').textContent = studentName;
        document.getElementById('avSelectedStudent').classList.remove('hidden');
        document.getElementById('avSelectedStudent').classList.add('flex');
        document.getElementById('avStudentSearchGroup').classList.add('hidden');
        document.getElementById('addViolationSubtitle').textContent = 'Recording violation for ' + studentName;
    } else {
        clearSelectedViolationStudent();
        document.getElementById('addViolationSubtitle').textContent = 'Search and select a student';
    }
    
    document.getElementById('avDescription').value = '';
    document.getElementById('avStrikeIndicator').classList.add('hidden');
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeAddViolationModal() {
    document.getElementById('addViolationModal').classList.add('hidden');
    document.getElementById('addViolationModal').classList.remove('flex');
}

function updateStrikeIndicator() {
    const select = document.getElementById('avCategory');
    const indicator = document.getElementById('avStrikeIndicator');
    const selectedOpt = select.options[select.selectedIndex];
    
    if (!selectedOpt || !selectedOpt.value) {
        indicator.classList.add('hidden');
        return;
    }
    
    const type = selectedOpt.dataset.type;
    const sanction = selectedOpt.dataset.defaultSanction || '';
    const colors = { minor: 'amber', major: 'orange', grave: 'red' };
    const color = colors[type] || 'slate';
    
    indicator.innerHTML = `
        <div class="p-3 bg-${color}-50 border border-${color}-200 rounded-lg">
            <div class="flex items-center gap-2 mb-1">
                <span class="text-xs font-bold uppercase text-${color}-700">${type} offense</span>
            </div>
            ${sanction ? `<p class="text-xs text-${color}-600">Default sanction: ${escapeHtml(sanction)}</p>` : ''}
            ${type === 'grave' ? '<p class="text-xs text-red-700 font-semibold mt-1">⚠ Grave offenses may lead to expulsion</p>' : ''}
        </div>
    `;
    indicator.classList.remove('hidden');
}

async function submitNewViolation(e) {
    e.preventDefault();
    const studentId = document.getElementById('avStudentId').value;
    const categoryId = document.getElementById('avCategory').value;
    
    if (!studentId) {
        showToast('Please select a student', 'error');
        return;
    }
    if (!categoryId) {
        showToast('Please select a violation category', 'error');
        return;
    }
    
    const btn = document.getElementById('avSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Recording...';
    
    try {
        const res = await fetch('manage_violations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                action: 'add',
                user_id: studentId,
                category_id: categoryId,
                description: document.getElementById('avDescription').value,
                school_year: document.getElementById('avSchoolYear').value,
                semester: document.getElementById('avSemester').value
            })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Violation recorded successfully', 'success');
            closeAddViolationModal();
            location.reload();
        } else {
            showToast(data.error || 'Failed to record violation', 'error');
        }
    } catch (err) {
        console.error('Error:', err);
        showToast('Failed to record violation', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg> Record Violation';
    }
}

// Violation History Modal
async function openViolationHistoryModal(studentId, studentName, studentNumber) {
    const modal = document.getElementById('violationHistoryModal');
    document.getElementById('violationHistorySubtitle').textContent = studentName + ' (' + studentNumber + ')';
    document.getElementById('violationHistoryContent').innerHTML = '<div class="text-center py-8 text-slate-400">Loading violation history...</div>';
    currentHistoryStudentId = studentId;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    await loadViolationHistory(studentId);
}

function closeViolationHistoryModal() {
    document.getElementById('violationHistoryModal').classList.add('hidden');
    document.getElementById('violationHistoryModal').classList.remove('flex');
    currentHistoryStudentId = null;
    currentHistoryData = [];
}

async function loadViolationHistory(studentId, filters = {}) {
    try {
        let url = 'manage_violations.php?action=history&user_id=' + studentId;
        if (filters.school_year) url += '&school_year=' + encodeURIComponent(filters.school_year);
        if (filters.type) url += '&type=' + encodeURIComponent(filters.type);
        if (filters.status) url += '&status=' + encodeURIComponent(filters.status);
        
        const res = await fetch(url);
        const data = await res.json();
        
        if (data.success) {
            currentHistoryData = data.violations;
            
            // Populate school year filter
            const sySelect = document.getElementById('vhFilterSchoolYear');
            const currentSY = sySelect.value;
            const schoolYears = [...new Set(data.violations.map(v => v.school_year).filter(Boolean))];
            sySelect.innerHTML = '<option value="">All School Years</option>';
            schoolYears.forEach(sy => {
                sySelect.innerHTML += `<option value="${sy}" ${sy === currentSY ? 'selected' : ''}>${sy}</option>`;
            });
            
            // Update summary bar
            const summary = { active: 0, pending_reparation: 0, apprehended: 0, minor: 0, major: 0, grave: 0 };
            data.violations.forEach(v => {
                if (summary[v.status] !== undefined) summary[v.status]++;
                if (summary[v.category_type] !== undefined) summary[v.category_type]++;
            });

            const currentDis = data.current_disciplinary_status || null;
            const currentDisBadge = currentDis
                ? `<span class="text-xs px-3 py-1 rounded-full bg-indigo-100 text-indigo-700 font-semibold">Current ${formatDisciplineCode(currentDis.disciplinary_code)} • ${adminViolationTypeLabel(currentDis.category_type)} • Offense #${currentDis.offense_number || 1}</span>`
                : '<span class="text-xs px-3 py-1 rounded-full bg-slate-100 text-slate-600">No DIS on file</span>';
            
            document.getElementById('vhSummaryBar').innerHTML = `
                <span class="text-xs px-3 py-1 rounded-full bg-red-100 text-red-700 font-semibold">${summary.active} Active</span>
                <span class="text-xs px-3 py-1 rounded-full bg-amber-100 text-amber-700 font-semibold">${summary.pending_reparation} Pending</span>
                <span class="text-xs px-3 py-1 rounded-full bg-teal-100 text-teal-700 font-semibold">${summary.apprehended} Apprehended</span>
                ${currentDisBadge}
                <span class="text-xs px-3 py-1 rounded-full bg-slate-100 text-slate-600">${data.violations.length} Total</span>
            `;
            
            renderViolationHistory(data.violations);
        } else {
            document.getElementById('violationHistoryContent').innerHTML = '<div class="text-center py-8 text-red-400">Failed to load history</div>';
        }
    } catch (e) {
        console.error('Error:', e);
        document.getElementById('violationHistoryContent').innerHTML = '<div class="text-center py-8 text-red-400">Error loading history</div>';
    }
}

function filterViolationHistory() {
    if (!currentHistoryStudentId) return;
    loadViolationHistory(currentHistoryStudentId, {
        school_year: document.getElementById('vhFilterSchoolYear').value,
        type: document.getElementById('vhFilterType').value,
        status: document.getElementById('vhFilterStatus').value
    });
}

function renderViolationHistory(violations) {
    const content = document.getElementById('violationHistoryContent');
    
    if (violations.length === 0) {
        content.innerHTML = `
            <div class="text-center py-8">
                <svg class="w-12 h-12 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-slate-500">No violations found</p>
            </div>
        `;
        return;
    }
    
    const statusColors = {
        active: { bg: 'bg-red-100', text: 'text-red-700', label: 'Active' },
        pending_reparation: { bg: 'bg-amber-100', text: 'text-amber-700', label: 'Pending Reparation' },
        apprehended: { bg: 'bg-teal-100', text: 'text-teal-700', label: 'Apprehended' }
    };
    const typeColors = { minor: 'amber', major: 'orange', grave: 'red' };
    
    let html = '<div class="space-y-4">';
    violations.forEach(v => {
        const sc = statusColors[v.status] || statusColors.active;
        const tc = typeColors[v.category_type] || 'slate';
        const categoryTypeLabel = v.violation_type_label || adminViolationTypeLabel(v.category_type);
        const disciplinaryCode = formatDisciplineCode(v.disciplinary_code);
        const recommendedTaskLabel = reparationTaskLabel(v.recommended_reparation_type || 'other');
        const date = new Date(v.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        
        html += `
            <div class="glass-card rounded-2xl p-4 sm:p-5 border border-white/70 shadow-[0_14px_36px_rgba(15,23,42,0.12)] hover:shadow-[0_18px_44px_rgba(15,23,42,0.16)] transition-all">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-xs font-bold uppercase px-2 py-0.5 rounded bg-${tc}-100 text-${tc}-700">${escapeHtml(categoryTypeLabel)}</span>
                            <span class="text-xs font-semibold px-2 py-0.5 rounded ${sc.bg} ${sc.text}">${sc.label}</span>
                            <span class="text-xs font-semibold px-2 py-0.5 rounded bg-indigo-100 text-indigo-700">${escapeHtml(disciplinaryCode)}</span>
                            <span class="text-xs text-slate-400">#${v.offense_number || '-'}</span>
                        </div>
                        <p class="font-medium text-slate-800">${escapeHtml(v.category_name)}</p>
                        ${v.description ? `<p class="text-sm text-slate-500 mt-1">${escapeHtml(normalizeStrikeLabel(v.description))}</p>` : ''}
                        <div class="flex items-center gap-4 mt-2 text-xs text-slate-400">
                            <span>${date}</span>
                            ${v.school_year ? `<span>${escapeHtml(v.school_year)} - ${escapeHtml(v.semester || '')}</span>` : ''}
                            ${v.recorded_by_name ? `<span>By: ${escapeHtml(v.recorded_by_name)}</span>` : ''}
                        </div>
                        ${v.status === 'active' ? `
                            <p class="text-xs text-indigo-700 mt-2"><strong>Suggested by ${escapeHtml(disciplinaryCode)}:</strong> ${escapeHtml(recommendedTaskLabel)}</p>
                        ` : ''}
                        ${v.status === 'apprehended' && v.reparation_type ? `
                            <div class="mt-2 p-2 bg-teal-50/80 border border-teal-200/70 rounded-lg text-xs text-teal-700">
                                <strong>Reparation:</strong> ${escapeHtml(reparationTaskLabel(v.reparation_type))}
                                ${v.reparation_notes ? ` — ${escapeHtml(normalizeStrikeLabel(v.reparation_notes))}` : ''}
                                ${v.reparation_completed_at ? `<br>Resolved: ${new Date(v.reparation_completed_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}` : ''}
                            </div>
                        ` : ''}
                        ${v.status === 'pending_reparation' && v.reparation_type ? `
                            <div class="mt-2 p-2 bg-amber-50/85 border border-amber-200/80 rounded-lg text-xs text-amber-800">
                                <strong>Assigned Task:</strong> ${escapeHtml(reparationTaskLabel(v.reparation_type))}
                                ${v.reparation_notes ? ` — ${escapeHtml(normalizeStrikeLabel(v.reparation_notes))}` : ''}
                            </div>
                        ` : ''}
                    </div>
                    ${v.status === 'active' ? `
                        <div class="flex flex-col gap-1.5 ml-3 shrink-0">
                            <button onclick="openAssignReparationModal('${v.id}')" 
                                class="px-3 py-1.5 bg-amber-500 text-white rounded-lg text-xs font-medium btn-hover whitespace-nowrap">
                                Assign Reparation
                            </button>
                            <button onclick="openResolveViolationModal('${v.id}', '${escapeHtml(v.category_name)}')" 
                                class="px-3 py-1.5 bg-teal-500 text-white rounded-lg text-xs font-medium btn-hover whitespace-nowrap">
                                Resolve
                            </button>
                        </div>
                    ` : v.status === 'pending_reparation' ? `
                        <button onclick="openResolveViolationModal('${v.id}', '${escapeHtml(v.category_name)}')" 
                            class="px-3 py-1.5 bg-teal-500 text-white rounded-lg text-xs font-medium btn-hover whitespace-nowrap ml-3 shrink-0">
                            Mark Resolved
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
    });
    html += '</div>';
    content.innerHTML = html;
}

// Resolve single violation
function openResolveViolationModal(violationId, categoryName) {
    const violation = currentHistoryData.find(v => String(v.id) === String(violationId)) || null;
    const offenseNumber = violation ? Number(violation.offense_number || 1) : 1;
    const typeLabel = violation
        ? (violation.violation_type_label || adminViolationTypeLabel(violation.category_type))
        : 'Unspecified';
    const disCode = violation ? formatDisciplineCode(violation.disciplinary_code) : 'DIS N/A';
    const recommendedType = violation ? String(violation.recommended_reparation_type || 'other') : 'other';
    const allowedTypes = violation && Array.isArray(violation.allowed_reparation_types)
        ? violation.allowed_reparation_types
        : ['other'];
    const preferredType = violation && violation.reparation_type
        ? String(violation.reparation_type)
        : recommendedType;

    document.getElementById('rvViolationId').value = violationId;
    document.getElementById('resolveViolationInfo').textContent = 'Resolving: ' + categoryName + ' (' + typeLabel + ', ' + ordinalLabel(offenseNumber) + ' Offense, ' + disCode + ')';
    const reparationSelect = document.getElementById('rvReparationType');
    applyAssignReparationChoices(reparationSelect, allowedTypes, preferredType);
    document.getElementById('rvNotes').value = '';
    document.getElementById('rvSendNotification').checked = true;
    
    const modal = document.getElementById('resolveViolationModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeResolveViolationModal() {
    document.getElementById('resolveViolationModal').classList.add('hidden');
    document.getElementById('resolveViolationModal').classList.remove('flex');
    const reparationSelect = document.getElementById('rvReparationType');
    if (reparationSelect) {
        reparationSelect.disabled = false;
    }
}

async function submitResolveViolation(e) {
    e.preventDefault();
    const violationId = document.getElementById('rvViolationId').value;
    const reparationType = document.getElementById('rvReparationType').value;
    
    if (!reparationType) {
        showToast('Please select a reparation type', 'error');
        return;
    }
    
    const btn = document.getElementById('rvSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Resolving...';
    
    try {
        const res = await fetch('manage_violations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                action: 'resolve',
                violation_id: violationId,
                reparation_type: reparationType,
                reparation_notes: document.getElementById('rvNotes').value,
                send_notification: document.getElementById('rvSendNotification').checked
            })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Violation resolved — marked as apprehended', 'success');
            closeResolveViolationModal();
            // If no more active violations, reload the full page to remove this student from the list
            if (data.all_cleared) {
                location.reload();
            } else if (currentHistoryStudentId) {
                await loadViolationHistory(currentHistoryStudentId);
            } else {
                location.reload();
            }
        } else {
            if (Array.isArray(data.allowed_reparation_types)) {
                const resolveSelect = document.getElementById('rvReparationType');
                const fallbackPreferred = String(data.recommended_reparation_type || data.allowed_reparation_types[0] || 'other');
                applyAssignReparationChoices(resolveSelect, data.allowed_reparation_types, fallbackPreferred);
            }
            showToast(data.error || 'Failed to resolve violation', 'error');
        }
    } catch (err) {
        console.error('Error:', err);
        showToast('Failed to resolve violation', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Mark as Apprehended';
    }
}

// Assign Reparation Task (active → pending_reparation)
function openAssignReparationModal(violationId) {
    const violation = currentHistoryData.find(v => String(v.id) === String(violationId)) || null;
    const categoryName = violation ? String(violation.category_name || 'Violation') : 'Violation';
    const offenseNumber = violation ? Number(violation.offense_number || 1) : 1;
    const typeLabel = violation
        ? (violation.violation_type_label || adminViolationTypeLabel(violation.category_type))
        : 'Unspecified';
    const disCode = violation ? formatDisciplineCode(violation.disciplinary_code) : 'DIS N/A';
    const disTitle = violation ? String(violation.disciplinary_title || 'Disciplinary Measure') : 'Disciplinary Measure';
    const disAction = violation ? String(violation.disciplinary_action || '') : '';
    const recommendedType = violation ? String(violation.recommended_reparation_type || 'other') : 'other';
    const allowedTypes = violation && Array.isArray(violation.allowed_reparation_types)
        ? violation.allowed_reparation_types
        : ['other'];

    document.getElementById('arViolationId').value = violationId;
    document.getElementById('assignReparationInfo').textContent = 'Assigning task for: ' + categoryName + ' (' + typeLabel + ', ' + ordinalLabel(offenseNumber) + ' Offense)';
    const reparationSelect = document.getElementById('arReparationType');
    applyAssignReparationChoices(reparationSelect, allowedTypes, recommendedType);
    document.getElementById('arNotes').value = '';
    document.getElementById('arSendNotification').checked = true;

    const summaryPanel = document.getElementById('arPolicySummary');
    const summaryTitle = document.getElementById('arPolicySummaryTitle');
    const summaryAction = document.getElementById('arPolicySummaryAction');
    const summaryTask = document.getElementById('arPolicySummaryTask');
    if (summaryPanel && summaryTitle && summaryAction && summaryTask) {
        summaryTitle.textContent = disCode + ' - ' + disTitle;
        summaryAction.textContent = disAction || 'Follow Student Services Office directives for this disciplinary level.';
        const allowedLabels = allowedTypes.map(t => reparationTaskLabel(t));
        summaryTask.textContent = 'Allowed tasks: ' + allowedLabels.join(', ') + '. Auto-selected: ' + reparationTaskLabel((reparationSelect && reparationSelect.value) || recommendedType);
        summaryPanel.classList.remove('hidden');
    }

    const modal = document.getElementById('assignReparationModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeAssignReparationModal() {
    document.getElementById('assignReparationModal').classList.add('hidden');
    document.getElementById('assignReparationModal').classList.remove('flex');
    const reparationSelect = document.getElementById('arReparationType');
    if (reparationSelect) {
        reparationSelect.disabled = false;
    }
    const summaryPanel = document.getElementById('arPolicySummary');
    if (summaryPanel) {
        summaryPanel.classList.add('hidden');
    }
}

async function submitAssignReparation(e) {
    e.preventDefault();
    const violationId    = document.getElementById('arViolationId').value;
    const reparationType = document.getElementById('arReparationType').value;

    if (!reparationType) {
        showToast('Please select a reparation task', 'error');
        return;
    }

    const btn = document.getElementById('arSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Assigning...';

    try {
        const res = await fetch('manage_violations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                action: 'assign_reparation',
                violation_id: violationId,
                reparation_type: reparationType,
                reparation_notes: document.getElementById('arNotes').value,
                send_notification: document.getElementById('arSendNotification').checked
            })
        });
        const data = await res.json();
        if (data.success) {
            showToast(data.message || 'Reparation task assigned', 'success');
            closeAssignReparationModal();
            if (currentHistoryStudentId) {
                await loadViolationHistory(currentHistoryStudentId);
            } else {
                location.reload();
            }
        } else {
            showToast(data.error || 'Failed to assign reparation task', 'error');
        }
    } catch (err) {
        console.error('Error:', err);
        showToast('Failed to assign reparation task', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg> Assign Reparation Task';
    }
}

// Resolve ALL violations for a student
function confirmResolveAll(studentId, studentName) {
    showConfirmModal({
        title: `Resolve all violations for ${studentName}?`,
        message: 'This will mark all active violations as apprehended:',
        items: [
            'All active violations will be resolved',
            'Student will be notified they can claim their ID/Good Moral',
            'Gate access will be restored if applicable'
        ],
        confirmText: 'Resolve All',
        cancelText: 'Cancel',
        confirmClass: 'bg-teal-600 hover:bg-teal-700',
        onConfirm: () => {
            resolveAllViolations(studentId);
        }
    });
}

async function resolveAllViolations(studentId) {
    try {
        const res = await fetch('clear_violation.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                student_id: studentId,
                reparation_type: 'batch_resolution',
                reparation_notes: 'All violations resolved by SSO admin',
                send_notification: true
            })
        });
        const data = await res.json();
        if (data.success) {
            showToast('All violations resolved successfully', 'success');
            location.reload();
        } else {
            showToast(data.error || 'Failed to resolve violations', 'error');
        }
    } catch (err) {
        console.error('Error:', err);
        showToast('Failed to resolve violations', 'error');
    }
}

// ===== END VIOLATION MANAGEMENT =====

// ===== LIVE VIOLATION HISTORY (REALTIME TABLE) =====

let liveViolationRows = [];
let liveViolationSeenIds = new Set();
let liveViolationAfterId = 0;
let liveViolationLatestServerId = 0;
let liveViolationPollTimer = null;
let liveViolationSearchText = '';
let liveViolationSort = { key: 'created_at', dir: 'desc' };

function liveViolationNormalize(value) {
    return String(value ?? '').toLowerCase().trim();
}

function liveViolationCompare(a, b, key, dir) {
    const direction = dir === 'asc' ? 1 : -1;
    const va = a?.[key];
    const vb = b?.[key];

    if (key === 'created_at') {
        const ta = va ? Date.parse(va) : 0;
        const tb = vb ? Date.parse(vb) : 0;
        return (ta - tb) * direction;
    }

    const sa = liveViolationNormalize(va);
    const sb = liveViolationNormalize(vb);
    if (sa < sb) return -1 * direction;
    if (sa > sb) return 1 * direction;
    return 0;
}

function formatLiveViolationDate(value) {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleString();
}

function computeLiveViolationSearchHaystack(row) {
    return [
        row.created_at,
        row.student_name,
        row.student_id,
        row.email,
        row.rfid_uid,
        row.course,
        row.year_level,
        row.current_semester,
        row.violation_name,
        row.offense_category,
        row.offense_number
    ].map(v => String(v ?? '')).join(' ').toLowerCase();
}

function renderLiveViolationTable() {
    const tbody = document.getElementById('liveViolationTbody');
    const meta = document.getElementById('liveViolationMeta');
    if (!tbody || !meta) return;

    const query = liveViolationNormalize(liveViolationSearchText);

    let filtered = liveViolationRows;
    if (query) {
        filtered = liveViolationRows.filter(r => computeLiveViolationSearchHaystack(r).includes(query));
    }

    const sorted = [...filtered].sort((a, b) => liveViolationCompare(a, b, liveViolationSort.key, liveViolationSort.dir));

    if (sorted.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="10" class="px-4 py-10 text-center text-slate-400">No violations found.</td>
            </tr>
        `;
    } else {
        tbody.innerHTML = sorted.map(r => {
            const rfid = r.rfid_uid ? String(r.rfid_uid) : 'Not Registered';
            return `
                <tr class="hover:bg-white/60 transition-colors">
                    <td class="px-4 py-3 text-slate-700 whitespace-nowrap">${escapeHtml(formatLiveViolationDate(r.created_at))}</td>
                    <td class="px-4 py-3 text-slate-800 font-medium whitespace-nowrap">${escapeHtml(r.student_name || '')}</td>
                    <td class="px-4 py-3 text-slate-700 whitespace-nowrap">${escapeHtml(r.student_id || '')}</td>
                    <td class="px-4 py-3 text-slate-700 whitespace-nowrap">${escapeHtml(r.email || '')}</td>
                    <td class="px-4 py-3 text-slate-700 whitespace-nowrap font-mono">${escapeHtml(rfid)}</td>
                    <td class="px-4 py-3 text-slate-700 whitespace-nowrap">${escapeHtml(r.course || '')}</td>
                    <td class="px-4 py-3 text-slate-700 whitespace-nowrap">${escapeHtml(r.year_level || '')}</td>
                    <td class="px-4 py-3 text-slate-700 whitespace-nowrap">${escapeHtml(r.current_semester || '')}</td>
                    <td class="px-4 py-3 text-slate-800 whitespace-nowrap">${escapeHtml(r.violation_name || '')}</td>
                    <td class="px-4 py-3 text-slate-700 whitespace-nowrap">${escapeHtml(adminViolationTypeLabel(r.offense_category || ''))}</td>
                </tr>
            `;
        }).join('');
    }

    const now = new Date();
    const loaded = liveViolationRows.length;
    const showing = sorted.length;
    meta.textContent = `Loaded ${loaded} record${loaded !== 1 ? 's' : ''}. Showing ${showing}. Latest ID: ${liveViolationLatestServerId}. Updated: ${now.toLocaleTimeString()}`;
}

async function fetchLiveViolationRows({ initial = false } = {}) {
    const url = new URL('live_violation_feed.php', window.location.href);
    url.searchParams.set('after_id', String(initial ? 0 : liveViolationAfterId));
    url.searchParams.set('limit', String(initial ? 1000 : 500));

    const res = await fetch(url.toString(), { cache: 'no-store' });
    const data = await res.json();
    if (!data || !data.success) {
        throw new Error(data?.error || 'Failed to fetch live violations');
    }

    liveViolationLatestServerId = Number(data.latest_id || liveViolationLatestServerId || 0);

    const rows = Array.isArray(data.rows) ? data.rows : [];
    if (rows.length === 0) {
        // Keep afterId in sync even if there are currently no rows.
        if (initial && liveViolationAfterId === 0) {
            liveViolationAfterId = liveViolationLatestServerId;
        }
        return;
    }

    for (const row of rows) {
        const id = Number(row?.id || 0);
        if (!id || liveViolationSeenIds.has(id)) continue;
        liveViolationSeenIds.add(id);
        liveViolationRows.push(row);
        if (id > liveViolationAfterId) liveViolationAfterId = id;
    }
}

function attachLiveViolationSortHandlers() {
    const tbody = document.getElementById('liveViolationTbody');
    const table = tbody ? tbody.closest('table') : null;
    const thead = table ? table.querySelector('thead') : null;
    if (!thead) return;
    const headers = thead.querySelectorAll('th[data-sort-key]');
    headers.forEach(th => {
        th.addEventListener('click', () => {
            const key = th.getAttribute('data-sort-key') || 'created_at';
            if (liveViolationSort.key === key) {
                liveViolationSort.dir = liveViolationSort.dir === 'asc' ? 'desc' : 'asc';
            } else {
                liveViolationSort.key = key;
                liveViolationSort.dir = key === 'created_at' ? 'desc' : 'asc';
            }
            renderLiveViolationTable();
        });
    });
}

function initLiveViolationHistoryTable() {
    const tbody = document.getElementById('liveViolationTbody');
    const searchInput = document.getElementById('liveViolationSearch');
    const meta = document.getElementById('liveViolationMeta');
    if (!tbody || !searchInput || !meta) return;

    attachLiveViolationSortHandlers();

    let searchTimer = null;
    searchInput.addEventListener('input', () => {
        liveViolationSearchText = searchInput.value;
        if (searchTimer) clearTimeout(searchTimer);
        searchTimer = setTimeout(() => renderLiveViolationTable(), 120);
    });

    (async () => {
        try {
            meta.textContent = 'Loading...';
            await fetchLiveViolationRows({ initial: true });
            renderLiveViolationTable();

            // Poll for new rows.
            if (liveViolationPollTimer) clearInterval(liveViolationPollTimer);
            liveViolationPollTimer = setInterval(async () => {
                try {
                    await fetchLiveViolationRows({ initial: false });
                    renderLiveViolationTable();
                } catch (e) {
                    // Keep UI stable; transient network errors are expected.
                    console.error('Live violation poll error:', e);
                }
            }, 3000);
        } catch (e) {
            console.error('Live violation init error:', e);
            meta.textContent = 'Failed to load live violations.';
            tbody.innerHTML = `
                <tr>
                    <td colspan="10" class="px-4 py-10 text-center text-red-400">Failed to load violations.</td>
                </tr>
            `;
        }
    })();
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('liveViolationTbody')) {
        initLiveViolationHistoryTable();
    }
});

// ===== END LIVE VIOLATION HISTORY =====

// Approve student account
function approveStudent(studentId, studentName) {
    // Show modern confirmation modal
    showConfirmModal({
        title: `Approve account for ${studentName}?`,
        message: 'This will:',
        items: [
            'Activate their account',
            'Send approval email',
            'Allow them to log in'
        ],
        confirmText: 'OK',
        cancelText: 'Cancel',
        confirmClass: 'bg-blue-600 hover:bg-blue-700',
        onConfirm: () => {
            executeApproval(studentId, studentName);
        }
    });
}

function executeApproval(studentId, studentName) {
    fetch('verify_account.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            student_id: studentId,
            action: 'approve'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show toast and reload INSTANTLY
            showToast('Approved!', 'success', 0);
            
            // Fire email in background - don't wait for it
            fetch('send_approval_email.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ student_id: studentId })
            }).catch(() => {}); // Ignore any email errors
            
            // Reload immediately - don't wait for email
            location.reload();
        } else {
            showToast(data.error || 'Failed to approve account', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to approve. Please try again.', 'error');
    });
}

// Deny student account
function denyStudent(studentId, studentName) {
    if (!confirm(`Deny and remove account for ${studentName}?\n\n⚠️ WARNING: This will:\n✗ Delete their account permanently\n✗ Send denial email notification\n\nThis action cannot be undone!`)) {
        return;
    }
    
    // Double confirmation for destructive action
    if (!confirm(`Are you absolutely sure?\n\nStudent: ${studentName}\nAction: DENY & DELETE\n\nType reason in next dialog...`)) {
        return;
    }
    
    // Show loading state
    const btn = event.target.closest('button');
    const originalContent = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<svg class="animate-spin h-5 w-5 mx-auto" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
    
    fetch('verify_account.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            student_id: studentId,
            action: 'deny'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message with animation
            btn.innerHTML = '<svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
            
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            alert(data.error || 'Failed to deny account');
            btn.disabled = false;
            btn.innerHTML = originalContent;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to deny account. Please try again.');
        btn.disabled = false;
        btn.innerHTML = originalContent;
    });
}

// Sidebar toggle functionality
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    // Toggle classes
    sidebar.classList.toggle('sidebar-hidden');
    overlay.classList.toggle('active');
}

function renderSidebarMiniCalendar(now) {
    const monthEl = document.getElementById('sidebarCalendarMonth');
    const dateEl = document.getElementById('sidebarCalendarDate');
    const timeEl = document.getElementById('sidebarCalendarClock');
    const gridEl = document.getElementById('sidebarCalendarGrid');

    if (!monthEl || !dateEl || !timeEl || !gridEl) return;

    const year = now.getFullYear();
    const month = now.getMonth();
    const today = now.getDate();

    monthEl.textContent = now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    dateEl.textContent = now.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
    timeEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });

    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const prevMonthDays = new Date(year, month, 0).getDate();

    const labels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
    const cells = [];

    for (const label of labels) {
        cells.push(`<div class="sidebar-calendar-cell sidebar-calendar-head">${label}</div>`);
    }

    for (let i = firstDay - 1; i >= 0; i--) {
        cells.push(`<div class="sidebar-calendar-cell is-muted">${prevMonthDays - i}</div>`);
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const todayClass = day === today ? ' is-today' : '';
        cells.push(`<div class="sidebar-calendar-cell${todayClass}">${day}</div>`);
    }

    const totalCellsWithoutHeads = cells.length - labels.length;
    const trailingCount = (7 - (totalCellsWithoutHeads % 7)) % 7;
    for (let nextDay = 1; nextDay <= trailingCount; nextDay++) {
        cells.push(`<div class="sidebar-calendar-cell is-muted">${nextDay}</div>`);
    }

    gridEl.innerHTML = cells.join('');
}

function initSidebarMiniCalendar() {
    const gridEl = document.getElementById('sidebarCalendarGrid');
    if (!gridEl) return;

    let monthKey = '';
    const tick = () => {
        const now = new Date();
        const key = now.getFullYear() + '-' + now.getMonth();
        if (key !== monthKey) {
            monthKey = key;
            renderSidebarMiniCalendar(now);
            return;
        }

        const dateEl = document.getElementById('sidebarCalendarDate');
        const timeEl = document.getElementById('sidebarCalendarClock');
        if (dateEl) {
            dateEl.textContent = now.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
        }
        if (timeEl) {
            timeEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
        }
    };

    tick();
    setInterval(tick, 1000);
}

// Close sidebar when clicking outside on mobile
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize mobile behavior on small screens
    function initMobileSidebar() {
        if (window.innerWidth <= 768) {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            // Start with sidebar hidden on mobile
            if (!sidebar.classList.contains('sidebar-hidden')) {
                sidebar.classList.add('sidebar-hidden');
            }
            overlay.classList.remove('active');
        } else {
            // On desktop, always show sidebar
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.remove('sidebar-hidden');
            overlay.classList.remove('active');
        }
    }
    
    initMobileSidebar();
    
    // Re-initialize on window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            initMobileSidebar();
        }, 250);
    });

    initSidebarMiniCalendar();
});

// ========================================
// EDIT STUDENT FUNCTIONS
// ========================================

let editStudentIdValidationTimer = null;
let editStudentIdValidationAbortController = null;
let latestEditStudentIdValidationRequestId = 0;
let editStudentIdIsValidAndAvailable = false;

function setEditStudentIdRealtimeMessage(message, type = 'info') {
    const el = document.getElementById('editStudentIdRealtimeResult');
    if (!el) return;

    const styles = {
        info: 'bg-slate-100 text-slate-700',
        checking: 'bg-blue-50 text-blue-700',
        success: 'bg-emerald-50 text-emerald-700',
        warning: 'bg-amber-50 text-amber-700',
        error: 'bg-red-50 text-red-700'
    };

    el.className = `text-xs mt-2 px-3 py-2 rounded-lg ${styles[type] || styles.info}`;
    el.textContent = message;
}

function setEditStudentSaveButtonState(enabled) {
    const saveBtn = document.getElementById('editStudentSaveBtn');
    if (!saveBtn) return;
    saveBtn.disabled = !enabled;
    saveBtn.classList.toggle('opacity-60', !enabled);
    saveBtn.classList.toggle('cursor-not-allowed', !enabled);
}

function resetEditStudentIdValidationState() {
    editStudentIdIsValidAndAvailable = false;
    latestEditStudentIdValidationRequestId = 0;

    if (editStudentIdValidationTimer) {
        clearTimeout(editStudentIdValidationTimer);
        editStudentIdValidationTimer = null;
    }

    if (editStudentIdValidationAbortController) {
        editStudentIdValidationAbortController.abort();
        editStudentIdValidationAbortController = null;
    }
}

function debouncedValidateEditStudentId() {
    if (editStudentIdValidationTimer) {
        clearTimeout(editStudentIdValidationTimer);
    }
    editStudentIdValidationTimer = setTimeout(() => {
        validateEditStudentIdRealtime();
    }, 250);
}

async function validateEditStudentIdRealtime(options = {}) {
    const { showCheckingMessage = true } = options;
    const studentIdInput = document.getElementById('editStudentId');
    const userIdInput = document.getElementById('editStudentUserId');

    if (!studentIdInput) return false;

    const studentId = studentIdInput.value.trim();
    const userId = userIdInput ? userIdInput.value : '';

    editStudentIdIsValidAndAvailable = false;
    setEditStudentSaveButtonState(false);

    if (!studentId) {
        setEditStudentIdRealtimeMessage('Enter Student ID to validate availability.', 'warning');
        return false;
    }

    if (!/^\d{9}$/.test(studentId)) {
        setEditStudentIdRealtimeMessage('Student ID must be exactly 9 digits.', 'warning');
        return false;
    }

    if (editStudentIdValidationAbortController) {
        editStudentIdValidationAbortController.abort();
    }

    const requestId = ++latestEditStudentIdValidationRequestId;
    editStudentIdValidationAbortController = new AbortController();

    if (showCheckingMessage) {
        setEditStudentIdRealtimeMessage('Checking Student ID availability...', 'checking');
    }

    try {
        const response = await fetch('check_student_id_availability.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                student_id: studentId,
                expected_user_id: userId || null
            }),
            signal: editStudentIdValidationAbortController.signal
        });

        if (requestId !== latestEditStudentIdValidationRequestId) {
            return false;
        }

        let data = null;
        try {
            data = await response.json();
        } catch (parseError) {
            setEditStudentIdRealtimeMessage('Unable to validate Student ID right now. Please try again.', 'error');
            return false;
        }

        if (!response.ok || !data.success) {
            setEditStudentIdRealtimeMessage(data?.error || 'Student ID validation failed. Please try again.', 'error');
            return false;
        }

        if (data.available) {
            editStudentIdIsValidAndAvailable = true;
            setEditStudentSaveButtonState(true);
            setEditStudentIdRealtimeMessage(data.message || 'PASS: Student ID is valid and available.', 'success');
            return true;
        }

        setEditStudentIdRealtimeMessage(data.message || 'This student ID is already taken.', 'error');
        return false;
    } catch (error) {
        if (error.name === 'AbortError') {
            return false;
        }
        setEditStudentIdRealtimeMessage('Unable to validate Student ID right now. Please check your connection.', 'error');
        return false;
    }
}

function openEditStudentModal(userId, name, studentId, email, profilePicture, course) {
    resetEditStudentIdValidationState();

    // Set form values
    document.getElementById('editStudentUserId').value = userId;
    document.getElementById('editStudentName').value = name;
    document.getElementById('editStudentId').value = studentId;
    document.getElementById('editStudentEmail').value = email;

    const editStudentIdInput = document.getElementById('editStudentId');
    const initialStudentId = (editStudentIdInput?.value || '').trim();
    if (editStudentIdInput && initialStudentId && !/^\d{9}$/.test(initialStudentId)) {
        setEditStudentIdRealtimeMessage('Temporary Student ID detected. Enter the real 9-digit Student ID.', 'warning');
    }
    
    // Set course dropdown
    const courseSelect = document.getElementById('editStudentCourse');
    if (course) {
        // Try exact match first
        let found = false;
        for (let i = 0; i < courseSelect.options.length; i++) {
            if (courseSelect.options[i].value === course) {
                courseSelect.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) {
            // Add as custom option if not in the list
            const opt = document.createElement('option');
            opt.value = course;
            opt.textContent = course;
            courseSelect.appendChild(opt);
            courseSelect.value = course;
        }
    } else {
        courseSelect.selectedIndex = 0;
    }
    
    // Show modal
    document.getElementById('editStudentModal').classList.remove('hidden');
    document.getElementById('editStudentModal').classList.add('flex');

    // Validate current student ID state immediately so save action is gated.
    debouncedValidateEditStudentId();
    
    // Initialize Digital ID Preview
    try {
        if (window.digitalIdPreview) {
            window.digitalIdPreview.destroy();
        }
        window.digitalIdPreview = new DigitalIdCard('#digitalIdPreviewContainer', {
            templateSrc: '../assets/images/id-card-template.png',
            student: {
                name: name || '',
                studentId: document.getElementById('editStudentId').value.trim(),
                course: course || '',
                email: email || '',
                profilePicture: (profilePicture && profilePicture !== '') 
                    ? '../assets/images/profiles/' + profilePicture 
                    : null
            }
        });
        window.digitalIdPreview.render();
        // Show delete button if student already has a photo
        var delBtn = document.getElementById('deletePhotoBtn');
        if (delBtn) delBtn.style.display = (profilePicture && profilePicture !== '') ? 'inline-block' : 'none';
        setTimeout(function(){ window.digitalIdPreview.animateEntrance(); }, 50);
    } catch(idErr) {
        console.error('DigitalIdCard error:', idErr);
        document.getElementById('digitalIdPreviewContainer').innerHTML = '<div style="padding:20px;background:#fee2e2;border-radius:8px;color:#dc2626;text-align:center;">ID Card Error: ' + idErr.message + '</div>';
    }
}

// Live update the Digital ID card as the admin types
function updateDigitalIdPreview() {
    if (!window.digitalIdPreview) return;
    window.digitalIdPreview.update({
        name: document.getElementById('editStudentName').value.trim(),
        studentId: document.getElementById('editStudentId').value.trim(),
        course: document.getElementById('editStudentCourse').value
    });
}

function enforceNumericStudentId(inputEl) {
    if (!inputEl) return;
    inputEl.value = inputEl.value.replace(/\D/g, '').slice(0, 9);
}

function closeEditStudentModal() {
    resetEditStudentIdValidationState();
    setEditStudentSaveButtonState(true);

    document.getElementById('editStudentModal').classList.add('hidden');
    document.getElementById('editStudentModal').classList.remove('flex');
    
    // Destroy Digital ID preview
    if (window.digitalIdPreview) {
        window.digitalIdPreview.destroy();
        window.digitalIdPreview = null;
    }
    var delBtn = document.getElementById('deletePhotoBtn');
    if (delBtn) delBtn.style.display = 'none';
}

// Remove photo from ID card (and delete from server)
async function removeIdCardPhoto() {
    var userId = document.getElementById('editStudentUserId').value;
    
    // Remove from preview immediately
    if (window.digitalIdPreview) {
        window.digitalIdPreview.removePhoto();
    }
    
    // Delete from server if it exists
    try {
        var response = await fetch('delete_student_picture.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ user_id: userId })
        });
        var data = await response.json();
        if (!data.success && data.error && !data.error.includes('No profile picture')) {
            console.warn('Photo delete warning:', data.error);
        }
    } catch(e) {
        console.warn('Photo delete error (non-fatal):', e);
    }
}

async function saveStudentInfo() {
    var userId = document.getElementById('editStudentUserId').value;
    var name = document.getElementById('editStudentName').value.trim();
    var studentIdInput = document.getElementById('editStudentId');
    enforceNumericStudentId(studentIdInput);
    var studentId = studentIdInput.value.trim();
    var course = document.getElementById('editStudentCourse').value;
    
    if (!name) {
        showToast('Please enter student name.', 'warning', 3600, {
            title: 'Missing Required Field',
            tag: 'Validation'
        });
        return;
    }
    if (!studentId) {
        showToast('Please enter student ID.', 'warning', 3600, {
            title: 'Missing Required Field',
            tag: 'Validation'
        });
        return;
    }
    if (!/^\d{9}$/.test(studentId)) {
        showToast('Student ID must be exactly 9 digits (numbers only).', 'warning', 4000, {
            title: 'Invalid Student ID Format',
            tag: 'Validation'
        });
        return;
    }

    const isStudentIdValid = await validateEditStudentIdRealtime({ showCheckingMessage: false });
    if (!isStudentIdValid) {
        showToast('Student ID failed validation. Make sure it is 9 digits and not already taken.', 'error', 4200, {
            title: 'Unable To Save Changes',
            tag: 'Validation'
        });
        return;
    }
    
    try {
        // 1. Upload photo first if there's a pending one
        if (window.digitalIdPreview && window.digitalIdPreview.hasPendingPhoto()) {
            var formData = new FormData();
            formData.append('student_id', userId);
            formData.append('file', window.digitalIdPreview.getPendingPhotoFile());
            
            var photoResp = await fetch('upload_student_picture.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: formData
            });
            var photoData = await photoResp.json();
            if (!photoData.success) {
                showToast('Photo upload failed: ' + (photoData.error || 'Unknown error'), 'error', 4200, {
                    title: 'Photo Upload Failed',
                    tag: 'Upload'
                });
                return;
            }
        }
        
        // 2. Save student info
        var response = await fetch('update_student_info.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                user_id: userId,
                name: name,
                student_id: studentId,
                course: course
            })
        });
        
        var data = await response.json();
        
        if (data.success) {
            closeEditStudentModal();
            showToast('Admin edits were saved successfully for ' + name + '.', 'success', 4200, {
                title: 'Student Information Updated',
                tag: 'Confirmed'
            });
            setTimeout(() => location.reload(), 900);
        } else {
            if (data.error && data.error.includes('no changes made')) {
                closeEditStudentModal();
                showToast('No new edits were detected. Student information is already up to date.', 'info', 3600, {
                    title: 'No Changes Detected',
                    tag: 'Up To Date'
                });
                setTimeout(() => location.reload(), 900);
            } else {
                showToast('Update failed: ' + (data.error || 'Unknown error'), 'error', 4200, {
                    title: 'Failed To Update Student',
                    tag: 'Action Required'
                });
            }
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to update student information. Please try again.', 'error', 4200, {
            title: 'Request Failed',
            tag: 'Network'
        });
    }
}

// ========================================
// ========================================
// REGISTERED CARDS SEARCH FUNCTIONALITY
// ========================================

// ========================================
// RFID CHECKER FUNCTIONS
// ========================================

// RFID Checker – scanner listener + lookup
(function() {
    const checkerInput = document.getElementById('rfidCheckerInput');
    if (!checkerInput) return;

    let checkerBuffer = '';
    let checkerTimeout = null;

    // Listen for RFID scanner keyboard emulation on the input
    checkerInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            lookupRfidCard();
            return;
        }
    });

    // Also listen globally for fast scanner input when page is focused
    document.addEventListener('keydown', function(e) {
        // Only capture when rfid_checker section is active and no modal is open
        if (!document.getElementById('rfidCheckerInput')) return;
        const activeEl = document.activeElement;
        // If already focused on the checker input, let the input handler deal with it
        if (activeEl && activeEl.id === 'rfidCheckerInput') return;
        // Skip if user is typing in another input/textarea
        if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT')) return;

        if (e.key === 'Enter' && checkerBuffer.length >= 4) {
            e.preventDefault();
            checkerInput.value = checkerBuffer;
            checkerBuffer = '';
            clearTimeout(checkerTimeout);
            lookupRfidCard();
            return;
        }
        if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            checkerBuffer += e.key;
            clearTimeout(checkerTimeout);
            checkerTimeout = setTimeout(() => { checkerBuffer = ''; }, 300);
        }
    });
})();

function lookupRfidCard() {
    const input = document.getElementById('rfidCheckerInput');
    const uid = (input ? input.value.trim() : '');
    const resultDiv = document.getElementById('rfidCheckerResult');

    if (!uid) {
        resultDiv.innerHTML = `
            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
                <p class="text-yellow-700 font-medium">Please enter or scan an RFID UID first.</p>
            </div>`;
        return;
    }

    resultDiv.innerHTML = `
        <div class="glass-card rounded-2xl p-8 text-center">
            <svg class="animate-spin h-8 w-8 text-blue-500 mx-auto mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <p class="text-slate-500">Looking up RFID card...</p>
        </div>`;

    fetch('check_rfid.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ rfid_uid: uid, csrf_token: csrfToken })
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            resultDiv.innerHTML = `
                <div class="bg-red-50 border border-red-200 rounded-xl p-6">
                    <div class="flex items-center gap-3 mb-2">
                        <span class="text-3xl">❌</span>
                        <div>
                            <h3 class="text-lg font-bold text-red-800">Not Found</h3>
                            <p class="text-red-600 text-sm">${escapeHtml(data.error || 'This RFID UID is not registered to any student.')}</p>
                        </div>
                    </div>
                    <div class="mt-3 bg-white rounded-lg p-3 border border-red-100">
                        <p class="text-slate-500 text-sm">Scanned UID: <code class="bg-slate-100 px-2 py-1 rounded font-mono">${escapeHtml(uid)}</code></p>
                    </div>
                </div>`;
            return;
        }

        const s = data.student;
        const card = data.card;

        // Status badge
        let statusBadge = '';
        if (card && card.is_lost == 1) {
            statusBadge = '<span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-700">🚫 LOST / DISABLED</span>';
        } else if (card) {
            statusBadge = '<span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-700">✅ Active</span>';
        } else {
            statusBadge = '<span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-semibold bg-blue-100 text-blue-700">📋 Registered (no card record)</span>';
        }

        // Violation severity
        let violationColor = 'green';
        let violationLabel = 'No violations';
        if (s.violation_count >= 4) {
            violationColor = 'red'; violationLabel = 'BLOCKED — Max offenses exceeded';
        } else if (s.violation_count === 3) {
            violationColor = 'orange'; violationLabel = 'FINAL WARNING — 3 offenses';
        } else if (s.violation_count === 2) {
            violationColor = 'yellow'; violationLabel = 'Warning — 2 offenses';
        } else if (s.violation_count === 1) {
            violationColor = 'blue'; violationLabel = '1 offense';
        }

        const lastScan = data.last_scan
            ? new Date(data.last_scan).toLocaleString()
            : '<span class="text-slate-400">Never scanned</span>';

        const lostDate = (card && card.is_lost == 1 && card.lost_at)
            ? new Date(card.lost_at).toLocaleString()
            : null;

        resultDiv.innerHTML = `
            <div class="glass-card rounded-2xl overflow-hidden">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        ${s.profile_picture
                            ? '<img src="../assets/images/profiles/' + escapeHtml(s.profile_picture) + '" class="w-12 h-12 rounded-full object-cover border-2 border-slate-200">'
                            : '<div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold text-lg">' + escapeHtml(s.name.charAt(0).toUpperCase()) + '</div>'
                        }
                        <div>
                            <h3 class="text-lg font-bold text-slate-800">${escapeHtml(s.name)}</h3>
                            <p class="text-sm text-slate-500">${escapeHtml(s.student_id)} ${s.course ? '• ' + escapeHtml(s.course) : ''}</p>
                        </div>
                    </div>
                    ${statusBadge}
                </div>

                <!-- Details Grid -->
                <div class="p-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- RFID UID -->
                        <div class="bg-slate-50 rounded-lg p-4">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">RFID UID</p>
                            <p class="text-lg font-mono font-semibold text-slate-800">${escapeHtml(s.rfid_uid)}</p>
                        </div>

                        <!-- Email -->
                        <div class="bg-slate-50 rounded-lg p-4">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Email</p>
                            <p class="text-sm font-medium text-slate-800 break-all">${escapeHtml(s.email)}</p>
                        </div>

                        <!-- Violations -->
                        <div class="bg-${violationColor}-50 rounded-lg p-4 border border-${violationColor}-200">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Violations</p>
                            <p class="text-2xl font-bold text-${violationColor}-700">${escapeHtml(String(s.violation_count))}</p>
                            <p class="text-xs text-${violationColor}-600 mt-1">${violationLabel}</p>
                        </div>

                        <!-- Last Scan -->
                        <div class="bg-slate-50 rounded-lg p-4">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Last Gate Scan</p>
                            <p class="text-sm font-medium text-slate-800">${lastScan}</p>
                        </div>

                        <!-- Account Status -->
                        <div class="bg-slate-50 rounded-lg p-4">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Account Status</p>
                            <p class="text-sm font-semibold ${s.status === 'Active' ? 'text-green-700' : 'text-yellow-700'}">${escapeHtml(s.status)}</p>
                        </div>

                        <!-- Registered At -->
                        <div class="bg-slate-50 rounded-lg p-4">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">RFID Registered</p>
                            <p class="text-sm font-medium text-slate-800">${s.rfid_registered_at ? new Date(s.rfid_registered_at).toLocaleString() : '<span class=&quot;text-slate-400&quot;>Unknown</span>'}</p>
                        </div>
                    </div>

                    ${card && card.is_lost == 1 ? `
                    <div class="mt-4 bg-red-50 border-2 border-red-300 rounded-lg p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-xl">🚫</span>
                            <h4 class="font-bold text-red-800">Card Marked as LOST</h4>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                            <div>
                                <span class="text-red-600 font-medium">Lost Date:</span>
                                <span class="text-red-800">${lostDate || 'Unknown'}</span>
                            </div>
                            <div>
                                <span class="text-red-600 font-medium">Reason:</span>
                                <span class="text-red-800">${escapeHtml(card.lost_reason || 'No reason provided')}</span>
                            </div>
                        </div>
                        <p class="text-red-700 text-xs mt-2 font-medium">⚠️ This card is disabled. Gate scans will be rejected until an admin re-enables it.</p>
                    </div>
                    ` : ''}
                </div>
            </div>`;
    })
    .catch(err => {
        console.error('RFID Checker error:', err);
        resultDiv.innerHTML = `
            <div class="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
                <p class="text-red-700 font-medium">Failed to look up RFID card. Please try again.</p>
            </div>`;
    });
}

// Real-time search for registered cards
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('registeredCardSearch');
    
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase().trim();
            const cards = document.querySelectorAll('.registered-card-item');
            let visibleCount = 0;
            
            cards.forEach(card => {
                const studentId = card.getAttribute('data-student-id');
                const studentName = card.getAttribute('data-student-name');
                
                // Search in both student ID and name
                if (studentId.includes(searchTerm) || studentName.includes(searchTerm)) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Update result count
            const resultCount = document.getElementById('searchResultCount');
            if (resultCount) {
                if (searchTerm === '') {
                    resultCount.textContent = '';
                } else {
                    resultCount.textContent = `Showing ${visibleCount} of ${cards.length} registered card${cards.length !== 1 ? 's' : ''}`;
                }
            }
        });
        
        // Clear search on Escape key
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                searchInput.blur();
            }
        });
    }
});

// ========================================
// AUDIT LOG FUNCTIONS
// ========================================

// Show audit log details modal
function showAuditDetails(log) {
    try {
        const details = JSON.parse(log.details);
        const detailsHtml = Object.entries(details).map(([key, value]) => {
            // Special handling for UPDATE_STUDENT changes object
            if (key === 'changes' && value && typeof value === 'object' && !Array.isArray(value)) {
                const changeRows = Object.entries(value).map(([field, change]) => {
                    const fromValue = change && typeof change === 'object' && change.from !== undefined ? String(change.from) : '';
                    const toValue = change && typeof change === 'object' && change.to !== undefined ? String(change.to) : '';

                    return `
                        <div class="py-2 border-b border-slate-100 last:border-b-0">
                            <div class="font-medium text-slate-700">${field.replace(/_/g, ' ').toUpperCase()}</div>
                            <div class="text-sm text-slate-600 mt-1">
                                <span class="text-slate-500">From:</span> ${escapeHtml(fromValue || '—')}
                            </div>
                            <div class="text-sm text-slate-600">
                                <span class="text-slate-500">To:</span> ${escapeHtml(toValue || '—')}
                            </div>
                        </div>
                    `;
                }).join('');

                return `
                    <div class="py-2 border-b border-slate-100">
                        <div class="font-medium text-slate-700 mb-2">CHANGES:</div>
                        <div class="bg-white rounded-lg p-3 border border-slate-200">
                            ${changeRows || '<div class="text-sm text-slate-500">No field changes recorded</div>'}
                        </div>
                    </div>
                `;
            }

            let displayValue = value;
            if (value === null || value === undefined || value === '') {
                displayValue = '—';
            } else if (typeof value === 'object') {
                displayValue = JSON.stringify(value);
            }

            // Human-readable labels for reparation_type values
            if (key === 'reparation_type' && typeof displayValue === 'string' && displayValue !== '—') {
                const reparationLabels = {
                    written_apology:       'Written Apology Letter',
                    community_service:     'Community Service',
                    counseling:            'Counseling Session',
                    parent_conference:     'Parent/Guardian Conference',
                    suspension_compliance: 'Suspension Compliance',
                    restitution:           'Restitution / Payment',
                    suspension_served:     'Suspension Period Served',
                    batch_resolution:      'Batch Resolution (All Violations)',
                    other:                 'Other',
                };
                displayValue = reparationLabels[displayValue] || displayValue.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            }

            if (typeof displayValue === 'string' && displayValue !== '—') {
                displayValue = normalizeStrikeLabel(displayValue);
            }

            return `
                <div class="flex justify-between py-2 border-b border-slate-100">
                    <span class="font-medium text-slate-700">${key.replace(/_/g, ' ').toUpperCase()}:</span>
                    <span class="text-slate-600">${escapeHtml(String(displayValue))}</span>
                </div>
            `;
        }).join('');
        
        showCustomConfirm(
            `Audit Log Details - ${log.action_type.replace(/_/g, ' ')}`,
            `
                <div class="text-left space-y-3">
                    <div class="bg-slate-50 p-4 rounded-lg space-y-2">
                        ${detailsHtml}
                    </div>
                    <div class="text-xs text-slate-500 mt-4 pt-4 border-t border-slate-200">
                        <div>
                            <div><strong>Timestamp:</strong> ${new Date(log.created_at).toLocaleString()}</div>
                        </div>
                    </div>
                </div>
            `,
            {
                type: 'info',
                okText: 'Close',
                cancelText: null
            }
        );
    } catch (error) {
        console.error('Error displaying audit details:', error);
        showToast('Failed to display audit details', 'error');
    }
}

// Export current audit log filters to Excel
function exportAuditToExcel() {
    const actionType = document.getElementById('filterActionType').value;
    const dateFrom   = document.getElementById('filterDateFrom').value;
    const dateTo     = document.getElementById('filterDateTo').value;

    const params = new URLSearchParams();
    if (actionType) params.set('action_type', actionType);
    if (dateFrom)   params.set('date_from', dateFrom);
    if (dateTo)     params.set('date_to', dateTo);

    const url = 'export_audit_logs.php' + (params.toString() ? '?' + params.toString() : '');
    window.location.href = url;
}

// Apply audit log filters
async function applyAuditFilters(silent = false) {
    const actionType = document.getElementById('filterActionType').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    
    const params = new URLSearchParams({
        action_type: actionType,
        date_from: dateFrom,
        date_to: dateTo
    });
    
    try {
        const response = await fetch(`filter_audit_logs.php?${params}`, {
            headers: {
                'X-CSRF-Token': csrfToken
            }
        });
        const data = await response.json();
        
        if (data.success) {
            updateAuditTable(data.logs);
            if (!silent) {
                showToast(`Found ${data.count} audit log${data.count !== 1 ? 's' : ''}`, 'success', 2000);
            }
        } else {
            if (!silent) {
                showToast(data.error || 'Failed to filter logs', 'error');
            }
        }
    } catch (error) {
        console.error('Error:', error);
        if (!silent) {
            showToast('Failed to apply filters', 'error');
        }
    }
}

// Update audit table with filtered results
function updateAuditTable(logs) {
    const tbody = document.getElementById('auditLogTableBody');
    
    if (logs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-12 text-slate-600">
                    <svg class="w-16 h-16 mx-auto text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <p class="font-medium text-slate-700 mb-1">No audit logs found</p>
                    <p class="text-sm text-slate-500">Try adjusting your filter criteria</p>
                </td>
            </tr>
        `;
        return;
    }
    
    const actionColors = {
        'APPROVE_STUDENT': 'bg-green-100 text-green-800',
        'DENY_STUDENT': 'bg-red-100 text-red-800',
        'REGISTER_RFID': 'bg-blue-100 text-blue-800',
        'UNREGISTER_RFID': 'bg-yellow-100 text-yellow-800',
        'MARK_LOST': 'bg-orange-100 text-orange-800',
        'MARK_FOUND': 'bg-emerald-100 text-emerald-800',
        'UPDATE_STUDENT': 'bg-indigo-100 text-indigo-800',
        'DELETE_STUDENT': 'bg-red-100 text-red-800',
        'ADD_VIOLATION': 'bg-rose-100 text-rose-800',
        'RESOLVE_VIOLATION': 'bg-teal-100 text-teal-800',
        'RESOLVE_ALL_VIOLATIONS': 'bg-emerald-100 text-emerald-800',
        'ASSIGN_REPARATION': 'bg-amber-100 text-amber-800',
        'EXPORT_AUDIT_LOG': 'bg-purple-100 text-purple-800',
    };
    
    tbody.innerHTML = logs.map(log => {
        const date = new Date(log.created_at);
        const actionColor = actionColors[log.action_type] || 'bg-slate-100 text-slate-800';
        
        return `
            <tr class="border-b border-slate-100 hover:bg-slate-50 transition-colors">
                <td class="py-3 px-4 text-sm text-slate-600">
                    ${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}<br>
                    <span class="text-xs text-slate-500">${date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                </td>
                <td class="py-3 px-4 text-sm font-medium text-slate-800">
                    ${escapeHtml(log.admin_name)}
                </td>
                <td class="py-3 px-4">
                    <span class="${actionColor} text-xs font-semibold px-2 py-1 rounded-full">
                        ${log.action_type.replace(/_/g, ' ')}
                    </span>
                </td>
                <td class="py-3 px-4 text-sm text-slate-700">
                    ${log.target_name ? `
                        <strong>${escapeHtml(log.target_name)}</strong><br>
                        <span class="text-xs text-slate-500">${log.target_type.charAt(0).toUpperCase() + log.target_type.slice(1)} ID: ${log.target_id}</span>
                    ` : '<span class="text-slate-500">N/A</span>'}
                </td>
                <td class="py-3 px-4 text-sm text-slate-600">
                    ${escapeHtml(log.description)}
                </td>
                <td class="py-3 px-4 text-center">
                    ${log.details ? `
                        <button onclick='showAuditDetails(${JSON.stringify(log).replace(/'/g, "\\'")})' 
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium transition-colors">
                            View Details
                        </button>
                    ` : '<span class="text-slate-400 text-sm">-</span>'}
                </td>
            </tr>
        `;
    }).join('');
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ========================================
// AUDIT LOG LIVE REFRESH
// ========================================
let auditLiveInterval = null;
let auditLiveEnabled = false;

function toggleAuditLiveRefresh() {
    auditLiveEnabled = !auditLiveEnabled;
    const indicator = document.getElementById('auditLiveIndicator');
    const toggleBtn = document.getElementById('auditLiveToggle');
    const toggleText = document.getElementById('auditLiveToggleText');
    
    if (auditLiveEnabled) {
        // Start live refresh every 5 seconds
        auditLiveInterval = setInterval(() => {
            applyAuditFilters(true); // silent mode - no toast
        }, 5000);
        
        // Run immediately
        applyAuditFilters(true);
        
        toggleText.textContent = 'Disable Live';
    } else {
        // Stop live refresh
        if (auditLiveInterval) {
            clearInterval(auditLiveInterval);
            auditLiveInterval = null;
        }
        
        toggleText.textContent = 'Enable Live';
    }
}

// Auto-enable live refresh when the audit controls are present
if (document.getElementById('auditLiveToggle')) {
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-start live refresh for real-time audit updates
        if (!auditLiveEnabled) {
            toggleAuditLiveRefresh();
        }
    });
}

// ========================================
// MODERN CONFIRMATION MODAL
// ========================================

function showConfirmModal({ title, message, items = [], confirmText = 'Confirm', cancelText = 'Cancel', confirmClass = 'bg-blue-600 hover:bg-blue-700', onConfirm }) {
    // Remove any existing modal
    const existingModal = document.getElementById('customConfirmModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal HTML
    const itemsList = items.map(item => `<div class="flex items-center gap-2 text-gray-700"><span class="text-green-600">✓</span> ${item}</div>`).join('');
    
    const modalHTML = `
        <div id="customConfirmModal" class="fixed inset-0 z-50 flex items-center justify-center animate-fadeIn" style="animation: fadeIn 0.2s ease-out;">
            <div class="absolute inset-0 bg-black bg-opacity-50 backdrop-blur-sm" onclick="closeConfirmModal()"></div>
            <div class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 transform animate-slideUp" style="animation: slideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);">
                <div class="p-6">
                    <h3 class="text-xl font-semibold text-gray-900 mb-3">${title}</h3>
                    ${message ? `<p class="text-gray-600 mb-3">${message}</p>` : ''}
                    ${items.length ? `<div class="space-y-2 mb-6">${itemsList}</div>` : ''}
                    <div class="flex gap-3">
                        <button onclick="confirmModalAction()" class="${confirmClass} text-white px-6 py-2.5 rounded-lg font-medium transition-all transform hover:scale-105 active:scale-95 flex-1">
                            ${confirmText}
                        </button>
                        <button onclick="closeConfirmModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2.5 rounded-lg font-medium transition-all transform hover:scale-105 active:scale-95 flex-1">
                            ${cancelText}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <style>
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes slideUp {
                from { transform: translateY(20px) scale(0.95); opacity: 0; }
                to { transform: translateY(0) scale(1); opacity: 1; }
            }
        </style>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Store callback
    window._confirmModalCallback = onConfirm;
}

function closeConfirmModal() {
    const modal = document.getElementById('customConfirmModal');
    if (modal) {
        modal.style.animation = 'fadeOut 0.2s ease-out';
        setTimeout(() => modal.remove(), 200);
    }
    delete window._confirmModalCallback;
}

function confirmModalAction() {
    const callback = window._confirmModalCallback;
    closeConfirmModal();
    if (callback && typeof callback === 'function') {
        callback();
    }
}

// ========================================
// TOAST NOTIFICATIONS
// ========================================

function showToast(message, type = 'info') {
    const colors = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        warning: 'bg-amber-500',
        info: 'bg-blue-500'
    };
    
    const icons = {
        success: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>',
        error: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>',
        warning: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>',
        info: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    };
    
    const bgColor = colors[type] || colors.info;
    const icon = icons[type] || icons.info;
    
    const toastHTML = `
        <div class="toast ${bgColor} text-white px-6 py-4 rounded-lg shadow-lg flex items-center gap-3 min-w-[300px] max-w-md transform transition-all duration-300 animate-slideInRight" style="animation: slideInRight 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                ${icon}
            </svg>
            <span class="flex-1">${message}</span>
            <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200 transition-colors">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </button>
        </div>
        <style>
            @keyframes slideInRight {
                from { transform: translateX(400px); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        </style>
    `;
    
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'fixed top-4 right-4 space-y-2 z-50';
        document.body.appendChild(container);
    }
    
    const toastElement = document.createElement('div');
    toastElement.innerHTML = toastHTML;
    const toast = toastElement.firstElementChild;
    container.appendChild(toast);
    
    // Auto-remove after 4 seconds
    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease-out';
        toast.style.transform = 'translateX(400px)';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}
</script>

<!-- Face Enroll Modal -->
<div id="faceEnrollModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50" style="display:none;">
    <div class="bg-white rounded-xl p-6 max-w-2xl w-full mx-4 fade-in max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-xl font-semibold text-slate-800">Enroll Student Face</h3>
                <p class="text-slate-600 text-sm" id="faceEnrollStudentName"></p>
            </div>
            <button onclick="closeFaceEnrollModal()" class="text-slate-400 hover:text-slate-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Progress Steps -->
        <div class="flex items-center justify-center gap-2 mb-4">
            <div id="enrollStep1" class="flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-[#0056b3] text-white">
                <span>1</span> Front
            </div>
            <div class="w-4 h-0.5 bg-slate-300"></div>
            <div id="enrollStep2" class="flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-slate-200 text-slate-500">
                <span>2</span> Left
            </div>
            <div class="w-4 h-0.5 bg-slate-300"></div>
            <div id="enrollStep3" class="flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-slate-200 text-slate-500">
                <span>3</span> Right
            </div>
        </div>

        <!-- Status indicator -->
        <div id="faceEnrollStatus" class="bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4">
            Initializing camera...
        </div>

        <!-- Captured faces preview -->
        <div id="enrollCapturedPreview" class="hidden mb-4">
            <p class="text-xs font-medium text-slate-500 mb-2">Captured Angles:</p>
            <div class="flex gap-2" id="enrollCapturedThumbs"></div>
        </div>

        <!-- Webcam container -->
        <div class="relative mx-auto mb-4 bg-black rounded-lg overflow-hidden" style="max-width:640px;">
            <video id="faceEnrollVideo" class="w-full" autoplay muted playsinline></video>
            <canvas id="faceEnrollCanvas" class="absolute top-0 left-0 w-full h-full pointer-events-none"></canvas>
        </div>

        <!-- Instruction -->
        <div id="enrollInstruction" class="text-center mb-4">
            <p class="text-slate-700 font-medium" id="enrollAngleInstruction">Position the student facing the camera <strong>directly (front)</strong></p>
        </div>

        <!-- Capture controls -->
        <div class="flex gap-3">
            <button onclick="captureEnrollFace()" id="btnEnrollCapture" class="flex-1 px-4 py-3 bg-[#0056b3] hover:bg-blue-700 text-white rounded-lg font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                📷 Capture Front View
            </button>
            <button onclick="closeFaceEnrollModal()" class="px-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>

<?php if (filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)): ?>
<!-- Face Recognition Scripts (Admin) -->
<script defer src="../assets/js/vendor/face-api.min.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/vendor/face-api.min.js'); ?>"></script>
<script defer src="../assets/js/face-recognition.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/face-recognition.js'); ?>"></script>
<script>
// Face Logic for Admin
const CSRF_TOKEN_FACE = (typeof csrfToken !== 'undefined') ? csrfToken : '';
let faceRegSystem = null;
let faceRegModelsLoaded = false;

async function deleteFaceData(studentId, studentName) {
    if (!confirm('Delete all face recognition data for ' + studentName + '? This cannot be undone.')) {
        return;
    }
    
    try {
        const response = await fetch('../api/delete_face.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN_FACE
            },
            credentials: 'same-origin',
            body: JSON.stringify({ student_id: studentId })
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (typeof showToast === 'function') {
                showToast(data.message, 'success');
            }
            // Reload page to refresh table
            setTimeout(() => window.location.reload(), 1000);
        } else {
            alert('Error: ' + (data.error || 'Failed to delete'));
        }
    } catch (error) {
        alert('Network error: ' + error.message);
    }
}

function filterFaceStudents() {
    const filter = document.getElementById('faceSearchInput').value.toLowerCase();
    const rows = document.querySelectorAll('.face-student-row');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
}

// ================================================================
// FACE ENROLLMENT (face_enroll section)
// ================================================================
let faceEnrollSystem = null;
let faceEnrollStudentId = null;
let faceEnrollModelsLoaded = false;
let enrollLiveTimer = null;
const enrollAngles = ['front', 'left', 'right'];
let enrollCurrentStep = 0; // 0=front, 1=left, 2=right
let enrollCapturedCount = 0;

function filterEnrollStudents() {
    const filter = (document.getElementById('enrollSearchInput')?.value || '').toLowerCase();
    const cards = document.querySelectorAll('.enroll-student-card');
    cards.forEach(card => {
        const searchText = card.getAttribute('data-search') || '';
        card.style.display = searchText.includes(filter) ? '' : 'none';
    });
}

async function openFaceEnrollModal(studentId, studentName, studentSid) {
    faceEnrollStudentId = studentId;
    enrollCurrentStep = 0;
    enrollCapturedCount = 0;
    
    document.getElementById('faceEnrollStudentName').textContent = studentName + ' (' + studentSid + ')';
    document.getElementById('enrollCapturedPreview').classList.add('hidden');
    document.getElementById('enrollCapturedThumbs').innerHTML = '';
    updateEnrollSteps(0);
    updateEnrollInstruction('front');
    
    const modal = document.getElementById('faceEnrollModal');
    modal.style.display = 'flex';
    modal.classList.remove('hidden');
    
    const statusEl = document.getElementById('faceEnrollStatus');
    const captureBtn = document.getElementById('btnEnrollCapture');
    captureBtn.disabled = true;
    captureBtn.textContent = '📷 Capture Front View';

    // Initialize face system (reuse faceRegSystem if already loaded)
    if (!faceRegSystem) {
        faceRegSystem = new FaceRecognitionSystem({
            modelPath: '../assets/models',
            minConfidence: 0.5,
            csrfToken: CSRF_TOKEN_FACE,
            onStatusChange: (s, msg) => { statusEl.textContent = msg; },
            onError: (msg) => { statusEl.textContent = '❌ ' + msg; statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4'; }
        });
    }

    if (!faceRegModelsLoaded) {
        statusEl.textContent = 'Loading face recognition models (first time may take a moment)...';
        statusEl.className = 'bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4';
        const ok = await faceRegSystem.loadModels();
        if (!ok) {
            statusEl.textContent = '❌ Failed to load models. Ensure model files are in assets/models/';
            statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
            return;
        }
        faceRegModelsLoaded = true;
    }

    statusEl.textContent = 'Starting camera...';
    const camOk = await faceRegSystem.startCamera(
        document.getElementById('faceEnrollVideo'),
        document.getElementById('faceEnrollCanvas')
    );

    if (camOk) {
        statusEl.textContent = '✅ Camera ready. Position the student\'s face FRONT and click Capture.';
        statusEl.className = 'bg-green-50 text-green-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        startEnrollLivePreview();
    } else {
        statusEl.textContent = '❌ Camera failed. Check browser permissions.';
        statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
    }
}

function startEnrollLivePreview() {
    if (enrollLiveTimer) clearInterval(enrollLiveTimer);
    enrollLiveTimer = setInterval(async () => {
        if (!faceRegSystem) return;
        await faceRegSystem.detectSingleFace();
    }, 300);
}

function closeFaceEnrollModal() {
    if (enrollLiveTimer) { clearInterval(enrollLiveTimer); enrollLiveTimer = null; }
    if (faceRegSystem) { faceRegSystem.stopCamera(); }
    const modal = document.getElementById('faceEnrollModal');
    modal.style.display = 'none';
    modal.classList.add('hidden');
}

function updateEnrollSteps(activeIndex) {
    for (let i = 0; i < 3; i++) {
        const el = document.getElementById('enrollStep' + (i + 1));
        if (i < activeIndex) {
            el.className = 'flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-green-500 text-white';
        } else if (i === activeIndex) {
            el.className = 'flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-[#0056b3] text-white';
        } else {
            el.className = 'flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-slate-200 text-slate-500';
        }
    }
}

function updateEnrollInstruction(angle) {
    const instructions = {
        'front': 'Position the student facing the camera <strong>directly (front)</strong>',
        'left': 'Ask the student to turn their head slightly to the <strong>left</strong>',
        'right': 'Ask the student to turn their head slightly to the <strong>right</strong>'
    };
    document.getElementById('enrollAngleInstruction').innerHTML = instructions[angle] || '';
    
    const captureBtn = document.getElementById('btnEnrollCapture');
    captureBtn.textContent = '📷 Capture ' + angle.charAt(0).toUpperCase() + angle.slice(1) + ' View';
}

async function captureEnrollFace() {
    if (!faceRegSystem || !faceEnrollStudentId) return;
    
    const statusEl = document.getElementById('faceEnrollStatus');
    const captureBtn = document.getElementById('btnEnrollCapture');
    captureBtn.disabled = true;
    
    const currentAngle = enrollAngles[enrollCurrentStep];
    statusEl.textContent = '📸 Capturing ' + currentAngle + ' view...';
    statusEl.className = 'bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4';
    
    const detection = await faceRegSystem.detectSingleFace();
    
    if (!detection) {
        statusEl.textContent = '❌ No face detected. Ensure the face is clearly visible.';
        statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        return;
    }
    
    if (detection.score < 0.5) {
        statusEl.textContent = '⚠️ Low confidence (' + (detection.score * 100).toFixed(1) + '%). Try better lighting.';
        statusEl.className = 'bg-yellow-50 text-yellow-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        return;
    }
    
    // Quality assessment
    const quality = faceRegSystem.assessFaceQuality(detection);
    if (!quality.acceptable) {
        const issues = [];
        if (quality.factors.size < 0.4) issues.push('face too small');
        if (quality.factors.centering < 0.4) issues.push('not centered');
        if (quality.factors.angle < 0.4) issues.push('too angled');
        statusEl.textContent = '⚠️ Quality too low (' + (quality.score * 100).toFixed(0) + '%). ' + (issues.join(', ') || 'try better conditions');
        statusEl.className = 'bg-yellow-50 text-yellow-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        return;
    }
    
    // Register this angle
    statusEl.textContent = '⬆️ Saving ' + currentAngle + ' face descriptor (quality: ' + (quality.score * 100).toFixed(0) + '%)...';
    
    const result = await faceRegSystem.registerFace(
        faceEnrollStudentId,
        detection.descriptor,
        currentAngle,
        detection.score,
        '../api/register_face.php'
    );
    
    if (!result.success) {
        statusEl.textContent = '❌ ' + (result.error || 'Failed to save face.');
        statusEl.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
        captureBtn.disabled = false;
        return;
    }
    
    // Success for this angle - add thumbnail
    enrollCapturedCount++;
    const thumbContainer = document.getElementById('enrollCapturedThumbs');
    const preview = document.getElementById('enrollCapturedPreview');
    preview.classList.remove('hidden');
    
    // Capture thumbnail from video
    const video = document.getElementById('faceEnrollVideo');
    const thumbCanvas = document.createElement('canvas');
    thumbCanvas.width = 60;
    thumbCanvas.height = 60;
    const ctx = thumbCanvas.getContext('2d');
    const size = Math.min(video.videoWidth, video.videoHeight);
    const sx = (video.videoWidth - size) / 2;
    const sy = (video.videoHeight - size) / 2;
    ctx.drawImage(video, sx, sy, size, size, 0, 0, 60, 60);
    
    thumbContainer.innerHTML += `
        <div class="text-center">
            <img src="${thumbCanvas.toDataURL('image/jpeg', 0.7)}" class="w-14 h-14 rounded-lg object-cover border-2 border-green-400">
            <p class="text-xs text-green-600 mt-1">✅ ${currentAngle}</p>
        </div>
    `;
    
    // Move to next step
    enrollCurrentStep++;
    
    if (enrollCurrentStep >= enrollAngles.length) {
        // All 3 angles captured - enrollment complete!
        if (enrollLiveTimer) { clearInterval(enrollLiveTimer); enrollLiveTimer = null; }
        
        statusEl.textContent = '🎉 Face enrollment complete! All 3 angles captured successfully.';
        statusEl.className = 'bg-green-50 text-green-700 text-sm p-3 rounded-lg mb-4 font-medium';
        
        updateEnrollSteps(3); // All green
        
        captureBtn.textContent = '✅ Enrollment Complete';
        captureBtn.disabled = true;
        captureBtn.className = 'flex-1 px-4 py-3 bg-green-500 text-white rounded-lg font-medium cursor-default';
        
        showToast('Face enrolled successfully for ' + document.getElementById('faceEnrollStudentName').textContent, 'success');
        
        // Remove the student card from the grid with animation
        const card = document.querySelector(`.enroll-student-card button[onclick*="openFaceEnrollModal(${faceEnrollStudentId}"]`);
        if (card) {
            const cardEl = card.closest('.enroll-student-card');
            if (cardEl) {
                cardEl.style.transition = 'all 0.5s ease';
                cardEl.style.opacity = '0';
                cardEl.style.transform = 'scale(0.95)';
                setTimeout(() => cardEl.remove(), 500);
            }
        }
        
        // Close modal after delay and reload to update counts
        setTimeout(() => {
            closeFaceEnrollModal();
            window.location.reload();
        }, 2500);
    } else {
        // Move to next angle
        const nextAngle = enrollAngles[enrollCurrentStep];
        updateEnrollSteps(enrollCurrentStep);
        updateEnrollInstruction(nextAngle);
        
        statusEl.textContent = '✅ ' + currentAngle + ' captured! Now position for ' + nextAngle + ' view.';
        statusEl.className = 'bg-green-50 text-green-700 text-sm p-3 rounded-lg mb-4';
        
        captureBtn.disabled = false;
    }
}
</script>
<?php endif; ?>

        </div>
    </div>

</body>
</html>
