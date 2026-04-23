<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/admin_face_helper.php';

require_superadmin_auth();

require_permission('admin.create', [
    'actor_role' => 'superadmin',
    'response' => 'http',
    'message' => 'Forbidden: missing permission admin.create.',
]);

$page_title = 'Enroll Admin Face';

$faceEnabled = filter_var(env('FACE_RECOGNITION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);

$adminUserId = (int)($_GET['admin_id'] ?? 0);
if ($adminUserId <= 0) {
    $_SESSION['error'] = 'Invalid admin ID.';
    header('Location: homepage.php');
    exit;
}

try {
    $pdo = pdo();
    ensure_admin_face_tables($pdo);

    $stmt = $pdo->prepare("SELECT id, name, email, student_id, status FROM users WHERE id = ? AND role = 'Admin' LIMIT 1");
    $stmt->execute([$adminUserId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        $_SESSION['error'] = 'Admin not found.';
        header('Location: homepage.php');
        exit;
    }

    $stmt = $pdo->prepare('SELECT label FROM admin_face_descriptors WHERE user_id = ? AND is_active = 1 ORDER BY created_at ASC');
    $stmt->execute([$adminUserId]);
    $labels = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (Throwable $e) {
    error_log('Enroll admin face page error: ' . $e->getMessage());
    $_SESSION['error'] = 'Unable to load enrollment page.';
    header('Location: homepage.php');
    exit;
}

$existingLabels = array_values(array_filter(array_map('strtolower', array_map('strval', $labels ?? []))));
$existingLabelsJson = json_encode($existingLabels);

include __DIR__ . '/includes/header.php';
?>

<div class="mb-6 fade-in">
    <div class="glass-effect rounded-xl p-4 sm:p-6 shadow-lg">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Enroll Admin Face</h1>
                <p class="text-slate-600 mt-1">Admin: <span class="font-semibold text-slate-800"><?php echo e((string)$admin['name']); ?></span> • <?php echo e((string)$admin['email']); ?></p>
                <p class="text-slate-500 text-sm mt-1">Admin ID: <?php echo e((string)$admin['student_id']); ?></p>
            </div>
            <div class="flex gap-2">
                <a href="homepage.php" class="px-4 py-2 rounded-xl border border-slate-300 text-slate-700 font-semibold hover:bg-slate-50 transition-colors">Back</a>
            </div>
        </div>
    </div>
</div>

<?php if (!$faceEnabled): ?>
    <div class="glass-effect rounded-xl p-6 shadow-lg">
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 p-4 rounded-xl">
            <p class="font-semibold">Face recognition is currently disabled.</p>
            <p class="text-sm mt-1">Set <span class="font-mono">FACE_RECOGNITION_ENABLED=true</span> in your <span class="font-mono">.env</span> file to enable enrollment.</p>
        </div>
    </div>
<?php else: ?>

<div class="glass-effect rounded-xl p-4 sm:p-6 shadow-lg fade-in" style="animation-delay: 0.1s;">
    <div class="flex flex-col lg:flex-row gap-6">
        <div class="flex-1">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <span id="step1" class="flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-slate-200 text-slate-500">1 • Front</span>
                <span id="step2" class="flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-slate-200 text-slate-500">2 • Left</span>
                <span id="step3" class="flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-slate-200 text-slate-500">3 • Right</span>
            </div>

            <div id="statusBox" class="bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4">Loading face recognition models…</div>

            <div class="relative mx-auto mb-4 bg-black rounded-lg overflow-hidden" style="max-width: 640px;">
                <video id="video" class="w-full" autoplay muted playsinline></video>
                <canvas id="canvas" class="absolute top-0 left-0 w-full h-full pointer-events-none"></canvas>
            </div>

            <div class="text-center mb-4">
                <p class="text-slate-700 font-medium" id="instruction"></p>
            </div>

            <div class="flex gap-3">
                <button id="btnCapture" class="flex-1 px-4 py-3 bg-gradient-to-r from-[#0056b3] to-[#003d82] text-white rounded-xl font-semibold btn-hover disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    Capture
                </button>
                <button id="btnCancel" class="px-4 py-3 border border-slate-300 text-slate-700 rounded-xl font-semibold hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
            </div>
        </div>

        <div class="w-full lg:w-80">
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                <h2 class="text-sm font-semibold text-slate-800 mb-2">Enrollment Notes</h2>
                <ul class="text-sm text-slate-600 space-y-2">
                    <li>Use good lighting and a neutral background.</li>
                    <li>Keep face centered and close enough.</li>
                    <li>Capture 3 angles: front, left, right.</li>
                </ul>
                <div class="mt-4">
                    <p class="text-xs font-medium text-slate-500">Already captured:</p>
                    <div id="captured" class="mt-2 flex flex-wrap gap-2"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo e($_SESSION['csrf_token']); ?>';
const adminUserId = <?php echo (int)$adminUserId; ?>;
const existingLabels = <?php echo $existingLabelsJson ?: '[]'; ?>;

const requiredAngles = ['front', 'left', 'right'];
let currentStep = 0;

function normalizeLabel(label) {
    return (label || '').toString().toLowerCase();
}

function computeStartStep() {
    const set = new Set(existingLabels.map(normalizeLabel));
    for (let i = 0; i < requiredAngles.length; i++) {
        if (!set.has(requiredAngles[i])) return i;
    }
    return requiredAngles.length;
}

function renderCaptured() {
    const container = document.getElementById('captured');
    container.innerHTML = '';
    const set = new Set(existingLabels.map(normalizeLabel));

    requiredAngles.forEach(a => {
        const done = set.has(a);
        const el = document.createElement('span');
        el.className = done
            ? 'px-2 py-1 rounded-lg text-xs font-semibold bg-green-100 text-green-700 border border-green-200'
            : 'px-2 py-1 rounded-lg text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-200';
        el.textContent = done ? `✓ ${a}` : a;
        container.appendChild(el);
    });
}

function updateStepsUI() {
    for (let i = 0; i < 3; i++) {
        const el = document.getElementById('step' + (i + 1));
        if (!el) continue;
        if (i < currentStep) {
            el.className = 'flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-green-500 text-white';
        } else if (i === currentStep) {
            el.className = 'flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-[#0056b3] text-white';
        } else {
            el.className = 'flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-slate-200 text-slate-500';
        }
    }
}

function setInstruction(angle) {
    const map = {
        front: 'Position the admin facing the camera directly (front).',
        left: 'Ask the admin to turn their head slightly to the left.',
        right: 'Ask the admin to turn their head slightly to the right.'
    };
    document.getElementById('instruction').textContent = map[angle] || '';
    document.getElementById('btnCapture').textContent = 'Capture ' + angle.charAt(0).toUpperCase() + angle.slice(1);
}
</script>

<script defer src="../assets/js/vendor/face-api.min.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/vendor/face-api.min.js'); ?>"></script>
<script defer src="../assets/js/face-recognition.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/face-recognition.js'); ?>"></script>

<script>
let faceSystem = null;
let liveTimer = null;

function setStatus(message, type = 'info') {
    const box = document.getElementById('statusBox');
    if (!box) return;
    box.textContent = message;
    if (type === 'error') box.className = 'bg-red-50 text-red-700 text-sm p-3 rounded-lg mb-4';
    else if (type === 'success') box.className = 'bg-green-50 text-green-700 text-sm p-3 rounded-lg mb-4';
    else if (type === 'warn') box.className = 'bg-yellow-50 text-yellow-700 text-sm p-3 rounded-lg mb-4';
    else box.className = 'bg-blue-50 text-blue-700 text-sm p-3 rounded-lg mb-4';
}

async function start() {
    renderCaptured();
    currentStep = computeStartStep();

    if (currentStep >= requiredAngles.length) {
        updateStepsUI();
        setStatus('✅ Enrollment already complete for this admin.', 'success');
        document.getElementById('btnCapture').disabled = true;
        setInstruction('front');
        return;
    }

    updateStepsUI();
    setInstruction(requiredAngles[currentStep]);

    faceSystem = new FaceRecognitionSystem({
        modelPath: '../assets/models',
        minConfidence: 0.5,
        csrfToken: csrfToken,
        onStatusChange: (s, msg) => setStatus(msg, 'info'),
        onError: (msg) => setStatus('❌ ' + msg, 'error')
    });

    setStatus('Loading face recognition models (first time may take a moment)…', 'info');
    const okModels = await faceSystem.loadModels();
    if (!okModels) {
        setStatus('❌ Failed to load models. Ensure model files exist in assets/models/.', 'error');
        return;
    }

    setStatus('Starting camera…', 'info');
    const okCam = await faceSystem.startCamera(
        document.getElementById('video'),
        document.getElementById('canvas')
    );

    if (!okCam) {
        setStatus('❌ Camera failed. Check browser permissions.', 'error');
        return;
    }

    setStatus('✅ Camera ready. Align face and click Capture.', 'success');
    document.getElementById('btnCapture').disabled = false;

    liveTimer = setInterval(async () => {
        if (!faceSystem) return;
        await faceSystem.detectSingleFace();
    }, 300);
}

async function capture() {
    if (!faceSystem) return;

    const btn = document.getElementById('btnCapture');
    btn.disabled = true;

    const angle = requiredAngles[currentStep];
    setStatus('📸 Capturing ' + angle + '…', 'info');

    const detection = await faceSystem.detectSingleFace();
    if (!detection) {
        setStatus('❌ No face detected. Please position the face clearly.', 'error');
        btn.disabled = false;
        return;
    }

    if (detection.score < 0.5) {
        setStatus('⚠️ Low confidence (' + (detection.score * 100).toFixed(1) + '%). Try better lighting.', 'warn');
        btn.disabled = false;
        return;
    }

    const quality = faceSystem.assessFaceQuality(detection);
    if (!quality.acceptable) {
        setStatus('⚠️ Quality too low (' + (quality.score * 100).toFixed(0) + '%). Try again with better positioning.', 'warn');
        btn.disabled = false;
        return;
    }

    setStatus('⬆️ Saving ' + angle + ' descriptor…', 'info');

    const result = await faceSystem.registerFace(
        adminUserId,
        detection.descriptor,
        angle,
        detection.score,
        'register_admin_face.php'
    );

    if (!result || !result.success) {
        setStatus('❌ ' + (result?.error || 'Failed to save face.'), 'error');
        btn.disabled = false;
        return;
    }

    existingLabels.push(angle);
    renderCaptured();

    currentStep++;
    if (currentStep >= requiredAngles.length) {
        updateStepsUI();
        setStatus('🎉 Enrollment complete! Front, left, and right captured.', 'success');
        btn.textContent = 'Enrollment Complete';
        btn.disabled = true;

        if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
        if (faceSystem) { faceSystem.stopCamera(); }

        Swal.fire({
            icon: 'success',
            title: 'Enrollment Complete',
            text: 'Admin face enrollment has been saved successfully.',
            confirmButtonColor: '#0056b3'
        }).then(() => {
            window.location.href = 'homepage.php';
        });
        return;
    }

    updateStepsUI();
    setInstruction(requiredAngles[currentStep]);
    setStatus('✅ Captured! Now capture ' + requiredAngles[currentStep] + '.', 'success');
    btn.disabled = false;
}

function cancel() {
    if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
    try { if (faceSystem) faceSystem.stopCamera(); } catch (e) {}
    window.location.href = 'homepage.php';
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('btnCapture').addEventListener('click', capture);
    document.getElementById('btnCancel').addEventListener('click', cancel);
    start();
});
</script>

<?php endif; ?>

<?php
// Footer and closing tags are in header include's layout; keep consistent with existing pages.
?>

</main>

<!-- Footer -->
<footer class="glass-effect border-t border-slate-200 mt-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
        <p class="text-center text-sm text-slate-500">
            © <?php echo date('Y'); ?> Philippine Christian University • Super Admin Panel
        </p>
    </div>
</footer>

</body>
</html>
