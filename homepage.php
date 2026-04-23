<?php

require_once 'db.php';
require_once __DIR__ . '/includes/disciplinary_measure_helper.php';

function homepage_offense_severity_label(int $offenseNumber): string {
    if ($offenseNumber <= 1) {
        return 'Low';
    }
    if ($offenseNumber === 2) {
        return 'Medium';
    }
    return 'High';
}

function homepage_violation_type_label(?string $dbType): string {
    $type = strtolower(trim((string)$dbType));
    if ($type === 'grave') {
        return 'Major';
    }
    if ($type === 'major') {
        return 'Moderate';
    }
    if ($type === 'minor') {
        return 'Minor';
    }
    return $type !== '' ? ucfirst($type) : 'Unspecified';
}

function homepage_ordinal(int $n): string {
    if ($n % 100 >= 11 && $n % 100 <= 13) {
        return $n . 'th';
    }
    $last = $n % 10;
    if ($last === 1) {
        return $n . 'st';
    }
    if ($last === 2) {
        return $n . 'nd';
    }
    if ($last === 3) {
        return $n . 'rd';
    }
    return $n . 'th';
}

function homepage_pending_reparation_guidance(string $categoryType, int $offenseNumber, string $categoryName): string {
    $safeOffense = max(1, $offenseNumber);
    $notice = build_disciplinary_intervention_message(
        violation_mark_level_from_total_marks($safeOffense),
        $safeOffense,
        $categoryName,
        $categoryType
    );
    $code = is_array($notice) ? strtoupper(trim((string)($notice['code'] ?? ''))) : '';
    $type = strtolower(trim($categoryType));

    if ($code === 'DIS 1') {
        return 'Please submit one document only: the Violation Form, which includes your written apology and serves as the official written record of the incident. Kindly complete the form in full by indicating the date, your full name, student ID, details of the violation, acknowledgment of what occurred, and your commitment to avoid repeating the behavior. The completed Violation Form must be signed by the offender and parent/guardian, then submitted promptly to the Discipline Office/SSO.';
    }

    if ($code === 'DIS 2') {
        $hoursGuide = '';
        if ($type === 'minor') {
            $hoursGuide = 'For Minor Offenses, render a total of eight (8) hours of community service.';
        } elseif ($type === 'major') {
            $hoursGuide = 'For Moderate Offenses, render a total of twelve (12) hours of community service.';
        }

        return 'School community service is a disciplinary intervention where learners who committed offenses perform service-oriented activities that benefit the school community. It develops responsibility, accountability, and positive contribution. ' . $hoursGuide;
    }

    if ($code === 'DIS 3') {
        return 'Suspension is a school intervention where learners are removed from class for a designated period to preserve discipline and order. Out-of-School Suspension (OSS) temporarily excludes the learner from face-to-face and online classes for a duration set by the Discipline Officer. In-School Suspension (ISS) requires twenty (20) hours of community service for the week and bans participation in face-to-face and online classes during the intervention period.';
    }

    if ($code === 'DIS 4') {
        return 'Dismissal or Withdrawal from School means the violating student is dropped from school due to a major offense that threatens the school\'s reputation and the safety of the academic community. For Manila Campus, issued dismissals are final and executory once approved by the SSO and VP for ICT and Student Services.';
    }

    return 'Follow the instructions from the Student Services Office and complete the assigned disciplinary requirements on time.';
}

// Enhanced session security check
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id']) || empty($_SESSION['user']['email'])) {
    // Clear any existing session data
    session_unset();
    session_destroy();
    
    // Start new session for toast message
    session_start();
    $_SESSION['toast'] = 'Please log in to access the system';
    
    // Redirect to login
    header('Location: login.php');
    exit;
}

// Prevent session fixation
if (!isset($_SESSION['last_activity'])) {
    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
}

// Session timeout after 30 minutes of inactivity
if (time() - $_SESSION['last_activity'] > 1800) {
    session_unset();
    session_destroy();
    
    // Start new session for toast message
    session_start();
    $_SESSION['toast'] = 'Session expired. Please log in again';
    
    header('Location: login.php');
    exit;
}

// Update last activity time
$_SESSION['last_activity'] = time();

$user = $_SESSION['user'];

// Get complete user information from database
try {
    $pdo = pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND email = ? LIMIT 1');
    $stmt->execute([$user['id'], $user['email']]);
    $userData = $stmt->fetch();
    
    if (!$userData || $userData['status'] !== 'Active') {
        session_unset();
        session_destroy();
        
        // Start new session for toast message
        session_start();
        $_SESSION['toast'] = 'Your account is no longer active. Please contact support.';
        
        header('Location: login.php');
        exit;
    }
    
    // Update user data with complete information from database
    $user = $userData;

    // Fetch pending reparation tasks so they can be shown prominently
    $pendingReparations = [];
    $studentDisciplinaryNotice = null;
    $latestActiveViolationDetails = null;
    $studentOffenseOnFileNotice = null;
    $studentOffenseOnFileSummary = null;
    $violationCardRealtimeState = [
        'active_count' => (int)($user['active_violations_count'] ?? 0),
        'latest_active_violation' => null,
        'offense_on_file' => null,
    ];
    try {
        $svCheck = $pdo->query("SHOW TABLES LIKE 'student_violations'")->fetch();
        if ($svCheck) {
            $prStmt = $pdo->prepare("
                SELECT sv.id, sv.offense_number, sv.reparation_type, sv.reparation_notes,
                       sv.school_year, sv.semester,
                       vc.name AS category_name, vc.type AS category_type
                FROM student_violations sv
                JOIN violation_categories vc ON sv.category_id = vc.id
                WHERE sv.user_id = ? AND sv.status = 'pending_reparation'
                ORDER BY sv.created_at DESC
            ");
            $prStmt->execute([$user['id']]);
            $pendingReparations = $prStmt->fetchAll(PDO::FETCH_ASSOC);

            $latestActiveStmt = $pdo->prepare("
                SELECT sv.category_id, sv.offense_number, sv.status,
                       vc.name AS category_name, vc.type AS category_type
                FROM student_violations sv
                JOIN violation_categories vc ON sv.category_id = vc.id
                WHERE sv.user_id = ? AND sv.status IN ('active', 'pending_reparation')
                ORDER BY sv.created_at DESC, sv.id DESC
                LIMIT 1
            ");
            $latestActiveStmt->execute([$user['id']]);
            $latestActive = $latestActiveStmt->fetch(PDO::FETCH_ASSOC);

            if ($latestActive && isset($latestActive['category_id'])) {
                $offenseNumber = max(1, (int)($latestActive['offense_number'] ?? 1));
                if ($offenseNumber > 0) {
                    $markLevel = violation_mark_level_from_total_marks($offenseNumber);
                    $studentDisciplinaryNotice = build_disciplinary_intervention_message(
                        $markLevel,
                        $offenseNumber,
                        (string)$latestActive['category_name'],
                        (string)($latestActive['category_type'] ?? '')
                    );

                    if (is_array($studentDisciplinaryNotice)) {
                        $studentDisciplinaryNotice['mark_level'] = $markLevel;
                        $studentDisciplinaryNotice['offense_number'] = $offenseNumber;
                    }

                    $latestActiveViolationDetails = [
                        'category_name' => (string)$latestActive['category_name'],
                        'category_type' => homepage_violation_type_label((string)($latestActive['category_type'] ?? '')),
                        'offense_number' => $offenseNumber,
                        'mark_level' => $markLevel,
                        'severity_label' => homepage_offense_severity_label($offenseNumber),
                        'status' => (string)($latestActive['status'] ?? 'active'),
                        'disciplinary_notice' => $studentDisciplinaryNotice,
                    ];
                }
            }

            $offenseHistoryStmt = $pdo->prepare("
                SELECT sv.category_id,
                       vc.name AS category_name,
                       vc.type AS category_type,
                       sv.offense_number,
                       sv.status,
                       sv.created_at AS latest_marked_at
                FROM student_violations sv
                JOIN violation_categories vc ON sv.category_id = vc.id
                WHERE sv.user_id = ?
                ORDER BY sv.created_at DESC, sv.id DESC
                LIMIT 1
            ");
            $offenseHistoryStmt->execute([$user['id']]);
            $offenseHistory = $offenseHistoryStmt->fetch(PDO::FETCH_ASSOC);

            if ($offenseHistory) {
                $historyOffenseNumber = max(1, (int)($offenseHistory['offense_number'] ?? 1));
                if ($historyOffenseNumber >= 1) {
                    $studentOffenseOnFileNotice = build_disciplinary_intervention_message(
                        violation_mark_level_from_total_marks($historyOffenseNumber),
                        $historyOffenseNumber,
                        (string)$offenseHistory['category_name'],
                        (string)($offenseHistory['category_type'] ?? '')
                    );

                    $studentOffenseOnFileSummary = [
                        'category_name' => (string)$offenseHistory['category_name'],
                        'category_type' => homepage_violation_type_label((string)($offenseHistory['category_type'] ?? '')),
                        'offense_number' => $historyOffenseNumber,
                        'severity_label' => homepage_offense_severity_label($historyOffenseNumber),
                        'code' => is_array($studentOffenseOnFileNotice) ? (string)($studentOffenseOnFileNotice['code'] ?? '') : '',
                        'title' => is_array($studentOffenseOnFileNotice) ? (string)($studentOffenseOnFileNotice['title'] ?? '') : '',
                        'message' => is_array($studentOffenseOnFileNotice) ? (string)($studentOffenseOnFileNotice['message'] ?? '') : '',
                    ];
                }
            }

            $violationCardRealtimeState = [
                'active_count' => (int)($user['active_violations_count'] ?? 0),
                'latest_active_violation' => $latestActiveViolationDetails,
                'offense_on_file' => $studentOffenseOnFileSummary,
            ];
        }
    } catch (\Exception $e) {
        error_log('Pending reparations fetch error: ' . $e->getMessage());
    }

} catch (\Exception $e) {
    error_log('Database verification failed in homepage: ' . $e->getMessage());
    
    session_unset();
    session_destroy();
    
    // Start new session for toast message
    session_start();
    $_SESSION['toast'] = 'System error. Please try again later.';
    
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            try {
                var key = 'homepage_theme_preference';
                var storedTheme = localStorage.getItem(key);
                var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                var resolvedTheme = (storedTheme === 'dark' || storedTheme === 'light')
                    ? storedTheme
                    : (prefersDark ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', resolvedTheme);
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <title>GateWatch | Home</title>
    <link rel="icon" type="image/png" href="assets/images/gatewatch-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/tailwind.config.js"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>
    <script src="https://unpkg.com/@heroicons/v2/24/outline/esm/index.js"></script>
    <style>
        :root {
            --sky-50: #f0f9ff;
            --sky-100: #e0f2fe;
            --sky-600: #0284c7;
            --slate-900: #0f172a;
            --glass: rgba(255, 255, 255, 0.65);
            --page-bg-start: #e0f2ff;
            --page-bg-end: #f8fbff;
            --bg-spot-1: rgba(2, 132, 199, 0.07);
            --bg-spot-2: rgba(59, 130, 246, 0.08);
            --hero-overlay-top: rgba(15, 23, 42, 0.14);
            --hero-overlay-bottom: rgba(255, 255, 255, 0.7);
            --page-text: #0f172a;
            --surface-1: rgba(255, 255, 255, 0.92);
            --surface-border: rgba(255, 255, 255, 0.5);
            --surface-shadow: 0 20px 60px rgba(15, 23, 42, 0.14);
            --header-bg: rgba(255, 255, 255, 0.9);
            --header-border: #e2e8f0;
            --brand-color: #000000;
            --toggle-track-bg: linear-gradient(135deg, #dbeafe, #f8fafc);
            --toggle-track-border: rgba(2, 132, 199, 0.24);
            --toggle-thumb-bg: linear-gradient(135deg, #0284c7, #0ea5e9);
            --toggle-thumb-shadow: 0 6px 14px rgba(2, 132, 199, 0.42);
            --toggle-icon-muted: #64748b;
            --toggle-label: #0f172a;
        }

        html[data-theme="dark"] {
            color-scheme: dark;
            --glass: rgba(15, 23, 42, 0.68);
            --page-bg-start: #020617;
            --page-bg-end: #0f172a;
            --bg-spot-1: rgba(14, 165, 233, 0.17);
            --bg-spot-2: rgba(56, 189, 248, 0.14);
            --hero-overlay-top: rgba(2, 6, 23, 0.72);
            --hero-overlay-bottom: rgba(15, 23, 42, 0.48);
            --page-text: #e2e8f0;
            --surface-1: rgba(15, 23, 42, 0.82);
            --surface-border: rgba(148, 163, 184, 0.3);
            --surface-shadow: 0 22px 65px rgba(1, 5, 20, 0.55);
            --header-bg: rgba(8, 13, 28, 0.84);
            --header-border: rgba(148, 163, 184, 0.28);
            --brand-color: #e2e8f0;
            --toggle-track-bg: linear-gradient(135deg, #1e293b, #020617);
            --toggle-track-border: rgba(56, 189, 248, 0.36);
            --toggle-thumb-bg: linear-gradient(135deg, #f8fafc, #dbeafe);
            --toggle-thumb-shadow: 0 8px 18px rgba(15, 23, 42, 0.58);
            --toggle-icon-muted: #94a3b8;
            --toggle-label: #e2e8f0;
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
            color: var(--page-text);
            background: var(--page-bg-start);
            background: radial-gradient(circle at 20% 20%, var(--bg-spot-1), transparent 30%),
                        radial-gradient(circle at 80% 10%, var(--bg-spot-2), transparent 25%),
                        linear-gradient(135deg, var(--page-bg-start) 0%, var(--page-bg-end) 100%);
            background-attachment: fixed;
            overflow-x: hidden;
            transition: background 0.55s ease, color 0.45s ease;
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
            background-image: url('assets/images/pcu-building.jpg');
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
            background: linear-gradient(180deg, var(--hero-overlay-top), var(--hero-overlay-bottom));
            z-index: 0;
            transition: background 0.45s ease;
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
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--surface-border);
            box-shadow: var(--surface-shadow);
            transition: background 0.45s ease, border-color 0.45s ease, box-shadow 0.45s ease;
        }

        .frosted {
            background: var(--surface-1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: background 0.45s ease, border-color 0.45s ease;
        }

        .pill {
            border-radius: 9999px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .stat-card {
            position: relative;
            overflow: hidden;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.08), rgba(14, 165, 233, 0.05));
            opacity: 0;
            transition: opacity 0.25s ease;
            pointer-events: none;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 35px rgba(15, 23, 42, 0.08);
        }

        .stat-card:hover::after {
            opacity: 1;
        }

        .avatar-ring {
            position: relative;
        }

        .avatar-ring::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 9999px;
            background: conic-gradient(from 120deg, rgba(2, 132, 199, 0.25), rgba(14, 165, 233, 0.4), rgba(2, 132, 199, 0.25));
            animation: rotate 6s linear infinite;
            z-index: 0;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .hero-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 1.5rem;
        }

        .hero-status-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 1rem;
            align-items: stretch;
        }

        .hero-status-grid > .stat-card {
            display: flex;
            flex-direction: column;
            min-height: 100%;
        }

        @media (max-width: 1024px) {
            .hero-grid {
                grid-template-columns: repeat(1, minmax(0, 1fr));
            }
        }

        @media (min-width: 768px) {
            .hero-status-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .floating-blob {
                display: none;
            }

            .avatar-ring::before {
                content: none;
                animation: none;
            }
        }

        .section-title {
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -8px;
            width: 64px;
            height: 4px;
            background: linear-gradient(90deg, #0284c7, #38bdf8);
            border-radius: 999px;
        }

        .accordion {
            transition: max-height 0.35s ease, opacity 0.35s ease;
        }

        .chip {
            border: 1px solid rgba(2, 132, 199, 0.15);
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.1), rgba(255, 255, 255, 0.6));
        }

        .cta-shadow {
            box-shadow: 0 20px 60px rgba(14, 165, 233, 0.2);
        }

        .nav-blur {
            background: var(--header-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-color: var(--header-border) !important;
            transition: background 0.45s ease, border-color 0.45s ease;
        }

        @font-face {
            font-family: 'old-english-canterbury';
            src: url('assets/fonts/canterbury-webfont.woff2') format('woff2'),
                 url('assets/fonts/canterbury-webfont.woff') format('woff');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }

        .brand-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
        }

        .brand-logo {
            width: 2.5rem;
            height: 2.5rem;
            flex-shrink: 0;
        }

        .brand-wordmark {
            font-family: 'old-english-canterbury', serif;
            font-weight: 400;
            font-size: clamp(1.05rem, 2.2vw, 1.875rem);
            line-height: 1;
            letter-spacing: 0;
            color: var(--brand-color);
            text-rendering: optimizeLegibility;
            white-space: nowrap;
            transition: color 0.45s ease;
        }

        .theme-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            border-radius: 999px;
            padding: 0.25rem 0.65rem 0.25rem 0.25rem;
            border: 1px solid rgba(148, 163, 184, 0.4);
            background: rgba(255, 255, 255, 0.75);
            color: var(--toggle-label);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.1);
            transition: transform 0.25s ease, background 0.35s ease, border-color 0.35s ease, box-shadow 0.35s ease;
        }

        .theme-toggle:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.18);
        }

        .theme-toggle:focus-visible {
            outline: 2px solid rgba(14, 165, 233, 0.65);
            outline-offset: 2px;
        }

        .theme-toggle-track {
            position: relative;
            width: 3.7rem;
            height: 1.9rem;
            border-radius: 999px;
            background: var(--toggle-track-bg);
            border: 1px solid var(--toggle-track-border);
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 0.44rem;
            overflow: hidden;
            transition: background 0.45s ease, border-color 0.45s ease;
        }

        .theme-toggle-icon {
            width: 0.82rem;
            height: 0.82rem;
            color: var(--toggle-icon-muted);
            z-index: 2;
            transition: color 0.35s ease, opacity 0.35s ease, transform 0.35s ease;
        }

        .theme-toggle-thumb {
            position: absolute;
            top: 0.17rem;
            left: 0.18rem;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 999px;
            background: var(--toggle-thumb-bg);
            box-shadow: var(--toggle-thumb-shadow);
            transition: transform 0.35s cubic-bezier(0.2, 0.9, 0.26, 1.05), background 0.35s ease, box-shadow 0.35s ease;
        }

        .theme-toggle-label {
            font-size: 0.78rem;
            line-height: 1;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--toggle-label);
            transition: color 0.35s ease;
        }

        html[data-theme="dark"] .theme-toggle {
            background: rgba(2, 6, 23, 0.72);
            border-color: rgba(56, 189, 248, 0.35);
            box-shadow: 0 12px 28px rgba(2, 6, 23, 0.55);
        }

        html[data-theme="dark"] .theme-toggle-thumb {
            transform: translateX(1.78rem);
        }

        html[data-theme="dark"] .theme-toggle .theme-toggle-sun {
            opacity: 0.55;
            transform: rotate(-22deg);
        }

        html[data-theme="dark"] .theme-toggle .theme-toggle-moon {
            opacity: 1;
            transform: rotate(0deg) scale(1.05);
            color: #38bdf8;
        }

        html[data-theme="light"] .theme-toggle .theme-toggle-sun {
            opacity: 1;
            transform: rotate(0deg) scale(1.05);
            color: #f59e0b;
        }

        html[data-theme="light"] .theme-toggle .theme-toggle-moon {
            opacity: 0.55;
            transform: rotate(18deg);
        }

        .theme-shift-flash {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 65;
            opacity: 0;
            background: radial-gradient(circle at var(--theme-fx-x, 82%) var(--theme-fx-y, 9%), rgba(56, 189, 248, 0.34), transparent 52%);
        }

        .theme-shift-flash.is-active {
            animation: themeShiftPulse 520ms ease-out;
        }

        @keyframes themeShiftPulse {
            from {
                opacity: 0.62;
                transform: scale(0.96);
            }
            to {
                opacity: 0;
                transform: scale(1.08);
            }
        }

        html[data-theme="dark"] .text-slate-900,
        html[data-theme="dark"] .text-slate-800 {
            color: #e2e8f0 !important;
        }

        html[data-theme="dark"] .text-slate-700,
        html[data-theme="dark"] .text-slate-600 {
            color: #cbd5e1 !important;
        }

        html[data-theme="dark"] .text-slate-500,
        html[data-theme="dark"] .text-slate-400 {
            color: #94a3b8 !important;
        }

        html[data-theme="dark"] .text-indigo-600 {
            color: #93c5fd !important;
        }

        html[data-theme="dark"] .text-indigo-800 {
            color: #bfdbfe !important;
        }

        html[data-theme="dark"] .bg-white {
            background-color: rgba(30, 41, 59, 0.82) !important;
        }

        html[data-theme="dark"] .border-slate-200,
        html[data-theme="dark"] .border-slate-300 {
            border-color: rgba(148, 163, 184, 0.35) !important;
        }

        html[data-theme="dark"] .chip {
            border-color: rgba(56, 189, 248, 0.25);
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.18), rgba(2, 6, 23, 0.45));
        }

        html[data-theme="dark"] .violation-history-modal-panel {
            background: rgba(8, 13, 28, 0.86);
            border-color: rgba(148, 163, 184, 0.38);
        }

        html[data-theme="dark"] .violation-history-item {
            background: rgba(15, 23, 42, 0.76);
            border-color: rgba(148, 163, 184, 0.3);
        }

        html[data-theme="dark"] .pending-reparation-panel {
            background: linear-gradient(135deg, rgba(113, 63, 18, 0.74) 0%, rgba(120, 53, 15, 0.84) 100%) !important;
            border-color: rgba(251, 191, 36, 0.62) !important;
        }

        .contact-support-modal {
            background: rgba(2, 6, 23, 0.38);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }

        .contact-support-panel {
            position: relative;
            border-radius: 1.4rem;
            padding: 2rem;
            background: linear-gradient(155deg, rgba(255, 255, 255, 0.84) 0%, rgba(255, 255, 255, 0.72) 100%);
            border: 1px solid rgba(255, 255, 255, 0.72);
            box-shadow: 0 26px 60px rgba(15, 23, 42, 0.3);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            overflow: hidden;
        }

        .contact-support-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(130deg, rgba(255, 255, 255, 0.34), rgba(255, 255, 255, 0.08) 55%, transparent 72%);
            pointer-events: none;
        }

        .contact-support-close {
            position: absolute;
            top: 0.9rem;
            right: 0.9rem;
            color: #94a3b8;
            transition: color 0.2s ease, transform 0.2s ease;
        }

        .contact-support-close:hover {
            color: #475569;
            transform: rotate(90deg);
        }

        .contact-support-icon-wrap {
            width: 4rem;
            height: 4rem;
            margin: 0 auto 1.1rem;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(186, 230, 253, 0.95), rgba(224, 242, 254, 0.82));
            border: 1px solid rgba(125, 211, 252, 0.45);
            box-shadow: 0 10px 24px rgba(2, 132, 199, 0.24);
        }

        .contact-support-note {
            background: linear-gradient(135deg, rgba(241, 245, 249, 0.88), rgba(248, 250, 252, 0.78));
            border: 1px solid rgba(191, 219, 254, 0.6);
            border-radius: 0.95rem;
            padding: 1.35rem 1.1rem;
            margin-bottom: 1.35rem;
        }

        .contact-support-mail-line {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            color: #0284c7;
            font-weight: 700;
            margin-top: 0.65rem;
            text-align: center;
            word-break: break-word;
        }

        .contact-support-mail-line a {
            color: inherit;
            text-decoration: none;
        }

        .contact-support-mail-line a:hover {
            text-decoration: underline;
        }

        .contact-support-action {
            width: 100%;
            height: 2.85rem;
            border-radius: 0.7rem;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #ffffff;
            font-size: 1.06rem;
            font-weight: 700;
            border: 1px solid rgba(2, 132, 199, 0.28);
            box-shadow: 0 10px 24px rgba(2, 132, 199, 0.3);
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }

        .contact-support-action:hover {
            filter: brightness(1.05);
            transform: translateY(-1px);
            box-shadow: 0 14px 30px rgba(2, 132, 199, 0.34);
        }

        .contact-support-action:active {
            transform: translateY(0);
        }

        html[data-theme="dark"] .contact-support-panel {
            background: linear-gradient(155deg, rgba(8, 13, 28, 0.95) 0%, rgba(15, 23, 42, 0.9) 100%);
            border-color: rgba(148, 163, 184, 0.35);
            box-shadow: 0 30px 70px rgba(2, 6, 23, 0.64);
        }

        html[data-theme="dark"] .contact-support-icon-wrap {
            background: linear-gradient(135deg, rgba(14, 116, 144, 0.5), rgba(14, 165, 233, 0.28));
            border-color: rgba(56, 189, 248, 0.36);
        }

        html[data-theme="dark"] .contact-support-note {
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.86), rgba(30, 41, 59, 0.8));
            border-color: rgba(56, 189, 248, 0.28);
        }

        html[data-theme="dark"] .contact-support-close {
            color: #94a3b8;
        }

        html[data-theme="dark"] .contact-support-close:hover {
            color: #cbd5e1;
        }

        @media (max-width: 640px) {
            .contact-support-panel {
                padding: 1.4rem;
                border-radius: 1.1rem;
            }

            .contact-support-mail-line {
                font-size: 0.96rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                transition: none !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
            }
        }

        .sidebar-button {
            transition: all 0.2s ease;
        }

        .sidebar-button:hover,
        .sidebar-button:focus,
        .sidebar-button[aria-expanded="true"] {
            background-color: #0284c7;
            color: white;
        }

        .profile-menu {
            transform-origin: top right;
            transition: transform 0.18s ease-out, opacity 0.18s ease-out;
        }

        .profile-menu[hidden] {
            display: none;
            transform: scale(0.95);
            opacity: 0;
        }

        .profile-menu:not([hidden]) {
            display: block;
            transform: scale(1);
            opacity: 1;
        }

        .hover-button {
            transition: all 0.25s ease;
        }

        .hover-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }

        @media (max-width: 768px) {
            .brand-link {
                gap: 0.4rem;
            }

            .brand-logo {
                width: 2rem;
                height: 2rem;
            }

            .brand-wordmark {
                font-size: clamp(0.88rem, 3.5vw, 1.05rem);
                white-space: normal;
                line-height: 1.15;
                letter-spacing: 0;
                max-width: calc(100vw - 10rem);
            }

            .theme-toggle-label {
                display: none;
            }
        }
    </style>
</head>
<body class="text-slate-900">
    <div class="page-shell">
        <div class="hero-photo"></div>
        <div class="hero-gradient"></div>
        <span class="floating-blob blob-1"></span>
        <span class="floating-blob blob-2"></span>
        <div id="themeShiftFlash" class="theme-shift-flash" aria-hidden="true"></div>

        <nav class="nav-blur border-b border-slate-200 fixed w-full top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between min-h-[4rem] py-2 items-center">
                    <div class="flex items-center gap-3">
                        <a href="homepage.php" class="brand-link hover:opacity-90 transition-opacity">
                            <img src="assets/images/pcu-logo.png" alt="PCU Logo" class="brand-logo">
                            <span class="brand-wordmark">Philippine Christian University</span>
                        </a>

                    </div>

                    <div class="flex items-center gap-2 sm:gap-3">
                        <button id="themeToggleButton" type="button" class="theme-toggle" aria-label="Toggle dark mode" aria-pressed="false">
                            <span class="theme-toggle-track" aria-hidden="true">
                                <svg class="theme-toggle-icon theme-toggle-sun" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m0 13.5V21m8.25-9H18m-13.5 0H2.25m15.273 6.364l-1.591-1.591M8.318 8.318 6.727 6.727m10.546 0-1.591 1.591M8.318 15.682l-1.591 1.591M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                                </svg>
                                <svg class="theme-toggle-icon theme-toggle-moon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3c-.066.344-.1.7-.1 1.06a8.25 8.25 0 008.25 8.25c.36 0 .716-.034 1.06-.1z" />
                                </svg>
                                <span class="theme-toggle-thumb"></span>
                            </span>
                            <span id="themeToggleLabel" class="theme-toggle-label">Light</span>
                        </button>

                        <form action="auth.php" method="POST" class="ml-0">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="sidebar-button px-4 py-2 rounded-full text-sm font-semibold text-slate-700 border border-slate-300 hover:text-white focus:outline-none focus:ring-2 focus:ring-sky-500">
                                Sign Out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </nav>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-12 relative z-10 flex-1">
            <?php
                $firstLetter = strtoupper(substr($user['name'], 0, 1));
                $colors = ['bg-blue-500', 'bg-green-500', 'bg-yellow-500', 'bg-red-500', 'bg-purple-500', 'bg-pink-500'];
                $colorIndex = ord($firstLetter) % count($colors);
                $bgColor = $colors[$colorIndex];
                $hasProfilePicture = !empty($user['profile_picture']);
                $profilePictureUrl = $hasProfilePicture ? 'serve_picture.php?f=' . rawurlencode($user['profile_picture']) : '';
                // Verify the file actually exists on disk
                if ($hasProfilePicture && !file_exists(__DIR__ . '/assets/images/profiles/' . $user['profile_picture'])) {
                    $hasProfilePicture = false;
                    $profilePictureUrl = '';
                }
            ?>

            <section class="hero-grid items-start mt-6">
                <div class="order-1 lg:order-1 lg:col-span-5 md:col-span-12">
                    <div class="glass-card rounded-3xl p-6 sm:p-7 card-animate">
                        <div class="flex flex-col md:flex-row items-center gap-4 text-center md:text-left">
                            <div class="avatar-ring mx-auto md:mx-0">
                                <?php if ($hasProfilePicture): ?>
                                    <div id="profile-pic-container" class="relative z-10 w-20 h-20 sm:w-24 sm:h-24 rounded-full overflow-hidden border-4 border-white shadow-lg">
                                        <img src="<?= $profilePictureUrl ?>" alt="Profile Picture" class="w-full h-full object-cover"
                                             onerror="var c=document.getElementById('profile-pic-container');c.className='relative z-10 w-20 h-20 sm:w-24 sm:h-24 rounded-full <?= $bgColor ?> border-4 border-white shadow-lg flex items-center justify-center text-white text-4xl sm:text-5xl font-bold';c.innerHTML='<?= htmlspecialchars($firstLetter) ?>';">
                                    </div>
                                <?php else: ?>
                                    <div class="relative z-10 w-20 h-20 sm:w-24 sm:h-24 rounded-full <?= $bgColor ?> border-4 border-white shadow-lg flex items-center justify-center text-white text-4xl sm:text-5xl font-bold">
                                        <?= htmlspecialchars($firstLetter) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 w-full">
                                <p class="text-sm uppercase tracking-[0.18em] text-sky-600 font-semibold mb-1">Student Access</p>
                                <h1 class="text-3xl sm:text-4xl font-bold text-slate-900 leading-tight">Welcome back, <?= htmlspecialchars($user['name']) ?></h1>
                                <div class="flex flex-wrap items-center justify-center md:justify-start gap-2 mt-3">
                                    <span class="badge bg-sky-50 text-sky-700 border border-sky-100">Role: <?= htmlspecialchars($user['role']) ?></span>
                                    <span class="badge bg-sky-50 text-sky-700 border border-sky-100">Student ID: <?= htmlspecialchars($user['student_id'] ?? 'N/A') ?></span>
                                </div>
                            </div>
                        </div>


                        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <button onclick="window.location.href='digital_id.php'" class="w-full h-12 pill bg-sky-600 text-white font-semibold shadow-lg cta-shadow hover:bg-sky-700 transition-colors flex items-center justify-center relative">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute left-4 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" />
                                </svg>
                                <span class="w-full text-center">Open Digital ID</span>
                            </button>
                            <button onclick="toggleContactSupport()" class="w-full h-12 pill bg-white text-slate-800 font-semibold border border-slate-200 hover:border-sky-200 hover:shadow-md transition-colors flex items-center justify-center relative">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-sky-600 absolute left-4 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                </svg>
                                <span class="w-full text-center">Contact Support</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="order-3 lg:order-2 lg:col-span-7 md:col-span-12">
                    <div class="hero-status-grid">
                        <div class="glass-card rounded-3xl p-5 card-animate stat-card">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Account</p>
                                    <h3 class="text-xl font-semibold text-slate-900">Status</h3>
                                </div>
                                <span class="badge bg-green-50 text-green-700 border border-green-100">Active</span>
                            </div>
                            <p class="text-slate-600 leading-relaxed">Keep your account active to avoid access delays at the gate.</p>
                        </div>

                        <?php
                        $rfidIsLost = false;
                        $rfidLostReason = '';
                        if (!empty($user['rfid_uid'])) {
                            try {
                                $lostStmt = $pdo->prepare("SELECT is_lost, lost_reason FROM rfid_cards WHERE user_id = ? AND rfid_uid = ? ORDER BY id DESC LIMIT 1");
                                $lostStmt->execute([$user['id'], $user['rfid_uid']]);
                                $rfidStatus = $lostStmt->fetch();
                                $rfidIsLost = $rfidStatus && $rfidStatus['is_lost'] == 1;
                                $rfidLostReason = $rfidStatus['lost_reason'] ?? '';
                            } catch (\PDOException $e) {
                                $rfidIsLost = false;
                            }
                        }
                        $cardBgColor = !empty($user['rfid_uid']) ? ($rfidIsLost ? 'red' : 'green') : 'amber';
                        $cardStatus = !empty($user['rfid_uid']) ? ($rfidIsLost ? 'LOST - Use Digital ID' : 'Active') : 'Not Active';

                        // Check face recognition enrollment
                        $faceEnrolled = false;
                        try {
                            $faceStmt = $pdo->prepare('SELECT COUNT(*) FROM face_descriptors WHERE user_id = ? AND is_active = 1');
                            $faceStmt->execute([$user['id']]);
                            $faceEnrolled = (int)$faceStmt->fetchColumn() > 0;
                        } catch (\PDOException $e) {
                            $faceEnrolled = false;
                        }
                        ?>
                        <div class="glass-card rounded-3xl p-5 card-animate stat-card">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.12em] text-slate-500">RFID</p>
                                    <h3 class="text-xl font-semibold text-slate-900">Card Status</h3>
                                </div>
                                <span class="badge bg-<?= $cardBgColor ?>-50 text-<?= $cardBgColor ?>-700 border border-<?= $cardBgColor ?>-100">
                                    <?= htmlspecialchars($cardStatus) ?>
                                </span>
                            </div>
                            <p class="text-slate-600 leading-relaxed">
                                <?= $rfidIsLost ? 'Marked lost. Please use Digital ID until a replacement is issued.' : 'Present your RFID or Digital ID at the gate for smooth entry.' ?>
                            </p>
                        </div>

                        <div class="glass-card rounded-3xl p-5 card-animate stat-card">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Violations</p>
                                    <h3 class="text-xl font-semibold text-slate-900">Record</h3>
                                </div>
                                <?php 
                                    $activeViolations = (int)($user['active_violations_count'] ?? 0);
                                ?>
                                <?php if ($activeViolations > 0): ?>
                                <span id="violationStatusBadge" class="badge bg-red-50 text-red-700 border border-red-100">
                                    <?= $activeViolations ?> Active
                                </span>
                                <?php else: ?>
                                <span id="violationStatusBadge" class="badge bg-green-50 text-green-700 border border-green-100">
                                    Clear
                                </span>
                                <?php endif; ?>
                            </div>
                            <div id="violationCardContent">
                            <?php if ($activeViolations > 0): ?>
                            <?php
                                $showAwaitingAdminMessage = !is_array($latestActiveViolationDetails)
                                    || (string)($latestActiveViolationDetails['status'] ?? 'active') !== 'pending_reparation';
                            ?>
                            <?php if ($showAwaitingAdminMessage): ?>
                            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-2">
                                <p class="text-red-700 text-sm font-medium">You have <?= $activeViolations ?> active violation<?= $activeViolations > 1 ? 's' : '' ?>.</p>
                                <p class="text-red-600 text-xs mt-1">Wait for the admin’s confirmation to determine the appropriate action for your current violation. If you have any questions, please click “Contact Support” beside your digital ID to email the Student Services Office regarding your concern.</p>
                            </div>
                            <?php endif; ?>
                            <?php if (is_array($latestActiveViolationDetails)): ?>
                            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-2">
                                <p class="text-red-900 text-sm font-semibold">Current violation: <?= htmlspecialchars((string)($latestActiveViolationDetails['category_name'] ?? 'Violation')) ?></p>
                                <p class="text-red-700 text-xs mt-1">
                                    Type: <?= htmlspecialchars((string)($latestActiveViolationDetails['category_type'] ?? 'Unspecified')) ?>
                                    • Offense #<?= (int)($latestActiveViolationDetails['offense_number'] ?? 1) ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <p class="text-slate-600 leading-relaxed">Stay compliant to keep this clear. Need help? Contact support anytime.</p>
                            <?php endif; ?>
                            </div>
                            <button onclick="loadMyViolationHistory()" class="mt-auto pt-3 w-full text-center text-sm text-indigo-600 hover:text-indigo-800 font-medium transition-colors">
                                View My Violation History &rarr;
                            </button>
                        </div>

                        <div class="glass-card rounded-3xl p-5 card-animate stat-card">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Face</p>
                                    <h3 class="text-xl font-semibold text-slate-900">Recognition</h3>
                                </div>
                                <?php if ($faceEnrolled): ?>
                                <span class="badge bg-green-50 text-green-700 border border-green-100">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Verified
                                </span>
                                <?php else: ?>
                                <span class="badge bg-amber-50 text-amber-700 border border-amber-100">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                    </svg>
                                    Unverified
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-slate-600 leading-relaxed">
                                <?= $faceEnrolled
                                    ? 'Your face is enrolled and ready for Face Recognition entry at supported gates.'
                                    : 'No face enrolled yet. Contact an admin to register your face for gate entry.' ?>
                            </p>
                        </div>

                    </div>
                </div>

                <?php if (!empty($pendingReparations)): ?>
                <div class="order-2 lg:order-3 lg:col-span-12 md:col-span-12">
                    <div class="mt-4 rounded-2xl card-animate pending-reparation-panel" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #f59e0b;">
                        <div class="p-4 flex items-center gap-3 border-b border-amber-200">
                            <div class="w-9 h-9 rounded-xl bg-amber-500 flex items-center justify-center shrink-0">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                </svg>
                            </div>
                            <div class="flex-1">
                                <p class="font-bold text-amber-900 text-sm">Action Required — Pending Reparation Task<?= count($pendingReparations) > 1 ? 's' : '' ?></p>
                                <p class="text-amber-800 text-xs mt-0.5">You must complete the following task<?= count($pendingReparations) > 1 ? 's' : '' ?> assigned by the Student Services Office to resolve your violation<?= count($pendingReparations) > 1 ? 's' : '' ?>.</p>
                            </div>
                        </div>
                        <?php
                        $taskLabels = [
                            'written_apology'       => ['icon' => '✏️', 'label' => 'Written Apology Letter', 'desc' => 'Submit a formal written apology letter to the SSO Office.'],
                            'community_service'     => ['icon' => '🤝', 'label' => 'Community Service', 'desc' => 'Report to the SSO Office to receive your community service work assignment.'],
                            'counseling'            => ['icon' => '💬', 'label' => 'Counseling Session', 'desc' => 'Schedule and attend a counseling session with the school guidance counselor.'],
                            'parent_conference'     => ['icon' => '👨‍👩‍👦', 'label' => 'Parent/Guardian Conference', 'desc' => 'Your parent or guardian must visit the SSO Office in person.'],
                            'suspension_compliance' => ['icon' => '📋', 'label' => 'Suspension Compliance', 'desc' => 'Review your suspension notice and follow the specified conditions.'],
                            'restitution'           => ['icon' => '💰', 'label' => 'Restitution / Payment', 'desc' => 'Settle the required payment or replacement at the SSO Office.'],
                            'other'                 => ['icon' => '📌', 'label' => 'Assigned Task', 'desc' => 'Report to the SSO Office for specific instructions.'],
                        ];
                        foreach ($pendingReparations as $index => $pr):
                            $taskKey = $pr['reparation_type'] ?? 'other';
                            $task = $taskLabels[$taskKey] ?? $taskLabels['other'];
                            $guidanceText = homepage_pending_reparation_guidance(
                                (string)($pr['category_type'] ?? ''),
                                (int)($pr['offense_number'] ?? 1),
                                (string)($pr['category_name'] ?? '')
                            );
                        ?>
                        <div class="p-4 <?= $index > 0 ? 'border-t border-amber-200' : '' ?>">
                            <div class="flex gap-3">
                                <span class="text-xl shrink-0 mt-0.5"><?= $task['icon'] ?></span>
                                <div class="flex-1">
                                    <div class="flex flex-wrap items-center gap-2 mb-1">
                                        <span class="font-semibold text-amber-900 text-sm"><?= htmlspecialchars($task['label']) ?></span>
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-amber-200 text-amber-800 font-medium"><?= htmlspecialchars(ucwords($pr['category_type'])) ?> violation</span>
                                    </div>
                                    <p class="text-xs text-amber-800 font-medium"><?= htmlspecialchars($pr['category_name']) ?><?= $pr['offense_number'] ? ' — ' . homepage_ordinal((int)$pr['offense_number']) . ' Offense' : '' ?></p>
                                    <p class="text-xs text-amber-700 mt-1"><?= htmlspecialchars($guidanceText) ?></p>
                                    <?php if (!empty($pr['reparation_notes'])): ?>
                                    <p class="text-xs text-amber-900 mt-1.5 px-2 py-1 bg-amber-200 rounded font-medium"><strong>Admin note:</strong> <?= htmlspecialchars($pr['reparation_notes']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div class="px-4 pb-4">
                            <a href="javascript:void(0)" onclick="loadMyViolationHistory()" class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 underline underline-offset-2 hover:text-amber-900">
                                View full violation history &rarr;
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </section>

            <section class="mt-10">
                <div class="mb-6">
                    <h2 class="section-title text-2xl sm:text-3xl font-bold text-slate-900">Gate Entry Guide</h2>
                    <p class="text-slate-600 mt-3">Follow these steps every time you enter campus to avoid violations.</p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 card-animate">
                    <!-- Step 1 -->
                    <div class="glass-card rounded-3xl p-6 stat-card flex flex-col gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-sky-600 grid place-items-center text-white text-xl font-bold leading-none shrink-0 shadow-lg">1</div>
                            <div>
                                <p class="text-xs uppercase tracking-widest text-sky-600 font-semibold">Before You Enter</p>
                                <h3 class="text-lg font-bold text-slate-900">Prepare Your ID</h3>
                            </div>
                        </div>
                        <p class="text-slate-600 leading-relaxed">Have your <strong class="text-slate-800">RFID card</strong> ready in hand, or open your <strong class="text-slate-800">Digital ID</strong> on your phone before reaching the gate. This keeps the line moving for everyone.</p>
                    </div>

                    <!-- Step 2 -->
                    <div class="glass-card rounded-3xl p-6 stat-card flex flex-col gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-sky-600 grid place-items-center text-white text-xl font-bold leading-none shrink-0 shadow-lg">2</div>
                            <div>
                                <p class="text-xs uppercase tracking-widest text-sky-600 font-semibold">At The Gate</p>
                                <h3 class="text-lg font-bold text-slate-900">Scan, Show, or Look</h3>
                            </div>
                        </div>
                        <p class="text-slate-600 leading-relaxed"><strong class="text-slate-800">Tap your RFID card</strong>, let the guard scan your <strong class="text-slate-800">Digital ID QR code</strong>, or use <strong class="text-slate-800">Face Recognition</strong> at supported gates. All three methods are accepted at campus entry points.</p>
                        <div class="mt-auto flex flex-col gap-2">
                            <div class="frosted rounded-xl px-4 py-2.5 border border-sky-100 flex items-center gap-3">
                                <span class="h-2.5 w-2.5 rounded-full bg-green-500 shrink-0"></span>
                                <p class="text-sm text-slate-700">RFID card tap</p>
                            </div>
                            <div class="frosted rounded-xl px-4 py-2.5 border border-sky-100 flex items-center gap-3">
                                <span class="h-2.5 w-2.5 rounded-full bg-green-500 shrink-0"></span>
                                <p class="text-sm text-slate-700">Digital ID QR scan</p>
                            </div>
                            <div class="frosted rounded-xl px-4 py-2.5 border border-sky-100 flex items-center gap-3">
                                <span class="h-2.5 w-2.5 rounded-full bg-sky-500 shrink-0"></span>
                                <p class="text-sm text-slate-700">Face Recognition </span></p>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="glass-card rounded-3xl p-6 stat-card flex flex-col gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-sky-600 grid place-items-center text-white text-xl font-bold leading-none shrink-0 shadow-lg">3</div>
                            <div>
                                <p class="text-xs uppercase tracking-widest text-sky-600 font-semibold">After Entry</p>
                                <h3 class="text-lg font-bold text-slate-900">Entry Logged</h3>
                            </div>
                        </div>
                        <p class="text-slate-600 leading-relaxed">Your entry is <strong class="text-slate-800">recorded automatically</strong> in real time. Entering without a valid ID will result in a <strong class="text-rose-600">violation record</strong> on your account.</p>
                        <div class="mt-auto frosted rounded-2xl px-4 py-3 border border-rose-100 flex items-center gap-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                            <p class="text-sm text-slate-700">No ID = violation on record</p>
                        </div>
                    </div>
                </div>

                <!-- Lost RFID Banner (shown only if RFID is lost) -->
                <?php if ($rfidIsLost): ?>
                <div class="mt-4 glass-card rounded-3xl p-5 card-animate flex flex-col sm:flex-row items-start sm:items-center gap-4 border border-rose-200">
                    <div class="w-12 h-12 rounded-2xl bg-rose-100 flex items-center justify-center shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div class="flex-1">
                        <p class="font-bold text-rose-700">Your RFID card is marked as lost</p>
                        <p class="text-sm text-slate-600 mt-0.5">Use your <strong>Digital ID QR</strong> for gate entry until a replacement card is issued by the admin.<?= $rfidLostReason ? ' Reason: ' . htmlspecialchars($rfidLostReason) : '' ?></p>
                    </div>
                    <button onclick="window.location.href='digital_id.php'" class="hover-button pill px-5 py-2.5 bg-rose-600 text-white font-semibold text-sm whitespace-nowrap shadow-md">
                        Open Digital ID
                    </button>
                </div>
                <?php endif; ?>
            </section>
        </main>

        <footer class="relative z-10 mt-auto">
            <!-- Main Footer Body -->
            <div style="background: rgba(8, 13, 28, 0.93); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border-top: 1px solid rgba(255,255,255,0.07);">
                <div class="w-full px-5 sm:px-10 lg:px-20 py-8 md:py-10">

                    <!-- Mobile Layout -->
                    <div class="flex lg:hidden" style="flex-direction: column; align-items: center; text-align: center; gap: 1.5rem; max-width: 340px; margin: 0 auto;">

                        <!-- Logo -->
                        <a href="homepage.php" style="width: 80px; height: 80px; border-radius: 50%; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; box-shadow: 0 0 30px rgba(2,132,199,0.12); text-decoration: none;">
                            <img src="assets/images/pcu-logo.png" alt="Philippine Christian University Logo" style="width: 56px; height: 56px; object-fit: contain; filter: drop-shadow(0 0 6px rgba(255,255,255,0.25));">
                        </a>

                        <!-- Social Icons -->
                        <div style="display: flex; align-items: center; justify-content: center; gap: 1rem;">
                            <a href="https://www.facebook.com/pcuupdatesmanila" target="_blank" rel="noopener noreferrer" aria-label="PCU Manila Facebook Page" style="width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; color: #cbd5e1; text-decoration: none;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                            </a>
                            <a href="https://www.youtube.com/@philippinechristianunivers1648" target="_blank" rel="noopener noreferrer" aria-label="PCU Manila YouTube Channel" style="width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; color: #cbd5e1; text-decoration: none;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.41 19.1C5.12 19.56 12 19.56 12 19.56s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.95-1.95 29 29 0 0 0 .46-5.34 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02" fill="white"/></svg>
                            </a>
                        </div>

                        <!-- Address -->
                        <p style="font-size: 0.875rem; color: #94a3b8; line-height: 1.6; margin: 0;">1648 Taft Avenue corner Pedro Gil St.,<br>Malate, Manila</p>

                        <!-- Divider -->
                        <div style="width: 60px; height: 1px; background: rgba(255,255,255,0.08);"></div>

                        <!-- Contact Us -->
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.25rem;">
                            <p style="font-size: 0.875rem; font-weight: 600; color: #ffffff; margin: 0;">Contact Us:</p>
                            <p style="font-size: 0.875rem; color: #94a3b8; line-height: 1.5; margin: 0;">PBX Phone Lines (connecting all<br>departments in the Manila Campus)</p>
                            <a href="tel:+6328248537082485390" style="font-size: 0.875rem; color: #38bdf8; text-decoration: underline; text-underline-offset: 3px;">Trunk Lines: 8248 - 5370 to 8248 - 5390</a>
                        </div>

                        <!-- Divider -->
                        <div style="width: 60px; height: 1px; background: rgba(255,255,255,0.08);"></div>

                        <!-- Working Hours -->
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.25rem;">
                            <p style="font-size: 0.875rem; font-weight: 600; color: #ffffff; margin: 0;">Working Hours</p>
                            <p style="font-size: 0.875rem; color: #94a3b8; margin: 0;">Monday - Saturday</p>
                            <p style="font-size: 0.875rem; color: #38bdf8; margin: 0;">8 am - 5 pm</p>
                            <p style="font-size: 0.875rem; color: #94a3b8; margin: 0;">Sunday - <span style="color: #fb7185;">Closed</span></p>
                        </div>

                        <!-- Divider -->
                        <div style="width: 60px; height: 1px; background: rgba(255,255,255,0.08);"></div>

                        <!-- Map -->
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; width: 100%;">
                            <p style="font-size: 0.875rem; font-weight: 600; color: #ffffff; margin: 0;">Our Location</p>
                            <div style="width: 100%; height: 200px; border-radius: 0.75rem; overflow: hidden; border: 1px solid #334155;">
                                <iframe
                                    src="https://www.openstreetmap.org/export/embed.html?bbox=120.9861%2C14.5726%2C120.9921%2C14.5786&amp;layer=mapnik&amp;marker=14.575619%2C120.989119"
                                    width="100%" height="100%"
                                    style="border: 0; display: block;"
                                    loading="lazy"
                                    title="Philippine Christian University — 1648 Taft Avenue, Malate, Manila"
                                    sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox">
                                </iframe>
                            </div>
                            <a href="https://www.openstreetmap.org/way/268207595#map=19/14.575619/120.989119"
                               target="_blank" rel="noopener noreferrer"
                               style="font-size: 0.75rem; color: #38bdf8; text-decoration: none;">
                                View larger map ↗
                            </a>
                        </div>

                        <!-- Privacy Policy -->
                        <a href="#" style="font-size: 0.875rem; font-weight: 600; color: #e2e8f0; text-decoration: none;">Privacy Policy</a>

                    </div>

                    <!-- Desktop Layout: 3-column grid (hidden on mobile/tablet) -->
                    <div class="hidden lg:grid lg:grid-cols-3 gap-10">

                        <!-- Left: Contact Info -->
                        <div class="flex flex-col gap-4">
                            <a href="#" class="text-sm font-semibold text-slate-200 hover:text-sky-300 transition-colors w-fit">Privacy Policy</a>
                            <div class="flex flex-col gap-2">
                                <p class="text-sm font-semibold text-white">Contact Us:</p>
                                <p class="text-sm text-slate-400 leading-relaxed">PBX Phone Lines (connecting all departments in the Manila Campus)</p>
                                <a href="tel:+6328248537082485390" class="text-sm text-sky-400 underline underline-offset-2 hover:text-sky-300 transition-colors w-fit">
                                    Trunk Lines: 8248 - 5370 to 8248 - 5390
                                </a>
                            </div>
                        </div>

                        <!-- Center: Logo, Socials, Address, Hours -->
                        <div class="flex flex-col items-center gap-4 text-center">
                            <a href="homepage.php"><img src="assets/images/pcu-logo.png" alt="Philippine Christian University Logo" class="w-16 h-16 object-contain" style="filter: drop-shadow(0 0 6px rgba(255,255,255,0.25));"></a>
                            <div class="flex items-center gap-5">
                                <a href="https://www.facebook.com/pcuupdatesmanila" target="_blank" rel="noopener noreferrer" aria-label="PCU Manila Facebook Page" class="text-slate-300 hover:text-blue-400 transition-colors duration-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>
                                    </svg>
                                </a>
                                <a href="https://www.youtube.com/@philippinechristianunivers1648" target="_blank" rel="noopener noreferrer" aria-label="PCU Manila YouTube Channel" class="text-slate-300 hover:text-red-500 transition-colors duration-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.41 19.1C5.12 19.56 12 19.56 12 19.56s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.95-1.95 29 29 0 0 0 .46-5.34 29 29 0 0 0-.46-5.33z"/>
                                        <polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02" fill="white"/>
                                    </svg>
                                </a>
                            </div>
                            <p class="text-sm text-slate-400 leading-relaxed">1648 Taft Avenue corner Pedro Gil St.,<br>Malate, Manila</p>
                            <div>
                                <p class="text-sm font-semibold text-white mb-1">Working Hours</p>
                                <p class="text-sm text-slate-400">Monday - Saturday<br><span class="text-sky-400">8 am - 5 pm</span></p>
                                <p class="text-sm text-slate-400 mt-1">Sunday - <span class="text-rose-400">Closed</span></p>
                            </div>
                        </div>

                        <!-- Right: OpenStreetMap -->
                        <div class="flex flex-col gap-3">
                            <p class="text-sm font-semibold text-white">Our Location</p>
                            <div class="rounded-xl overflow-hidden border border-slate-700 shadow-lg w-full" style="height: 200px;">
                                <iframe
                                    src="https://www.openstreetmap.org/export/embed.html?bbox=120.9861%2C14.5726%2C120.9921%2C14.5786&amp;layer=mapnik&amp;marker=14.575619%2C120.989119"
                                    width="100%" height="100%"
                                    style="border: 0; display: block;"
                                    loading="lazy"
                                    title="Philippine Christian University — 1648 Taft Avenue, Malate, Manila"
                                    sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox">
                                </iframe>
                            </div>
                            <a href="https://www.openstreetmap.org/way/268207595#map=19/14.575619/120.989119"
                               target="_blank" rel="noopener noreferrer"
                               class="text-xs text-sky-400 hover:text-sky-300 transition-colors text-center">
                                View larger map ↗
                            </a>
                        </div>

                    </div>

                </div>
            </div>

            <!-- Bottom copyright bar -->
            <div style="background: rgba(4, 8, 18, 0.96); border-top: 1px solid rgba(255,255,255,0.05);">
                <div class="w-full px-5 sm:px-10 lg:px-20 py-3 flex flex-col lg:flex-row items-center justify-center lg:justify-between gap-1.5 lg:gap-2 text-center">
                    <span class="text-xs font-medium text-slate-500 whitespace-nowrap">Made by: GROUP-INGS</span>
                    <span class="text-xs text-slate-400" style="text-align: center; width: 100%; display: block;">Copyright&copy; 2026. All Rights Reserved Philippine Christian University</span>
                    <span class="text-xs font-medium text-slate-500 whitespace-nowrap">Ver 1.25</span>
                </div>
            </div>
        </footer>
    </div>

    <!-- Contact Support Modal (Hidden by default) -->
    <div id="contactSupportModal" class="contact-support-modal fixed inset-0 items-center justify-center z-50 hidden" style="display: none;">
        <div class="contact-support-panel max-w-md w-full mx-4">
            <!-- Close button -->
            <button onclick="toggleContactSupport()" class="contact-support-close" aria-label="Close contact support modal">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            
            <!-- Icon -->
            <div class="flex justify-center mb-6">
                <div class="contact-support-icon-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                    </svg>
                </div>
            </div>
            
            <!-- Title -->
            <h4 class="text-2xl font-bold text-slate-800 text-center mb-4">Contact Support</h4>
            
            <!-- Message -->
            <div class="contact-support-note">
                <p class="text-slate-700 text-center mb-4 leading-relaxed">
                    For any issues or concerns regarding your RFID account, please contact our support team:
                </p>
                <div class="contact-support-mail-line">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <a href="mailto:studentservices.manila@pcu.edu.ph">studentservices.manila@pcu.edu.ph</a>
                </div>
            </div>
            
            <!-- Action button -->
            <button 
                onclick="window.open('https://mail.google.com/mail/?view=cm&fs=1&to=studentservices.manila@pcu.edu.ph', '_blank')" 
                class="contact-support-action flex items-center justify-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                Send Email
            </button>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="fixed bottom-4 right-4 space-y-2 z-50"></div>

    <script>
        // CSRF Token for JavaScript fetch requests
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        const THEME_STORAGE_KEY = 'homepage_theme_preference';

        function applyHomepageTheme(theme, shouldAnimate, eventForFlash) {
            const resolvedTheme = theme === 'dark' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', resolvedTheme);

            const toggleButton = document.getElementById('themeToggleButton');
            const toggleLabel = document.getElementById('themeToggleLabel');
            if (toggleButton) {
                toggleButton.setAttribute('aria-pressed', String(resolvedTheme === 'dark'));
                toggleButton.setAttribute('aria-label', resolvedTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            }
            if (toggleLabel) {
                toggleLabel.textContent = resolvedTheme === 'dark' ? 'Dark' : 'Light';
            }

            if (shouldAnimate) {
                const flash = document.getElementById('themeShiftFlash');
                if (flash) {
                    if (eventForFlash && typeof eventForFlash.clientX === 'number' && typeof eventForFlash.clientY === 'number') {
                        flash.style.setProperty('--theme-fx-x', eventForFlash.clientX + 'px');
                        flash.style.setProperty('--theme-fx-y', eventForFlash.clientY + 'px');
                    } else {
                        flash.style.setProperty('--theme-fx-x', '82%');
                        flash.style.setProperty('--theme-fx-y', '9%');
                    }
                    flash.classList.remove('is-active');
                    void flash.offsetWidth;
                    flash.classList.add('is-active');
                }
            }
        }

        function initHomepageThemeToggle() {
            let savedTheme = null;
            try {
                savedTheme = localStorage.getItem(THEME_STORAGE_KEY);
            } catch (e) {
                savedTheme = null;
            }

            const systemPrefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            const initialTheme = (savedTheme === 'dark' || savedTheme === 'light')
                ? savedTheme
                : (document.documentElement.getAttribute('data-theme') || (systemPrefersDark ? 'dark' : 'light'));

            applyHomepageTheme(initialTheme, false);

            const toggleButton = document.getElementById('themeToggleButton');
            if (toggleButton) {
                toggleButton.addEventListener('click', function (event) {
                    const currentTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                    const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
                    applyHomepageTheme(nextTheme, true, event);
                    try {
                        localStorage.setItem(THEME_STORAGE_KEY, nextTheme);
                    } catch (e) {
                        // Non-blocking if browser storage is unavailable.
                    }
                });
            }
        }

        initHomepageThemeToggle();
        
        // User menu toggle
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenu = document.getElementById('user-menu');
        
        if (userMenuButton && userMenu) {
            userMenuButton.addEventListener('click', (event) => {
                event.stopPropagation(); // Prevent event from bubbling
                const isExpanded = userMenuButton.getAttribute('aria-expanded') === 'true';
                userMenuButton.setAttribute('aria-expanded', !isExpanded);
                userMenu.toggleAttribute('hidden');
            });

            // Close menu when clicking outside
            document.addEventListener('click', (event) => {
                if (!userMenuButton.contains(event.target) && !userMenu.contains(event.target)) {
                    userMenuButton.setAttribute('aria-expanded', 'false');
                    userMenu.hidden = true;
                }
            });
        }

        // Function to close user menu
        function closeUserMenu() {
            if (userMenuButton && userMenu) {
                userMenuButton.setAttribute('aria-expanded', 'false');
                userMenu.hidden = true;
            }
        }

        // Toggle user information
        function toggleInformation() {
            const infoSection = document.getElementById('userInformation');
            if (infoSection.classList.contains('hidden')) {
                infoSection.classList.remove('hidden');
                infoSection.style.maxHeight = infoSection.scrollHeight + 'px';
                infoSection.style.opacity = '1';
            } else {
                infoSection.style.maxHeight = '0px';
                infoSection.style.opacity = '0';
                setTimeout(() => infoSection.classList.add('hidden'), 300);
            }
        }

        // GSAP animations for page entry
        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.card-animate');
            gsap.set(cards, { y: 20, opacity: 0 });
            gsap.to(cards, {
                y: 0,
                opacity: 1,
                duration: 0.7,
                stagger: 0.08,
                ease: 'power2.out'
            });

            const blobs = document.querySelectorAll('.floating-blob');
            gsap.to(blobs, {
                scale: 1.05,
                duration: 6,
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut'
            });
        });

        // Toggle edit profile form
        function toggleEditProfile() {
            const editForm = document.getElementById('editProfileForm');
            const modalContent = editForm.querySelector('div');
            
            if (editForm.classList.contains('hidden')) {
                editForm.classList.remove('hidden');
                editForm.style.display = 'flex';
                setTimeout(() => {
                    modalContent.classList.add('modal-show');
                }, 10);
            } else {
                modalContent.classList.remove('modal-show');
                setTimeout(() => {
                    editForm.classList.add('hidden');
                    editForm.style.display = 'none';
                }, 300);
            }
        }

        // Toggle contact support modal
        function toggleContactSupport() {
            const modal = document.getElementById('contactSupportModal');
            const modalContent = modal.querySelector('div');
            
            if (modal.classList.contains('hidden')) {
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
                setTimeout(() => {
                    modalContent.classList.add('modal-show');
                }, 10);
            } else {
                modalContent.classList.remove('modal-show');
                setTimeout(() => {
                    modal.classList.add('hidden');
                    modal.style.display = 'none';
                }, 300);
            }
        }
    </script>

    <!-- Violation History Modal -->
    <div id="myViolationHistoryModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50" style="display:none;">
        <div class="glass-card violation-history-modal-panel rounded-3xl p-6 sm:p-7 max-w-2xl w-full mx-4 max-h-[84vh] overflow-y-auto shadow-2xl" style="animation: slideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl sm:text-2xl font-semibold text-slate-900">My Violation History</h3>
                <button onclick="closeMyViolationHistory()" class="rounded-full p-1.5 text-slate-500 hover:text-slate-700 hover:bg-white/70 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div id="myViolationHistoryContent">
                <div class="text-center py-8 text-slate-400">Loading...</div>
            </div>
        </div>
    </div>
    <style>
        @keyframes slideUp { from { transform: translateY(20px) scale(0.95); opacity: 0; } to { transform: translateY(0) scale(1); opacity: 1; } }

        #myViolationHistoryModal {
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
        }

        .violation-history-modal-panel {
            background: rgba(255, 255, 255, 0.78);
            border: 1px solid rgba(255, 255, 255, 0.65);
        }

        .violation-history-list {
            display: grid;
            gap: 0.75rem;
        }

        .violation-history-item {
            border-radius: 1rem;
            padding: 0.95rem 1rem;
            background: rgba(255, 255, 255, 0.62);
            border: 1px solid rgba(255, 255, 255, 0.75);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }

        .violation-history-item.is-active {
            border-color: rgba(254, 202, 202, 0.95);
            background: rgba(254, 242, 242, 0.86);
        }

        .violation-history-item.is-pending {
            border-color: rgba(253, 230, 138, 0.95);
            background: rgba(255, 251, 235, 0.9);
        }

        .violation-history-item.is-resolved {
            border-color: rgba(153, 246, 228, 0.95);
            background: rgba(240, 253, 250, 0.9);
        }

        @media (max-width: 640px) {
            .violation-history-modal-panel {
                margin: 0.75rem;
                max-height: 88vh;
                padding: 1rem;
                border-radius: 1.25rem;
            }

            .violation-history-item {
                padding: 0.85rem 0.9rem;
            }
        }
    </style>

    <script>
        const initialViolationCardState = <?= json_encode($violationCardRealtimeState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        function violationCardEsc(value) {
            return String(value ?? '').replace(/[&<>"']/g, function(ch) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch] || ch;
            });
        }

        function offenseOrdinal(n) {
            const num = Number(n || 0);
            if (num % 100 >= 11 && num % 100 <= 13) return num + 'th';
            const tail = num % 10;
            if (tail === 1) return num + 'st';
            if (tail === 2) return num + 'nd';
            if (tail === 3) return num + 'rd';
            return num + 'th';
        }

        function violationTypeLabel(type) {
            const value = String(type || '').toLowerCase().trim();
            if (value === 'minor') return 'Minor';
            if (value === 'major') return 'Moderate';
            if (value === 'grave') return 'Major';
            if (!value) return 'Unspecified';
            return value.charAt(0).toUpperCase() + value.slice(1);
        }

        function renderViolationCardState(state) {
            const badgeEl = document.getElementById('violationStatusBadge');
            const contentEl = document.getElementById('violationCardContent');
            if (!badgeEl || !contentEl || !state) return;

            const activeCount = Number(state.active_count || 0);
            const latest = state.latest_active_violation || null;

            if (activeCount > 0) {
                badgeEl.className = 'badge bg-red-50 text-red-700 border border-red-100';
                badgeEl.textContent = activeCount + ' Active';

                let html = '';
                const latestStatus = String((latest && latest.status) ? latest.status : '').toLowerCase();
                const showAwaitingAdminMessage = latestStatus !== 'pending_reparation';

                if (showAwaitingAdminMessage) {
                    html += '<div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-2">';
                    html += '<p class="text-red-700 text-sm font-medium">You have ' + activeCount + ' active violation' + (activeCount > 1 ? 's' : '') + '.</p>';
                    html += '<p class="text-red-600 text-xs mt-1">Wait for admin confirmation and complete any assigned reparation to resolve your violation.</p>';
                    html += '</div>';
                }

                if (latest) {
                    html += '<div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-2">';
                    html += '<p class="text-red-900 text-sm font-semibold">Current violation: ' + violationCardEsc(latest.category_name || 'Violation') + '</p>';
                    html += '<p class="text-red-700 text-xs mt-1">Type: ' + violationCardEsc(latest.category_type || 'Unspecified') + ' • Offense #' + violationCardEsc(latest.offense_number || 1) + '</p>';
                    html += '</div>';
                }

                contentEl.innerHTML = html;
                return;
            }

            badgeEl.className = 'badge bg-green-50 text-green-700 border border-green-100';
            badgeEl.textContent = 'Clear';

            let clearHtml = '<p class="text-slate-600 leading-relaxed">Stay compliant to keep this clear. Need help? Contact support anytime.</p>';

            contentEl.innerHTML = clearHtml;
        }

        async function refreshViolationCardRealtime() {
            try {
                const res = await fetch('get_student_violations.php?live=1&ts=' + Date.now(), {
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                const data = await res.json();
                if (data && data.success && data.live_status) {
                    renderViolationCardState(data.live_status);
                }
            } catch (e) {
                // Keep previous card state if refresh fails.
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            renderViolationCardState(initialViolationCardState);
            refreshViolationCardRealtime();
            setInterval(refreshViolationCardRealtime, 10000);
        });

        function loadMyViolationHistory() {
            const modal = document.getElementById('myViolationHistoryModal');
            const content = document.getElementById('myViolationHistoryContent');
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            content.innerHTML = '<div class="text-center py-8 text-slate-400">Loading violation history...</div>';

            fetch('get_student_violations.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.violations.length > 0) {
                        const esc = violationCardEsc;
                        const statusLabels = {
                            active: { cls: 'border-red-200 bg-red-100/80 text-red-700', label: 'Active' },
                            pending_reparation: { cls: 'border-amber-200 bg-amber-100/80 text-amber-800', label: 'Pending' },
                            apprehended: { cls: 'border-teal-200 bg-teal-100/80 text-teal-800', label: 'Resolved' }
                        };

                        // Summary
                        let summaryHtml = '<div class="flex gap-2 mb-4 flex-wrap">';
                        summaryHtml += `<span class="text-xs px-3 py-1 rounded-full border border-white/80 bg-white/80 text-slate-700">${data.violations.length} Total</span>`;
                        if (data.summary) {
                            if (data.summary.active > 0) summaryHtml += `<span class="text-xs px-3 py-1 rounded-full border border-red-200 bg-red-100/85 text-red-700">${data.summary.active} Active</span>`;
                            if (data.summary.apprehended > 0) summaryHtml += `<span class="text-xs px-3 py-1 rounded-full border border-teal-200 bg-teal-100/85 text-teal-700">${data.summary.apprehended} Resolved</span>`;
                        }
                        summaryHtml += '</div>';

                        let html = summaryHtml + '<div class="violation-history-list">';
                        data.violations.forEach(v => {
                            const sc = statusLabels[v.status] || statusLabels.active;
                            const stateClass = v.status === 'pending_reparation'
                                ? 'is-pending'
                                : (v.status === 'apprehended' ? 'is-resolved' : 'is-active');
                            const date = new Date(v.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
                            const categoryName = String(v.category_name || 'Violation').trim();
                            const needsPolicySuffix = !/\bpolicy\b[\s.!?,;:]*$/i.test(categoryName);
                            const offenseTitle = offenseOrdinal(v.offense_number || 1) + ' Offense on ' + categoryName + (needsPolicySuffix ? ' policy' : '');

                            html += `
                                <div class="violation-history-item ${stateClass}">
                                    <div class="flex items-center justify-between gap-2 flex-wrap">
                                        <p class="font-semibold text-slate-900 text-sm sm:text-base">${esc(offenseTitle)}</p>
                                        <span class="text-xs font-semibold px-2.5 py-1 rounded-full border ${sc.cls}">${sc.label}</span>
                                    </div>
                                    <div class="flex items-center gap-2 mt-2 text-xs text-slate-500 flex-wrap">
                                        <span>On ${date}</span>
                                        ${v.school_year ? `<span>• ${esc(v.school_year)} - ${esc(v.semester || '')}</span>` : ''}
                                    </div>
                                    <div class="flex items-center gap-2 mt-2 flex-wrap">
                                        <span class="text-[11px] uppercase tracking-[0.08em] px-2 py-1 rounded-full border border-white/80 bg-white/75 text-slate-600">${esc(violationTypeLabel(v.category_type))}</span>
                                        <span class="text-[11px] px-2 py-1 rounded-full border border-white/80 bg-white/75 text-slate-600">Offense #${esc(v.offense_number || '-')}</span>
                                    </div>
                                </div>
                            `;
                        });
                        html += '</div>';
                        content.innerHTML = html;
                    } else if (data.success) {
                        content.innerHTML = '<div class="text-center py-8"><svg class="w-12 h-12 mx-auto text-green-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><p class="text-slate-500">No violations on record</p></div>';
                    } else {
                        content.innerHTML = '<div class="text-center py-8 text-red-400">Failed to load history</div>';
                    }
                })
                .catch(() => {
                    content.innerHTML = '<div class="text-center py-8 text-red-400">Error loading history</div>';
                });
        }

        function closeMyViolationHistory() {
            const modal = document.getElementById('myViolationHistoryModal');
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }
    </script>

    <script src="assets/js/app.js"></script>
</body>
</html>

