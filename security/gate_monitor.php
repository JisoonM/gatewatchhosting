<?php

require_once __DIR__ . '/../db.php';

// ============================================
// SECURITY CONFIGURATION
// ============================================

// Centralized auth check (includes no-cache headers + session timeout + regeneration)
require_security_auth();

// Security Headers - Protect against common web vulnerabilities
header('X-Frame-Options: DENY');  // Prevent clickjacking
header('X-Content-Type-Options: nosniff');  // Prevent MIME-sniffing
header('X-XSS-Protection: 1; mode=block');  // Enable XSS filter (legacy browsers)
header('Referrer-Policy: strict-origin-when-cross-origin');  // Control referrer information

// Content Security Policy - Allow only trusted sources
$csp = "default-src 'self'; ";
$csp .= "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; ";
$csp .= "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; ";
$csp .= "img-src 'self' data: https:; ";
$csp .= "font-src 'self' data:; ";
$csp .= "connect-src 'self'; ";
$csp .= "worker-src 'self'; ";
$csp .= "frame-ancestors 'none';";
header("Content-Security-Policy: " . $csp);

$guard_username = $_SESSION['security_username'] ?? 'Security Guard';

// Check if face recognition is enabled
$faceRecEnabled = filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
$faceMatchThreshold = (float)env('FACE_MATCH_THRESHOLD', '0.45');
$qrScanEnabled = filter_var(env('QR_SCAN_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
$qrChallengeEnabled = filter_var(env('QR_CHALLENGE_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
$qrFaceBindingEnabled = filter_var(env('QR_FACE_BINDING_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
$qrFaceBindingStrict = filter_var(env('QR_FACE_BINDING_STRICT', 'true'), FILTER_VALIDATE_BOOLEAN);

// Permissions-Policy - Conditionally allow camera for face recognition
if ($faceRecEnabled || $qrScanEnabled) {
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');  // Allow camera for face recognition
} else {
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');  // Disable all unnecessary features
}

// Ensure CSRF token exists for API calls
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get today's statistics
try {
    $pdo = pdo();
    
    // Total scans today
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM violations 
        WHERE DATE(scanned_at) = CURDATE()
    ");
    $todayScans = $stmt->fetchColumn();
    
    // Unique students today
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT user_id) 
        FROM violations 
        WHERE DATE(scanned_at) = CURDATE()
    ");
    $uniqueStudents = $stmt->fetchColumn();
    
    // High violation students (3+)
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE role = 'Student' AND violation_count >= 3
    ");
    $highViolationCount = $stmt->fetchColumn();
    
    // Face entries today
    $faceEntriesToday = 0;
    if ($faceRecEnabled) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM face_entry_logs WHERE DATE(created_at) = CURDATE()");
            $faceEntriesToday = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            $faceEntriesToday = 0;
        }
    }
    
} catch (\PDOException $e) {
    error_log('Stats error: ' . $e->getMessage());
    $todayScans = 0;
    $uniqueStudents = 0;
    $highViolationCount = 0;
    $faceEntriesToday = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GateWatch Security | Gate Monitor</title>
    <link rel="icon" type="image/png" href="../assets/images/gatewatch-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/tailwind.config.js"></script>
    <script src="../assets/js/digital-id-card.js?v=11"></script>
    <?php if ($qrScanEnabled): ?>
    <script defer src="../assets/js/vendor/zxing.min.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/vendor/zxing.min.js'); ?>"></script>
    <?php endif; ?>
    <link rel="preload" as="image" href="../assets/images/id-card-template.png">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <!-- Face Recognition: face-api.js (pre-trained TensorFlow.js models) -->
    <?php if ($faceRecEnabled): ?>
    <script defer src="../assets/js/vendor/face-api.min.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/vendor/face-api.min.js'); ?>"></script>
    <script defer src="../assets/js/face-tracker.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/face-tracker.js'); ?>"></script>
    <script defer src="../assets/js/face-recognition.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/face-recognition.js'); ?>"></script>
    <?php endif; ?>
    <style type="text/tailwindcss">
        .fade-in {
            animation: none;
        }
        .btn-hover {
            transition: all 0.3s ease;
        }
        .btn-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 86, 179, 0.1), 0 2px 4px -1px rgba(0, 86, 179, 0.06);
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.95); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.7; }
            100% { transform: scale(0.95); opacity: 1; }
        }
        .pulse-ring {
            animation: pulse-ring 2s ease-in-out infinite;
        }

        .rfid-ready-mark {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: clamp(8rem, 18vw, 10.5rem);
            aspect-ratio: 1;
            border-radius: 999px;
            padding: 1.15rem;
            background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.98), rgba(224, 242, 254, 0.92));
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 18px 48px rgba(2, 132, 199, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.85);
            isolation: isolate;
        }

        .rfid-ready-mark::before,
        .rfid-ready-mark::after {
            content: '';
            position: absolute;
            inset: -10px;
            border-radius: inherit;
            pointer-events: none;
        }

        .rfid-ready-mark::before {
            background: radial-gradient(circle, rgba(14, 165, 233, 0.16), transparent 68%);
            filter: blur(8px);
            z-index: -2;
        }

        .rfid-ready-mark::after {
            inset: -18px;
            border: 1px solid rgba(2, 132, 199, 0.16);
            z-index: -1;
        }

        .rfid-ready-logo {
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 10px 18px rgba(15, 23, 42, 0.16));
        }

    </style>
    <style>
        :root {
            --sky-50: #f0f9ff;
            --sky-100: #e0f2fe;
            --sky-600: #0284c7;
            --slate-900: #0f172a;
            --glass: rgba(255, 255, 255, 0.65);
        }

        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
        }

        body {
            background: radial-gradient(circle at 20% 20%, rgba(2, 132, 199, 0.08), transparent 30%),
                        radial-gradient(circle at 80% 0%, rgba(14, 165, 233, 0.1), transparent 28%),
                        radial-gradient(circle at 0% 80%, rgba(2, 132, 199, 0.07), transparent 25%),
                        linear-gradient(135deg, #e0f2ff 0%, #f8fbff 100%);
            background-attachment: fixed;
            overflow-x: hidden;
            color: #0f172a;
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
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.18), rgba(255, 255, 255, 0.78));
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
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.14);
        }

        .nav-blur {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
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

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .sidebar-button {
            transition: all 0.2s ease;
        }

        .sidebar-button:hover,
        .sidebar-button:focus {
            background-color: #0284c7;
            color: white;
        }

        @font-face {
            font-family: 'old-english-canterbury';
            src: url('../assets/fonts/canterbury-webfont.woff2') format('woff2'),
                 url('../assets/fonts/canterbury-webfont.woff') format('woff');
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
            color: #000000;
            text-rendering: optimizeLegibility;
            white-space: nowrap;
        }

        .qr-video-wrap {
            position: relative;
            border-radius: 1rem;
            overflow: hidden;
            background: linear-gradient(160deg, #0f172a, #1e293b);
            border: 1px solid rgba(148, 163, 184, 0.25);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08), 0 18px 44px rgba(15, 23, 42, 0.25);
            min-height: 250px;
        }

        .qr-scan-line {
            position: absolute;
            left: 8%;
            right: 8%;
            height: 2px;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(45, 212, 191, 0), rgba(45, 212, 191, 0.95), rgba(45, 212, 191, 0));
            box-shadow: 0 0 18px rgba(45, 212, 191, 0.8);
            animation: qr-scan-move 2.3s ease-in-out infinite;
            pointer-events: none;
        }

        @keyframes qr-scan-move {
            0% { top: 14%; opacity: 0.65; }
            50% { top: 84%; opacity: 1; }
            100% { top: 14%; opacity: 0.65; }
        }

        .qr-corner {
            position: absolute;
            width: 42px;
            height: 42px;
            border-color: rgba(56, 189, 248, 0.85);
            border-style: solid;
            border-width: 0;
            pointer-events: none;
        }

        .qr-corner.tl { top: 16px; left: 16px; border-top-width: 3px; border-left-width: 3px; border-top-left-radius: 0.85rem; }
        .qr-corner.tr { top: 16px; right: 16px; border-top-width: 3px; border-right-width: 3px; border-top-right-radius: 0.85rem; }
        .qr-corner.bl { bottom: 16px; left: 16px; border-bottom-width: 3px; border-left-width: 3px; border-bottom-left-radius: 0.85rem; }
        .qr-corner.br { bottom: 16px; right: 16px; border-bottom-width: 3px; border-right-width: 3px; border-bottom-right-radius: 0.85rem; }

        .qr-pop-success {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.26), rgba(2, 132, 199, 0.72));
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            animation: qr-pop-in 380ms cubic-bezier(0.2, 0.9, 0.18, 1.02);
            z-index: 15;
        }

        .qr-pop-pill {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 999px;
            padding: 0.6rem 1.1rem;
            font-size: 0.9rem;
            font-weight: 700;
            color: #0f766e;
            box-shadow: 0 14px 36px rgba(15, 23, 42, 0.32);
        }

        @keyframes qr-pop-in {
            0% { opacity: 0; transform: scale(0.88); }
            100% { opacity: 1; transform: scale(1); }
        }

        .violation-overlay {
            position: fixed;
            inset: 0;
            z-index: 70;
            display: none;
            align-items: center;
            justify-content: center;
            padding: clamp(0.85rem, 2vw, 1.6rem);
            background:
                radial-gradient(circle at 14% 8%, rgba(14, 165, 233, 0.18), transparent 42%),
                radial-gradient(circle at 90% 92%, rgba(2, 132, 199, 0.16), transparent 48%),
                rgba(15, 23, 42, 0.48);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .violation-overlay.open {
            display: flex;
        }

        .violation-modal {
            width: min(1140px, 100%);
            max-height: calc(100vh - 1.7rem);
            overflow: hidden;
            border-radius: 1.4rem;
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 24px 64px rgba(15, 23, 42, 0.25);
            background: rgba(255, 255, 255, 0.66);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            display: grid;
            grid-template-rows: auto 1fr;
        }

        .violation-header {
            padding: 1.1rem 1.35rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.6);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.72), rgba(240, 249, 255, 0.58));
        }

        .violation-rail {
            max-height: 100%;
            grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.25fr);
            overflow: hidden;
            display: grid;
        }

        .violation-student-pane {
            padding: 1.15rem 1.2rem;
            border-right: 1px solid rgba(255, 255, 255, 0.58);
            background: linear-gradient(180deg, rgba(224, 242, 254, 0.34), rgba(255, 255, 255, 0.2));
            overflow-y: auto;
        }

        .violation-form-pane {
            padding: 1.15rem 1.2rem;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.24), rgba(240, 249, 255, 0.16));
            overflow-y: auto;
        }

        .violation-panel-card {
            border: 1px solid rgba(255, 255, 255, 0.58);
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.56);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 0.95rem;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.1);
        }

        .violation-mini-label {
            font-size: 0.69rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #64748b;
            font-weight: 700;
        }

        .violation-student-avatar {
            width: 3.2rem;
            height: 3.2rem;
            border-radius: 0.95rem;
            border: 1px solid rgba(255, 255, 255, 0.68);
            background: linear-gradient(140deg, rgba(224, 242, 254, 0.88), rgba(219, 234, 254, 0.7));
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            color: #0f172a;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            flex-shrink: 0;
        }

        .violation-student-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .violation-select-control,
        .violation-input-control {
            width: 100%;
            border-radius: 0.8rem;
            border: 1.5px solid rgba(255, 255, 255, 0.65);
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            color: #0f172a;
            font-size: 0.94rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .violation-select-control {
            height: 2.9rem;
            padding: 0 0.85rem;
        }

        .violation-input-control {
            min-height: 6.6rem;
            padding: 0.7rem 0.85rem;
            resize: vertical;
        }

        .violation-select-control:focus,
        .violation-input-control:focus {
            outline: none;
            border-color: rgba(14, 116, 144, 0.65);
            box-shadow: 0 0 0 3px rgba(186, 230, 253, 0.58);
        }

        .violation-category-shell {
            margin-top: 0.5rem;
            border-radius: 0.9rem;
            border: 1px solid rgba(203, 213, 225, 0.7);
            background: rgba(248, 250, 252, 0.72);
            padding: 0.6rem;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .violation-category-search {
            width: 100%;
            border: 1px solid rgba(148, 163, 184, 0.52);
            border-radius: 0.72rem;
            padding: 0.54rem 0.72rem;
            font-size: 0.88rem;
            color: #0f172a;
            background: rgba(255, 255, 255, 0.9);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .violation-category-search:focus {
            outline: none;
            border-color: rgba(3, 105, 161, 0.62);
            box-shadow: 0 0 0 3px rgba(186, 230, 253, 0.6);
        }

        .violation-category-tabs {
            margin-top: 0.55rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }

        .violation-category-tab {
            border: 1px solid rgba(148, 163, 184, 0.42);
            background: rgba(255, 255, 255, 0.88);
            color: #334155;
            border-radius: 999px;
            padding: 0.28rem 0.62rem;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.18s ease;
        }

        .violation-category-tab:hover,
        .violation-category-tab:focus {
            outline: none;
            border-color: rgba(3, 105, 161, 0.56);
            color: #0c4a6e;
        }

        .violation-category-tab.active {
            border-color: transparent;
            background: linear-gradient(135deg, #0369a1, #0284c7);
            color: #ffffff;
            box-shadow: 0 8px 16px rgba(2, 132, 199, 0.25);
        }

        .violation-category-list {
            margin-top: 0.55rem;
            border-radius: 0.8rem;
            border: 1px solid rgba(148, 163, 184, 0.32);
            background: rgba(255, 255, 255, 0.92);
            flex: 1;
            min-height: 260px;
            overflow: auto;
            padding: 0.35rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
        }

        .violation-category-panel {
            display: flex;
            flex-direction: column;
            min-height: 0;
            flex: 1;
        }

        .violation-category-group {
            margin-bottom: 0.35rem;
        }

        .violation-category-group:last-child {
            margin-bottom: 0;
        }

        .violation-category-heading {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 0.45rem 0.55rem;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.11em;
            color: #334155;
            font-weight: 800;
            background: rgba(226, 232, 240, 0.78);
            border-radius: 0.55rem;
            margin-bottom: 0.2rem;
        }

        .violation-category-option {
            width: 100%;
            border: 0;
            background: transparent;
            text-align: left;
            font-size: 0.93rem;
            color: #0f172a;
            padding: 0.52rem 0.6rem;
            border-radius: 0.55rem;
            cursor: pointer;
            transition: background-color 0.16s ease, color 0.16s ease;
        }

        .violation-category-option:hover,
        .violation-category-option:focus {
            outline: none;
            background: #e0f2fe;
            color: #0c4a6e;
        }

        .violation-category-option.active {
            background: linear-gradient(135deg, #0369a1, #0284c7);
            color: #ffffff;
            font-weight: 700;
        }

        .violation-category-empty {
            text-align: center;
            color: #64748b;
            font-size: 0.86rem;
            padding: 0.9rem 0.6rem;
        }

        .violation-preview-card {
            border: 1px solid rgba(255, 255, 255, 0.58);
            border-radius: 1rem;
            background: linear-gradient(150deg, rgba(255, 255, 255, 0.58), rgba(240, 249, 255, 0.46));
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 1rem;
        }

        .violation-action-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 0.7rem;
            margin-top: auto;
        }

        .violation-secondary-btn {
            border: 1px solid rgba(255, 255, 255, 0.68);
            border-radius: 0.72rem;
            padding: 0.55rem 0.9rem;
            font-size: 0.88rem;
            font-weight: 700;
            color: #475569;
            background: rgba(255, 255, 255, 0.64);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            transition: all 0.2s ease;
        }

        .violation-secondary-btn:hover {
            background: rgba(255, 255, 255, 0.8);
            border-color: rgba(255, 255, 255, 0.8);
        }

        .violation-primary-btn {
            border: 0;
            border-radius: 0.72rem;
            padding: 0.6rem 1rem;
            font-size: 0.9rem;
            font-weight: 700;
            color: #ffffff;
            background: linear-gradient(135deg, #0369a1, #0284c7);
            box-shadow: 0 12px 25px rgba(2, 132, 199, 0.25);
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
        }

        .violation-primary-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 26px rgba(2, 132, 199, 0.3);
        }

        .violation-primary-btn:disabled {
            opacity: 0.58;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .violation-mark-pill {
            border-radius: 999px;
            padding: 0.32rem 0.75rem;
            font-size: 0.71rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            border: 1px solid transparent;
        }

        .violation-mark-pill.type-minor {
            color: #166534;
            background: #dcfce7;
            border-color: #bbf7d0;
        }

        .violation-mark-pill.type-moderate {
            color: #92400e;
            background: #fef3c7;
            border-color: #fde68a;
        }

        .violation-mark-pill.type-major {
            color: #991b1b;
            background: #fee2e2;
            border-color: #fecaca;
        }

        .violation-mark-pill.type-neutral {
            color: #475569;
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        @media (max-width: 1024px) {
            .violation-rail {
                grid-template-columns: 1fr;
                overflow-y: auto;
            }

            .violation-student-pane {
                border-right: 0;
                border-bottom: 1px solid rgba(255, 255, 255, 0.6);
            }

            .violation-category-list {
                min-height: 220px;
            }
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
        }
    </style>
    <?php session_guard_script('security_login.php'); ?>
</head>
<body class="text-slate-900">
    <div class="page-shell">
        <div class="hero-photo"></div>
        <div class="hero-gradient"></div>
        <span class="floating-blob blob-1"></span>
        <span class="floating-blob blob-2"></span>

        <!-- Header -->
        <nav class="nav-blur border-b border-slate-200 fixed w-full top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between min-h-[4rem] py-2 items-center">
                    <div class="flex items-center gap-3">
                        <a href="gate_monitor.php" class="brand-link hover:opacity-90 transition-opacity">
                            <img src="../assets/images/pcu-logo.png" alt="PCU Logo" class="brand-logo">
                            <span class="brand-wordmark">Philippine Christian University</span>
                        </a>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="text-right hidden sm:block">
                            <p class="text-slate-700 font-medium text-sm"><?php echo htmlspecialchars($guard_username); ?></p>
                            <p class="text-slate-400 text-xs"><?php echo date('M j, Y g:i A'); ?></p>
                        </div>
                        <a href="security_logout.php" class="sidebar-button px-4 py-2 rounded-full text-sm font-semibold text-slate-700 border border-slate-300 hover:text-white hover:bg-sky-600 focus:outline-none focus:ring-2 focus:ring-sky-500 transition-colors">
                            Sign Out
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-12 relative z-10 flex-1">
            <!-- Page Title -->
            <div class="glass-card rounded-3xl p-6 mb-6">
                <h1 class="text-2xl font-bold text-slate-800">Gate Entrance Monitoring</h1>
                <p class="text-slate-600 mt-1">Monitor student RFID card scans and track violations in real-time</p>
            </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6 mb-6 md:mb-8">
            <!-- Today's Scans -->
            <div class="glass-card rounded-3xl p-5 stat-card">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Today's</p>
                        <h3 class="text-xl font-semibold text-slate-900">Scans</h3>
                    </div>
                    <span class="badge bg-blue-50 text-blue-700 border border-blue-100">
                        <?php echo number_format($todayScans); ?> total
                    </span>
                </div>
                <p class="text-slate-600 leading-relaxed">Total RFID card taps recorded at the gate today.</p>
            </div>

            <!-- Unique Students -->
            <div class="glass-card rounded-3xl p-5 stat-card">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Unique</p>
                        <h3 class="text-xl font-semibold text-slate-900">Students</h3>
                    </div>
                    <span class="badge bg-purple-50 text-purple-700 border border-purple-100">
                        <?php echo number_format($uniqueStudents); ?> today
                    </span>
                </div>
                <p class="text-slate-600 leading-relaxed">Distinct students who have scanned their cards today.</p>
            </div>

            <!-- High Violations -->
            <div class="glass-card rounded-3xl p-5 stat-card sm:col-span-2 lg:col-span-1">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Violations</p>
                        <h3 class="text-xl font-semibold text-slate-900">High Alert</h3>
                    </div>
                    <span class="badge bg-rose-50 text-rose-700 border border-rose-100">
                        <?php echo number_format($highViolationCount); ?> students
                    </span>
                </div>
                <p class="text-slate-600 leading-relaxed">Students with 3 or more recorded violations.</p>
            </div>
        </div>

        <!-- Main Scan Area -->
        <div class="max-w-4xl mx-auto mb-6 md:mb-8 glass-card rounded-3xl stat-card">
            <!-- Mode Selector -->
            <div class="flex gap-2 p-5 pb-0">
                <button id="btnRfidMode" onclick="switchMode('rfid')" class="flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-sky-600 text-white shadow-md">
                    <svg class="w-5 h-5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    RFID Scanner
                </button>
                <?php if ($qrScanEnabled): ?>
                <button id="btnQrMode" onclick="switchMode('qr')" class="flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-white/80 text-slate-600 border border-white/60 hover:bg-white">
                    <svg class="w-5 h-5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h2m0 0v2m0-2h2m-4 4h6"/></svg>
                    Digital QR
                </button>
                <?php endif; ?>
                <?php if ($faceRecEnabled): ?>
                <button id="btnFaceMode" onclick="switchMode('face')" class="flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-white/80 text-slate-600 border border-white/60 hover:bg-white">
                    <svg class="w-5 h-5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    Face Recognition
                </button>
            <?php endif; ?>
            </div>

            <!-- RFID Scanner Panel -->
            <div id="rfidPanel" class="overflow-hidden">
                <div class="px-6 pt-5 pb-4 border-b border-slate-200/30">
                    <p class="text-xs uppercase tracking-[0.12em] text-slate-500">RFID</p>
                    <h2 class="text-xl font-semibold text-slate-900">Scanner</h2>
                    <p class="text-sm text-slate-600 mt-1">Tap student card on reader to record entry</p>
                </div>
                <!-- Scan Status Display -->
                <div id="scanStatus" class="p-8 md:p-12 text-center min-h-[300px] md:min-h-[400px] flex items-center justify-center">
                    <div class="max-w-md mx-auto">
                        <div class="mb-4 relative inline-block">
                            <div class="pulse-ring absolute inset-0 bg-sky-300 rounded-full opacity-20 scale-[1.18]"></div>
                            <div class="rfid-ready-mark">
                                <img src="../assets/images/gatewatch-logo.png" alt="GateWatch Logo" class="rfid-ready-logo">
                            </div>
                        </div>
                        <h2 class="text-2xl md:text-3xl font-bold text-slate-700 mb-2">Ready to Scan</h2>
                        <p class="text-slate-500 text-sm md:text-base">Hold RFID card near scanner to verify student entry</p>
                        <p class="text-slate-400 text-xs md:text-sm mt-2">System Active • Waiting for card...</p>
                    </div>
                </div>
            </div>

            <?php if ($qrScanEnabled): ?>
            <!-- QR Scanner Panel (hidden by default) -->
            <div id="qrPanel" class="overflow-hidden hidden">
                <div class="px-6 pt-5 pb-4 border-b border-slate-200/30">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Digital ID</p>
                            <h2 class="text-xl font-semibold text-slate-900">QR Scanner</h2>
                            <p class="text-sm text-slate-600 mt-1">Scan the student digital ID QR to verify official account ownership</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button id="btnStartQr" onclick="startQrScanner()" class="px-3 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm rounded-lg transition-colors font-medium">
                                &#9654; Start
                            </button>
                            <button id="btnStopQr" onclick="stopQrScanner()" class="px-3 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-sm rounded-lg transition-colors font-medium hidden">
                                &#9632; Stop
                            </button>
                            <button id="btnQrRefocus" onclick="refocusQrCamera(true)" class="px-3 py-2 bg-sky-100 hover:bg-sky-200 text-sky-800 text-sm rounded-lg transition-colors font-medium hidden" title="Force camera refocus for blurry QR">
                                Focus
                            </button>
                            <button id="btnResetQrPending" onclick="resetQrPendingState()" class="px-3 py-2 bg-amber-100 hover:bg-amber-200 text-amber-800 text-sm rounded-lg transition-colors font-medium hidden">
                                Reset Pending
                            </button>
                        </div>
                    </div>
                </div>
                <div class="p-6 md:p-8">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                        <div>
                            <div class="qr-video-wrap">
                                <video id="qrVideo" class="w-full h-full object-cover" autoplay muted playsinline></video>
                                <canvas id="qrCanvas" class="hidden"></canvas>
                                <div id="qrScanLine" class="qr-scan-line hidden"></div>
                                <span class="qr-corner tl"></span>
                                <span class="qr-corner tr"></span>
                                <span class="qr-corner bl"></span>
                                <span class="qr-corner br"></span>
                                <div id="qrSuccessOverlay" class="qr-pop-success hidden">
                                    <div class="qr-pop-pill">Verified Student QR</div>
                                </div>
                            </div>
                            <p id="qrStatus" class="text-sm text-slate-600 mt-3">Scanner idle. Press Start to activate camera.</p>
                            <p id="qrChallengeMeta" class="text-xs text-slate-500 mt-1 hidden"></p>
                        </div>

                        <div id="qrResult" class="bg-white/80 rounded-2xl border border-slate-200 p-5 min-h-[250px] flex items-center justify-center">
                            <div class="text-center text-slate-500">
                                <p class="font-semibold">Awaiting QR Scan</p>
                                <p class="text-sm mt-1">Only official GateWatch student QR codes are accepted.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($faceRecEnabled): ?>
            <!-- Face Recognition Panel (hidden by default) -->
            <div id="facePanel" class="overflow-hidden hidden">
                <div class="px-6 pt-5 pb-4 border-b border-slate-200/30">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Face</p>
                            <h2 class="text-xl font-semibold text-slate-900">Recognition</h2>
                            <p class="text-sm text-slate-600 mt-1" id="faceStatus">Loading models...</p>
                        </div>
                        <div class="flex gap-2">
                            <button id="btnStartFace" onclick="startFaceDetection()" class="px-3 py-2 bg-green-500 hover:bg-green-600 text-white text-sm rounded-lg transition-colors font-medium hidden">
                                &#9654; Start
                            </button>
                            <button id="btnStopFace" onclick="stopFaceDetection()" class="px-3 py-2 bg-red-500 hover:bg-red-600 text-white text-sm rounded-lg transition-colors font-medium hidden">
                                &#9632; Stop
                            </button>
                            <button id="btnStudentDisplay" onclick="openStudentDisplay()" class="px-3 py-2 bg-slate-600 hover:bg-slate-700 text-white text-sm rounded-lg transition-colors font-medium flex items-center gap-1.5" title="Open student-facing display in a new tab">
                                <span class="sd-dot w-2 h-2 rounded-full bg-slate-400 inline-block"></span>
                                &#x1F4FA; Student View
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Webcam & Detection Area -->
                <div class="p-6 md:p-8">

                    <!-- ===== CAMERA SELECTOR — always visible ===== -->
                    <div class="mb-5 bg-white/70 border border-slate-200/70 rounded-xl p-4 backdrop-blur">
                        <div class="flex items-center gap-3 flex-wrap">
                            <svg class="w-5 h-5 text-[#0056b3] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.069A1 1 0 0121 8.882v6.236a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                            <label for="cameraSelect" class="text-sm font-semibold text-slate-700 whitespace-nowrap">Select Camera:</label>
                            <select id="cameraSelect" onchange="changeCameraDevice(this.value)"
                                class="flex-1 min-w-0 border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-[#0056b3] focus:border-[#0056b3]">
                                <option value="">&#8212; Detecting cameras... &#8212;</option>
                            </select>
                            <button onclick="populateCameraSelector()" title="Refresh camera list"
                                class="flex-shrink-0 flex items-center gap-1.5 px-3 py-2 bg-white border border-slate-300 hover:bg-slate-100 text-slate-600 text-sm rounded-lg transition-colors font-medium">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                Refresh
                            </button>
                        </div>
                        <p id="cameraSelectHint" class="text-xs text-slate-400 mt-2 ml-8">Camera list will populate once permission is granted.</p>
                    </div>
                    <!-- ===== END CAMERA SELECTOR ===== -->
                    <div id="faceInitStatus" class="text-center py-12">
                        <svg class="animate-spin w-12 h-12 mx-auto text-blue-500 mb-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p class="text-slate-600">Initializing face recognition system...</p>
                    </div>
                    
                    <div id="faceVideoContainer" class="relative mx-auto hidden" style="max-width: 640px;">
                        <video id="faceVideo" class="w-full rounded-lg shadow-inner bg-black" autoplay muted playsinline></video>
                        <canvas id="faceCanvas" class="absolute top-0 left-0 w-full h-full pointer-events-none"></canvas>
                        
                        <!-- Face match result overlay -->
                        <div id="faceMatchOverlay" class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-4 hidden">
                            <div id="faceMatchInfo" class="text-white"></div>
                        </div>
                    </div>
                    
                    <!-- Face scan result display (full screen result like RFID) -->
                    <div id="faceScanResult" class="mt-4 hidden"></div>
                    
                    <!-- Face recognition stats -->
                    <div class="flex gap-4 mt-4 text-center text-sm">
                        <div class="flex-1 bg-white/70 backdrop-blur rounded-lg p-3 border border-white/60">
                            <p class="text-slate-500">Loaded Faces</p>
                            <p id="faceLoadedCount" class="text-xl font-bold text-slate-800">0</p>
                        </div>
                        <div class="flex-1 bg-white/70 backdrop-blur rounded-lg p-3 border border-white/60">
                            <p class="text-slate-500">Matches Today</p>
                            <p class="text-xl font-bold text-slate-800"><?php echo number_format($faceEntriesToday); ?></p>
                        </div>
                        <div class="flex-1 bg-white/70 backdrop-blur rounded-lg p-3 border border-white/60">
                            <p class="text-slate-500">Threshold</p>
                            <p class="text-xl font-bold text-slate-800"><?php echo $faceMatchThreshold; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Scans Log -->
        <div class="max-w-5xl mx-auto">
            <div class="glass-card rounded-3xl overflow-hidden stat-card">
                <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-slate-200/30">
                    <div>
                        <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Activity</p>
                        <h3 class="text-xl font-semibold text-slate-900">Recent Scans</h3>
                        <p class="text-sm text-slate-600 mt-1">Live activity log</p>
                    </div>
                    <span class="badge bg-sky-50 text-sky-700 border border-sky-100 cursor-pointer hover:bg-sky-100 transition-colors" onclick="clearLog()">
                        Clear Log
                    </span>
                </div>
                <div id="scanLog" class="p-6 space-y-3 max-h-[400px] overflow-y-auto">
                    <p class="text-center text-slate-400 py-8 text-sm">No scans recorded yet. Waiting for first card tap...</p>
                </div>
            </div>
        </div>
        </main>
    </div>

    <div id="violationChooserOverlay" class="violation-overlay" aria-hidden="true">
        <div class="violation-modal">
            <div class="violation-header">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.12em] text-slate-500">Security Action Required</p>
                        <h3 class="text-xl font-bold text-slate-900">Choose Violation</h3>
                        <p class="text-sm text-slate-600 mt-1">Select the correct violation category to record this gate incident.</p>
                    </div>
                    <button id="closeViolationChooserBtn" class="violation-secondary-btn" type="button">
                        Close
                    </button>
                </div>
            </div>

            <div class="violation-rail">
                <section class="violation-student-pane">
                    <div id="violationStudentSummary" class="mb-4"></div>
                    <div class="violation-panel-card">
                        <p class="violation-mini-label">Incident Workflow</p>
                        <ol class="mt-3 space-y-2 text-sm text-slate-600">
                            <li class="flex items-start gap-2">
                                <span class="w-5 h-5 rounded-full bg-sky-100 text-sky-700 text-xs font-bold inline-flex items-center justify-center mt-0.5">1</span>
                                <span>Student identity has been verified.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="w-5 h-5 rounded-full bg-sky-100 text-sky-700 text-xs font-bold inline-flex items-center justify-center mt-0.5">2</span>
                                <span>Select the exact violation from the category list.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="w-5 h-5 rounded-full bg-sky-100 text-sky-700 text-xs font-bold inline-flex items-center justify-center mt-0.5">3</span>
                                <span>Submit to record instantly and append to the scan log.</span>
                            </li>
                        </ol>
                        <p class="mt-3 text-xs text-slate-500">Submitting this incident records an offense immediately based on the selected category.</p>
                    </div>

                    <div class="violation-preview-card mt-4">
                        <p class="violation-mini-label">Selected Violation</p>
                        <h4 id="selectedViolationName" class="mt-2 text-lg font-bold text-slate-800">No violation selected</h4>
                        <p id="selectedViolationDesc" class="mt-2 text-sm text-slate-600">Select a violation category from the list above.</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span id="selectedViolationType" class="violation-mark-pill type-neutral">Type</span>
                            <span id="selectedViolationArticle" class="violation-mark-pill type-neutral">Article</span>
                        </div>
                        <p id="selectedViolationSanction" class="mt-3 text-sm text-slate-700"></p>
                    </div>

                    <div class="violation-panel-card mt-4">
                        <label for="violationNotes" class="violation-mini-label">Guard Notes (Optional)</label>
                        <textarea id="violationNotes" rows="5" class="mt-2 violation-input-control" placeholder="Add context for this incident..."></textarea>
                    </div>
                </section>

                <section class="violation-form-pane">
                    <div id="violationSelectionPanel" class="h-full flex flex-col gap-4">
                        <div class="violation-panel-card violation-category-panel">
                            <label for="violationCategoryDropdown" class="violation-mini-label">Violation Category</label>
                            <div class="violation-category-shell">
                                <input
                                    id="violationCategorySearch"
                                    type="text"
                                    class="violation-category-search"
                                    placeholder="Search violation category..."
                                    autocomplete="off"
                                >
                                <div id="violationCategoryTabs" class="violation-category-tabs" role="tablist" aria-label="Violation category type filters">
                                    <button type="button" class="violation-category-tab active" data-filter="all">All</button>
                                    <button type="button" class="violation-category-tab" data-filter="minor">Minor</button>
                                    <button type="button" class="violation-category-tab" data-filter="moderate">Moderate</button>
                                    <button type="button" class="violation-category-tab" data-filter="major">Major</button>
                                </div>
                                <div id="violationCategoryList" class="violation-category-list" role="listbox" aria-label="Violation category list">
                                    <div id="violationCategoryListOptions"></div>
                                </div>
                                <select id="violationCategoryDropdown" class="hidden" tabindex="-1" aria-hidden="true">
                                    <option value="">Select a category...</option>
                                </select>
                            </div>
                            <p id="violationDropdownHint" class="mt-2 text-xs text-slate-500">Choose the correct violation type and category.</p>
                        </div>

                        <div class="violation-action-row">
                            <button id="cancelViolationBtn" type="button" class="violation-secondary-btn">
                                Cancel
                            </button>
                            <button id="submitViolationBtn" type="button" class="violation-primary-btn" disabled>
                                Record Violation
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <script>
    // ================================================================
    // SECURITY & CONFIGURATION
    // ================================================================
    const CSRF_TOKEN = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
    const QR_FEATURE_ENABLED = <?php echo $qrScanEnabled ? 'true' : 'false'; ?>;
    const QR_CHALLENGE_ENABLED = <?php echo $qrChallengeEnabled ? 'true' : 'false'; ?>;
    const QR_FACE_BINDING_ENABLED = <?php echo $qrFaceBindingEnabled ? 'true' : 'false'; ?>;
    const QR_FACE_BINDING_STRICT = <?php echo $qrFaceBindingStrict ? 'true' : 'false'; ?>;
    const FACE_FEATURE_ENABLED = <?php echo $faceRecEnabled ? 'true' : 'false'; ?>;
    const QR_DEBUG_METRICS = <?php echo filter_var(env('QR_DEBUG_METRICS', 'false'), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false'; ?>;
    
    let cardBuffer = '';
    let bufferTimeout = null;
    let scanLog = [];
    let scanInProgress = false;

    let violationCatalog = [];
    let violationCategoryUiState = {
        filterType: 'all',
        searchTerm: ''
    };
    let violationChooserState = {
        open: false,
        selectedCategoryId: null,
        selectedCategory: null,
        student: null,
        scanSource: 'rfid',
        scanPayload: null,
        scanToken: null,
        scanTokenExpiresAt: null,
    };

    // XSS escape helper for safe innerHTML rendering
    function escHtml(str) {
        if (str === null || str === undefined) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function ordinalLabel(num) {
        if (num === 1) return '1st';
        if (num === 2) return '2nd';
        return '3rd';
    }

    function isAwaitingViolationChoice(data) {
        return !!(data && data.success && data.awaiting_violation_selection && data.student);
    }

    function scanSourceLabel(source) {
        if (source === 'qr') return 'Digital QR';
        if (source === 'face') return 'Face Recognition';
        return 'RFID';
    }

    function violationTypeClass(type) {
        const normalized = String(type || '').toLowerCase();
        if (normalized === 'major') return 'type-major';
        if (normalized === 'moderate') return 'type-moderate';
        if (normalized === 'minor') return 'type-minor';
        return 'type-neutral';
    }

    function studentInitials(name) {
        const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return 'ST';
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    // Listen for RFID scanner (keyboard emulation) - Use keydown to capture ALL keys before processing
    document.addEventListener('keydown', function(e) {
        if (violationChooserState.open) {
            return;
        }

        const tagName = ((e.target && e.target.tagName) || '').toLowerCase();
        if (tagName === 'input' || tagName === 'textarea' || (e.target && e.target.isContentEditable)) {
            return;
        }

        const rfidPanel = document.getElementById('rfidPanel');
        if (rfidPanel && rfidPanel.classList.contains('hidden')) {
            return;
        }

        // If Enter key, process the buffer we've accumulated
        if (e.key === 'Enter') {
            e.preventDefault();
            const uid = cardBuffer.trim();
            if (uid.length >= 4 && !scanInProgress) {
                processRFIDScan(uid);
            }
            cardBuffer = '';
            clearTimeout(bufferTimeout);
            return;
        }
        
        // Accumulate all other single-character keys
        if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            cardBuffer += e.key;
            clearTimeout(bufferTimeout);
            bufferTimeout = setTimeout(() => {
                if (cardBuffer.length > 0 && cardBuffer.length < 4) {
                    cardBuffer = '';
                }
            }, 80);
        }
    });

    function setupViolationChooserUi() {
        const closeBtn = document.getElementById('closeViolationChooserBtn');
        const cancelBtn = document.getElementById('cancelViolationBtn');
        const submitBtn = document.getElementById('submitViolationBtn');
        const categoryDropdown = document.getElementById('violationCategoryDropdown');
        const categorySearch = document.getElementById('violationCategorySearch');
        const categoryTabs = document.getElementById('violationCategoryTabs');
        const categoryListOptions = document.getElementById('violationCategoryListOptions');

        if (closeBtn) closeBtn.addEventListener('click', () => closeViolationChooser(true));
        if (cancelBtn) cancelBtn.addEventListener('click', () => closeViolationChooser(true));
        if (submitBtn) submitBtn.addEventListener('click', submitSelectedViolation);

        if (categoryDropdown) {
            categoryDropdown.addEventListener('change', function() {
                selectViolationCategory(Number(this.value || 0));
            });
        }

        if (categorySearch) {
            categorySearch.addEventListener('input', function() {
                violationCategoryUiState.searchTerm = String(this.value || '').trim().toLowerCase();
                renderViolationCategoryList();
            });
        }

        if (categoryTabs) {
            categoryTabs.addEventListener('click', function(event) {
                const tabBtn = event.target.closest('[data-filter]');
                if (!tabBtn) return;
                violationCategoryUiState.filterType = String(tabBtn.getAttribute('data-filter') || 'all').toLowerCase();
                syncViolationCategoryFilterTabs();
                renderViolationCategoryList();
            });
        }

        if (categoryListOptions) {
            categoryListOptions.addEventListener('click', function(event) {
                const optionBtn = event.target.closest('[data-category-id]');
                if (!optionBtn) return;
                const categoryId = Number(optionBtn.getAttribute('data-category-id') || 0);
                selectViolationCategory(categoryId);
            });
        }
    }

    function syncViolationCategoryFilterTabs() {
        const tabButtons = document.querySelectorAll('#violationCategoryTabs [data-filter]');
        tabButtons.forEach((button) => {
            const filter = String(button.getAttribute('data-filter') || 'all').toLowerCase();
            button.classList.toggle('active', filter === violationCategoryUiState.filterType);
        });
    }

    async function loadViolationCategories() {
        try {
            const response = await fetch('get_violation_categories.php', {
                method: 'GET',
                credentials: 'same-origin'
            });
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to load categories');
            }

            if (Array.isArray(data.flat)) {
                violationCatalog = data.flat;
            } else {
                violationCatalog = [];
            }

            populateViolationCategoryDropdown();
        } catch (error) {
            console.error('Violation category fetch failed:', error);
            violationCatalog = [];
            populateViolationCategoryDropdown();
        }
    }

    function populateViolationCategoryDropdown() {
        const dropdown = document.getElementById('violationCategoryDropdown');
        const hintEl = document.getElementById('violationDropdownHint');
        if (!dropdown) return;

        dropdown.innerHTML = '<option value="">Select a category...</option>';

        const grouped = { minor: [], moderate: [], major: [] };
        violationCatalog.forEach((item) => {
            const type = (item.type || 'minor').toLowerCase();
            if (!grouped[type]) {
                grouped[type] = [];
            }
            grouped[type].push(item);
        });

        const labels = {
            minor: 'Minor Offenses',
            moderate: 'Moderate Offenses',
            major: 'Major Offenses'
        };

        ['minor', 'moderate', 'major'].forEach((type) => {
            const list = grouped[type] || [];
            if (!list.length) return;

            const optgroup = document.createElement('optgroup');
            optgroup.label = labels[type] || type;

            list.forEach((item) => {
                const option = document.createElement('option');
                option.value = String(item.id);
                option.textContent = item.name || ('Category #' + item.id);
                optgroup.appendChild(option);
            });

            dropdown.appendChild(optgroup);
        });

        dropdown.value = violationChooserState.selectedCategoryId ? String(violationChooserState.selectedCategoryId) : '';
        syncViolationCategoryFilterTabs();
        renderViolationCategoryList();
        highlightSelectedCategoryOption();

        if (hintEl) {
            hintEl.textContent = violationCatalog.length
                ? ('Loaded ' + violationCatalog.length + ' active violation categories.')
                : 'No active violation categories found. Please contact admin.';
        }
    }

    function renderViolationCategoryList() {
        const listOptions = document.getElementById('violationCategoryListOptions');
        if (!listOptions) return;

        const filterType = violationCategoryUiState.filterType || 'all';
        const searchTerm = violationCategoryUiState.searchTerm || '';
        listOptions.innerHTML = '';

        const filtered = violationCatalog.filter((item) => {
            const type = String(item.type || 'minor').toLowerCase();
            const name = String(item.name || '').toLowerCase();
            const desc = String(item.description || '').toLowerCase();

            const passesType = filterType === 'all' || type === filterType;
            const passesSearch = !searchTerm || name.includes(searchTerm) || desc.includes(searchTerm);
            return passesType && passesSearch;
        });

        if (!filtered.length) {
            listOptions.innerHTML = '<p class="violation-category-empty">No categories match this filter.</p>';
            return;
        }

        const grouped = { minor: [], moderate: [], major: [] };
        filtered.forEach((item) => {
            const type = String(item.type || 'minor').toLowerCase();
            if (!grouped[type]) grouped[type] = [];
            grouped[type].push(item);
        });

        const labels = {
            minor: 'Minor Offenses',
            moderate: 'Moderate Offenses',
            major: 'Major Offenses'
        };

        ['minor', 'moderate', 'major'].forEach((type) => {
            const list = grouped[type] || [];
            if (!list.length) return;

            const listGroup = document.createElement('div');
            listGroup.className = 'violation-category-group';

            const heading = document.createElement('p');
            heading.className = 'violation-category-heading';
            heading.textContent = labels[type] || type;
            listGroup.appendChild(heading);

            list.forEach((item) => {
                const optionBtn = document.createElement('button');
                optionBtn.type = 'button';
                optionBtn.className = 'violation-category-option';
                optionBtn.setAttribute('data-category-id', String(item.id));
                optionBtn.textContent = item.name || ('Category #' + item.id);
                listGroup.appendChild(optionBtn);
            });

            listOptions.appendChild(listGroup);
        });

        highlightSelectedCategoryOption();
    }

    function highlightSelectedCategoryOption() {
        const listOptions = document.getElementById('violationCategoryListOptions');
        if (!listOptions) return;

        const selectedId = violationChooserState.selectedCategoryId ? String(violationChooserState.selectedCategoryId) : '';
        const buttons = listOptions.querySelectorAll('[data-category-id]');
        buttons.forEach((btn) => {
            const active = btn.getAttribute('data-category-id') === selectedId;
            btn.classList.toggle('active', active);
        });
    }

    function renderViolationStudentSummary() {
        const wrap = document.getElementById('violationStudentSummary');
        if (!wrap) return;
        const student = violationChooserState.student || {};
        const source = violationChooserState.scanSource || 'rfid';
        const picturePath = student.profile_picture
            ? ('../assets/images/profiles/' + String(student.profile_picture))
            : '';
        const avatarHtml = picturePath
            ? `<img src="${escHtml(picturePath)}" alt="Student profile picture">`
            : `<span>${escHtml(studentInitials(student.name || 'Student'))}</span>`;

        wrap.innerHTML = `
            <div class="violation-panel-card">
                <p class="violation-mini-label">Identified Student</p>
                <div class="mt-3 flex items-start justify-between gap-3">
                    <div class="flex items-start gap-3 min-w-0">
                        <div class="violation-student-avatar">${avatarHtml}</div>
                        <div class="min-w-0">
                            <p class="text-lg font-bold text-slate-900 leading-tight">${escHtml(student.name || '')}</p>
                            <p class="text-sm text-slate-600 mt-0.5">${escHtml(student.student_id || '')}</p>
                        </div>
                    </div>
                    <span class="violation-mark-pill type-neutral">${escHtml(scanSourceLabel(source))}</span>
                </div>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                    <div class="rounded-lg bg-slate-50 border border-slate-200 px-3 py-2">
                        <p class="text-[0.65rem] uppercase tracking-[0.11em] text-slate-500 font-semibold">Email</p>
                        <p class="text-slate-700 mt-1 break-all">${escHtml(student.email || 'No email')}</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 border border-slate-200 px-3 py-2">
                        <p class="text-[0.65rem] uppercase tracking-[0.11em] text-slate-500 font-semibold">Course</p>
                        <p class="text-slate-700 mt-1">${escHtml(student.course || 'Not specified')}</p>
                    </div>
                </div>
            </div>
        `;
    }

    function selectViolationCategory(categoryId) {
        const found = violationCatalog.find((item) => Number(item.id) === Number(categoryId));
        violationChooserState.selectedCategoryId = found ? Number(found.id) : null;
        violationChooserState.selectedCategory = found || null;
        const hideArticleForRfid = (violationChooserState.scanSource || 'rfid') === 'rfid';

        const submitBtn = document.getElementById('submitViolationBtn');
        if (submitBtn) {
            submitBtn.disabled = !found;
        }

        const nameEl = document.getElementById('selectedViolationName');
        const descEl = document.getElementById('selectedViolationDesc');
        const typeEl = document.getElementById('selectedViolationType');
        const articleEl = document.getElementById('selectedViolationArticle');
        const sanctionEl = document.getElementById('selectedViolationSanction');

        if (!found) {
            if (nameEl) nameEl.textContent = 'No violation selected';
            if (descEl) descEl.textContent = 'Select a violation category from the list above.';
            if (typeEl) {
                typeEl.textContent = 'Type';
                typeEl.className = 'violation-mark-pill type-neutral';
            }
            if (articleEl) {
                articleEl.textContent = 'Article';
                articleEl.className = 'violation-mark-pill type-neutral';
                articleEl.style.display = hideArticleForRfid ? 'none' : 'inline-flex';
            }
            if (sanctionEl) sanctionEl.textContent = '';
            const dropdown = document.getElementById('violationCategoryDropdown');
            if (dropdown) dropdown.value = '';
            highlightSelectedCategoryOption();
            return;
        }

        if (nameEl) nameEl.textContent = found.name || 'Unnamed category';
        if (descEl) descEl.textContent = found.description || 'No description provided.';
        if (typeEl) {
            typeEl.textContent = (found.type || 'minor').toUpperCase();
            typeEl.className = 'violation-mark-pill ' + violationTypeClass(found.type || 'minor');
        }
        if (articleEl) {
            if (hideArticleForRfid) {
                articleEl.textContent = '';
                articleEl.className = 'violation-mark-pill type-neutral';
                articleEl.style.display = 'none';
            } else {
                articleEl.textContent = found.article_reference || 'No article reference';
                articleEl.className = 'violation-mark-pill type-neutral';
                articleEl.style.display = 'inline-flex';
            }
        }
        if (sanctionEl) {
            sanctionEl.innerHTML = '<strong>Default sanction:</strong> ' + escHtml(found.default_sanction || 'To be determined by admin policy');
        }

        const dropdown = document.getElementById('violationCategoryDropdown');
        if (dropdown) dropdown.value = String(found.id);
        highlightSelectedCategoryOption();
    }

    function showAwaitingViolationHint(scanData) {
        const source = scanData.scan_source || 'rfid';
        const student = scanData.student || {};

        const html = `
            <div class="fade-in w-full rounded-2xl border-2 border-sky-500 bg-sky-50/90 p-6 text-center">
                <div class="text-5xl mb-3">🛡️</div>
                <h3 class="text-2xl font-bold text-sky-900 mb-2">Student Identified</h3>
                <p class="text-slate-700 font-semibold">${escHtml(student.name || '')}</p>
                <p class="text-slate-500 text-sm">${escHtml(student.student_id || '')} ${student.course ? '• ' + escHtml(student.course) : ''}</p>
                <p class="text-sky-700 text-sm mt-3">Waiting for guard to choose the violation type.</p>
            </div>
        `;

        if (source === 'qr') {
            const qrResult = document.getElementById('qrResult');
            if (qrResult) qrResult.innerHTML = html;
            setQrStatus('Student verified. Select violation category to finalize recording.', false);
            return;
        }

        if (source === 'face') {
            const faceResult = document.getElementById('faceScanResult');
            if (faceResult) {
                faceResult.classList.remove('hidden');
                faceResult.innerHTML = html;
            }
            const faceStatusEl = document.getElementById('faceStatus');
            if (faceStatusEl) faceStatusEl.textContent = 'Student identified. Choose violation to complete.';
            return;
        }

        const scanStatus = document.getElementById('scanStatus');
        if (scanStatus) scanStatus.innerHTML = html;
    }

    function openViolationChooser(scanData) {
        if (!scanData || !scanData.student) return;

        violationChooserState.open = true;
        violationChooserState.student = scanData.student;
        violationChooserState.scanSource = scanData.scan_source || 'rfid';
        violationChooserState.scanPayload = scanData;
        violationChooserState.scanToken = scanData.violation_selection_token || null;
        violationChooserState.scanTokenExpiresAt = scanData.violation_selection_expires_at || null;
        violationChooserState.selectedCategoryId = null;
        violationChooserState.selectedCategory = null;

        if (violationChooserState.scanSource === 'qr' && typeof stopQrScanner === 'function') {
            stopQrScanner();
        }

        const notesEl = document.getElementById('violationNotes');
        if (notesEl) notesEl.value = '';

        const categorySearch = document.getElementById('violationCategorySearch');
        if (categorySearch) categorySearch.value = '';
        violationCategoryUiState.searchTerm = '';
        violationCategoryUiState.filterType = 'all';

        renderViolationStudentSummary();
        if (!violationCatalog.length) {
            loadViolationCategories();
        }
        populateViolationCategoryDropdown();
        selectViolationCategory(0);

        const overlay = document.getElementById('violationChooserOverlay');
        if (overlay) {
            overlay.classList.add('open');
            overlay.setAttribute('aria-hidden', 'false');
        }
        document.body.classList.add('overflow-hidden');
    }

    function closeViolationChooser(resetView) {
        const sourceOnClose = violationChooserState.scanSource || 'rfid';

        const overlay = document.getElementById('violationChooserOverlay');
        if (overlay) {
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('overflow-hidden');

        const submitBtn = document.getElementById('submitViolationBtn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Record Violation';
        }

        violationChooserState.open = false;

        if (resetView) {
            violationChooserState.selectedCategoryId = null;
            violationChooserState.selectedCategory = null;

            if (sourceOnClose === 'qr') {
                const qrResult = document.getElementById('qrResult');
                if (qrResult) {
                    qrResult.innerHTML = `
                        <div class="text-center text-slate-500">
                            <p class="font-semibold">Awaiting QR Scan</p>
                            <p class="text-sm mt-1">Only official GateWatch student QR codes are accepted.</p>
                        </div>
                    `;
                }

                const qrPanel = document.getElementById('qrPanel');
                if (qrPanel && !qrPanel.classList.contains('hidden')) {
                    setTimeout(() => {
                        startQrScanner();
                    }, 120);
                } else {
                    setQrStatus('Scanner idle. Press Start to activate camera.', false);
                }
            } else if (sourceOnClose === 'face') {
                const faceResult = document.getElementById('faceScanResult');
                if (faceResult) {
                    faceResult.classList.add('hidden');
                    faceResult.innerHTML = '';
                }

                const faceStatusEl = document.getElementById('faceStatus');
                if (faceStatusEl) {
                    faceStatusEl.textContent = 'Scan canceled. Press Start to scan next student.';
                }
            } else {
                resetScanDisplay();
            }
        }

        violationChooserState.student = null;
        violationChooserState.scanPayload = null;
        violationChooserState.scanToken = null;
        violationChooserState.scanTokenExpiresAt = null;
    }

    function renderRecordedViolationResult(resultData) {
        const source = resultData.scan_source || 'rfid';
        const student = resultData.student || {};
        const violation = resultData.violation || {};
        const offenseNumber = Number(violation.offense_number || violation.mark_level || 1);
        const offenseLabel = violation.mark_label || (ordinalLabel(offenseNumber) + ' Offense');
        const disCode = violation.disciplinary_code || resultData.disciplinary_notice?.code || '';
        const disTitle = violation.disciplinary_title || resultData.disciplinary_notice?.title || '';

        let tone = {
            bg: 'bg-emerald-50',
            border: 'border-emerald-500',
            title: 'text-emerald-900',
            subtitle: 'text-emerald-700',
            chip: 'bg-emerald-100 text-emerald-700',
            icon: '✅'
        };
        if (offenseNumber === 2) {
            tone = {
                bg: 'bg-amber-50',
                border: 'border-amber-500',
                title: 'text-amber-900',
                subtitle: 'text-amber-700',
                chip: 'bg-amber-100 text-amber-700',
                icon: '⚠️'
            };
        } else if (offenseNumber === 3) {
            tone = {
                bg: 'bg-rose-50',
                border: 'border-rose-600',
                title: 'text-rose-900',
                subtitle: 'text-rose-700',
                chip: 'bg-rose-100 text-rose-700',
                icon: '🚨'
            };
        } else if (offenseNumber >= 4) {
            tone = {
                bg: 'bg-red-50',
                border: 'border-red-700',
                title: 'text-red-900',
                subtitle: 'text-red-700',
                chip: 'bg-red-100 text-red-700',
                icon: '⛔'
            };
        }

        const cardContainerId = source === 'rfid'
            ? 'recordedViolationCardRfid'
            : 'recordedViolationCardFace';

        const resultHtml = source === 'qr'
            ? `
            <div class="fade-in w-full">
                <div class="${tone.bg} border-4 ${tone.border} rounded-2xl p-5 md:p-6">
                    <div class="flex items-start gap-3">
                        <div class="text-4xl md:text-5xl leading-none">${tone.icon}</div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-xl md:text-2xl font-bold ${tone.title}">${escHtml(offenseLabel.toUpperCase())} RECORDED</h3>
                            <p class="${tone.subtitle} text-sm mt-1">${escHtml(disCode && disTitle ? (disCode + ' - ' + disTitle) : (violation.default_sanction || 'Refer to policy sanction table.'))}</p>
                        </div>
                    </div>
                    <div class="mt-4 bg-white rounded-xl border border-slate-200 p-4">
                        <p class="text-lg font-bold text-slate-800 leading-tight">${escHtml(student.name || '')}</p>
                        <p class="text-slate-600 text-sm mt-1">ID: ${escHtml(student.student_id || '')}</p>
                        ${student.course ? `<p class="text-slate-500 text-sm">${escHtml(student.course)}</p>` : ''}
                        <p class="text-slate-500 text-xs mt-1 break-all">${escHtml(student.email || '')}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="violation-mark-pill ${tone.chip}">${escHtml((violation.category_type || 'minor').toUpperCase())}</span>
                            <span class="violation-mark-pill bg-slate-100 text-slate-600">${escHtml(violation.category_name || 'Violation')}</span>
                            <span class="violation-mark-pill bg-slate-100 text-slate-600">Offense #${escHtml(violation.offense_number || 1)}</span>
                        </div>
                    </div>
                    ${violation.article_reference ? `<p class="text-xs text-slate-500 mt-3">${escHtml(violation.article_reference)}</p>` : ''}
                </div>
            </div>
        `
            : `
            <div class="fade-in w-full">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                    <div id="${cardContainerId}" class="flex justify-center"></div>
                    <div class="${tone.bg} border-4 ${tone.border} rounded-2xl p-6 md:p-8">
                        <div class="text-5xl md:text-6xl mb-4">${tone.icon}</div>
                        <h3 class="text-2xl md:text-3xl font-bold ${tone.title} mb-3">${escHtml(offenseLabel.toUpperCase())} RECORDED</h3>
                        <div class="bg-white rounded-lg p-4 md:p-6 mb-4">
                            <p class="text-xl md:text-2xl font-bold text-slate-800 mb-1">${escHtml(student.name || '')}</p>
                            <p class="text-slate-600 mb-1">ID: ${escHtml(student.student_id || '')}</p>
                            ${student.course ? `<p class="text-slate-500 text-sm mb-1">${escHtml(student.course)}</p>` : ''}
                            <p class="text-slate-500 text-sm">${escHtml(student.email || '')}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="violation-mark-pill ${tone.chip}">${escHtml((violation.category_type || 'minor').toUpperCase())}</span>
                                <span class="violation-mark-pill bg-slate-100 text-slate-600">${escHtml(violation.category_name || 'Violation')}</span>
                                <span class="violation-mark-pill bg-slate-100 text-slate-600">Offense #${escHtml(violation.offense_number || 1)}</span>
                            </div>
                        </div>
                        <p class="${tone.subtitle} text-sm md:text-base font-semibold">${escHtml(disCode && disTitle ? (disCode + ' - ' + disTitle) : (violation.default_sanction || 'Refer to policy sanction table.'))}</p>
                        ${(source !== 'rfid' && violation.article_reference) ? `<p class="text-xs text-slate-500 mt-2">${escHtml(violation.article_reference)}</p>` : ''}
                    </div>
                </div>
            </div>
        `;

        if (source === 'qr') {
            const qrResult = document.getElementById('qrResult');
            if (qrResult) qrResult.innerHTML = resultHtml;
            if (typeof flashQrSuccessOverlay === 'function') {
                flashQrSuccessOverlay();
            }
            if (offenseNumber >= 4) {
                setQrStatus('4th offense recorded. Elevate case to SSO for final disciplinary disposition.', true);
            } else if (offenseNumber === 3) {
                setQrStatus('3rd offense recorded. Student is subject to suspension workflow under DIS 3.', true);
            } else if (offenseNumber === 2) {
                setQrStatus('2nd offense recorded under DIS 2. SSO compliance is required.', false);
            } else {
                setQrStatus('1st offense recorded under DIS 1. Awaiting SSO processing.', false);
            }
        } else if (source === 'face') {
            const faceResult = document.getElementById('faceScanResult');
            if (faceResult) {
                faceResult.classList.remove('hidden');
                faceResult.innerHTML = resultHtml;
            }
            const faceStatusEl = document.getElementById('faceStatus');
            if (faceStatusEl) {
                faceStatusEl.textContent = 'Violation recorded. Press Start to scan next student.';
            }
        } else {
            const scanStatus = document.getElementById('scanStatus');
            if (scanStatus) scanStatus.innerHTML = resultHtml;
        }

        if (source !== 'qr') {
            const idCard = new DigitalIdCard('#' + cardContainerId, {
                templateSrc: '../assets/images/id-card-template.png',
                student: {
                    name: student.name || '',
                    studentId: student.student_id || '',
                    course: student.course || '',
                    email: student.email || '',
                    profilePicture: student.profile_picture
                        ? '../assets/images/profiles/' + student.profile_picture
                        : null
                }
            });
            idCard.render();
        }
    }

    async function submitSelectedViolation() {
        if (!violationChooserState.student || !violationChooserState.selectedCategoryId) {
            return;
        }

        if (!violationChooserState.scanToken) {
            alert('Scan verification token missing or expired. Please rescan the student.');
            closeViolationChooser(true);
            return;
        }

        const submitBtn = document.getElementById('submitViolationBtn');
        const notesEl = document.getElementById('violationNotes');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Recording...';
        }

        try {
            const response = await fetch('record_violation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({
                    user_id: violationChooserState.student.id,
                    category_id: violationChooserState.selectedCategoryId,
                    scan_source: violationChooserState.scanSource,
                    scan_token: violationChooserState.scanToken,
                    notes: notesEl ? notesEl.value.trim() : '',
                    csrf_token: CSRF_TOKEN
                })
            });

            const result = await response.json();
            if (!result.success) {
                throw new Error(result.error || 'Failed to record violation');
            }

            closeViolationChooser(false);
            renderRecordedViolationResult(result);

            const offenseNumber = Number(result.violation?.offense_number || result.violation?.mark_level || 1);
            addToScanLog(
                result.student || violationChooserState.student,
                result.recorded_at || new Date().toISOString(),
                offenseNumber,
                offenseNumber >= 3,
                'violation_recorded',
                {
                    categoryName: result.violation?.category_name || 'Violation',
                    markLabel: result.violation?.mark_label || (ordinalLabel(offenseNumber) + ' Offense')
                }
            );

            if ((result.scan_source || violationChooserState.scanSource) === 'rfid') {
                setTimeout(resetScanDisplay, offenseNumber >= 3 ? 9000 : 6000);
            }

            if ((result.scan_source || violationChooserState.scanSource) === 'face') {
                setTimeout(() => {
                    const faceResult = document.getElementById('faceScanResult');
                    if (faceResult) {
                        faceResult.classList.add('hidden');
                        faceResult.innerHTML = '';
                    }
                }, offenseNumber >= 3 ? 9000 : 6000);
            }

            if ((result.scan_source || violationChooserState.scanSource) === 'qr' && QR_CHALLENGE_ENABLED) {
                await issueQrChallenge(true);
            }

            if ((result.scan_source || violationChooserState.scanSource) === 'qr') {
                const qrPanel = document.getElementById('qrPanel');
                if (qrPanel && !qrPanel.classList.contains('hidden')) {
                    setTimeout(() => {
                        startQrScanner();
                    }, 800);
                }
            }
        } catch (error) {
            alert('Failed to record violation: ' + error.message);
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Record Violation';
            }
            return;
        }

        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Record Violation';
        }
    }

    function processRFIDScan(uid) {
        scanInProgress = true;

        // No loading screen — fire immediately, display result instantly
        fetch('gate_scan.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify({rfid_uid: uid, csrf_token: CSRF_TOKEN})
        })
        .then(res => res.json())
        .then(data => {
            scanInProgress = false;
            if (isAwaitingViolationChoice(data)) {
                showAwaitingViolationHint(data);
                openViolationChooser(data);
            } else if (data.success) {
                displaySuccessScan(data);
            } else if (data.is_lost) {
                displayLostCardScan(data, uid);
            } else if (data.access_denied) {
                displayAccessDenied(data);
            } else {
                displayErrorScan(data, uid);
            }
        })
        .catch(error => {
            scanInProgress = false;
            displayErrorScan({error: 'Network error occurred'}, uid);
        });
    }

    function displaySuccessScan(data) {
        const student  = data.student;
        const mark     = data.gate_mark || student.gate_mark || 1;
        const created  = !!data.violation_created;

        let severityColor, severityBg, severityBorder, severityIcon, headlineText, subText;

        if (created) {
            // Mark 3 — formal violation recorded
            severityColor  = 'red';
            severityBg     = 'bg-red-50';
            severityBorder = 'border-red-600';
            severityIcon   = '🚨';
            headlineText   = '3-MARK LIMIT REACHED';
            subText        = 'A formal violation has been recorded. Student must report to the SSO Office.';
        } else if (mark >= 3) {
            // Mark held at 3 while admin resolution/reparation is pending
            severityColor  = 'orange';
            severityBg     = 'bg-orange-50';
            severityBorder = 'border-orange-500';
            severityIcon   = '⏳';
            headlineText   = 'VIOLATION PENDING RESOLUTION';
            subText        = 'Formal violation already recorded. Gate marks will reset after admin resolves all pending reparations.';
        } else if (mark === 2) {
            // Mark 2 — warning
            severityColor  = 'yellow';
            severityBg     = 'bg-yellow-50';
            severityBorder = 'border-yellow-400';
            severityIcon   = '⚠️';
            headlineText   = '2ND MARK RECORDED';
            subText        = 'Warning: one more scan without physical ID will create a formal violation.';
        } else {
            // Mark 1 — gentle notice
            severityColor  = 'green';
            severityBg     = 'bg-green-50';
            severityBorder = 'border-green-400';
            severityIcon   = '✓';
            headlineText   = '1ST MARK RECORDED';
            subText        = 'Entry allowed. Remind student to bring their physical ID next time.';
        }

        const markBar = `
            <div class="flex items-center gap-2 mt-3">
                <span class="text-xs text-slate-500 font-medium">Gate Marks:</span>
                ${[1,2,3].map(i => `
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold border-2 ${
                        i <= (created ? 3 : mark)
                            ? (created && i === 3 ? 'bg-red-500 border-red-600 text-white' : 'bg-amber-400 border-amber-500 text-white')
                            : 'bg-slate-100 border-slate-300 text-slate-400'
                    }">${i}</div>
                `).join('')}
                ${created
                    ? '<span class="text-xs font-bold text-red-600 ml-1">→ Violation Created</span>'
                    : (mark >= 3
                        ? '<span class="text-xs font-bold text-orange-600 ml-1">→ Awaiting Admin Resolution</span>'
                        : `<span class="text-xs text-slate-400 ml-1">${3 - mark} mark${3 - mark !== 1 ? 's' : ''} until violation</span>`)}
            </div>`;

        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in w-full">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                    <!-- Digital ID Card -->
                    <div id="gateDigitalIdContainer" class="flex justify-center"></div>

                    <!-- Mark / Violation Info -->
                    <div class="${severityBg} border-4 ${severityBorder} rounded-2xl p-6 md:p-8">
                        <div class="text-5xl md:text-6xl mb-4">${severityIcon}</div>
                        <h2 class="text-2xl md:text-3xl font-bold text-${severityColor}-800 mb-3">NO PHYSICAL ID</h2>

                        <div class="bg-white rounded-lg p-4 md:p-6 mb-4">
                            <p class="text-xl md:text-2xl font-bold text-slate-800 mb-2">${escHtml(student.name)}</p>
                            <p class="text-slate-600 mb-1">ID: ${escHtml(student.student_id)}</p>
                            ${student.course ? `<p class="text-slate-500 text-sm mb-1">${escHtml(student.course)}</p>` : ''}
                            <p class="text-slate-500 text-sm">${escHtml(student.email)}</p>
                            ${markBar}
                        </div>

                        <div class="bg-${severityColor}-100 rounded-lg p-4 mb-3">
                            <p class="text-${severityColor}-900 font-bold text-xl md:text-2xl mb-1">${headlineText}</p>
                            <p class="text-${severityColor}-700 text-sm md:text-base">${subText}</p>
                        </div>

                        ${created ? `
                        <div class="bg-red-200 rounded-lg p-3 text-center animate-pulse">
                            <p class="text-red-900 font-bold text-sm">🔔 Student must contact the SSO Office to resolve this violation.</p>
                        </div>` : `
                        <p class="text-slate-500 text-xs mt-2">Entry allowed — scan logged.</p>`}
                    </div>
                </div>
            </div>
        `;

        // Render Digital ID Card
        const gateIdCard = new DigitalIdCard('#gateDigitalIdContainer', {
            templateSrc: '../assets/images/id-card-template.png',
            student: {
                name:           student.name || '',
                studentId:      student.student_id || '',
                course:         student.course || '',
                email:          student.email || '',
                profilePicture: student.profile_picture
                    ? '../assets/images/profiles/' + student.profile_picture
                    : null
            }
        });
        gateIdCard.render();

        addToScanLog(student, data.timestamp, mark, created);
        setTimeout(resetScanDisplay, created ? 8000 : (mark === 2 ? 5000 : 4000));
    }

    function displayLostCardScan(data, uid) {
        const student = data.student || {};
        const lostInfo = data.lost_info || {};
        const lostDate = lostInfo.lost_at ? new Date(lostInfo.lost_at).toLocaleString() : 'Unknown';

        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in">
                <div class="bg-orange-50 border-4 border-orange-500 rounded-2xl p-6 md:p-8">
                    <div class="text-5xl md:text-6xl mb-4">🚫</div>
                    <h2 class="text-2xl md:text-3xl font-bold text-orange-800 mb-3 animate-pulse">RFID CARD DISABLED</h2>
                    
                    ${student.name ? `
                    <div class="bg-white rounded-lg p-4 md:p-5 mb-4 border-2 border-orange-300">
                        <p class="text-xl md:text-2xl font-bold text-slate-800 mb-1">${escHtml(student.name)}</p>
                        <p class="text-slate-600 text-sm">ID: ${escHtml(student.student_id || '')}</p>
                    </div>
                    ` : ''}

                    <div class="bg-orange-600 rounded-lg p-4 mb-4 text-white">
                        <p class="font-bold text-xl md:text-2xl mb-1">⚠️ CARD MARKED AS LOST</p>
                        <p class="text-sm mb-1">This RFID card has been disabled by an administrator.</p>
                        <p class="text-xs opacity-80">Disabled on: ${escHtml(lostDate)}</p>
                    </div>

                    <div class="bg-blue-50 border-2 border-blue-400 rounded-lg p-4 mb-3">
                        <p class="text-blue-900 font-bold text-base mb-2">📧 STUDENT MUST CONTACT:</p>
                        <p class="text-blue-800 text-lg font-semibold">Student Services Office</p>
                        <p class="text-blue-700 text-sm">Email: <strong>studentservices@pcu.edu.ph</strong></p>
                    </div>

                    <p class="text-orange-700 font-bold text-sm mt-3">🔒 Entry via this card is NOT permitted until re-enabled</p>
                </div>
            </div>
        `;

        // Add to scan log with lost indicator
        if (student.name) {
            addToScanLog({
                name: student.name,
                student_id: student.student_id || '',
                email: '',
                violation_count: 0,
                severity: 'lost'
            }, data.timestamp || new Date().toISOString(), null, false, 'lost');
        }

        // Keep on screen longer so guard can read the info
        setTimeout(resetScanDisplay, 7000);
    }

    function displayErrorScan(data, uid) {
        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in">
                <div class="bg-red-50 border-4 border-red-500 rounded-2xl p-6 md:p-8">
                    <div class="text-5xl md:text-6xl mb-4">❌</div>
                    <h2 class="text-2xl md:text-3xl font-bold text-red-800 mb-3">UNKNOWN CARD</h2>
                    <p class="text-slate-600 mb-2">This RFID card is not registered</p>
                    <code class="bg-slate-100 px-3 py-2 rounded text-sm md:text-base inline-block mb-3">${escHtml(uid)}</code>
                    <p class="text-red-600 text-sm md:text-base">${escHtml(data.error || 'Access Denied')}</p>
                </div>
            </div>
        `;

        setTimeout(resetScanDisplay, 3000);
    }

    function displayAccessDenied(data) {
        const student = data.student;
        
        document.getElementById('scanStatus').innerHTML = `
            <div class="w-full">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                    <!-- Digital ID Card -->
                    <div id="gateDigitalIdContainer" class="flex justify-center"></div>
                    
                    <!-- Access Denied Info -->
                    <div class="bg-red-50 border-4 border-red-600 rounded-2xl p-6 md:p-8">
                        <div class="text-5xl md:text-6xl mb-4">⛔</div>
                        <h2 class="text-2xl md:text-3xl font-bold text-red-900 mb-3 animate-pulse">ACCESS DENIED</h2>
                        
                        <div class="bg-white rounded-lg p-4 md:p-5 mb-4 border-2 border-red-300">
                            <p class="text-xl md:text-2xl font-bold text-slate-800 mb-2">${escHtml(student.name)}</p>
                            <p class="text-slate-600 mb-1">ID: ${escHtml(student.student_id)}</p>
                            ${student.course ? `<p class="text-slate-500 text-sm mb-1">${escHtml(student.course)}</p>` : ''}
                            <p class="text-slate-500 text-sm">${escHtml(student.email)}</p>
                        </div>
                        <div class="bg-red-600 rounded-lg p-4 mb-4 text-white">
                            <p class="font-bold text-xl md:text-2xl mb-1">🚫 ENTRY NOT ALLOWED</p>
                            <p class="text-sm">${escHtml(data.message || 'Student has unresolved SSO compliance. Entry remains blocked until the case is cleared.')}</p>
                            ${Number(data.sso_hold_count || 0) > 0 ? `<p class="text-xs mt-2 opacity-90">Open SSO compliance cases: ${escHtml(String(data.sso_hold_count))}</p>` : ''}
                        </div>
                        <div class="bg-yellow-100 border-2 border-yellow-500 rounded-lg p-4 mb-3">
                            <p class="text-yellow-900 font-bold text-sm mb-1">⚠️ ACTION REQUIRED</p>
                            <p class="text-yellow-800 text-sm">Refer student to the SSO Office for violation details and resolution</p>
                        </div>
                        <p class="text-red-700 font-bold text-sm mt-3 animate-pulse">🔒 STUDENT MUST CONTACT SSO OFFICE</p>
                    </div>
                </div>
            </div>
        `;

        // Render Digital ID Card for denied student
        const deniedIdCard = new DigitalIdCard('#gateDigitalIdContainer', {
            templateSrc: '../assets/images/id-card-template.png',
            student: {
                name: student.name || '',
                studentId: student.student_id || '',
                course: student.course || '',
                email: student.email || '',
                profilePicture: student.profile_picture
                    ? '../assets/images/profiles/' + student.profile_picture
                    : null
            }
        });
        deniedIdCard.render();

        // Add to log
        addToScanLog(student, data.timestamp || new Date().toISOString(), null, false, 'blocked');

        // Keep on screen long enough for guard to read both ID and denial info
        setTimeout(resetScanDisplay, 7000);
    }

    function resetScanDisplay() {
        document.getElementById('scanStatus').innerHTML = `
            <div class="fade-in max-w-md mx-auto">
                <div class="mb-4 relative inline-block">
                    <div class="pulse-ring absolute inset-0 bg-sky-300 rounded-full opacity-20 scale-[1.18]"></div>
                    <div class="rfid-ready-mark">
                        <img src="../assets/images/gatewatch-logo.png" alt="GateWatch Logo" class="rfid-ready-logo">
                    </div>
                </div>
                <h2 class="text-2xl md:text-3xl font-bold text-slate-700 mb-2">Ready to Scan</h2>
                <p class="text-slate-500 text-sm md:text-base">Hold RFID card near scanner to verify student entry</p>
                <p class="text-slate-400 text-xs md:text-sm mt-2">System Active • Waiting for card...</p>
            </div>
        `;
    }

    let qrStream = null;
    let qrDetector = null;
    let qrScannerRunning = false;
    let qrScanLoopTimer = null;
    let qrRequestInFlight = false;
    let qrDecodeInFlight = false;
    let qrLastToken = '';
    let qrLastTokenAt = 0;
    let qrLastTokenScope = '';
    let qrReader = null;
    let qrDecoderMode = null;
    let qrChallengeId = '';
    let qrChallengeExpiresAtMs = 0;
    let qrChallengeTicker = null;
    let qrPendingFaceUserId = null;
    let qrPendingFaceStudentId = '';
    let qrPendingFaceExpiresAtMs = 0;
    let qrFocusAssistTimer = null;
    let qrFocusInFlight = false;
    let qrLoopLastRunAt = 0;
    let qrVideoReadySinceMs = 0;
    const QR_SCAN_MIN_INTERVAL_MS = 33;
    const QR_DUPLICATE_SUPPRESS_MS = 1800;
    let qrScannerState = 'idle';
    let qrMetrics = {
        sessionStartedAt: 0,
        firstDecodeMs: null,
        lastDecodeMs: 0,
        lastVerifyMs: 0,
        acceptedCount: 0,
        rejectedCount: 0
    };

    function setQrScannerState(nextState, details = null) {
        if (qrScannerState === nextState && !details) return;
        qrScannerState = nextState;
        if (QR_DEBUG_METRICS) {
            console.debug('[QR_STATE]', nextState, details || {});
        }
    }

    function resetQrMetrics() {
        qrMetrics = {
            sessionStartedAt: Date.now(),
            firstDecodeMs: null,
            lastDecodeMs: 0,
            lastVerifyMs: 0,
            acceptedCount: 0,
            rejectedCount: 0
        };
    }

    function noteQrDecodeLatency(latencyMs) {
        qrMetrics.lastDecodeMs = Math.round(latencyMs);
        if (qrMetrics.firstDecodeMs === null && qrMetrics.sessionStartedAt > 0) {
            qrMetrics.firstDecodeMs = Math.round(Date.now() - qrMetrics.sessionStartedAt);
            if (QR_DEBUG_METRICS) {
                console.debug('[QR_METRIC] first_decode_ms=', qrMetrics.firstDecodeMs);
            }
        }
    }

    function noteQrVerificationLatency(latencyMs, wasAccepted) {
        qrMetrics.lastVerifyMs = Math.round(latencyMs);
        if (wasAccepted) {
            qrMetrics.acceptedCount += 1;
        } else {
            qrMetrics.rejectedCount += 1;
        }

        if (QR_DEBUG_METRICS) {
            console.debug('[QR_METRIC]', {
                verifyMs: qrMetrics.lastVerifyMs,
                decodeMs: qrMetrics.lastDecodeMs,
                firstDecodeMs: qrMetrics.firstDecodeMs,
                accepted: qrMetrics.acceptedCount,
                rejected: qrMetrics.rejectedCount
            });
        }
    }

    function getActiveQrVideoTrack() {
        if (qrStream && typeof qrStream.getVideoTracks === 'function') {
            const streamTrack = qrStream.getVideoTracks()[0];
            if (streamTrack && streamTrack.readyState === 'live') {
                return streamTrack;
            }
        }

        const video = document.getElementById('qrVideo');
        const videoStream = video && video.srcObject;
        if (videoStream && typeof videoStream.getVideoTracks === 'function') {
            const videoTrack = videoStream.getVideoTracks()[0];
            if (videoTrack && videoTrack.readyState === 'live') {
                return videoTrack;
            }
        }

        return null;
    }

    async function applyTrackConstraintsSafe(track, constraints) {
        if (!track || typeof track.applyConstraints !== 'function') return false;
        try {
            await track.applyConstraints(constraints);
            return true;
        } catch (e) {
            return false;
        }
    }

    function waitQrFocus(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    function setQrFocusButtonBusy(isBusy) {
        const btn = document.getElementById('btnQrRefocus');
        if (!btn) return;
        btn.disabled = !!isBusy;
        btn.textContent = isBusy ? 'Focusing...' : 'Focus';
        btn.classList.toggle('opacity-70', !!isBusy);
        btn.classList.toggle('cursor-wait', !!isBusy);
    }

    function trackSupportsFocusPulse(track) {
        if (!track || typeof track.getCapabilities !== 'function') return false;
        const caps = track.getCapabilities() || {};
        if (Array.isArray(caps.focusMode) && caps.focusMode.includes('single-shot')) {
            return true;
        }
        if (Array.isArray(caps.focusMode) && caps.focusMode.includes('manual')) {
            return true;
        }
        return false;
    }

    async function optimizeQrCameraFocus(track, useFocusPulse) {
        if (!track) return false;

        const caps = (typeof track.getCapabilities === 'function') ? (track.getCapabilities() || {}) : {};
        let changed = false;

        if (Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
            changed = (await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'continuous' }] })) || changed;
        }

        if (useFocusPulse && Array.isArray(caps.focusMode) && caps.focusMode.includes('single-shot')) {
            changed = (await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'single-shot' }] })) || changed;
            if (Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
                await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'continuous' }] });
            }
        }

        if (useFocusPulse && Array.isArray(caps.focusMode) && caps.focusMode.includes('manual')) {
            const focusDistanceCaps = caps.focusDistance || null;
            if (focusDistanceCaps && typeof focusDistanceCaps.min === 'number' && typeof focusDistanceCaps.max === 'number') {
                const minFd = Number(focusDistanceCaps.min);
                const maxFd = Number(focusDistanceCaps.max);
                const nearFd = minFd + ((maxFd - minFd) * 0.28);
                const midFd = minFd + ((maxFd - minFd) * 0.5);

                changed = (await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'manual', focusDistance: nearFd }] })) || changed;
                changed = (await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'manual', focusDistance: midFd }] })) || changed;
            } else {
                changed = (await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'manual' }] })) || changed;
            }

            if (Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
                await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'continuous' }] });
            }
        }

        if (Array.isArray(caps.exposureMode) && caps.exposureMode.includes('continuous')) {
            changed = (await applyTrackConstraintsSafe(track, { advanced: [{ exposureMode: 'continuous' }] })) || changed;
        }

        if (Array.isArray(caps.whiteBalanceMode) && caps.whiteBalanceMode.includes('continuous')) {
            changed = (await applyTrackConstraintsSafe(track, { advanced: [{ whiteBalanceMode: 'continuous' }] })) || changed;
        }

        if (caps.zoom && typeof caps.zoom.min === 'number' && typeof caps.zoom.max === 'number') {
            const minZoom = Number(caps.zoom.min);
            const maxZoom = Number(caps.zoom.max);
            if (Number.isFinite(minZoom) && Number.isFinite(maxZoom) && maxZoom > minZoom) {
                const preferred = Math.min(maxZoom, Math.max(minZoom, 1.15));
                changed = (await applyTrackConstraintsSafe(track, { advanced: [{ zoom: preferred }] })) || changed;
            }
        }

        return changed;
    }

    async function runSlowManualQrRefocus(track) {
        if (!track) return false;

        const caps = (typeof track.getCapabilities === 'function') ? (track.getCapabilities() || {}) : {};
        let changed = false;

        if (Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
            setQrStatus('Focus step 1/3: locking lens...', false);
            changed = (await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'continuous' }] })) || changed;
            await waitQrFocus(260);
        }

        if (Array.isArray(caps.focusMode) && caps.focusMode.includes('single-shot')) {
            setQrStatus('Focus step 2/3: fine-tuning QR edges...', false);
            changed = (await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'single-shot' }] })) || changed;
            await waitQrFocus(320);

            if (Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
                changed = (await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'continuous' }] })) || changed;
            }
            await waitQrFocus(220);
        }

        if (Array.isArray(caps.focusMode) && caps.focusMode.includes('manual')) {
            const focusDistanceCaps = caps.focusDistance || null;
            if (focusDistanceCaps && typeof focusDistanceCaps.min === 'number' && typeof focusDistanceCaps.max === 'number') {
                const minFd = Number(focusDistanceCaps.min);
                const maxFd = Number(focusDistanceCaps.max);
                const nearFd = minFd + ((maxFd - minFd) * 0.3);
                const midFd = minFd + ((maxFd - minFd) * 0.5);

                setQrStatus('Focus step 3/3: stabilizing image...', false);
                changed = (await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'manual', focusDistance: nearFd }] })) || changed;
                await waitQrFocus(260);
                changed = (await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'manual', focusDistance: midFd }] })) || changed;
                await waitQrFocus(240);

                if (Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
                    changed = (await applyTrackConstraintsSafe(track, { advanced: [{ focusMode: 'continuous' }] })) || changed;
                }
                await waitQrFocus(180);
            }
        }

        return changed;
    }

    async function hardRefocusQrCamera() {
        if (!qrScannerRunning) return false;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return false;
        if (qrDecoderMode !== 'barcode-detector') return false;

        const video = document.getElementById('qrVideo');
        if (!video) return false;

        const previousStream = qrStream;
        let newStream = null;
        const baseVideoConstraints = {
            facingMode: { ideal: 'environment' },
            width: { ideal: 1920 },
            height: { ideal: 1080 },
            frameRate: { ideal: 24, max: 30 }
        };

        try {
            newStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    ...baseVideoConstraints,
                    advanced: [{ focusMode: 'continuous' }]
                },
                audio: false
            });

            qrStream = newStream;
            video.srcObject = newStream;
            await video.play();

            if (previousStream && previousStream !== newStream) {
                previousStream.getTracks().forEach(track => track.stop());
            }

            await initQrCameraFocusControls();
            return true;
        } catch (e) {
            if (!newStream) {
                try {
                    newStream = await navigator.mediaDevices.getUserMedia({
                        video: baseVideoConstraints,
                        audio: false
                    });

                    qrStream = newStream;
                    video.srcObject = newStream;
                    await video.play();

                    if (previousStream && previousStream !== newStream) {
                        previousStream.getTracks().forEach(track => track.stop());
                    }

                    await initQrCameraFocusControls();
                    return true;
                } catch (_) {
                }
            }

            if (newStream) {
                newStream.getTracks().forEach(track => track.stop());
            }
            return false;
        }
    }

    function scheduleQrFocusAssist() {
        if (qrFocusAssistTimer) {
            clearInterval(qrFocusAssistTimer);
            qrFocusAssistTimer = null;
        }

        qrFocusAssistTimer = setInterval(() => {
            if (!qrScannerRunning || qrRequestInFlight || qrFocusInFlight) return;
            const track = getActiveQrVideoTrack();
            if (!track || !trackSupportsFocusPulse(track)) return;

            qrFocusInFlight = true;
            optimizeQrCameraFocus(track, true)
                .catch(() => {})
                .finally(() => {
                    qrFocusInFlight = false;
                });
        }, 3500);
    }

    async function initQrCameraFocusControls() {
        const track = getActiveQrVideoTrack();
        if (!track) return false;

        await optimizeQrCameraFocus(track, true);
        scheduleQrFocusAssist();
        return true;
    }

    async function refocusQrCamera(isManual) {
        if (!qrScannerRunning) return false;
        if (qrFocusInFlight) {
            if (isManual) {
                setQrStatus('Refocus is already running. Hold the QR steady for a moment.', false);
            }
            return false;
        }

        const track = getActiveQrVideoTrack();
        if (!track) {
            if (isManual) {
                setQrStatus('Camera not ready yet. Try refocus again in a moment.', true);
            }
            return false;
        }

        qrFocusInFlight = true;
        try {
            if (isManual) {
                setQrFocusButtonBusy(true);
                setQrStatus('Refocusing... hold the QR steady for about 2 seconds.', false);
            }

            let focused = isManual
                ? await runSlowManualQrRefocus(track)
                : await optimizeQrCameraFocus(track, true);

            if (!focused) {
                if (isManual) {
                    setQrStatus('Trying deep refocus... keep camera steady.', false);
                }
                focused = await hardRefocusQrCamera();
            }
            if (isManual) {
                if (focused) {
                    setQrStatus('Focus complete. Keep QR steady and centered for scan.', false);
                } else {
                    setQrStatus('Camera focus controls are limited on this device. Move QR 15-25cm away and hold steady.', true);
                }
            }
            return focused;
        } finally {
            if (isManual) {
                setQrFocusButtonBusy(false);
            }
            qrFocusInFlight = false;
        }
    }

    function updateQrPendingResetBtn() {
        const btn = document.getElementById('btnResetQrPending');
        if (!btn) return;
        btn.classList.toggle('hidden', !(QR_FACE_BINDING_ENABLED && qrPendingFaceUserId));
    }

    async function resetQrPendingState() {
        if (!QR_FACE_BINDING_ENABLED) return;
        try {
            const response = await fetch('qr_pending_reset.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({ csrf_token: CSRF_TOKEN })
            });
            const data = await response.json();

            qrPendingFaceUserId = null;
            qrPendingFaceStudentId = '';
            qrPendingFaceExpiresAtMs = 0;
            updateQrPendingResetBtn();

            if (data && data.success) {
                setQrStatus(data.message || 'Pending QR state cleared.', false);
                displayQrVerificationFailure({
                    error: 'QR_PENDING_CLEARED',
                    message: data.message || 'Pending QR state cleared.'
                });
            } else {
                setQrStatus((data && data.message) || 'Unable to clear pending QR state.', true);
            }
        } catch (err) {
            console.error('Pending reset failed:', err);
            setQrStatus('Unable to clear pending QR state right now.', true);
        }
    }

    function updateModeButtonStates(mode) {
        const btnRfid = document.getElementById('btnRfidMode');
        const btnQr = document.getElementById('btnQrMode');
        const btnFace = document.getElementById('btnFaceMode');
        const activeCls = 'flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-sky-600 text-white shadow-md';
        const inactiveCls = 'flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-white/80 text-slate-600 border border-white/60 hover:bg-white';

        if (btnRfid) btnRfid.className = mode === 'rfid' ? activeCls : inactiveCls;
        if (btnQr) btnQr.className = mode === 'qr' ? activeCls : inactiveCls;
        if (btnFace) btnFace.className = mode === 'face' ? activeCls : inactiveCls;
    }

    function switchMode(mode) {
        if (typeof currentMode !== 'undefined') {
            currentMode = mode;
        }

        const rfidPanel = document.getElementById('rfidPanel');
        const qrPanel = document.getElementById('qrPanel');
        const facePanel = document.getElementById('facePanel');

        if (rfidPanel) rfidPanel.classList.add('hidden');
        if (qrPanel) qrPanel.classList.add('hidden');
        if (facePanel) facePanel.classList.add('hidden');

        if (mode === 'rfid') {
            if (rfidPanel) rfidPanel.classList.remove('hidden');
            stopQrScanner();
            if (FACE_FEATURE_ENABLED && typeof stopFaceDetection === 'function') {
                stopFaceDetection();
                if (typeof stopRecognitionKeepAlive === 'function') stopRecognitionKeepAlive();
                if (typeof stopFaceAutoRefresh === 'function') stopFaceAutoRefresh();
                if (typeof faceSystem !== 'undefined' && faceSystem && typeof faceSystem.stopCamera === 'function') {
                    faceSystem.stopCamera();
                    cameraRunning = false;
                }
                if (typeof stopFrameBroadcast === 'function') stopFrameBroadcast();
            }
            document.body.focus();
        } else if (mode === 'qr') {
            if (qrPanel) qrPanel.classList.remove('hidden');
            if (FACE_FEATURE_ENABLED && typeof stopFaceDetection === 'function') {
                stopFaceDetection();
                if (typeof stopRecognitionKeepAlive === 'function') stopRecognitionKeepAlive();
                if (typeof stopFaceAutoRefresh === 'function') stopFaceAutoRefresh();
                if (typeof faceSystem !== 'undefined' && faceSystem && typeof faceSystem.stopCamera === 'function') {
                    faceSystem.stopCamera();
                    cameraRunning = false;
                }
                if (typeof stopFrameBroadcast === 'function') stopFrameBroadcast();
            }
        } else if (mode === 'face' && FACE_FEATURE_ENABLED) {
            if (facePanel) facePanel.classList.remove('hidden');
            stopQrScanner();
            if (typeof initFaceRecognition === 'function' && typeof faceInitialized !== 'undefined') {
                if (!faceInitialized) {
                    if (typeof populateCameraSelector === 'function') populateCameraSelector();
                    initFaceRecognition();
                } else if (typeof restartCamera === 'function' && typeof cameraRunning !== 'undefined' && !cameraRunning) {
                    restartCamera();
                }
            }
        }

        updateModeButtonStates(mode);
    }

    function isLikelyDigitalIdJwt(rawValue) {
        const token = (rawValue || '').trim();
        if (token.length < 64 || token.length > 4096) return false;
        return /^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(token);
    }

    function setQrStatus(msg, isError) {
        const status = document.getElementById('qrStatus');
        if (!status) return;
        status.textContent = msg;
        status.className = isError ? 'text-sm text-red-600 mt-3 font-medium' : 'text-sm text-slate-600 mt-3';
    }

    function renderQrChallengeMeta() {
        const meta = document.getElementById('qrChallengeMeta');
        if (!meta) return;
        if (!QR_CHALLENGE_ENABLED) {
            meta.classList.add('hidden');
            meta.textContent = '';
            return;
        }

        if (QR_FACE_BINDING_ENABLED && qrPendingFaceUserId) {
            const pendingSec = Math.max(0, Math.ceil((qrPendingFaceExpiresAtMs - Date.now()) / 1000));
            meta.classList.remove('hidden');
            meta.textContent = pendingSec > 0
                ? ('Pending face confirmation for ' + escHtml(qrPendingFaceStudentId || 'student') + ' (' + pendingSec + 's left)')
                : 'Pending face confirmation expired. Scan a new QR.';
            return;
        }

        const remainingMs = Math.max(0, qrChallengeExpiresAtMs - Date.now());
        const remainingSec = Math.ceil(remainingMs / 1000);
        if (!qrChallengeId || remainingSec <= 0) {
            meta.classList.remove('hidden');
            meta.textContent = 'Challenge: refreshing...';
            return;
        }

        meta.classList.remove('hidden');
        meta.textContent = 'Challenge active (' + remainingSec + 's left)';
    }

    function startQrChallengeTicker() {
        if (!QR_CHALLENGE_ENABLED) return;
        if (qrChallengeTicker) {
            clearInterval(qrChallengeTicker);
        }
        qrChallengeTicker = setInterval(() => {
            renderQrChallengeMeta();
        }, 1000);
        renderQrChallengeMeta();
    }

    function stopQrChallengeTicker() {
        if (qrChallengeTicker) {
            clearInterval(qrChallengeTicker);
            qrChallengeTicker = null;
        }
    }

    async function issueQrChallenge(force) {
        if (!QR_CHALLENGE_ENABLED) return true;

        const now = Date.now();
        // Re-issue when less than 10s remains to avoid race-to-expiry scans.
        if (!force && qrChallengeId && qrChallengeExpiresAtMs > now + 10000) {
            renderQrChallengeMeta();
            return true;
        }

        try {
            setQrScannerState('challenge_issuing', { force: !!force });
            const previousChallengeId = qrChallengeId;
            const response = await fetch('qr_challenge.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({ action: 'issue', force_new: !!force, csrf_token: CSRF_TOKEN })
            });
            const data = await response.json();

            if (!data.success || data.enabled === false || !data.challenge_id || !data.expires_at) {
                throw new Error(data.error || 'challenge_issue_failed');
            }

            qrChallengeId = String(data.challenge_id || '').trim();
            qrChallengeExpiresAtMs = new Date(data.expires_at.replace(' ', 'T')).getTime();
            if (!Number.isFinite(qrChallengeExpiresAtMs) || qrChallengeExpiresAtMs <= now) {
                qrChallengeExpiresAtMs = now + 10000;
            }

            if (previousChallengeId && previousChallengeId !== qrChallengeId) {
                qrLastToken = '';
                qrLastTokenAt = 0;
                qrLastTokenScope = '';
            }

            renderQrChallengeMeta();
            setQrScannerState('challenge_ready', { reused: !!data.reused, expires_at: data.expires_at || null });
            return true;
        } catch (err) {
            console.error('QR challenge issue failed:', err);
            qrChallengeId = '';
            qrChallengeExpiresAtMs = 0;
            renderQrChallengeMeta();
            setQrScannerState('challenge_error');
            return false;
        }
    }

    async function startQrScanner() {
        if (!QR_FEATURE_ENABLED || qrScannerRunning) return;
        setQrScannerState('starting');
        if (!window.isSecureContext) {
            setQrStatus('QR scanner requires secure context (HTTPS or localhost).', true);
            setQrScannerState('error');
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setQrStatus('Camera API is not available in this browser.', true);
            setQrScannerState('error');
            return;
        }

        if (!('BarcodeDetector' in window)) {
            if (window.ZXing && window.ZXing.BrowserQRCodeReader) {
                await startQrScannerWithZxing();
                return;
            }
            setQrStatus('This browser does not support QR detection. Use latest Chrome/Edge.', true);
            return;
        }

        try {
            if (QR_FACE_BINDING_ENABLED && FACE_FEATURE_ENABLED && typeof startFaceEnginePreload === 'function') {
                startFaceEnginePreload();
            }

            if (QR_CHALLENGE_ENABLED) {
                const challengeReady = await issueQrChallenge(true);
                if (!challengeReady) {
                    setQrStatus('Unable to start gate challenge. Please retry scanner start.', true);
                    return;
                }
                startQrChallengeTicker();
            }

            const supportedFormats = await BarcodeDetector.getSupportedFormats();
            if (!supportedFormats.includes('qr_code')) {
                if (window.ZXing && window.ZXing.BrowserQRCodeReader) {
                    await startQrScannerWithZxing();
                    return;
                }
                setQrStatus('QR format is not supported by this browser barcode engine.', true);
                return;
            }

            qrDecoderMode = 'barcode-detector';
            qrDetector = new BarcodeDetector({ formats: ['qr_code'] });
            qrStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1920 },
                    height: { ideal: 1080 },
                    frameRate: { ideal: 24, max: 30 },
                    advanced: [{ focusMode: 'continuous' }]
                },
                audio: false
            });

            const video = document.getElementById('qrVideo');
            video.srcObject = qrStream;
            await video.play();
            video.onclick = () => {
                refocusQrCamera(true);
            };
            await initQrCameraFocusControls();

            qrScannerRunning = true;
            qrDecodeInFlight = false;
            qrLoopLastRunAt = 0;
            qrVideoReadySinceMs = 0;
            qrLastToken = '';
            qrLastTokenAt = 0;
            qrLastTokenScope = '';
            resetQrMetrics();
            document.getElementById('btnStartQr')?.classList.add('hidden');
            document.getElementById('btnStopQr')?.classList.remove('hidden');
            document.getElementById('btnQrRefocus')?.classList.remove('hidden');
            document.getElementById('qrScanLine')?.classList.remove('hidden');
            setQrStatus('Scanner active. Align the student digital ID QR code in frame. Tap Focus if image becomes blurry.', false);
            setQrScannerState('scanning');
            runQrScanLoop();
        } catch (err) {
            console.error('QR scanner start failed:', err);

            if (window.ZXing && window.ZXing.BrowserQRCodeReader) {
                await startQrScannerWithZxing();
                return;
            }

            stopQrScanner();
            setQrStatus('Unable to access camera. Check camera permission and try again.', true);
            setQrScannerState('error');
        }
    }

    async function startQrScannerWithZxing() {
        try {
            setQrScannerState('starting');
            if (QR_FACE_BINDING_ENABLED && FACE_FEATURE_ENABLED && typeof startFaceEnginePreload === 'function') {
                startFaceEnginePreload();
            }

            if (QR_CHALLENGE_ENABLED) {
                const challengeReady = await issueQrChallenge(true);
                if (!challengeReady) {
                    setQrStatus('Unable to start gate challenge. Please retry scanner start.', true);
                    return;
                }
                startQrChallengeTicker();
            }

            qrDecoderMode = 'zxing';
            const video = document.getElementById('qrVideo');
            qrReader = new ZXing.BrowserQRCodeReader();
            qrScannerRunning = true;
            qrLastToken = '';
            qrLastTokenAt = 0;
            qrLastTokenScope = '';
            resetQrMetrics();
            document.getElementById('btnStartQr')?.classList.add('hidden');
            document.getElementById('btnStopQr')?.classList.remove('hidden');
            document.getElementById('btnQrRefocus')?.classList.remove('hidden');
            document.getElementById('qrScanLine')?.classList.remove('hidden');
            setQrStatus('Scanner active (compatibility mode). Align the student digital ID QR code in frame.', false);
            setQrScannerState('scanning', { decoder: 'zxing' });

            video.onclick = () => {
                refocusQrCamera(true);
            };

            setTimeout(() => {
                initQrCameraFocusControls().catch(() => {});
            }, 450);

            await qrReader.decodeFromVideoDevice(undefined, video, async (result, err) => {
                if (!qrScannerRunning || qrRequestInFlight) return;
                if (result && typeof result.getText === 'function') {
                    const token = (result.getText() || '').trim();
                    await handleQrRawToken(token);
                }
                if (err && !(err instanceof ZXing.NotFoundException)) {
                    console.warn('ZXing scan warning:', err);
                }
            });
        } catch (err) {
            console.error('ZXing scanner start failed:', err);
            stopQrScanner();
            setQrStatus('Unable to start camera scanner. Check permission and retry.', true);
            setQrScannerState('error');
        }
    }

    async function handleQrRawToken(rawValue) {
        const token = (rawValue || '').trim();
        if (token === '') return;

        if (isLikelyDigitalIdJwt(token)) {
            const now = Date.now();
            const tokenScope = (QR_CHALLENGE_ENABLED ? (qrChallengeId || 'challenge-none') : 'challenge-off')
                + '|' + (qrPendingFaceUserId ? ('pending-' + String(qrPendingFaceUserId)) : 'pending-none');
            if (!(token === qrLastToken && tokenScope === qrLastTokenScope && (now - qrLastTokenAt) < QR_DUPLICATE_SUPPRESS_MS)) {
                qrLastToken = token;
                qrLastTokenAt = now;
                qrLastTokenScope = tokenScope;
                await verifyStudentQrToken(token);
            }
        } else {
            displayQrRejectedFormat();
        }
    }

    function stopQrScanner() {
        qrScannerRunning = false;
        qrRequestInFlight = false;
        qrDecodeInFlight = false;
        qrLoopLastRunAt = 0;
        qrVideoReadySinceMs = 0;
        qrLastToken = '';
        qrLastTokenAt = 0;
        qrLastTokenScope = '';
        if (qrScanLoopTimer) {
            cancelAnimationFrame(qrScanLoopTimer);
            qrScanLoopTimer = null;
        }

        if (qrReader) {
            try {
                qrReader.reset();
            } catch (e) {
            }
            qrReader = null;
        }

        qrDetector = null;
        qrDecoderMode = null;

        const video = document.getElementById('qrVideo');
        if (video) {
            video.pause();
            video.srcObject = null;
        }

        if (qrStream) {
            qrStream.getTracks().forEach(track => track.stop());
            qrStream = null;
        }

        document.getElementById('btnStartQr')?.classList.remove('hidden');
        document.getElementById('btnStopQr')?.classList.add('hidden');
        document.getElementById('btnQrRefocus')?.classList.add('hidden');
        document.getElementById('qrScanLine')?.classList.add('hidden');
        if (qrFocusAssistTimer) {
            clearInterval(qrFocusAssistTimer);
            qrFocusAssistTimer = null;
        }
        if (video) {
            video.onclick = null;
        }
        qrChallengeId = '';
        qrChallengeExpiresAtMs = 0;
        stopQrChallengeTicker();
        renderQrChallengeMeta();
        qrPendingFaceUserId = null;
        qrPendingFaceStudentId = '';
        qrPendingFaceExpiresAtMs = 0;
        updateQrPendingResetBtn();
        setQrScannerState('idle');
    }

    async function runQrScanLoop() {
        if (!qrScannerRunning) return;

        const video = document.getElementById('qrVideo');
        if (!video || !qrDetector || video.readyState < 2 || !video.videoWidth || !video.videoHeight) {
            qrVideoReadySinceMs = 0;
            qrScanLoopTimer = requestAnimationFrame(runQrScanLoop);
            return;
        }

        if (!qrVideoReadySinceMs) {
            qrVideoReadySinceMs = Date.now();
            qrScanLoopTimer = requestAnimationFrame(runQrScanLoop);
            return;
        }

        if ((Date.now() - qrVideoReadySinceMs) < 120) {
            qrScanLoopTimer = requestAnimationFrame(runQrScanLoop);
            return;
        }

        const nowPerf = performance.now();
        if ((nowPerf - qrLoopLastRunAt) < QR_SCAN_MIN_INTERVAL_MS) {
            qrScanLoopTimer = requestAnimationFrame(runQrScanLoop);
            return;
        }

        try {
            if (!qrRequestInFlight && !qrDecodeInFlight) {
                qrDecodeInFlight = true;
                setQrScannerState('decoding');
                const decodeStartedAt = performance.now();
                const results = await qrDetector.detect(video);
                noteQrDecodeLatency(performance.now() - decodeStartedAt);
                if (results && results.length > 0) {
                    const token = ((results[0] && results[0].rawValue) || '').trim();
                    await handleQrRawToken(token);
                }
            }
        } catch (err) {
            console.warn('QR detect loop warning:', err);
        } finally {
            qrDecodeInFlight = false;
            qrLoopLastRunAt = performance.now();
            if (qrScannerRunning && !qrRequestInFlight) {
                setQrScannerState('scanning');
            }
        }

        qrScanLoopTimer = requestAnimationFrame(runQrScanLoop);
    }

    function displayQrRejectedFormat() {
        const resultEl = document.getElementById('qrResult');
        if (!resultEl) return;
        resultEl.innerHTML = `
            <div class="w-full bg-amber-50 border border-amber-300 rounded-xl p-4 text-center">
                <p class="text-amber-900 font-semibold">Unsupported QR Code</p>
                <p class="text-amber-700 text-sm mt-1">Only official GateWatch student digital ID QR codes are accepted.</p>
            </div>
        `;
    }

    async function verifyStudentQrToken(token) {
        qrRequestInFlight = true;
        setQrStatus('Validating QR token with server...', false);
        setQrScannerState('verifying');
        const verifyStartedAt = performance.now();

        try {
            if (QR_CHALLENGE_ENABLED) {
                const challengeReady = await issueQrChallenge(false);
                if (!challengeReady || !qrChallengeId) {
                    displayQrVerificationFailure({ error: 'QR_CHALLENGE_REQUIRED', message: 'Challenge unavailable. Generate a fresh challenge and rescan.' });
                    setQrStatus('Challenge unavailable. Regenerate challenge and rescan.', true);
                    noteQrVerificationLatency(performance.now() - verifyStartedAt, false);
                    setQrScannerState('waiting_refresh');
                    return;
                }
            }

            const response = await fetch('qr_scan.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({
                    token: token,
                    challenge_id: QR_CHALLENGE_ENABLED ? qrChallengeId : undefined,
                    csrf_token: CSRF_TOKEN
                })
            });
            const data = await response.json();

            if (isAwaitingViolationChoice(data)) {
                qrPendingFaceUserId = null;
                qrPendingFaceStudentId = '';
                qrPendingFaceExpiresAtMs = 0;
                updateQrPendingResetBtn();
                showAwaitingViolationHint(data);
                openViolationChooser(data);
                noteQrVerificationLatency(performance.now() - verifyStartedAt, true);
                setQrScannerState('accepted');
            } else if (data.success && data.verified && data.student) {
                qrPendingFaceUserId = null;
                qrPendingFaceStudentId = '';
                qrPendingFaceExpiresAtMs = 0;
                updateQrPendingResetBtn();
                displayQrVerificationSuccess(data);
                addToScanLog(
                    data.student,
                    data.timestamp || new Date().toISOString(),
                    data.gate_mark || data.student.gate_mark || 1,
                    !!data.violation_created
                );
                if (data.violation_created) {
                    setQrStatus('Violation created after 3rd scan. Ready for next student scan.', true);
                } else if ((data.gate_mark || data.student.gate_mark || 1) >= 3) {
                    setQrStatus('Formal violation already pending. Marks reset only after admin resolves all pending reparations.', true);
                } else if ((data.gate_mark || data.student.gate_mark || 1) === 2) {
                    setQrStatus('2nd mark recorded. One more scan creates a formal violation.', false);
                } else {
                    setQrStatus('1st mark recorded. Ready for next student scan.', false);
                }
                if (QR_CHALLENGE_ENABLED) {
                    await issueQrChallenge(true);
                }
                noteQrVerificationLatency(performance.now() - verifyStartedAt, true);
                setQrScannerState('accepted');
            } else if (data.success && data.qr_pending_face && data.student) {
                qrPendingFaceUserId = Number(data.student.id || 0) || null;
                qrPendingFaceStudentId = String(data.student.student_id || '');
                if (data.pending_expires_at) {
                    const pendingMs = new Date(String(data.pending_expires_at).replace(' ', 'T')).getTime();
                    qrPendingFaceExpiresAtMs = Number.isFinite(pendingMs) ? pendingMs : (Date.now() + 15000);
                } else {
                    qrPendingFaceExpiresAtMs = Date.now() + 15000;
                }
                updateQrPendingResetBtn();

                displayQrPendingFace(data);
                addToScanLog(
                    data.student,
                    data.timestamp || new Date().toISOString(),
                    null,
                    false,
                    'qr_pending_face'
                );

                setQrStatus('QR accepted. Switch to face camera and confirm the same student.', false);
                noteQrVerificationLatency(performance.now() - verifyStartedAt, true);
                setQrScannerState('pending_face');

                if (!FACE_FEATURE_ENABLED || QR_FACE_BINDING_STRICT === true && typeof startFaceDetection !== 'function') {
                    setQrStatus('Strict mode: face confirmation is required, but face mode is unavailable.', true);
                    return;
                }

                if (FACE_FEATURE_ENABLED) {
                    if (typeof beginQrFaceConfirmationFlow === 'function') {
                        beginQrFaceConfirmationFlow();
                    } else {
                        switchMode('face');
                        if (typeof startFaceDetection === 'function') {
                            startFaceDetection();
                        }
                    }
                }
            } else if (data.access_denied) {
                displayQrAccessDenied(data);
                addToScanLog(data.student || {}, data.timestamp || new Date().toISOString(), null, false, 'blocked');
                setQrStatus(data.message || 'Access denied: student has unresolved SSO compliance.', true);
                qrPendingFaceUserId = null;
                qrPendingFaceStudentId = '';
                qrPendingFaceExpiresAtMs = 0;
                updateQrPendingResetBtn();
                if (QR_CHALLENGE_ENABLED) {
                    await issueQrChallenge(true);
                }
                noteQrVerificationLatency(performance.now() - verifyStartedAt, false);
                setQrScannerState('blocked');
            } else {
                displayQrVerificationFailure(data || {});
                if (QR_DEBUG_METRICS && data && data.decision_reason) {
                    console.debug('[QR_DECISION]', data.decision_reason, data);
                }
                if (data && data.qr_challenge_required) {
                    // Keep the same active challenge so student refresh has a stable target.
                    setQrStatus('Challenge required. Keep this scanner open, refresh the student Digital ID once, then rescan.', true);
                    setQrScannerState('waiting_refresh', { reason: data.decision_reason || 'challenge_required' });
                } else if (data && data.qr_expired_challenge) {
                    setQrStatus('Challenge expired. New challenge generated. Ask student to refresh QR.', true);
                    if (QR_CHALLENGE_ENABLED) {
                        await issueQrChallenge(true);
                    }
                    setQrScannerState('waiting_refresh', { reason: data.decision_reason || 'challenge_expired' });
                } else if (data && data.qr_challenge_mismatch) {
                    setQrStatus('Challenge mismatch. New challenge generated. Ask student to refresh QR, then rescan.', true);
                    if (QR_CHALLENGE_ENABLED) {
                        await issueQrChallenge(true);
                    }
                    setQrScannerState('waiting_refresh', { reason: data.decision_reason || 'challenge_mismatch' });
                } else if (data && data.qr_pending_blocked) {
                    setQrStatus('Pending QR already waiting for face confirmation. Complete that first.', true);
                    setQrScannerState('blocked', { reason: 'pending_face_blocked' });
                } else {
                    setQrStatus('Verification failed. Scanner remains active.', true);
                    setQrScannerState('error');
                }
                noteQrVerificationLatency(performance.now() - verifyStartedAt, false);
            }
        } catch (err) {
            console.error('QR verification error:', err);
            displayQrVerificationFailure({ error: 'NETWORK_ERROR', message: 'Network error while verifying QR.' });
            setQrStatus('Network error while verifying. Check connection and retry.', true);
            noteQrVerificationLatency(performance.now() - verifyStartedAt, false);
            setQrScannerState('error');
        } finally {
            qrRequestInFlight = false;
            if (qrScannerRunning && qrScannerState !== 'accepted' && qrScannerState !== 'pending_face' && qrScannerState !== 'blocked' && qrScannerState !== 'waiting_refresh') {
                setQrScannerState('scanning');
            }
        }
    }

    function flashQrSuccessOverlay() {
        const overlay = document.getElementById('qrSuccessOverlay');
        if (!overlay) return;
        overlay.classList.remove('hidden');
        setTimeout(() => overlay.classList.add('hidden'), 1100);
    }

    function displayQrVerificationSuccess(data) {
        const student = data.student || {};
        const mark = data.gate_mark || student.gate_mark || 1;
        const created = !!data.violation_created;
        const resultEl = document.getElementById('qrResult');
        if (!resultEl) return;

        flashQrSuccessOverlay();

        let severityColor, severityBg, severityBorder, headlineText, subText;
        if (created) {
            severityColor = 'red';
            severityBg = 'bg-red-50';
            severityBorder = 'border-red-600';
            headlineText = '3-MARK LIMIT REACHED';
            subText = 'A formal violation has been recorded. Student must report to the SSO Office.';
        } else if (mark >= 3) {
            severityColor = 'orange';
            severityBg = 'bg-orange-50';
            severityBorder = 'border-orange-500';
            headlineText = 'VIOLATION PENDING RESOLUTION';
            subText = 'Formal violation already recorded. Gate marks will reset after admin resolves all pending reparations.';
        } else if (mark === 2) {
            severityColor = 'yellow';
            severityBg = 'bg-yellow-50';
            severityBorder = 'border-yellow-400';
            headlineText = '2ND MARK RECORDED';
            subText = 'Warning: one more scan without physical ID will create a formal violation.';
        } else {
            severityColor = 'green';
            severityBg = 'bg-green-50';
            severityBorder = 'border-green-400';
            headlineText = '1ST MARK RECORDED';
            subText = 'Entry allowed. Remind student to bring their physical ID next time.';
        }

        const markBar = `
            <div class="flex items-center gap-2 mt-3 flex-wrap">
                <span class="text-xs text-slate-500 font-medium">Gate Marks:</span>
                ${[1,2,3].map(i => `
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold border-2 ${
                        i <= (created ? 3 : mark)
                            ? (created && i === 3 ? 'bg-red-500 border-red-600 text-white' : 'bg-amber-400 border-amber-500 text-white')
                            : 'bg-slate-100 border-slate-300 text-slate-400'
                    }">${i}</div>
                `).join('')}
                ${created
                    ? '<span class="text-xs font-bold text-red-600 ml-1">→ Violation Created</span>'
                    : (mark >= 3
                        ? '<span class="text-xs font-bold text-orange-600 ml-1">→ Awaiting Admin Resolution</span>'
                        : `<span class="text-xs text-slate-400 ml-1">${3 - mark} mark${3 - mark !== 1 ? 's' : ''} until violation</span>`)}
            </div>`;

        resultEl.innerHTML = `
            <div class="w-full fade-in">
                <div class="${severityBg} border-2 ${severityBorder} rounded-2xl p-4 mb-4">
                    <p class="text-${severityColor}-800 font-extrabold text-xl">${headlineText}</p>
                    <p class="text-${severityColor}-700 text-sm mt-1">${subText}</p>
                    ${markBar}
                </div>
                <div id="qrDigitalIdContainer" class="flex justify-center"></div>
            </div>
        `;

        const qrIdCard = new DigitalIdCard('#qrDigitalIdContainer', {
            templateSrc: '../assets/images/id-card-template.png',
            student: {
                name: student.name || '',
                studentId: student.student_id || '',
                course: student.course || '',
                email: student.email || '',
                profilePicture: student.profile_picture
                    ? '../assets/images/profiles/' + student.profile_picture
                    : null
            }
        });
        qrIdCard.render();
    }

    function displayQrAccessDenied(data) {
        const student = data.student || {};
        const resultEl = document.getElementById('qrResult');
        if (!resultEl) return;

        resultEl.innerHTML = `
            <div class="w-full bg-red-50 border-2 border-red-600 rounded-2xl p-4 text-center">
                <p class="text-red-900 font-bold text-lg">ACCESS DENIED</p>
                <p class="text-red-700 text-sm mt-1">${escHtml(data.message || 'Student has unresolved SSO compliance and must report to SSO.')}</p>
                ${Number(data.sso_hold_count || 0) > 0 ? `<p class="text-red-700 text-xs mt-1">Open SSO compliance cases: ${escHtml(String(data.sso_hold_count))}</p>` : ''}
                <p class="text-slate-700 text-sm mt-3 font-semibold">${escHtml(student.name || '')}</p>
                <p class="text-slate-500 text-xs">${escHtml(student.student_id || '')}</p>
            </div>
        `;
    }

    function displayQrPendingFace(data) {
        const student = data.student || {};
        const resultEl = document.getElementById('qrResult');
        if (!resultEl) return;

        resultEl.innerHTML = `
            <div class="w-full fade-in">
                <div class="bg-cyan-50 border-2 border-cyan-500 rounded-2xl p-4 mb-4">
                    <p class="text-cyan-900 font-extrabold text-xl">WAITING FOR FACE CONFIRMATION</p>
                    <p class="text-cyan-700 text-sm mt-1">QR accepted. Ask the same student to face the camera now.</p>
                </div>
                <div id="qrDigitalIdContainer" class="flex justify-center"></div>
            </div>
        `;

        const qrIdCard = new DigitalIdCard('#qrDigitalIdContainer', {
            templateSrc: '../assets/images/id-card-template.png',
            student: {
                name: student.name || '',
                studentId: student.student_id || '',
                course: student.course || '',
                email: student.email || '',
                profilePicture: student.profile_picture
                    ? '../assets/images/profiles/' + student.profile_picture
                    : null
            }
        });
        qrIdCard.render();
    }

    function displayQrVerificationFailure(data) {
        const errorCode = String(data.error || 'INVALID_QR');
        const message = escHtml(data.message || 'QR verification failed.');

        let title = 'QR Verification Failed';
        if (errorCode === 'QR_ALREADY_USED') title = 'QR Already Used';
        if (errorCode === 'QR_EXPIRED') title = 'QR Expired';
        if (errorCode === 'QR_PENDING_CLEARED') title = 'Pending State Cleared';
        if (errorCode === 'QR_CHALLENGE_REQUIRED') title = 'Challenge Required';
        if (errorCode === 'QR_EXPIRED_CHALLENGE') title = 'Challenge Expired';
        if (errorCode === 'QR_REJECTED_PROXY') title = 'Challenge Mismatch';
        if (errorCode === 'UNTRUSTED_QR' || errorCode === 'INVALID_QR' || errorCode === 'INVALID_QR_FORMAT') {
            title = 'Untrusted QR Code';
        }

        const resultEl = document.getElementById('qrResult');
        if (!resultEl) return;
        resultEl.innerHTML = `
            <div class="w-full bg-rose-50 border-2 border-rose-500 rounded-2xl p-4 text-center">
                <p class="text-rose-900 font-bold text-lg">${title}</p>
                <p class="text-rose-700 text-sm mt-1">${message}</p>
            </div>
        `;
    }

    function addToScanLog(student, timestamp, mark, violationCreated, eventType, meta = {}) {
        const time = new Date(timestamp).toLocaleTimeString();
        mark = mark || 1;

        let label, labelColor;
        if (eventType === 'blocked') {
            label = '⛔ Access Denied';
            labelColor = 'red';
        } else if (eventType === 'violation_recorded') {
            const markText = meta.markLabel || (ordinalLabel(mark) + ' Offense');
            const categoryText = meta.categoryName ? (' - ' + meta.categoryName) : '';
            label = '📝 ' + markText + categoryText;
            labelColor = mark >= 3 ? 'red' : (mark === 2 ? 'yellow' : 'green');
        } else if (eventType === 'proxy_rejected') {
            label = '🚫 Proxy Rejected';
            labelColor = 'red';
        } else if (eventType === 'qr_pending_face') {
            label = '🧾 QR Pending Face';
            labelColor = 'blue';
        } else if (eventType === 'qr_verified') {
            label = '✅ Digital QR Verified';
            labelColor = 'green';
        } else if (eventType === 'lost' || student.severity === 'lost') {
            label = '🚫 RFID Disabled';
            labelColor = 'orange';
        } else if (violationCreated) {
            label = '🚨 Violation Created';
            labelColor = 'red';
        } else if (mark >= 3) {
            label = '⏳ Pending Reparation';
            labelColor = 'orange';
        } else if (mark === 2) {
            label = '⚠️ Mark #2 — Warning';
            labelColor = 'yellow';
        } else {
            label = '✓ Mark #1 — Reminded';
            labelColor = 'green';
        }

        scanLog.unshift({student, time, label, labelColor});

        const logHTML = scanLog.map((log) => `
            <div class="bg-white rounded-lg p-4 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 border border-slate-200 shadow-sm">
                <div class="flex-1">
                    <p class="font-semibold text-slate-800 text-sm md:text-base">${escHtml(log.student.name)}</p>
                    <p class="text-xs md:text-sm text-slate-500">${escHtml(log.student.student_id)} • ${escHtml(log.student.email)}</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <p class="text-${log.labelColor}-600 font-bold text-sm md:text-base">${escHtml(log.label)}</p>
                        <p class="text-xs text-slate-400">${log.time}</p>
                    </div>
                    ${log.labelColor === 'red' ? '<span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full font-medium">SSO Referral</span>' : ''}
                </div>
            </div>
        `).join('');

        document.getElementById('scanLog').innerHTML = logHTML || '<p class="text-center text-slate-400 py-8 text-sm">No scans yet</p>';
    }

    function clearLog() {
        if (confirm('Clear all scan logs from this session?')) {
            scanLog = [];
            document.getElementById('scanLog').innerHTML = '<p class="text-center text-slate-400 py-8 text-sm">Log cleared. Waiting for scans...</p>';
        }
    }

    // Auto-focus on page load to ensure RFID scanner works
    window.addEventListener('load', function() {
        document.body.focus();
        // Pre-warm the ID card template image into browser cache so first scan is instant
        var _warmImg = new Image();
        _warmImg.src = '../assets/images/id-card-template.png';
        setupViolationChooserUi();
        loadViolationCategories();
        switchMode('rfid');
    });

    <?php if ($faceRecEnabled): ?>
    // ================================================================
    // FACE RECOGNITION MODE
    // ================================================================
    const FACE_THRESHOLD = <?php echo json_encode($faceMatchThreshold); ?>;
    let currentMode = 'rfid';
    let faceSystem = null;
    let faceInitialized = false;
    let faceReady = false;         // models + descriptors loaded and ready to detect
    let cameraRunning = false;      // true while a camera stream is active
    let selectedCameraId = null;    // deviceId of the chosen camera (null = browser default)
    let lastFaceMatchUserId = null;
    let lastFaceMatchTime = 0;
    const FACE_COOLDOWN_MS = 5000; // 5 second cooldown between same-person matches
    let facePreloadPromise = null;
    let facePreloadedSystem = null;
    let facePreloadedCount = 0;
    let facePreloadStarted = false;
    let keepAliveAudioCtx = null;
    let keepAliveOsc = null;
    let keepAliveGain = null;

    async function beginQrFaceConfirmationFlow() {
        switchMode('face');

        if (typeof startFaceEnginePreload === 'function') {
            startFaceEnginePreload();
        }

        if (typeof initFaceRecognition === 'function' && typeof faceInitialized !== 'undefined' && !faceInitialized) {
            if (typeof populateCameraSelector === 'function') {
                populateCameraSelector();
            }
            await initFaceRecognition();
        }

        const waitStart = Date.now();
        while (!faceReady && (Date.now() - waitStart) < 2500) {
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        if (typeof startFaceDetection === 'function') {
            await startFaceDetection();
        }
    }

    function startRecognitionKeepAlive() {
        try {
            if (keepAliveAudioCtx) return;
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;

            keepAliveAudioCtx = new AC();
            keepAliveOsc = keepAliveAudioCtx.createOscillator();
            keepAliveGain = keepAliveAudioCtx.createGain();

            // Practically silent keepalive signal.
            keepAliveOsc.type = 'sine';
            keepAliveOsc.frequency.value = 20;
            keepAliveGain.gain.value = 0.00001;

            keepAliveOsc.connect(keepAliveGain);
            keepAliveGain.connect(keepAliveAudioCtx.destination);
            keepAliveOsc.start();
        } catch (e) {
            // Non-fatal: continue without keepalive on unsupported browsers.
        }
    }

    function stopRecognitionKeepAlive() {
        try {
            if (keepAliveOsc) {
                keepAliveOsc.stop();
                keepAliveOsc.disconnect();
            }
            if (keepAliveGain) {
                keepAliveGain.disconnect();
            }
            if (keepAliveAudioCtx) {
                keepAliveAudioCtx.close();
            }
        } catch (e) {
        } finally {
            keepAliveOsc = null;
            keepAliveGain = null;
            keepAliveAudioCtx = null;
        }
    }

    function buildFaceSystem(statusEl, silent = false) {
        return new FaceRecognitionSystem({
            modelPath: '../assets/models',
            matchThreshold: FACE_THRESHOLD,
            minConfidence: 0.6,
            csrfToken: CSRF_TOKEN,
            detectionIntervalMs: 80,
            ssdInputSize: 224,
            ssdInputSizeFull: 320,
            livenessEnabled: true,
            requiredConsecutiveFrames: 5,
            matchAccumulatorMaxGap: 1,
            livenessMinFrames: 5,
            unrecognizedFramesThreshold: 5,
            livenessFailFramesThreshold: 10,
            minFaceSizePx: 80,
            minFaceSizeRatio: 0.08,
            minDistanceGap: 0.12,
            trackingEnabled: true,
            fullDetectionInterval: 3,
            matchWorkerPath: '../assets/js/face-match-worker.js',
            onStatusChange: (status, msg) => {
                if (!silent && statusEl) statusEl.textContent = msg;
            },
            onError: (msg, err) => {
                if (!silent && statusEl) statusEl.textContent = '❌ ' + msg;
                console.error('[FaceRec]', msg, err);
            },
            onDetection: handleFaceDetection,
            descriptorChunkSize: 40
        });
    }

    function startFaceEnginePreload() {
        if (facePreloadStarted || faceReady || faceInitialized) return;
        facePreloadStarted = true;

        facePreloadPromise = (async () => {
            const preloader = buildFaceSystem(null, true);
            const modelsOk = await preloader.loadModels();
            if (!modelsOk) return { modelsOk: false, faceCount: 0, system: null };

            // Yield once before descriptor hydration to keep page interactions fluid.
            await new Promise(resolve => setTimeout(resolve, 0));
            const faceCount = await preloader.loadKnownFaces('../api/get_face_descriptors.php');

            facePreloadedSystem = preloader;
            facePreloadedCount = faceCount;
            return { modelsOk: true, faceCount, system: preloader };
        })();
    }

    function legacyFaceModeSwitch(mode) {
        currentMode = mode;
        const rfidPanel = document.getElementById('rfidPanel');
        const facePanel = document.getElementById('facePanel');
        const btnRfid = document.getElementById('btnRfidMode');
        const btnFace = document.getElementById('btnFaceMode');

        if (mode === 'rfid') {
            rfidPanel.classList.remove('hidden');
            facePanel.classList.add('hidden');
            btnRfid.className = 'flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-sky-600 text-white shadow-md';
            btnFace.className = 'flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-white/80 text-slate-600 border border-white/60 hover:bg-white';
            
            // SECURITY: Fully stop and release camera hardware so the camera LED turns off
            // stopContinuousDetection() only pauses the detection loop — stopCamera() actually
            // releases the MediaStream tracks so no unknown process can access the camera.
            if (faceSystem) {
                faceSystem.stopContinuousDetection();
                faceSystem.stopCamera();
            }
            stopFaceAutoRefresh();
            stopRecognitionKeepAlive();
            cameraRunning = false;
            stopFrameBroadcast();
            broadcastToStudent({ type: 'stopped' });
            // Hide Start/Stop buttons while in RFID mode
            document.getElementById('btnStartFace').classList.add('hidden');
            document.getElementById('btnStopFace').classList.add('hidden');
            document.body.focus();
        } else {
            rfidPanel.classList.add('hidden');
            facePanel.classList.remove('hidden');
            btnFace.className = 'flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-sky-600 text-white shadow-md';
            btnRfid.className = 'flex-1 px-4 py-3 rounded-xl font-medium text-sm transition-all bg-white/80 text-slate-600 border border-white/60 hover:bg-white';
            
            if (!faceInitialized) {
                // First time — enumerate cameras immediately (fire-and-forget, no await needed)
                // then load models + faces, then auto-start camera preview
                populateCameraSelector();
                initFaceRecognition();
            } else {
                const faceCount = parseInt(document.getElementById('faceLoadedCount').textContent) || 0;
                document.getElementById('faceStatus').textContent = faceReady
                    ? `Ready - ${faceCount} faces loaded. Click Start to begin detection.`
                    : `Initializing face engine... ${faceCount} faces loaded so far.`;
                if (faceReady) {
                    document.getElementById('btnStartFace').classList.remove('hidden');
                } else {
                    document.getElementById('btnStartFace').classList.add('hidden');
                }
                document.getElementById('btnStopFace').classList.add('hidden');
                if (!cameraRunning) {
                    restartCamera();
                }
            }
        }
    }

    // Re-opens the camera stream after it was released (e.g. after switching to RFID mode)
    async function restartCamera() {
        const statusEl = document.getElementById('faceStatus');
        document.getElementById('faceVideoContainer').classList.remove('hidden');
        statusEl.textContent = 'Starting camera...';
        const cameraOk = await faceSystem.startCamera(
            document.getElementById('faceVideo'),
            document.getElementById('faceCanvas'),
            selectedCameraId
        );
        if (cameraOk) {
            cameraRunning = true;
            if (studentDisplayConnected) startFrameBroadcast();
            const faceCount = parseInt(document.getElementById('faceLoadedCount').textContent) || 0;
            statusEl.textContent = `Ready - ${faceCount} faces loaded. Click Start to begin detection.`;
            document.getElementById('btnStartFace').classList.remove('hidden');
            document.getElementById('faceVideoContainer').classList.remove('hidden');
        } else {
            statusEl.textContent = '❌ Camera failed. Check permissions.';
            cameraRunning = false;
        }
    }

    async function ensureStudentDisplayFeedReady() {
        if (!studentDisplayConnected) return;

        if (currentMode !== 'face') {
            switchMode('face');
            return;
        }

        if (!faceInitialized) {
            populateCameraSelector();
            initFaceRecognition();
            return;
        }

        if (!cameraRunning) {
            await restartCamera();
        } else {
            startFrameBroadcast();
        }
    }

    // Populates the camera selector dropdown
    // Safe to call at any time — early calls get generic labels, post-permission calls get real device names
    async function populateCameraSelector() {
        if (!faceSystem && !navigator.mediaDevices) return;
        let cameras = [];
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            cameras = devices
                .filter(d => d.kind === 'videoinput')
                .map((d, i) => ({ deviceId: d.deviceId, label: d.label || `Camera ${i + 1}` }));
        } catch (e) { return; }

        const select = document.getElementById('cameraSelect');
        const hint  = document.getElementById('cameraSelectHint');
        if (!select) return;

        // Preserve current value if possible
        const current = selectedCameraId || select.value;
        select.innerHTML = '';

        if (cameras.length === 0) {
            select.innerHTML = '<option value="">No cameras detected</option>';
            if (hint) hint.textContent = 'No cameras found. Connect a webcam and click Refresh.';
            return;
        }

        cameras.forEach((cam, i) => {
            const opt = document.createElement('option');
            opt.value = cam.deviceId;
            opt.textContent = cam.label || `Camera ${i + 1}`;
            if (cam.deviceId && cam.deviceId === current) opt.selected = true;
            select.appendChild(opt);
        });

        // Sync selectedCameraId
        if (!selectedCameraId || !cameras.find(c => c.deviceId === selectedCameraId)) {
            selectedCameraId = select.value;
        } else {
            select.value = selectedCameraId;
        }

        if (hint) {
            hint.textContent = cameras.length === 1
                ? '1 camera detected. Connect more cameras and click Refresh to see them.'
                : `${cameras.length} cameras detected. Select the one you want to use.`;
        }
    }

    // Called when admin picks a different camera from the dropdown
    async function changeCameraDevice(deviceId) {
        if (!deviceId) return;
        // Allow re-selecting even the same device (force refresh)
        if (!faceSystem) return;

        // Stop detection loop if running
        if (faceSystem._continuousRunning) stopFaceDetection();

        document.getElementById('faceStatus').textContent = 'Switching camera...';

        // Use switchCamera which gets the NEW stream BEFORE killing the old one.
        // This is the only reliable way on Chrome/Windows — if you stop first,
        // the browser recycles the old device handle to the new getUserMedia call.
        const cameraOk = await faceSystem.switchCamera(deviceId);

        if (cameraOk) {
            selectedCameraId = deviceId;
            cameraRunning = true;
            const faceCount = parseInt(document.getElementById('faceLoadedCount').textContent) || 0;
            document.getElementById('faceStatus').textContent =
                `Camera switched — ${faceCount} faces loaded. Click Start to begin detection.`;
            document.getElementById('btnStartFace').classList.remove('hidden');
            document.getElementById('btnStopFace').classList.add('hidden');
        } else {
            document.getElementById('faceStatus').textContent = '❌ Camera switch failed. Try another camera.';
        }
    }

    async function initFaceRecognition() {
        const statusEl = document.getElementById('faceStatus');
        const initEl = document.getElementById('faceInitStatus');

        // Prefer preloaded engine to avoid first-click hiccups.
        if (facePreloadedSystem && facePreloadedSystem.modelsLoaded) {
            faceSystem = facePreloadedSystem;
            faceSystem.onStatusChange = (status, msg) => { statusEl.textContent = msg; };
            faceSystem.onError = (msg, err) => {
                statusEl.textContent = '❌ ' + msg;
                console.error('[FaceRec]', msg, err);
            };
            faceSystem.onDetection = handleFaceDetection;
            faceSystem.csrfToken = CSRF_TOKEN;
        } else {
            faceSystem = buildFaceSystem(statusEl, false);
        }

        // Step 1: Immediately show panel and start camera preview (non-blocking UX)
        faceInitialized = true;
        faceReady = false;
        cameraRunning = false;
        initEl.classList.add('hidden');
        document.getElementById('btnStartFace').classList.add('hidden');
        document.getElementById('btnStopFace').classList.add('hidden');
        statusEl.textContent = 'Starting camera preview...';
        restartCamera();

        // Step 2: Ensure models/descriptors are ready (reuse preload if available).
        statusEl.textContent = 'Initializing face engine...';
        let faceCount = 0;

        if (facePreloadPromise) {
            const pre = await facePreloadPromise;
            if (!pre.modelsOk) {
                statusEl.textContent = '❌ Failed to load face models. Check model files in assets/models.';
                faceReady = false;
                return;
            }
            if (!faceSystem.modelsLoaded) {
                // Safety: if preloader finished but instance was rebuilt, load models once.
                const ok = await faceSystem.loadModels();
                if (!ok) {
                    statusEl.textContent = '❌ Failed to load face models. Check model files in assets/models.';
                    faceReady = false;
                    return;
                }
            }
            if (!faceSystem.knownDescriptors || faceSystem.knownDescriptors.length === 0) {
                faceCount = await faceSystem.loadKnownFaces('../api/get_face_descriptors.php');
            } else {
                faceCount = faceSystem.knownDescriptors.length || facePreloadedCount || 0;
            }
        } else {
            const modelsOk = await faceSystem.loadModels();
            if (!modelsOk) {
                statusEl.textContent = '❌ Failed to load face models. Check model files in assets/models.';
                faceReady = false;
                return;
            }
            await new Promise(resolve => setTimeout(resolve, 0));
            faceCount = await faceSystem.loadKnownFaces('../api/get_face_descriptors.php');
        }

        document.getElementById('faceLoadedCount').textContent = faceCount;

        // Ready for instant Start
        faceReady = true;
        statusEl.textContent = `Ready - ${faceCount} faces loaded. Click Start to begin detection.`;
        document.getElementById('btnStartFace').classList.remove('hidden');
        await populateCameraSelector();
    }

    // Start model/descriptor preload in background while guard is on RFID mode.
    setTimeout(() => { startFaceEnginePreload(); }, 1200);

    async function startFaceDetection() {
        if (!faceSystem) return;
        if (!faceReady) {
            document.getElementById('faceStatus').textContent = 'Initializing face engine... please wait a moment.';
            return;
        }

        // Recover from stale state: camera flag may be true while stream was already stopped.
        if (cameraRunning && (!faceSystem.stream || faceSystem.stream.getVideoTracks().length === 0)) {
            cameraRunning = false;
        }

        // Start camera only when operator clicks Start (keeps Face tab switch instant)
        if (!cameraRunning) {
            document.getElementById('faceStatus').textContent = 'Starting camera...';
            let cameraOk = await faceSystem.startCamera(
                document.getElementById('faceVideo'),
                document.getElementById('faceCanvas'),
                selectedCameraId
            );

            // Retry path: clear selected device and force default camera
            if (!cameraOk) {
                document.getElementById('faceStatus').textContent = 'Retrying with default camera...';
                selectedCameraId = null;
                cameraOk = await faceSystem.startCamera(
                    document.getElementById('faceVideo'),
                    document.getElementById('faceCanvas'),
                    null
                );
            }

            if (!cameraOk || !faceSystem.stream || faceSystem.stream.getVideoTracks().length === 0) {
                document.getElementById('faceStatus').textContent =
                    '❌ Camera failed to start. Close other camera apps and allow browser permission, then click Start again.';
                cameraRunning = false;
                return;
            }

            cameraRunning = true;
            document.getElementById('faceVideoContainer').classList.remove('hidden');
            if (studentDisplayConnected) startFrameBroadcast();
            // Permission is now granted, refresh labels/device IDs
            await populateCameraSelector();
        }

        faceSystem.startContinuousDetection();
        startRecognitionKeepAlive();
        document.getElementById('btnStartFace').classList.add('hidden');
        document.getElementById('btnStopFace').classList.remove('hidden');
        document.getElementById('faceStatus').textContent = '🟢 Detection active — verifying face (multi-frame + liveness)...';
        
        // Start periodic face database refresh (every 30 seconds)
        startFaceAutoRefresh();
        broadcastToStudent({ type: 'scanning' });
    }

    function stopFaceDetection() {
        if (!faceSystem) return;
        faceSystem.stopContinuousDetection();
        stopRecognitionKeepAlive();
        document.getElementById('btnStopFace').classList.add('hidden');
        document.getElementById('btnStartFace').classList.remove('hidden');
        document.getElementById('faceStatus').textContent = '⏸ Detection paused';
        broadcastToStudent({ type: 'idle' });
        
        // Stop auto-refresh when detection is paused
        stopFaceAutoRefresh();
    }

    // Auto-refresh face database using incremental sync (delta updates)
    let faceRefreshTimer = null;
    const FACE_REFRESH_INTERVAL = 15000; // 15 seconds (lighter with incremental sync)

    function startFaceAutoRefresh() {
        stopFaceAutoRefresh();
        faceRefreshTimer = setInterval(async () => {
            if (!faceSystem) return;
            try {
                const result = await faceSystem.loadKnownFacesIncremental('../api/get_face_updates.php');
                if (result && (result.added > 0 || result.removed > 0)) {
                    const newCount = faceSystem.knownDescriptors.length;
                    document.getElementById('faceLoadedCount').textContent = newCount;
                    document.getElementById('faceStatus').textContent =
                        `🟢 Detection active - ${newCount} faces loaded (+${result.added}/-${result.removed})`;
                    console.log(`[FaceRec] Incremental sync: +${result.added} -${result.removed}`);
                }
            } catch (err) {
                console.warn('[FaceRec] Incremental sync failed, trying full reload:', err);
                try {
                    await faceSystem.loadKnownFaces('../api/get_face_descriptors.php');
                } catch (e) { /* ignore secondary failure */ }
            }
        }, FACE_REFRESH_INTERVAL);
    }

    function stopFaceAutoRefresh() {
        if (faceRefreshTimer) {
            clearInterval(faceRefreshTimer);
            faceRefreshTimer = null;
        }
    }

    async function handleFaceDetection(result) {
        // ─── NOT MATCHED: Unrecognized person or liveness failure ───
        if (!result.matched) {
            const overlay = document.getElementById('faceMatchOverlay');
            const infoEl = document.getElementById('faceMatchInfo');
            const resultEl = document.getElementById('faceScanResult');

            if (result.reason === 'not_recognized') {
                // Person is NOT in the system — show big red alert
                overlay.classList.remove('hidden');
                infoEl.innerHTML = `
                    <p class="font-bold text-lg text-red-300">⚠ NOT RECOGNIZED</p>
                    <p class="text-sm text-red-200">This person is not registered in the system</p>
                `;
                resultEl.classList.remove('hidden');
                resultEl.innerHTML = `
                    <div class="bg-red-50 border-4 border-red-600 rounded-2xl p-6 md:p-8 text-center fade-in">
                        <div class="text-5xl md:text-6xl mb-4">🚫</div>
                        <h3 class="text-2xl md:text-3xl font-bold text-red-900 mb-3">PERSON NOT RECOGNIZED</h3>
                        <p class="text-red-700 text-lg mb-2">This person is <strong>NOT registered</strong> in the system.</p>
                        <p class="text-red-600 text-sm mb-4">Face does not match any enrolled student.</p>
                        <div class="bg-red-100 border-2 border-red-300 rounded-lg p-4 inline-block">
                            <p class="text-red-800 font-semibold text-sm">⚠ Ask the person to identify themselves or contact administration.</p>
                        </div>
                    </div>
                `;
                document.getElementById('faceStatus').textContent = '🔴 Unrecognized person detected';
                broadcastToStudent({ type: 'not_recognized' });
                // Auto-clear after a few seconds, detection continues
                setTimeout(() => {
                    resultEl.classList.add('hidden');
                    resultEl.innerHTML = '';
                    overlay.classList.add('hidden');
                    broadcastToStudent({ type: 'clear' });
                }, 5000);
                return;
            }

            if (result.reason === 'liveness_failed') {
                // Photo or screen detected — show warning
                overlay.classList.remove('hidden');
                infoEl.innerHTML = `
                    <p class="font-bold text-lg text-orange-300">⚠ PHOTO/SCREEN DETECTED</p>
                    <p class="text-sm text-orange-200">A live face is required for verification</p>
                `;
                resultEl.classList.remove('hidden');
                resultEl.innerHTML = `
                    <div class="bg-orange-50 border-4 border-orange-500 rounded-2xl p-6 md:p-8 text-center fade-in">
                        <div class="text-5xl md:text-6xl mb-4">📷</div>
                        <h3 class="text-2xl md:text-3xl font-bold text-orange-900 mb-3">PHOTO / SCREEN DETECTED</h3>
                        <p class="text-orange-700 text-lg mb-2">A <strong>printed photo or screen image</strong> was detected.</p>
                        <p class="text-orange-600 text-sm mb-4">Only live faces are accepted for gate entry.</p>
                        <div class="bg-orange-100 border-2 border-orange-300 rounded-lg p-4 inline-block">
                            <p class="text-orange-800 font-semibold text-sm">🔒 The student must be physically present and face the camera.</p>
                        </div>
                    </div>
                `;
                document.getElementById('faceStatus').textContent = '🟠 Photo/screen detected — live face required';
                broadcastToStudent({ type: 'liveness_failed' });
                setTimeout(() => {
                    resultEl.classList.add('hidden');
                    resultEl.innerHTML = '';
                    overlay.classList.add('hidden');
                    broadcastToStudent({ type: 'clear' });
                }, 5000);
                return;
            }

            // Generic no-match (shouldn't reach here normally, but handle gracefully)
            overlay.classList.add('hidden');
            return;
        }

        // ─── MATCHED: Verified student ───
        const match = result.match;
        const now = Date.now();

        // Show overlay with match info
        const overlay = document.getElementById('faceMatchOverlay');
        const infoEl = document.getElementById('faceMatchInfo');
        overlay.classList.remove('hidden');
        const liveness = result.liveness || {};
        const livenessText = liveness.reasons ? liveness.reasons.join(', ') : '';
        infoEl.innerHTML = `
            <p class="font-bold text-lg">${escHtml(match.name)}</p>
            <p class="text-sm opacity-80">${escHtml(match.studentId)} • Confidence: ${(match.confidence * 100).toFixed(1)}%</p>
            <p class="text-xs opacity-60">Verified: ${result.verifiedFrames || 0} frames • Liveness: ${livenessText || 'n/a'}</p>
        `;

        // Cooldown check - prevent duplicate entries for same person
        if (match.userId === lastFaceMatchUserId && (now - lastFaceMatchTime) < FACE_COOLDOWN_MS) {
            return;
        }

        lastFaceMatchUserId = match.userId;
        lastFaceMatchTime = now;

        // Pause detection while processing
        faceSystem.stopContinuousDetection();
        document.getElementById('faceStatus').textContent = '⏳ Processing match...';

        // Server-side verification before logging — blocks entry on mismatch
        let serverVerified = false;
        let verificationResult = null;
        if (result.queryDescriptor) {
            try {
                verificationResult = await faceSystem.verifyFaceMatch(
                    match.userId,
                    result.queryDescriptor,
                    parseFloat(match.confidence),
                    '../api/verify_face_match.php'
                );
                serverVerified = verificationResult && verificationResult.verified;
                if (!serverVerified) {
                    console.warn('[FaceRec] Server verification REJECTED match:', verificationResult);
                }
            } catch (e) {
                console.warn('[FaceRec] Server verification unavailable:', e);
                serverVerified = false;
            }
        }

        // If server-side verification failed, reject the match entirely
        if (!serverVerified) {
            const resultEl = document.getElementById('faceScanResult');
            resultEl.classList.remove('hidden');

            const isProxyReject = !!(verificationResult && verificationResult.qr_rejected_proxy);
            const title = isProxyReject ? 'PROXY ATTEMPT BLOCKED' : 'MATCH NOT VERIFIED';
            const line1 = isProxyReject
                ? 'Face does not match the student who presented QR.'
                : 'Server-side verification <strong>rejected</strong> this match.';
            const line2 = isProxyReject
                ? 'The QR presenter and face identity are different. Access denied.'
                : 'The face does not match closely enough to confirm identity.';

            resultEl.innerHTML = `
                <div class="bg-red-50 border-4 border-red-600 rounded-2xl p-6 md:p-8 text-center fade-in">
                    <div class="text-5xl md:text-6xl mb-4">🚫</div>
                    <h3 class="text-2xl md:text-3xl font-bold text-red-900 mb-3">${title}</h3>
                    <p class="text-red-700 text-lg mb-2">${line1}</p>
                    <p class="text-red-600 text-sm mb-4">${line2}</p>
                    <div class="bg-red-100 border-2 border-red-300 rounded-lg p-4 inline-block">
                        <p class="text-red-800 font-semibold text-sm">⚠ Ask the person to identify themselves or try again.</p>
                    </div>
                </div>
            `;
            if (isProxyReject) {
                addToScanLog(
                    {
                        name: match.name || 'Unknown',
                        student_id: qrPendingFaceStudentId || match.studentId || '',
                        email: '',
                        severity: 'critical'
                    },
                    new Date().toISOString(),
                    null,
                    false,
                    'proxy_rejected'
                );
            }

            qrPendingFaceUserId = null;
            qrPendingFaceStudentId = '';
            qrPendingFaceExpiresAtMs = 0;
            updateQrPendingResetBtn();
            document.getElementById('faceStatus').textContent = isProxyReject
                ? '🔴 Proxy attempt blocked — QR and face identities did not match'
                : '🔴 Server verification rejected — face does not match closely enough';
            broadcastToStudent({ type: 'not_recognized' });
            stopFaceAutoRefresh();
            document.getElementById('btnStopFace').classList.add('hidden');
            document.getElementById('btnStartFace').classList.remove('hidden');
            setTimeout(() => {
                resultEl.classList.add('hidden');
                resultEl.innerHTML = '';
                overlay.classList.add('hidden');
                broadcastToStudent({ type: 'clear' });
            }, 5000);
            return;
        }

        // Log the face entry to backend
        const response = await faceSystem.logFaceEntry(
            match.userId,
            parseFloat(match.confidence),
            '../api/log_face_entry.php',
            result.queryDescriptor
        );

        if (response && response.success && response.qr_face_verified && response.student) {
            qrPendingFaceUserId = null;
            qrPendingFaceStudentId = '';
            qrPendingFaceExpiresAtMs = 0;
            updateQrPendingResetBtn();

            await handleQrFaceVerifiedTransition(response, match, overlay);
            broadcastToStudent({ type: 'match', student: response.student, match: { confidence: match.confidence } });
            return;
        }

        // Display result (same style as RFID)
        displayFaceScanResult(response, match);
        const awaitingChoice = isAwaitingViolationChoice(response);

        // Broadcast to student display
        if (response.success) {
            qrPendingFaceUserId = null;
            qrPendingFaceStudentId = '';
            qrPendingFaceExpiresAtMs = 0;
            updateQrPendingResetBtn();
            broadcastToStudent({ type: 'match', student: response.student, match: { confidence: match.confidence } });
        } else if (response.access_denied) {
            qrPendingFaceUserId = null;
            qrPendingFaceStudentId = '';
            qrPendingFaceExpiresAtMs = 0;
            updateQrPendingResetBtn();
            broadcastToStudent({ type: 'access_denied', student: response.student, timestamp: response.timestamp });
        } else if (response.qr_rejected_proxy) {
            qrPendingFaceUserId = null;
            qrPendingFaceStudentId = '';
            qrPendingFaceExpiresAtMs = 0;
            updateQrPendingResetBtn();
            addToScanLog(response.student || {}, response.timestamp || new Date().toISOString(), null, false, 'proxy_rejected');
            broadcastToStudent({ type: 'clear' });
        } else {
            broadcastToStudent({ type: 'clear' });
        }

        if (awaitingChoice) {
            document.getElementById('btnStopFace').classList.add('hidden');
            document.getElementById('btnStartFace').classList.remove('hidden');
            document.getElementById('faceStatus').textContent = 'Student identified. Choose violation to complete.';
            return;
        }

        // Stop detection completely — guard must press Start for next student
        stopFaceAutoRefresh();
        document.getElementById('btnStopFace').classList.add('hidden');
        document.getElementById('btnStartFace').classList.remove('hidden');
        document.getElementById('faceStatus').textContent = '✅ Scan complete — press Start to scan next student';

        // Clear the result display after delay but keep detection stopped
        setTimeout(() => {
            const resultEl = document.getElementById('faceScanResult');
            resultEl.classList.add('hidden');
            overlay.classList.add('hidden');
            broadcastToStudent({ type: 'clear' });
        }, 4000);
    }

    async function handleQrFaceVerifiedTransition(response, match, overlayEl) {
        stopFaceAutoRefresh();
        if (typeof stopFaceDetection === 'function') {
            stopFaceDetection();
        }

        if (overlayEl) {
            overlayEl.classList.add('hidden');
        }

        const faceResultEl = document.getElementById('faceScanResult');
        if (faceResultEl) {
            faceResultEl.classList.add('hidden');
            faceResultEl.innerHTML = '';
        }

        switchMode('qr');

        if (isAwaitingViolationChoice(response)) {
            showAwaitingViolationHint(response);
            openViolationChooser(response);
            setQrStatus('QR + Face verified. Select violation category to finalize recording.', false);
            broadcastToStudent({ type: 'clear' });
            return;
        }

        displayQrVerificationSuccess(response);
        addToScanLog(
            response.student,
            response.timestamp || new Date().toISOString(),
            response.gate_mark || (response.student && response.student.gate_mark) || 1,
            !!response.violation_created,
            'qr_verified'
        );

        const mark = response.gate_mark || (response.student && response.student.gate_mark) || 1;
        if (response.violation_created) {
            setQrStatus('QR + Face verified. 3rd mark reached and formal violation created.', true);
        } else if (mark >= 3) {
            setQrStatus('QR + Face verified. Formal violation is pending resolution.', true);
        } else if (mark === 2) {
            setQrStatus('QR + Face verified. 2nd mark recorded in QR scanner flow.', false);
        } else {
            setQrStatus('QR + Face verified. 1st mark recorded in QR scanner flow.', false);
        }

        if (QR_CHALLENGE_ENABLED) {
            await issueQrChallenge(true);
        }

        setTimeout(() => {
            const qrResult = document.getElementById('qrResult');
            if (qrResult) {
                qrResult.innerHTML = '';
            }
            broadcastToStudent({ type: 'clear' });
        }, response.violation_created ? 8000 : (mark === 2 ? 5000 : 4000));
    }

    function renderFaceDigitalId(containerSelector, student, match) {
        const profilePictureFile = student.profile_picture || match.profilePicture || null;
        const idCard = new DigitalIdCard(containerSelector, {
            templateSrc: '../assets/images/id-card-template.png',
            student: {
                name: student.name || match.name || '',
                studentId: student.student_id || match.studentId || '',
                course: student.course || match.course || '',
                email: student.email || match.email || '',
                profilePicture: profilePictureFile
                    ? '../assets/images/profiles/' + profilePictureFile
                    : null
            }
        });
        idCard.render();
    }

    function displayFaceScanResult(response, match) {
        const resultEl = document.getElementById('faceScanResult');
        resultEl.classList.remove('hidden');

        if (response.access_denied) {
            const student = response.student || {};

            resultEl.innerHTML = `
                <div class="w-full fade-in">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                        <div id="faceDigitalIdContainer" class="flex justify-center"></div>
                        <div class="bg-red-50 border-4 border-red-600 rounded-2xl p-6 md:p-8">
                            <div class="text-5xl md:text-6xl mb-4">⛔</div>
                            <h3 class="text-2xl md:text-3xl font-bold text-red-900 mb-3 animate-pulse">ACCESS DENIED</h3>
                            <div class="bg-white rounded-lg p-4 md:p-5 mb-4 border-2 border-red-300">
                                <p class="text-xl md:text-2xl font-bold text-slate-800 mb-2">${escHtml(student.name || '')}</p>
                                <p class="text-slate-600 mb-1">ID: ${escHtml(student.student_id || '')}</p>
                                ${student.course ? `<p class="text-slate-500 text-sm mb-1">${escHtml(student.course)}</p>` : ''}
                                <p class="text-slate-500 text-sm">${escHtml(student.email || '')}</p>
                            </div>
                            <div class="bg-red-600 rounded-lg p-4 mb-4 text-white">
                                <p class="font-bold text-xl md:text-2xl mb-1">🚫 ENTRY NOT ALLOWED</p>
                                <p class="text-sm">${escHtml(response.message || 'Student has unresolved SSO compliance. Entry remains blocked until the case is cleared.')}</p>
                                ${Number(response.sso_hold_count || 0) > 0 ? `<p class="text-xs mt-2 opacity-90">Open SSO compliance cases: ${escHtml(String(response.sso_hold_count))}</p>` : ''}
                            </div>
                            <div class="bg-yellow-100 border-2 border-yellow-500 rounded-lg p-4 mb-3">
                                <p class="text-yellow-900 font-bold text-sm mb-1">⚠️ ACTION REQUIRED</p>
                                <p class="text-yellow-800 text-sm">Refer student to the SSO Office for violation details and resolution</p>
                            </div>
                            <p class="text-red-700 font-bold text-sm mt-3 animate-pulse">🔒 STUDENT MUST CONTACT SSO OFFICE</p>
                        </div>
                    </div>
                </div>`;

            renderFaceDigitalId('#faceDigitalIdContainer', student, match);
            addToScanLog(student, response.timestamp || new Date().toISOString(), null, false, 'blocked');
            setTimeout(() => {
                resultEl.classList.add('hidden');
                broadcastToStudent({ type: 'clear' });
            }, 7000);
            return;
        }

        if (isAwaitingViolationChoice(response)) {
            showAwaitingViolationHint(response);
            openViolationChooser(response);
            return;
        }

        if (!response.success) {
            const isBindingRequired = String(response.error || '') === 'QR_FACE_BINDING_REQUIRED';
            const isProxyReject = !!response.qr_rejected_proxy || String(response.error || '') === 'QR_REJECTED_PROXY';
            resultEl.innerHTML = `
                <div class="${isProxyReject ? 'bg-red-50 border-red-500' : 'bg-yellow-50 border-yellow-400'} border-2 rounded-xl p-4 text-center fade-in">
                    <p class="${isProxyReject ? 'text-red-800' : 'text-yellow-800'} font-medium">
                        ${escHtml(response.message || response.error || 'Unknown error')}
                    </p>
                    ${isBindingRequired ? '<p class="text-yellow-700 text-sm mt-1">Scan QR first, then verify face for the same student.</p>' : ''}
                </div>`;
            return;
        }

        const student = response.student;
        const mark    = response.gate_mark || student.gate_mark || 1;
        const created = !!response.violation_created;

        let severityColor, severityBg, severityBorder, severityIcon, headlineText, subText;

        if (created) {
            severityColor  = 'red';
            severityBg     = 'bg-red-50';
            severityBorder = 'border-red-600';
            severityIcon   = '🚨';
            headlineText   = '3-MARK LIMIT REACHED';
            subText        = 'A formal violation has been recorded. Student must report to the SSO Office.';
        } else if (mark >= 3) {
            severityColor  = 'orange';
            severityBg     = 'bg-orange-50';
            severityBorder = 'border-orange-500';
            severityIcon   = '⏳';
            headlineText   = 'VIOLATION PENDING RESOLUTION';
            subText        = 'Formal violation already recorded. Gate marks will reset after admin resolves all pending reparations.';
        } else if (mark === 2) {
            severityColor  = 'yellow';
            severityBg     = 'bg-yellow-50';
            severityBorder = 'border-yellow-400';
            severityIcon   = '⚠️';
            headlineText   = '2ND MARK RECORDED';
            subText        = 'Warning: one more scan without physical ID will create a formal violation.';
        } else {
            severityColor  = 'green';
            severityBg     = 'bg-green-50';
            severityBorder = 'border-green-400';
            severityIcon   = '✓';
            headlineText   = '1ST MARK RECORDED';
            subText        = 'Entry allowed. Remind student to bring their physical ID next time.';
        }

        const markBar = `
            <div class="flex items-center gap-2 mt-3">
                <span class="text-xs text-slate-500 font-medium">Gate Marks:</span>
                ${[1,2,3].map(i => `
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold border-2 ${
                        i <= (created ? 3 : mark)
                            ? (created && i === 3 ? 'bg-red-500 border-red-600 text-white' : 'bg-amber-400 border-amber-500 text-white')
                            : 'bg-slate-100 border-slate-300 text-slate-400'
                    }">${i}</div>
                `).join('')}
                ${created
                    ? '<span class="text-xs font-bold text-red-600 ml-1">→ Violation Created</span>'
                    : (mark >= 3
                        ? '<span class="text-xs font-bold text-orange-600 ml-1">→ Awaiting Admin Resolution</span>'
                        : `<span class="text-xs text-slate-400 ml-1">${3 - mark} mark${3 - mark !== 1 ? 's' : ''} until violation</span>`)}
            </div>`;

        resultEl.innerHTML = `
            <div class="w-full fade-in">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                    <div id="faceDigitalIdContainer" class="flex justify-center"></div>
                    <div class="${severityBg} border-4 ${severityBorder} rounded-2xl p-6 md:p-8">
                        <div class="text-5xl md:text-6xl mb-4">${severityIcon}</div>
                        <h3 class="text-2xl md:text-3xl font-bold text-${severityColor}-800 mb-3">NO PHYSICAL ID</h3>
                        <div class="bg-white rounded-lg p-4 md:p-6 mb-4">
                            <p class="text-xl md:text-2xl font-bold text-slate-800 mb-2">${escHtml(student.name)}</p>
                            <p class="text-slate-600 mb-1">ID: ${escHtml(student.student_id)}</p>
                            ${(student.course || match.course) ? `<p class="text-slate-500 text-sm mb-1">${escHtml(student.course || match.course)}</p>` : ''}
                            <p class="text-slate-500 text-sm">${escHtml(student.email)}</p>
                            ${markBar}
                        </div>
                        <div class="bg-${severityColor}-100 rounded-lg p-4 mb-3">
                            <p class="text-${severityColor}-900 font-bold text-xl md:text-2xl mb-1">${headlineText}</p>
                            <p class="text-${severityColor}-700 text-sm md:text-base">${subText}</p>
                        </div>
                        ${created ? `
                        <div class="bg-red-200 rounded-lg p-3 text-center animate-pulse">
                            <p class="text-red-900 font-bold text-sm">🔔 Student must contact the SSO Office to resolve this violation.</p>
                        </div>` : `
                        <p class="text-slate-500 text-xs mt-2">Entry allowed — face scan logged.</p>`}
                        <p class="text-xs text-slate-400 mt-2">Match confidence: ${(match.confidence * 100).toFixed(1)}%</p>
                    </div>
                </div>
            </div>`;

        renderFaceDigitalId('#faceDigitalIdContainer', student, match);
        addToScanLog(student, response.timestamp, mark, created);
        setTimeout(() => {
            resultEl.classList.add('hidden');
            broadcastToStudent({ type: 'clear' });
        }, created ? 8000 : (mark === 2 ? 5000 : 4000));
    }

    // ================================================================
    // STUDENT DISPLAY BROADCAST
    // ================================================================
    let studentFrameTimer = null;
    let studentDisplayConnected = false;
    let broadcastCanvas = document.createElement('canvas');
    let broadcastCtx = broadcastCanvas.getContext('2d');

    // Initialize channel immediately so the student display can connect
    // even before the guard switches to Face Recognition mode.
    const studentChannel = new BroadcastChannel('gatewatch-student-display');
    studentChannel.onmessage = function(e) {
        if (!e.data) return;
        if (e.data.type === 'student_display_connected') {
            studentDisplayConnected = true;
            updateStudentDisplayBtn(true);
            broadcastToStudent({
                type: 'connected',
                cameraRunning: cameraRunning,
                currentMode: currentMode,
                detectionRunning: !!(faceSystem && faceSystem._continuousRunning)
            });
            ensureStudentDisplayFeedReady();
        } else if (e.data.type === 'student_display_disconnected') {
            studentDisplayConnected = false;
            updateStudentDisplayBtn(false);
            stopFrameBroadcast();
        }
    };

    function openStudentDisplay() {
        // Open as popup window (not a tab) so the security scanner tab stays
        // focused and is never backgrounded. Positioned at right edge for
        // dual-monitor setups where screen 2 faces students at the gate.
        var w = Math.min(1024, screen.availWidth);
        var h = Math.min(768, screen.availHeight);
        var left = screen.availWidth - w;
        window.open(
            'student_display.php',
            'gatewatch_student_display',
            'width=' + w + ',height=' + h + ',left=' + left + ',top=0,resizable=yes,scrollbars=no,menubar=no,toolbar=no,location=no,status=no'
        );
        // Refocus security tab immediately so it never becomes a background tab.
        setTimeout(function() { window.focus(); }, 200);
        ensureStudentDisplayFeedReady();
    }

    function broadcastToStudent(msg) {
        if (!studentChannel) return;
        try { studentChannel.postMessage(msg); } catch(e) {}
    }

    // Student display stream settings only (does NOT affect face recognition matching).
    // Keep full source resolution for close visual parity with security preview.
    const BROADCAST_MAX_W = 4096;
    const BROADCAST_INTERVAL_MS = 42; // ~24 fps target
    const BROADCAST_MIME = 'image/webp';
    const BROADCAST_QUALITY = 0.88;
    let _bcBusy = false;
    let _tickWorker = null;
    let _intervalTimer = null;

    // Send one frame to student display.
    function _broadcastFrameTick() {
        if (!studentDisplayConnected) return;
        const video = document.getElementById('faceVideo');
        const overlay = document.getElementById('faceCanvas');
        if (!video || video.paused || !video.videoWidth) return;
        if (_bcBusy) return;

        const scale = Math.min(1, BROADCAST_MAX_W / video.videoWidth);
        const w = Math.round(video.videoWidth * scale);
        const h = Math.round(video.videoHeight * scale);
        if (broadcastCanvas.width !== w) broadcastCanvas.width = w;
        if (broadcastCanvas.height !== h) broadcastCanvas.height = h;

        broadcastCtx.drawImage(video, 0, 0, w, h);
        if (overlay && overlay.width > 0 && overlay.height > 0) {
            broadcastCtx.drawImage(overlay, 0, 0, w, h);
        }

        _bcBusy = true;
        broadcastCanvas.toBlob(function(blob) {
            _bcBusy = false;
            if (!blob || !studentDisplayConnected) return;
            broadcastToStudent({ type: 'frame', blob: blob });
        }, BROADCAST_MIME, BROADCAST_QUALITY);
    }

    function _createTickWorker() {
        if (_tickWorker) return _tickWorker;
        try {
            // Dedicated worker file is more reliable than blob workers under strict CSP.
            _tickWorker = new Worker('../assets/js/frame_tick_worker.js');
            _tickWorker.onmessage = function(ev) {
                if (ev && ev.data && ev.data.type === 'tick') _broadcastFrameTick();
            };
            _tickWorker.onerror = function() {
                _tickWorker = null;
                if (!_intervalTimer) _intervalTimer = setInterval(_broadcastFrameTick, BROADCAST_INTERVAL_MS);
            };
        } catch (e) {
            _tickWorker = null;
        }
        return _tickWorker;
    }

    function startFrameBroadcast() {
        if (studentFrameTimer) return;
        if (!studentChannel) return;
        studentFrameTimer = true;

        const worker = _createTickWorker();
        if (worker) {
            worker.postMessage({ type: 'start', intervalMs: BROADCAST_INTERVAL_MS });
        } else {
            _intervalTimer = setInterval(_broadcastFrameTick, BROADCAST_INTERVAL_MS);
        }
    }

    function stopFrameBroadcast() {
        if (!studentFrameTimer) return;
        studentFrameTimer = null;
        _bcBusy = false;
        if (_tickWorker) _tickWorker.postMessage({ type: 'stop' });
        if (_intervalTimer) {
            clearInterval(_intervalTimer);
            _intervalTimer = null;
        }
    }

    function updateStudentDisplayBtn(connected) {
        var btn = document.getElementById('btnStudentDisplay');
        if (!btn) return;
        var dot = btn.querySelector('.sd-dot');
        if (dot) {
            dot.className = 'sd-dot w-2 h-2 rounded-full inline-block ' + (connected ? 'bg-green-400' : 'bg-slate-400');
        }
    }
    <?php endif; ?>
    </script>
</body>
</html>
