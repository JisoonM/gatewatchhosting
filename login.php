<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/terms_helper.php';

send_security_headers();
send_no_cache_headers();

$pdo = pdo();

$showGoogleTermsModal = false;
$studentNameForTerms = '';
$studentEmailForTerms = '';
$termsTitle = gatewatch_terms_title();

$googleTermsError = (string)($_SESSION['google_terms_error'] ?? '');
$googleTermsOld = is_array($_SESSION['google_terms_old'] ?? null) ? $_SESSION['google_terms_old'] : [];
unset($_SESSION['google_terms_error'], $_SESSION['google_terms_old']);

$signup = $_SESSION['google_signup'] ?? null;
if (is_array($signup)) {
  $startedAt = (int)($signup['started_at'] ?? 0);
  $studentNameForTerms = trim((string)($signup['name'] ?? ''));
  $studentEmailForTerms = trim((string)($signup['email'] ?? ''));
  $googleIdForTerms = trim((string)($signup['google_id'] ?? ''));

  if ($startedAt <= 0 || (time() - $startedAt) > 900) {
    unset($_SESSION['google_signup']);
    $_SESSION['error'] = 'Signup session expired. Please try signing in with Google again.';
  } elseif ($studentNameForTerms === '' || $studentEmailForTerms === '' || $googleIdForTerms === '') {
    unset($_SESSION['google_signup']);
    $_SESSION['error'] = 'Incomplete Google sign-in details. Please try again.';
  } else {
    try {
      $stmt = $pdo->prepare('SELECT id FROM users WHERE google_id = ? OR email = ? LIMIT 1');
      $stmt->execute([$googleIdForTerms, $studentEmailForTerms]);

      if ($stmt->fetch()) {
        unset($_SESSION['google_signup']);
        $_SESSION['info'] = 'Your account is already registered. If verification is pending, please wait for approval.';
      } else {
        $showGoogleTermsModal = true;
      }
    } catch (Throwable $e) {
      unset($_SESSION['google_signup']);
      $_SESSION['error'] = 'Unable to continue registration at this time. Please try again.';
    }
  }
}

if (!$showGoogleTermsModal) {
  $googleTermsError = '';
  $googleTermsOld = [];
}

$openGuardianStep = $showGoogleTermsModal && (
  $googleTermsError !== '' ||
  ((string)($googleTermsOld['accepted_terms'] ?? '') === '1')
);

$guardianNameOld = trim((string)($googleTermsOld['guardian_full_name'] ?? ''));
$guardianEmailOld = trim((string)($googleTermsOld['guardian_email'] ?? ''));
$guardianContactOld = trim((string)($googleTermsOld['guardian_contact_number'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>GateWatch | Login</title>
  <link rel="icon" type="image/png" href="assets/images/gatewatch-logo.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" defer></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r134/three.min.js" defer></script>
  <script src="assets/js/tailwind.config.js"></script>
  <link rel="stylesheet" href="assets/css/styles.css">
  <style type="text/tailwindcss">
    .bg-pcu {
      position: relative;
      overflow: hidden;
      background-color: #020617;
    }
    .bg-pcu::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-image: url('pcu-building.jpg');
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      background-attachment: fixed;
      transform: scale(1.03);
      filter: blur(1.5px);
      -webkit-filter: blur(1.5px);
      z-index: -1;
    }
    .bg-pcu::after {
      content: '';
      position: absolute;
      inset: 0;
      background:
        radial-gradient(circle at 15% 20%, rgba(14, 165, 233, 0.16), transparent 45%),
        radial-gradient(circle at 82% 84%, rgba(56, 189, 248, 0.12), transparent 38%),
        linear-gradient(to bottom right, rgba(2, 6, 23, 0.78), rgba(15, 23, 42, 0.55));
      z-index: -1;
    }
    .glass-card {
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
    }
    .bg-noise {
      pointer-events: none;
      position: absolute;
      inset: 0;
      opacity: 0.1;
      background-image: radial-gradient(rgba(255,255,255,0.38) 0.45px, transparent 0.45px);
      background-size: 3px 3px;
      mix-blend-mode: soft-light;
      z-index: 1;
    }
    .halo {
      pointer-events: none;
      position: absolute;
      width: 18rem;
      height: 18rem;
      border-radius: 9999px;
      background: radial-gradient(circle, rgba(14,165,233,0.3) 0%, rgba(14,165,233,0) 65%);
      filter: blur(12px);
    }
    #google-btn {
      position: relative;
      overflow: hidden;
      display: inline-flex !important;
      align-items: center;
      justify-content: center;
      gap: 0.625rem;
      white-space: nowrap;
      padding: 0.6rem 1.4rem;
      border-radius: 9999px;
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      background: rgba(255,255,255,0.18);
      border: 1px solid rgba(255,255,255,0.38);
      box-shadow: 0 2px 12px rgba(0,0,0,0.22), inset 0 1px 0 rgba(255,255,255,0.25);
      transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
    }
    #google-btn:hover {
      background: rgba(255,255,255,0.28);
      box-shadow: 0 4px 22px rgba(14,165,233,0.28), inset 0 1px 0 rgba(255,255,255,0.3);
      transform: translateY(-1px);
    }
    #google-btn:active {
      transform: scale(0.97);
      box-shadow: 0 1px 6px rgba(0,0,0,0.2);
    }
    #google-btn .g-icon-wrap {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 1.6rem;
      height: 1.6rem;
      border-radius: 50%;
      background: white;
      box-shadow: 0 1px 3px rgba(0,0,0,0.15);
      flex-shrink: 0;
    }
    #google-btn .btn-label {
      color: #fff;
      text-shadow: 0 1px 4px rgba(0,0,0,0.45);
      font-size: 0.9rem;
      font-weight: 600;
      letter-spacing: 0.02em;
    }
    .terms-modal-backdrop {
      background: linear-gradient(to bottom right, rgba(2, 6, 23, 0.82), rgba(15, 23, 42, 0.72));
      backdrop-filter: blur(5px);
      -webkit-backdrop-filter: blur(5px);
    }
    #google-terms-modal .terms-panel {
      background: linear-gradient(145deg, rgba(15, 23, 42, 0.86), rgba(2, 6, 23, 0.9));
      max-width: 860px;
      max-height: 92vh;
      overflow: auto;
    }
    #google-terms-modal .terms-meta-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 0.75rem;
    }
    #google-terms-modal .terms-actions {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }
    #google-terms-modal .terms-consent-row {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 0.75rem;
      align-items: start;
    }
    #google-terms-modal .terms-input-row {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }
    @media (min-width: 640px) {
      #google-terms-modal .terms-meta-grid {
        grid-template-columns: 1fr 1fr;
      }
      #google-terms-modal .terms-actions {
        flex-direction: row;
      }
      #google-terms-modal .terms-input-row {
        flex-direction: row;
      }
    }
    .terms-content,
    .terms-content * {
      max-width: 100%;
    }
    .terms-content {
      overflow-wrap: anywhere;
      word-break: normal;
    }
    .terms-content table {
      display: block;
      width: 100%;
      overflow-x: auto;
    }
  </style>
</head>
<body class="text-slate-800 bg-pcu min-h-screen antialiased<?php echo $showGoogleTermsModal ? ' overflow-hidden' : ''; ?>">
  <video class="fixed inset-0 w-full h-full object-cover z-0" src="assets/images/PCU MANILA Campus 2025.mp4" autoplay muted loop playsinline></video>
  <div class="fixed inset-0 bg-gradient-to-br from-slate-950/65 via-slate-950/45 to-sky-900/40 z-[1]"></div>

  <canvas id="three-bg" class="fixed inset-0 w-full h-full z-[2] pointer-events-none"></canvas>
  <div class="bg-noise"></div>
  <div class="halo -top-20 -left-16 z-[3]" id="halo-one"></div>
  <div class="halo -bottom-24 -right-16 z-[3]" id="halo-two"></div>

  <main class="relative min-h-screen flex items-center justify-center px-4 py-10 z-[4]" id="page-shell">
    <section class="w-full max-w-lg glass-card bg-white/14 shadow-2xl rounded-[28px] border border-white/30 p-8 md:p-10 transition-all text-white" id="login-card">
          <div class="mb-8 flex flex-col items-center text-center" id="brand-block">
            <a href="login.php" class="block w-fit mx-auto hover:opacity-90 transition-opacity duration-200">
              <img src="assets/images/GateWatch Logo.png" alt="GateWatch Logo" class="w-20 h-20 md:w-24 md:h-24 mx-auto mb-5 object-contain drop-shadow-sm">
            </a>
            <h1 class="text-3xl md:text-4xl font-semibold tracking-tight text-white mb-2">GateWatch</h1>
            <p class="text-base text-sky-100/90">RFID-Enabled Violation Tracking</p>
          </div>

          <div class="space-y-4 mb-6" id="status-stack">

          <!-- PHASE 3: Google-Only Mode Message -->
          <?php if (isset($_GET['message']) && $_GET['message'] === 'google_only'): ?>
            <div class="mb-6 text-sm bg-blue-50 border-2 border-blue-200 rounded-lg p-4 shadow-sm">
              <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
                <div class="text-blue-800">
                  <p class="font-semibold mb-1">🔒 Sign Up with Google Account</p>
                  <p>Manual signup is no longer available. Please use "Sign in with Google" to create your account securely.</p>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if (isset($_SESSION['error'])): ?>
            <div class="mb-6 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-4 shadow-sm"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
          <?php endif; ?>
          <?php if (isset($_SESSION['info'])): ?>
            <div class="mb-6 text-sm bg-amber-50 border-2 border-amber-200 rounded-lg p-4 shadow-sm">
              <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
                <div class="text-amber-800">
                  <p class="font-semibold mb-1">Account Verification Pending</p>
                  <p><?php echo htmlspecialchars($_SESSION['info']); ?></p>
                </div>
              </div>
            </div>
            <?php unset($_SESSION['info']); ?>
          <?php endif; ?>
          <?php if (isset($_SESSION['toast'])): ?>
            <div data-toast="<?php echo htmlspecialchars($_SESSION['toast']); ?>"></div>
            <?php unset($_SESSION['toast']); ?>
          <?php endif; ?>
          </div>

          <!-- Google Sign-In Button -->
          <?php
          $show_google_button = false;
          $google_login_url = '#';
          
          if (file_exists('vendor/autoload.php') && file_exists('config/google_config.php')) {
              try {
                  require_once 'vendor/autoload.php';
                  require_once 'config/google_config.php';
                  
                  if (class_exists('Google_Client')) {
                      $google_client = new Google_Client();
                      $google_client->setClientId(GOOGLE_CLIENT_ID);
                      $google_client->setClientSecret(GOOGLE_CLIENT_SECRET);
                      $google_client->setRedirectUri(GOOGLE_REDIRECT_URI);
                      $google_client->addScope("email");
                      $google_client->addScope("profile");
                      
                      // OAuth state parameter for CSRF protection
                      // Add timestamp to prevent replay attacks (state expires in 10 minutes)
                      $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
                      $_SESSION['oauth_state_time'] = time();
                      $google_client->setState($_SESSION['oauth_state']);
                      
                      $google_login_url = $google_client->createAuthUrl();
                      $show_google_button = true;
                  }
              } catch (\Exception $e) {
                  // Google library not available, hide button
                  $show_google_button = false;
              }
          }
          ?>
          
          <?php if ($show_google_button): ?>
          <div id="google-action" class="flex justify-center">
            <a href="<?php echo htmlspecialchars($google_login_url); ?>"
               id="google-btn"
               class="focus:outline-none focus:ring-2 focus:ring-white/50">
              <span class="g-icon-wrap">
                <svg class="w-[15px] h-[15px]" viewBox="0 0 24 24">
                  <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                  <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                  <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                  <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
              </span>
              <span class="btn-label">Sign in with Google</span>
            </a>
          </div>
          <?php else: ?>
          <div class="text-center py-8" id="google-action">
            <svg class="w-16 h-16 mx-auto text-slate-200 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            <p class="text-white font-medium">Google Sign-In Unavailable</p>
            <p class="text-sky-100/80 text-sm mt-1">Please contact your administrator</p>
          </div>
          <?php endif; ?>
    </section>
  </main>

  <?php if ($showGoogleTermsModal): ?>
  <div id="google-terms-modal" class="fixed inset-0 z-[60] terms-modal-backdrop px-3 py-4 sm:px-6 sm:py-8 overflow-y-auto">
    <div class="min-h-full flex items-center justify-center">
      <section id="terms-panel" class="terms-panel w-full glass-card shadow-2xl rounded-[24px] sm:rounded-[28px] border border-white/30 p-4 sm:p-6 md:p-8 text-white">
        <div class="space-y-5">
          <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div class="min-w-0">
              <p class="text-[0.62rem] sm:text-xs uppercase tracking-[0.16em] text-sky-200/80">GateWatch Verification</p>
              <h2 class="text-2xl sm:text-3xl font-semibold tracking-tight leading-tight">Complete your registration</h2>
              <p class="mt-2 text-sky-100/85 text-sm sm:text-base">Before we create your GateWatch account, please review the Terms and provide emergency contact details.</p>
            </div>
          </div>

          <?php if ($googleTermsError !== ''): ?>
            <div class="text-sm bg-red-50/95 border border-red-200 rounded-lg p-4 text-red-800">
              <?php echo htmlspecialchars($googleTermsError); ?>
            </div>
          <?php endif; ?>

          <div class="rounded-2xl border border-white/20 bg-white/10 p-4 sm:p-5">
            <div class="terms-meta-grid">
              <div>
                <p class="text-[0.68rem] text-sky-100/70 uppercase tracking-[0.18em]">Student Name</p>
                <p class="font-semibold text-white break-words text-base sm:text-lg"><?php echo htmlspecialchars($studentNameForTerms); ?></p>
              </div>
              <div>
                <p class="text-[0.68rem] text-sky-100/70 uppercase tracking-[0.18em]">PCU Email Address</p>
                <p class="font-semibold text-white break-words text-sm sm:text-base"><?php echo htmlspecialchars($studentEmailForTerms); ?></p>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-2 text-xs text-sky-100/70">
            <span class="inline-flex h-2 w-2 rounded-full bg-sky-400"></span>
            <span><?php echo $openGuardianStep ? 'Step 2 of 2: Guardian details' : 'Step 1 of 2: Review terms'; ?></span>
          </div>

          <div class="<?php echo $openGuardianStep ? ' hidden' : ''; ?>" id="step-terms-login">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <h3 class="text-lg font-semibold leading-tight"><?php echo htmlspecialchars($termsTitle); ?></h3>
              <a href="terms_and_conditions.php" target="_blank" rel="noopener" class="text-sm text-sky-200 hover:text-white underline underline-offset-4">Open full Terms</a>
            </div>

            <div id="terms-scroll-box" class="mt-3 max-h-[32vh] sm:max-h-[38vh] overflow-auto rounded-2xl border border-white/20 bg-white/10 p-4 sm:p-5">
              <div class="terms-content space-y-4 text-sky-50/95 text-sm leading-relaxed [text-align:justify]">
                <?php echo gatewatch_terms_html(); ?>
              </div>
            </div>

            <div class="terms-consent-row mt-4 w-full min-w-0">
              <input id="accept-login" type="checkbox" class="mt-1 h-4 w-4 shrink-0 rounded border-white/30 bg-white/10 text-sky-400" <?php echo $openGuardianStep ? 'checked' : ''; ?> />
              <label for="accept-login" class="min-w-0 text-sm leading-relaxed text-sky-100/90 [text-align:justify] break-words">
                I have read and I agree to the Terms and Conditions, including my consent for GateWatch to collect and use my
                <strong>Full Name</strong>, <strong>Student ID</strong>, <strong>PCU Email Address</strong>, and my
                <strong>Parent/Guardian full name</strong>, <strong>email</strong>, and <strong>contact number</strong> for
                registration and emergency contact purposes.
              </label>
            </div>

            <p id="terms-scroll-hint" class="mt-3 text-xs text-sky-100/70">Scroll to the bottom of the Terms to enable Continue.</p>

            <div class="terms-actions mt-4">
              <button type="button" id="btn-continue-login" class="w-full rounded-full bg-sky-500/90 hover:bg-sky-500 px-6 py-2.5 text-sm font-semibold text-slate-900 transition disabled:opacity-50 disabled:cursor-not-allowed disabled:bg-sky-500/50">
                Accept & Continue
              </button>
              <form method="POST" action="google_terms.php" class="w-full">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="decline" />
                <button type="submit" class="w-full rounded-full border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-medium text-white hover:bg-white/15 transition">Decline & Exit</button>
              </form>
            </div>
          </div>

          <div class="<?php echo $openGuardianStep ? '' : ' hidden'; ?>" id="step-guardian-login">
            <h3 class="text-lg font-semibold leading-tight">Parent/Guardian Emergency Contact</h3>
            <p class="text-sm text-sky-100/85 mt-2 [text-align:justify]">
              Please provide your Parent/Guardian information so GateWatch can use it for emergency contact and registration support.
            </p>

            <form method="POST" action="google_terms.php" class="mt-5 space-y-4">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="complete" />
              <input type="hidden" name="accepted_terms" id="accepted_terms_login" value="<?php echo $openGuardianStep ? '1' : '0'; ?>" />

              <div>
                <label class="block text-sm font-medium text-sky-50">Parent/Guardian Full Name</label>
                <input name="guardian_full_name" type="text" required placeholder="e.g., Juan Dela Cruz"
                  value="<?php echo htmlspecialchars($guardianNameOld); ?>"
                  class="mt-1 w-full rounded-xl border border-white/25 bg-white/10 px-4 py-3 text-white placeholder:text-sky-100/50 focus:outline-none focus:ring-2 focus:ring-sky-300/60" />
              </div>

              <div>
                <label class="block text-sm font-medium text-sky-50">Parent/Guardian Email Address</label>
                <input name="guardian_email" type="email" required placeholder="e.g., guardian@example.com"
                  value="<?php echo htmlspecialchars($guardianEmailOld); ?>"
                  class="mt-1 w-full rounded-xl border border-white/25 bg-white/10 px-4 py-3 text-white placeholder:text-sky-100/50 focus:outline-none focus:ring-2 focus:ring-sky-300/60" />
              </div>

              <div>
                <label class="block text-sm font-medium text-sky-50">Parent/Guardian Contact Number</label>
                <input name="guardian_contact_number" type="tel" required placeholder="e.g., 09XXXXXXXXX"
                  value="<?php echo htmlspecialchars($guardianContactOld); ?>"
                  class="mt-1 w-full rounded-xl border border-white/25 bg-white/10 px-4 py-3 text-white placeholder:text-sky-100/50 focus:outline-none focus:ring-2 focus:ring-sky-300/60" />
              </div>

              <div class="terms-input-row pt-2">
                <button type="button" id="btn-back-login" class="w-full sm:w-auto rounded-full border border-white/25 bg-white/10 px-5 py-2.5 text-sm font-semibold text-white hover:bg-white/15 transition">Back</button>
                <button type="submit" class="w-full sm:w-auto rounded-full bg-emerald-400/90 hover:bg-emerald-400 px-6 py-2.5 text-sm font-semibold text-slate-900 transition">Submit & Create Account</button>
              </div>
            </form>
          </div>

          <p class="text-xs text-sky-100/70 [text-align:justify]">
            After submission, your account will be <strong>pending verification</strong> by the Student Services Office.
          </p>
        </div>
      </section>
    </div>
  </div>
  <?php endif; ?>

  <div id="toast-container" class="fixed top-4 right-4 space-y-2 z-50"></div>
  <script src="assets/js/app.js"></script>
  <script>
    function initThreeBackground() {
      if (typeof THREE === 'undefined') return;

      const canvas = document.getElementById('three-bg');
      if (!canvas) return;

      const scene = new THREE.Scene();
      const camera = new THREE.PerspectiveCamera(60, window.innerWidth / window.innerHeight, 0.1, 1000);
      camera.position.z = 45;

      const renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true });
      renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
      renderer.setSize(window.innerWidth, window.innerHeight);

      const count = 160;
      const particles = new THREE.BufferGeometry();
      const positions = new Float32Array(count * 3);
      const velocities = [];

      for (let i = 0; i < count; i++) {
        const i3 = i * 3;
        positions[i3] = (Math.random() - 0.5) * 90;
        positions[i3 + 1] = (Math.random() - 0.5) * 60;
        positions[i3 + 2] = (Math.random() - 0.5) * 30;
        velocities.push({ x: (Math.random() - 0.5) * 0.035, y: (Math.random() - 0.5) * 0.03 });
      }

      particles.setAttribute('position', new THREE.BufferAttribute(positions, 3));

      const points = new THREE.Points(
        particles,
        new THREE.PointsMaterial({ color: 0x7dd3fc, size: 0.23, transparent: true, opacity: 0.62 })
      );
      scene.add(points);

      const lineMaterial = new THREE.LineBasicMaterial({ color: 0x7dd3fc, transparent: true, opacity: 0.18 });
      const lineGeometry = new THREE.BufferGeometry();
      const linePositions = new Float32Array(count * 6);
      lineGeometry.setAttribute('position', new THREE.BufferAttribute(linePositions, 3));
      const lines = new THREE.LineSegments(lineGeometry, lineMaterial);
      scene.add(lines);

      function animate() {
        requestAnimationFrame(animate);

        const pos = particles.attributes.position.array;
        for (let i = 0; i < count; i++) {
          const i3 = i * 3;
          pos[i3] += velocities[i].x;
          pos[i3 + 1] += velocities[i].y;

          if (pos[i3] > 45 || pos[i3] < -45) velocities[i].x *= -1;
          if (pos[i3 + 1] > 30 || pos[i3 + 1] < -30) velocities[i].y *= -1;
        }

        let idx = 0;
        for (let i = 0; i < count; i += 4) {
          const a = i * 3;
          const b = ((i + 9) % count) * 3;

          linePositions[idx++] = pos[a];
          linePositions[idx++] = pos[a + 1];
          linePositions[idx++] = pos[a + 2];

          linePositions[idx++] = pos[b];
          linePositions[idx++] = pos[b + 1];
          linePositions[idx++] = pos[b + 2];
        }

        particles.attributes.position.needsUpdate = true;
        lineGeometry.setDrawRange(0, idx / 3);
        lineGeometry.attributes.position.needsUpdate = true;

        points.rotation.z += 0.00045;
        renderer.render(scene, camera);
      }

      animate();

      window.addEventListener('resize', function () {
        camera.aspect = window.innerWidth / window.innerHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(window.innerWidth, window.innerHeight);
      });
    }

    // Show toast notification if session message exists
    document.addEventListener('DOMContentLoaded', function() {
      initThreeBackground();

      if (typeof gsap !== 'undefined') {
        const entryTimeline = gsap.timeline({ defaults: { ease: 'power3.out' } });
        entryTimeline
          .from('#login-card', { y: 38, opacity: 0, duration: 0.9 })
          .from('#brand-block', { y: 12, opacity: 0, duration: 0.55 }, '-=0.45')
          .from('#status-stack > *', { y: 12, opacity: 0, duration: 0.35, stagger: 0.08 }, '-=0.24')
          .from('#google-action', { y: 16, opacity: 0, duration: 0.48 }, '-=0.2');

        gsap.to('#halo-one', { x: 26, y: -14, duration: 6.2, repeat: -1, yoyo: true, ease: 'sine.inOut' });
        gsap.to('#halo-two', { x: -24, y: 12, duration: 5.7, repeat: -1, yoyo: true, ease: 'sine.inOut' });

        const loginCard = document.getElementById('login-card');
        if (loginCard) {
          loginCard.addEventListener('mousemove', function (event) {
            const rect = loginCard.getBoundingClientRect();
            const offsetX = (event.clientX - rect.left) / rect.width - 0.5;
            const offsetY = (event.clientY - rect.top) / rect.height - 0.5;

            gsap.to(loginCard, {
              rotateY: offsetX * 4,
              rotateX: -offsetY * 3,
              transformPerspective: 900,
              transformOrigin: 'center',
              duration: 0.35,
              ease: 'power2.out'
            });
          });

          loginCard.addEventListener('mouseleave', function () {
            gsap.to(loginCard, { rotateX: 0, rotateY: 0, duration: 0.45, ease: 'power3.out' });
          });
        }

        const termsPanel = document.getElementById('terms-panel');
        if (termsPanel) {
          gsap.fromTo('#google-terms-modal', { opacity: 0 }, { opacity: 1, duration: 0.25, ease: 'power1.out' });
          gsap.fromTo(termsPanel, { y: 24, scale: 0.985, opacity: 0 }, { y: 0, scale: 1, opacity: 1, duration: 0.42, ease: 'power3.out' });
        }
      }

      const toastElement = document.querySelector('[data-toast]');
      if (toastElement) {
        const message = toastElement.getAttribute('data-toast');
        if (message) {
          showToast(message, 'info');
        }
      }

      const acceptLogin = document.getElementById('accept-login');
      const btnContinueLogin = document.getElementById('btn-continue-login');
      const termsScrollBox = document.getElementById('terms-scroll-box');
      const termsScrollHint = document.getElementById('terms-scroll-hint');
      const stepTermsLogin = document.getElementById('step-terms-login');
      const stepGuardianLogin = document.getElementById('step-guardian-login');
      const acceptedTermsLogin = document.getElementById('accepted_terms_login');
      const btnBackLogin = document.getElementById('btn-back-login');

      if (acceptLogin && btnContinueLogin && stepTermsLogin && stepGuardianLogin && acceptedTermsLogin && btnBackLogin) {
        const hasReachedBottom = () => {
          if (!termsScrollBox) return true;

          // If content doesn't overflow, treat as already read.
          if (termsScrollBox.scrollHeight <= (termsScrollBox.clientHeight + 2)) {
            return true;
          }

          return (termsScrollBox.scrollTop + termsScrollBox.clientHeight) >= (termsScrollBox.scrollHeight - 2);
        };

        const syncScrollHint = (reachedBottom) => {
          if (!termsScrollHint) return;
          termsScrollHint.textContent = reachedBottom
            ? 'You reached the end of the Terms. You may continue once consent is checked.'
            : 'Scroll to the bottom of the Terms to enable Continue.';
          termsScrollHint.classList.toggle('text-emerald-300', reachedBottom);
          termsScrollHint.classList.toggle('text-sky-100/70', !reachedBottom);
        };

        const syncContinueState = () => {
          const reachedBottom = hasReachedBottom();
          syncScrollHint(reachedBottom);
          btnContinueLogin.disabled = !(acceptLogin.checked && reachedBottom);
        };

        acceptLogin.addEventListener('change', syncContinueState);
        if (termsScrollBox) {
          termsScrollBox.addEventListener('scroll', syncContinueState);
        }
        syncContinueState();

        btnContinueLogin.addEventListener('click', () => {
          if (btnContinueLogin.disabled) return;
          acceptedTermsLogin.value = '1';
          stepTermsLogin.classList.add('hidden');
          stepGuardianLogin.classList.remove('hidden');

          const firstInput = stepGuardianLogin.querySelector('input[name="guardian_full_name"]');
          if (firstInput) {
            firstInput.focus({ preventScroll: true });
          }
          stepGuardianLogin.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        btnBackLogin.addEventListener('click', () => {
          stepGuardianLogin.classList.add('hidden');
          stepTermsLogin.classList.remove('hidden');
          acceptedTermsLogin.value = '0';
          stepTermsLogin.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      }
    });

    function showToast(message, type = 'info') {
      const container = document.getElementById('toast-container');
      const toast = document.createElement('div');
      
      const bgColor = type === 'error' ? 'bg-red-500' : type === 'success' ? 'bg-green-500' : 'bg-blue-500';
      
      toast.className = `${bgColor} text-white px-6 py-3 rounded-lg shadow-lg flex items-center gap-3 min-w-[300px] transform transition-all duration-300 translate-x-0 opacity-100`;
      toast.innerHTML = `
        <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
        </svg>
        <span class="flex-1">${message}</span>
        <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
          </svg>
        </button>
      `;
      
      container.appendChild(toast);

      if (typeof gsap !== 'undefined') {
        gsap.fromTo(toast, { x: 80, opacity: 0 }, { x: 0, opacity: 1, duration: 0.35, ease: 'power2.out' });
      }
      
      // Auto-remove after 5 seconds
      setTimeout(() => {
        if (typeof gsap !== 'undefined') {
          gsap.to(toast, { x: 120, opacity: 0, duration: 0.3, ease: 'power2.in', onComplete: () => toast.remove() });
        } else {
          toast.style.transform = 'translateX(400px)';
          toast.style.opacity = '0';
          setTimeout(() => toast.remove(), 300);
        }
      }, 5000);
    }
  </script>
</body>
</html>
