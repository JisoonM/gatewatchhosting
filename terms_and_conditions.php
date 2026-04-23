<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/terms_helper.php';

send_security_headers();
send_no_cache_headers();

// This page is only meant to be viewed during a NEW Google signup flow.
// Block direct URL access by users who are not currently completing registration.
$signup = $_SESSION['google_signup'] ?? null;
if (!is_array($signup)) {
  header('Location: login.php');
  exit;
}

$startedAt = (int)($signup['started_at'] ?? 0);
if ($startedAt <= 0 || (time() - $startedAt) > 900) {
  unset($_SESSION['google_signup']);
  $_SESSION['error'] = 'Signup session expired. Please try signing in with Google again.';
  header('Location: login.php');
  exit;
}

$studentEmail = (string)($signup['email'] ?? '');
$googleId = (string)($signup['google_id'] ?? '');
if ($studentEmail === '' || $googleId === '') {
  unset($_SESSION['google_signup']);
  $_SESSION['error'] = 'Incomplete Google sign-in details. Please try again.';
  header('Location: login.php');
  exit;
}

try {
  $pdo = pdo();
  $stmt = $pdo->prepare('SELECT id FROM users WHERE google_id = ? OR email = ? LIMIT 1');
  $stmt->execute([$googleId, $studentEmail]);
  if ($stmt->fetch()) {
    unset($_SESSION['google_signup']);
    $_SESSION['info'] = 'Your account is already registered. If verification is pending, please wait for approval.';
    header('Location: login.php');
    exit;
  }
} catch (Throwable $e) {
  unset($_SESSION['google_signup']);
  $_SESSION['error'] = 'Unable to display Terms at this time. Please try again.';
  header('Location: login.php');
  exit;
}

$title = gatewatch_terms_title();
$version = gatewatch_terms_version();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo e($title); ?> | GateWatch</title>
  <link rel="icon" type="image/png" href="assets/images/gatewatch-logo.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="assets/js/tailwind.config.js"></script>
  <link rel="stylesheet" href="assets/css/styles.css">
  <style type="text/tailwindcss">
    .glass-card { backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); }
    .bg-noise { pointer-events:none; position:absolute; inset:0; opacity:0.10;
      background-image: radial-gradient(rgba(255,255,255,0.38) 0.45px, transparent 0.45px);
      background-size: 3px 3px; mix-blend-mode: soft-light; z-index: 1; }
    .halo { pointer-events:none; position:absolute; width:18rem; height:18rem; border-radius:9999px;
      background: radial-gradient(circle, rgba(14,165,233,0.30) 0%, rgba(14,165,233,0) 65%);
      filter: blur(12px); }
  </style>
</head>
<body class="text-slate-800 min-h-screen antialiased bg-slate-950">
  <video class="fixed inset-0 w-full h-full object-cover z-0" src="assets/images/PCU MANILA Campus 2025.mp4" autoplay muted loop playsinline></video>
  <div class="fixed inset-0 bg-gradient-to-br from-slate-950/70 via-slate-950/50 to-sky-900/35 z-[1]"></div>
  <div class="bg-noise"></div>
  <div class="halo -top-20 -left-16 z-[2]"></div>
  <div class="halo -bottom-24 -right-16 z-[2]"></div>

  <main class="relative z-[3] min-h-screen px-4 py-10">
    <div class="mx-auto w-full max-w-4xl">
      <div class="glass-card bg-white/14 shadow-2xl rounded-[28px] border border-white/30 p-6 md:p-10 text-white">
        <div class="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <h1 class="text-2xl md:text-3xl font-semibold tracking-tight"><?php echo e($title); ?></h1>
            <p class="text-sky-100/85 mt-2">Version: <?php echo e($version); ?></p>
          </div>
          <div class="flex items-center gap-2">
            <a href="login.php" class="inline-flex items-center rounded-full border border-white/25 bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/15 transition">
              Back to Login
            </a>
          </div>
        </div>

        <div class="mt-6 rounded-2xl border border-white/20 bg-white/10 p-5 md:p-6">
          <div class="space-y-4 text-sky-50/95 leading-relaxed [text-align:justify]">
            <?php echo gatewatch_terms_html(); ?>
          </div>
        </div>

        <p class="mt-6 text-xs text-sky-100/75 [text-align:justify]">
          This document is provided for GateWatch project use. If your institution requires a formal legal policy,
          consult your authorized office or legal counsel.
        </p>
      </div>
    </div>
  </main>
</body>
</html>
