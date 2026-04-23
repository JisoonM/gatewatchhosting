<?php

session_start();

// Only allow access while a security guard session is active.
if (empty($_SESSION['security_logged_in']) || $_SESSION['security_logged_in'] !== true) {
    header('Location: ../security/security_login.php');
    exit;
}

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none';");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GateWatch | Student Verification Display</title>
    <link rel="icon" type="image/png" href="../assets/images/gatewatch-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/tailwind.config.js"></script>
    <script src="../assets/js/digital-id-card.js?v=11"></script>
    <link rel="preload" as="image" href="../assets/images/id-card-template.png">
    <style>
        @font-face {
            font-family: 'OldEnglish';
            src: url('../assets/fonts/OldEnglishTextMT.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
        }

        :root { --brand: #0056b3; }

        html, body { height: 100%; margin: 0; overflow: hidden; }

        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .brand-wordmark { font-family: 'OldEnglish', serif; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .fade-in { animation: fadeIn 0.4s ease-out; }

        @keyframes scanLine {
            0%   { top: 0; }
            100% { top: 100%; }
        }
        .scan-line { animation: scanLine 2.5s ease-in-out infinite; }

        @keyframes pulseDot {
            0%, 100% { opacity: .4; }
            50%      { opacity: 1; }
        }
        .pulse-dot { animation: pulseDot 1.4s ease-in-out infinite; }

        #feedCanvas { object-fit: contain; }
    </style>
</head>
<body class="text-white select-none">
<div class="h-screen flex flex-col">

    <!-- ===== HEADER ===== -->
    <header class="flex items-center justify-between px-6 py-3 bg-black/50 border-b border-white/10 flex-shrink-0 backdrop-blur">
        <div class="flex items-center gap-3">
            <img src="../assets/images/gatewatch-logo.png" alt="GateWatch" class="w-10 h-10 rounded-lg shadow-lg">
            <div>
                <h1 class="text-lg font-bold text-white leading-tight tracking-tight">GateWatch</h1>
                <p class="text-[11px] text-slate-400 tracking-wide">Student Verification Display</p>
            </div>
        </div>
        <div class="flex items-center gap-4">
            <span id="connectionStatus" class="flex items-center gap-1.5 text-xs font-medium">
                <span class="w-2 h-2 rounded-full bg-yellow-400 pulse-dot"></span>
                <span class="text-yellow-300">Waiting for connection&hellip;</span>
            </span>
            <span class="text-xs text-slate-500"><?php echo date('M j, Y'); ?></span>
        </div>
    </header>

    <!-- ===== MAIN CONTENT ===== -->
    <div class="flex-1 flex flex-col lg:flex-row gap-4 p-4 min-h-0">

        <!-- LEFT: Camera Feed -->
        <div class="lg:flex-[1.6] flex flex-col min-h-0">
            <div id="cameraContainer" class="relative flex-1 bg-black/60 rounded-2xl overflow-hidden border border-white/10 flex items-center justify-center">

                <!-- Idle overlay (shown before frames arrive) -->
                <div id="idleOverlay" class="absolute inset-0 flex items-center justify-center z-10 bg-black/60">
                    <div class="text-center p-8">
                        <img src="../assets/images/gatewatch-logo.png" alt="GateWatch" class="w-24 h-24 mx-auto mb-6 opacity-50 drop-shadow-xl">
                        <h2 class="text-2xl font-bold text-white/80 mb-2">Please Face the Camera</h2>
                        <p class="text-slate-400 text-sm">The security system will verify your identity</p>
                        <div class="mt-6 flex justify-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-sky-400 pulse-dot"></span>
                            <span class="w-2 h-2 rounded-full bg-sky-400 pulse-dot" style="animation-delay:.2s"></span>
                            <span class="w-2 h-2 rounded-full bg-sky-400 pulse-dot" style="animation-delay:.4s"></span>
                        </div>
                    </div>
                </div>

                <!-- Camera canvas (receives composited frames from guard) -->
                <canvas id="feedCanvas" class="w-full h-full"></canvas>

                <!-- Popup detection overlay (green box drawn by popup face engine) -->
                <canvas id="feedOverlay" class="absolute inset-0 w-full h-full pointer-events-none" style="object-fit: contain;"></canvas>

                <!-- Scan line effect -->
                <div id="scanLineOverlay" class="absolute inset-0 pointer-events-none hidden overflow-hidden">
                    <div class="scan-line absolute left-0 w-full h-0.5 bg-gradient-to-r from-transparent via-sky-400/60 to-transparent"></div>
                </div>

                <!-- Status badge -->
                <div id="feedBadge" class="absolute top-3 left-3 px-3 py-1.5 rounded-full text-xs font-medium bg-black/70 border border-white/20 text-slate-300 backdrop-blur z-20">
                    &#x23F3; Waiting for connection
                </div>
            </div>
        </div>

        <!-- RIGHT: Result Panel -->
        <div class="lg:w-[420px] flex flex-col min-h-0">
            <div id="resultCard" class="flex-1 bg-black/40 rounded-2xl border border-white/10 overflow-y-auto flex flex-col">
                <div id="resultPanel" class="flex-1 flex items-center justify-center p-6">
                    <div class="text-center">
                        <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-white/5 border border-white/10 flex items-center justify-center">
                            <svg class="w-10 h-10 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                      d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z"/>
                            </svg>
                        </div>
                        <p class="text-slate-400 text-sm leading-relaxed">Your Digital ID will appear here<br>after verification</p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// ============================================================
// STUDENT DISPLAY — BroadcastChannel Receiver
// ============================================================

function escHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(s)));
    return d.innerHTML;
}

const channel      = new BroadcastChannel('gatewatch-student-display');
const feedCanvas   = document.getElementById('feedCanvas');
const feedCtx      = feedCanvas.getContext('2d');
const feedOverlay  = document.getElementById('feedOverlay');
const idleOverlay  = document.getElementById('idleOverlay');
const scanLine     = document.getElementById('scanLineOverlay');
const feedBadge    = document.getElementById('feedBadge');
const resultPanel  = document.getElementById('resultPanel');
const connStatus   = document.getElementById('connectionStatus');

let connected       = false;
let connectionTimer = null;
let clearTimer      = null;

// Fast frame renderer: receives Blob, uses createImageBitmap (GPU-accelerated, no DOM Image)
let _prevBlobURL = null;
const _supportsImageBitmap = typeof createImageBitmap === 'function';

function renderFrame(blob) {
    if (_supportsImageBitmap) {
        createImageBitmap(blob).then(function(bmp) {
            if (feedCanvas.width !== bmp.width || feedCanvas.height !== bmp.height) {
                feedCanvas.width  = bmp.width;
                feedCanvas.height = bmp.height;
                feedOverlay.width  = bmp.width;
                feedOverlay.height = bmp.height;
            }
            feedCtx.drawImage(bmp, 0, 0);
            bmp.close();
        });
    } else {
        // Fallback for older browsers: objectURL → Image
        if (_prevBlobURL) URL.revokeObjectURL(_prevBlobURL);
        _prevBlobURL = URL.createObjectURL(blob);
        const img = new Image();
        img.onload = function() {
            if (feedCanvas.width !== img.naturalWidth || feedCanvas.height !== img.naturalHeight) {
                feedCanvas.width  = img.naturalWidth;
                feedCanvas.height = img.naturalHeight;
                feedOverlay.width  = img.naturalWidth;
                feedOverlay.height = img.naturalHeight;
            }
            feedCtx.drawImage(img, 0, 0);
            URL.revokeObjectURL(_prevBlobURL);
            _prevBlobURL = null;
        };
        img.src = _prevBlobURL;
    }
}

// ── Helpers ──────────────────────────────────────────────────

function setConnected(yes) {
    connected = yes;
    connStatus.innerHTML = yes
        ? '<span class="w-2 h-2 rounded-full bg-green-400 inline-block"></span> <span class="text-green-300">Connected</span>'
        : '<span class="w-2 h-2 rounded-full bg-yellow-400 pulse-dot inline-block"></span> <span class="text-yellow-300">Waiting for connection&hellip;</span>';
}

function showFeed()  { idleOverlay.classList.add('hidden'); }
function showIdle()  {
    idleOverlay.classList.remove('hidden');
    scanLine.classList.add('hidden');
    feedBadge.textContent = '\u23F3 Waiting for connection';
}

function defaultResult() {
    resultPanel.innerHTML = `
        <div class="flex-1 flex items-center justify-center p-6">
            <div class="text-center">
                <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-white/5 border border-white/10 flex items-center justify-center">
                    <svg class="w-10 h-10 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z"/>
                    </svg>
                </div>
                <p class="text-slate-400 text-sm leading-relaxed">Your Digital ID will appear here<br>after verification</p>
            </div>
        </div>`;
}

function schedClear(ms) {
    if (clearTimer) clearTimeout(clearTimer);
    clearTimer = setTimeout(defaultResult, ms);
}

// ── Incoming messages ────────────────────────────────────────

channel.onmessage = function (e) {
    const msg = e.data;
    if (!msg || typeof msg.type !== 'string') return;

    // Keep-alive / connection tracking
    if (!connected) setConnected(true);
    clearTimeout(connectionTimer);
    connectionTimer = setTimeout(function () { setConnected(false); showIdle(); }, 8000);

    switch (msg.type) {
        case 'connected':
            if (msg.cameraRunning) {
                feedBadge.textContent = msg.detectionRunning ? 'Scanning...' : 'Camera preview ready';
            } else if (msg.currentMode === 'face') {
                feedBadge.textContent = 'Starting camera...';
            } else {
                feedBadge.textContent = 'Preparing face preview...';
            }
            break;

        case 'frame':
            showFeed();
            if (msg.blob instanceof Blob) renderFrame(msg.blob);
            break;

        case 'scanning':
            feedBadge.innerHTML = '<span class="w-2 h-2 rounded-full bg-green-400 animate-pulse inline-block mr-1"></span> Scanning\u2026';
            scanLine.classList.remove('hidden');
            break;

        case 'idle':
            feedBadge.textContent = '\u23F8 Detection paused';
            scanLine.classList.add('hidden');
            break;

        case 'stopped':
            feedBadge.textContent = '\u23F9 Camera off';
            scanLine.classList.add('hidden');
            showIdle();
            break;

        case 'match':
            handleMatch(msg);
            break;

        case 'access_denied':
            handleAccessDenied(msg);
            break;

        case 'not_recognized':
            handleNotRecognized();
            break;

        case 'liveness_failed':
            handleLivenessFailed();
            break;

        case 'clear':
            defaultResult();
            break;
    }
};

// ── Result Handlers ──────────────────────────────────────────

function handleMatch(msg) {
    if (clearTimer) clearTimeout(clearTimer);
    const s = msg.student || {};
    const m = msg.match   || {};

    scanLine.classList.add('hidden');
    feedBadge.innerHTML = '<span class="w-2 h-2 rounded-full bg-green-400 inline-block mr-1"></span> \u2713 Verified';

    resultPanel.innerHTML = `
        <div class="p-5 fade-in">
            <div class="text-center mb-4">
                <span class="inline-flex items-center gap-2 bg-green-500/20 border border-green-400/40 rounded-full px-5 py-2">
                    <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="text-green-300 text-sm font-semibold">Identity Verified</span>
                </span>
            </div>
            <div id="studentIdCard" class="mb-4"></div>
            <div class="bg-white/5 rounded-xl p-4 border border-white/10 text-center">
                <p class="text-white font-semibold text-lg">${escHtml(s.name)}</p>
                <p class="text-slate-400 text-sm">${escHtml(s.student_id)}</p>
                ${s.course ? '<p class="text-slate-500 text-xs mt-1">' + escHtml(s.course) + '</p>' : ''}
                <p class="text-green-400/80 text-xs mt-2">Confidence: ${((m.confidence || 0) * 100).toFixed(1)}%</p>
            </div>
            ${s.violation_count > 0 ? `
            <div class="mt-3 bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-3 text-center">
                <p class="text-yellow-300 text-sm font-medium">Has Violation(s) on File</p>
                <p class="text-yellow-200/70 text-xs">Contact SSO Office for details</p>
            </div>` : ''}
        </div>`;

    new DigitalIdCard('#studentIdCard', {
        templateSrc: '../assets/images/id-card-template.png',
        student: {
            name:           s.name       || '',
            studentId:      s.student_id || '',
            course:         s.course     || '',
            email:          s.email      || '',
            profilePicture: s.profile_picture ? '../assets/images/profiles/' + s.profile_picture : null
        }
    }).render();

    schedClear(8000);
}

function handleAccessDenied(msg) {
    if (clearTimer) clearTimeout(clearTimer);
    const s = msg.student || {};

    feedBadge.innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500 inline-block mr-1"></span> \u26D4 Access Denied';

    resultPanel.innerHTML = `
        <div class="p-5 fade-in">
            <div class="text-center mb-4">
                <span class="inline-flex items-center gap-2 bg-red-500/20 border border-red-400/40 rounded-full px-5 py-2">
                    <span class="text-red-300 text-sm font-semibold">\u26D4 Access Denied</span>
                </span>
            </div>
            <div id="studentIdCard" class="mb-4"></div>
            <div class="bg-red-500/10 border border-red-500/30 rounded-xl p-4 text-center">
                <p class="text-red-300 font-bold text-lg mb-1">ENTRY NOT ALLOWED</p>
                <p class="text-red-200/80 text-sm">Maximum violation limit exceeded.</p>
                <p class="text-red-200/60 text-xs mt-2">Please contact the SSO Office.</p>
            </div>
        </div>`;

    new DigitalIdCard('#studentIdCard', {
        templateSrc: '../assets/images/id-card-template.png',
        student: {
            name:           s.name       || '',
            studentId:      s.student_id || '',
            course:         s.course     || '',
            email:          s.email      || '',
            profilePicture: s.profile_picture ? '../assets/images/profiles/' + s.profile_picture : null
        }
    }).render();

    schedClear(8000);
}

function handleNotRecognized() {
    if (clearTimer) clearTimeout(clearTimer);
    feedBadge.innerHTML = '<span class="w-2 h-2 rounded-full bg-red-400 inline-block mr-1"></span> Not Recognized';

    resultPanel.innerHTML = `
        <div class="flex-1 flex items-center justify-center p-6 fade-in">
            <div class="text-center">
                <div class="text-5xl mb-4">\uD83D\uDEAB</div>
                <h3 class="text-xl font-bold text-red-400 mb-2">NOT RECOGNIZED</h3>
                <p class="text-slate-400 text-sm leading-relaxed">Your face is not registered in the system.<br>Please present your RFID card or<br>contact administration.</p>
            </div>
        </div>`;

    schedClear(5000);
}

function handleLivenessFailed() {
    if (clearTimer) clearTimeout(clearTimer);
    feedBadge.innerHTML = '<span class="w-2 h-2 rounded-full bg-orange-400 inline-block mr-1"></span> Liveness Failed';

    resultPanel.innerHTML = `
        <div class="flex-1 flex items-center justify-center p-6 fade-in">
            <div class="text-center">
                <div class="text-5xl mb-4">\uD83D\uDCF7</div>
                <h3 class="text-xl font-bold text-orange-400 mb-2">LIVE FACE REQUIRED</h3>
                <p class="text-slate-400 text-sm leading-relaxed">Please face the camera directly.<br>Photos and screens are not accepted.</p>
            </div>
        </div>`;

    schedClear(5000);
}

// ── Lifecycle ────────────────────────────────────────────────

// Tell the guard tab we're here
channel.postMessage({ type: 'student_display_connected' });

// Periodic ping so the guard knows we're still alive
setInterval(function () {
    channel.postMessage({ type: 'student_display_connected' });
}, 2000);

window.addEventListener('beforeunload', function () {
    channel.postMessage({ type: 'student_display_disconnected' });
});
</script>
</body>
</html>
