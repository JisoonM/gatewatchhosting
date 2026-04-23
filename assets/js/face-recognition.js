/**
 * PCU Face Recognition System
 * Uses face-api.js (TensorFlow.js) with pre-trained models
 * 
 * Security: Face descriptors are extracted client-side, sent to server
 * for encrypted storage. Matching happens client-side with decrypted
 * descriptors fetched over authenticated API.
 * 
 * Anti-spoofing: LivenessDetector uses Eye Aspect Ratio (EAR) blink detection,
 * inter-frame landmark movement analysis, texture analysis (LBP variance),
 * and temporal consistency to reject flat photos/screens.
 * MatchAccumulator requires N consecutive frames matching the same person.
 * 
 * Performance: Uses detect → track → embed pipeline with Web Worker matching.
 * Only runs full ML inference every Nth frame; tracked frames use IOU prediction.
 * 
 * @version 3.0.0
*/
class LivenessDetector {
    constructor(options = {}) {
        this.maxFrames = options.maxFrames || 40;
        this.blinkThreshold = options.blinkThreshold || 0.21;
        this.movementThreshold = options.movementThreshold || 1.2;
        this.minFramesRequired = options.minFramesRequired || 8;
        this.frameHistory = [];
        this.earHistory = [];
        this.blinkDetected = false;
        // Texture analysis: store LBP-like variance scores per frame
        this._textureScores = [];
        // Temporal consistency: store face box sizes per frame
        this._faceSizes = [];
    }

    addFrame(landmarks, faceBox, videoElement) {
        if (!landmarks || !landmarks.positions || landmarks.positions.length < 68) return;
        const pts = landmarks.positions;
        const leftEAR = this._calcEAR(pts, 36);
        const rightEAR = this._calcEAR(pts, 42);
        const avgEAR = (leftEAR + rightEAR) / 2;

        this.earHistory.push(avgEAR);
        this.frameHistory.push({
            keyPoints: this._extractKeyPoints(pts),
            timestamp: Date.now(),
            faceBox: faceBox,
            ear: avgEAR
        });

        // Track face sizes for temporal consistency
        if (faceBox) {
            this._faceSizes.push({ area: faceBox.width * faceBox.height, ts: Date.now() });
            if (this._faceSizes.length > this.maxFrames) this._faceSizes.shift();
        }

        // Compute texture score if video element is available
        if (videoElement && faceBox) {
            const textureScore = this._calcTextureScore(videoElement, faceBox);
            this._textureScores.push(textureScore);
            if (this._textureScores.length > this.maxFrames) this._textureScores.shift();
        }

        if (this.frameHistory.length > this.maxFrames) {
            this.frameHistory.shift();
            this.earHistory.shift();
        }
        this._detectBlink();
    }

    checkLiveness() {
        if (this.frameHistory.length < this.minFramesRequired) {
            return { live: false, reason: 'insufficient_frames', score: 0, reasons: [] };
        }
        const movementScore = this._calcMovementVariance();
        const hasNonUniform = this._hasNonUniformMovement();
        const hasBlink = this.blinkDetected;
        const hasTexture = this._hasRealTexture();
        const isTemporalConsistent = this._checkTemporalConsistency();

        let score = 0;
        const reasons = [];

        // Natural micro-movement variance (real faces jitter; photos are static/uniform)
        if (movementScore > this.movementThreshold) {
            score += 0.25;
            reasons.push('movement');
        }
        // Non-uniform landmark movement (3D depth cues)
        if (hasNonUniform) {
            score += 0.25;
            reasons.push('depth');
        }
        // Blink detected (strongest anti-photo signal)
        if (hasBlink) {
            score += 0.2;
            reasons.push('blink');
        }
        // Texture analysis (real faces have higher pixel variance than flat screens/photos)
        if (hasTexture) {
            score += 0.15;
            reasons.push('texture');
        }
        // Temporal consistency (face size changes gradually, no sudden jumps)
        if (isTemporalConsistent) {
            score += 0.15;
            reasons.push('temporal');
        }

        return { live: score >= 0.4, score, reasons, blinkDetected: hasBlink, movementScore };
    }

    reset() {
        this.frameHistory = [];
        this.earHistory = [];
        this.blinkDetected = false;
        this._textureScores = [];
        this._faceSizes = [];
    }

    /**
     * Compute texture variance on the face region using pixel intensity gradients.
     * Real faces have rich texture (skin pores, shadows); flat photos/screens are smoother.
     * Uses a simplified Laplacian variance approach on a small canvas crop.
     * @param {HTMLVideoElement|HTMLCanvasElement} source - video or canvas with the current frame
     * @param {{x:number, y:number, width:number, height:number}} box - face bounding box
     * @returns {number} Texture variance score (higher = more real-looking)
     */
    _calcTextureScore(source, box) {
        try {
            if (!this._textureCanvas) {
                this._textureCanvas = document.createElement('canvas');
                this._textureCtx = this._textureCanvas.getContext('2d', { willReadFrequently: true });
            }
            // Sample a small region (32x32) from the face center for speed
            const sampleSize = 32;
            this._textureCanvas.width = sampleSize;
            this._textureCanvas.height = sampleSize;

            const srcW = source.videoWidth || source.width;
            const srcH = source.videoHeight || source.height;
            if (!srcW || !srcH) return 0;

            // Crop center of face box
            const cx = box.x + box.width / 2;
            const cy = box.y + box.height / 2;
            const cropSize = Math.min(box.width, box.height) * 0.6;
            const sx = Math.max(0, cx - cropSize / 2);
            const sy = Math.max(0, cy - cropSize / 2);
            const sw = Math.min(cropSize, srcW - sx);
            const sh = Math.min(cropSize, srcH - sy);

            this._textureCtx.drawImage(source, sx, sy, sw, sh, 0, 0, sampleSize, sampleSize);
            const imgData = this._textureCtx.getImageData(0, 0, sampleSize, sampleSize);
            const data = imgData.data;

            // Convert to grayscale and compute Laplacian variance
            const gray = new Float32Array(sampleSize * sampleSize);
            for (let i = 0; i < gray.length; i++) {
                const idx = i * 4;
                gray[i] = 0.299 * data[idx] + 0.587 * data[idx + 1] + 0.114 * data[idx + 2];
            }

            // Laplacian: for each pixel, compute |4*center - up - down - left - right|
            let sum = 0, count = 0;
            for (let y = 1; y < sampleSize - 1; y++) {
                for (let x = 1; x < sampleSize - 1; x++) {
                    const idx = y * sampleSize + x;
                    const lap = 4 * gray[idx] - gray[idx - 1] - gray[idx + 1]
                        - gray[idx - sampleSize] - gray[idx + sampleSize];
                    sum += lap * lap;
                    count++;
                }
            }
            return count > 0 ? sum / count : 0;
        } catch (e) {
            return 0;
        }
    }

    /**
     * Check if the face has real texture (not a flat photo/screen).
     * Returns true if average texture score is above threshold.
     */
    _hasRealTexture() {
        if (this._textureScores.length < 3) return false;
        const recent = this._textureScores.slice(-10);
        const avg = recent.reduce((s, v) => s + v, 0) / recent.length;
        // Threshold: real faces typically have Laplacian variance > 50,
        // flat photos/screens usually < 30
        return avg > 40;
    }

    /**
     * Check temporal consistency: face size should change gradually.
     * A photo being moved toward/away from the camera causes sudden jumps.
     */
    _checkTemporalConsistency() {
        if (this._faceSizes.length < 5) return false;
        const recent = this._faceSizes.slice(-10);
        let maxJump = 0;
        for (let i = 1; i < recent.length; i++) {
            const ratio = recent[i].area / recent[i - 1].area;
            const jump = Math.abs(ratio - 1);
            if (jump > maxJump) maxJump = jump;
        }
        // If the largest single-frame size change is < 20%, it's consistent
        return maxJump < 0.2;
    }

    // Eye Aspect Ratio: (||p1-p5|| + ||p2-p4||) / (2 * ||p0-p3||)
    _calcEAR(pts, offset) {
        const p = [];
        for (let i = 0; i < 6; i++) p.push(pts[offset + i]);
        const d1 = this._dist(p[1], p[5]);
        const d2 = this._dist(p[2], p[4]);
        const d3 = this._dist(p[0], p[3]);
        if (d3 === 0) return 0.3;
        return (d1 + d2) / (2 * d3);
    }

    _dist(a, b) {
        return Math.sqrt((a.x - b.x) ** 2 + (a.y - b.y) ** 2);
    }

    _extractKeyPoints(pts) {
        return {
            noseTip:        { x: pts[30].x, y: pts[30].y },
            leftEyeCenter:  { x: (pts[36].x + pts[39].x) / 2, y: (pts[36].y + pts[39].y) / 2 },
            rightEyeCenter: { x: (pts[42].x + pts[45].x) / 2, y: (pts[42].y + pts[45].y) / 2 },
            chin:           { x: pts[8].x,  y: pts[8].y },
            leftCheek:      { x: pts[1].x,  y: pts[1].y },
            rightCheek:     { x: pts[15].x, y: pts[15].y }
        };
    }

    _detectBlink() {
        if (this.earHistory.length < 5) return;
        const recent = this.earHistory.slice(-6);
        const hasLow = recent.some(v => v < this.blinkThreshold);
        const highCount = recent.filter(v => v > 0.25).length;
        if (hasLow && highCount >= 2) this.blinkDetected = true;
    }

    // Variance of inter-frame nose movement. Photos are static; live faces jitter.
    _calcMovementVariance() {
        if (this.frameHistory.length < 5) return 0;
        const frames = this.frameHistory.slice(-20);
        const shifts = [];
        for (let i = 1; i < frames.length; i++) {
            const prev = frames[i - 1].keyPoints;
            const curr = frames[i].keyPoints;
            const dx = curr.noseTip.x - prev.noseTip.x;
            const dy = curr.noseTip.y - prev.noseTip.y;
            shifts.push(Math.sqrt(dx * dx + dy * dy));
        }
        const mean = shifts.reduce((s, v) => s + v, 0) / shifts.length;
        let variance = 0;
        for (const s of shifts) variance += (s - mean) ** 2;
        variance /= shifts.length;
        return mean + Math.sqrt(variance);
    }

    // Different face parts should move differently on a 3D face (depth cues).
    // A flat photo moves uniformly.
    _hasNonUniformMovement() {
        if (this.frameHistory.length < 6) return false;
        const frames = this.frameHistory.slice(-12);
        let nonUniformCount = 0;
        let checkedCount = 0;

        for (let i = 1; i < frames.length; i++) {
            const prev = frames[i - 1].keyPoints;
            const curr = frames[i].keyPoints;
            const noseShift    = this._dist(prev.noseTip, curr.noseTip);
            const leftEyeShift = this._dist(prev.leftEyeCenter, curr.leftEyeCenter);
            const chinShift    = this._dist(prev.chin, curr.chin);

            if (noseShift > 0.5) {
                checkedCount++;
                const r1 = leftEyeShift / noseShift;
                const r2 = chinShift / noseShift;
                if (Math.abs(r1 - 1) > 0.08 || Math.abs(r2 - 1) > 0.12) {
                    nonUniformCount++;
                }
            }
        }
        return checkedCount >= 2 && nonUniformCount >= Math.ceil(checkedCount * 0.4);
    }
}

// ================================================================
// MULTI-FRAME VERIFICATION: MatchAccumulator
// Requires the same person to be matched across N consecutive frames
// before confirming identity. Prevents single-frame false positives.
// ================================================================
class MatchAccumulator {
    constructor(options = {}) {
        this.requiredConsecutive = options.requiredConsecutive || 5;
        this.maxGap = options.maxGap || 2;
        this.history = [];
        this.maxHistory = 30;
        // Track consecutive "no match" frames (face detected but not recognized)
        this.noMatchStreak = 0;
        // Track how many frames with a face present but liveness failing
        this.livenessFailStreak = 0;
        // Flags to fire notification only once per streak
        this._noMatchNotified = false;
        this._livenessFailNotified = false;
    }

    addMatch(userId, distance) {
        this.history.push({ userId, distance, timestamp: Date.now() });
        if (this.history.length > this.maxHistory) this.history.shift();
        this.noMatchStreak = 0;
        this._noMatchNotified = false;
        this.livenessFailStreak = 0;
        this._livenessFailNotified = false;
    }

    addNoMatch() {
        this.history.push({ userId: null, distance: Infinity, timestamp: Date.now() });
        if (this.history.length > this.maxHistory) this.history.shift();
        this.noMatchStreak++;
    }

    addLivenessFail() {
        this.livenessFailStreak++;
    }

    resetLivenessFail() {
        this.livenessFailStreak = 0;
        this._livenessFailNotified = false;
    }

    /**
     * Returns true ONCE when the no-match streak reaches the threshold.
     * After returning true, will not return true again until reset.
     */
    shouldNotifyUnrecognized(threshold) {
        if (this.noMatchStreak >= threshold && !this._noMatchNotified) {
            this._noMatchNotified = true;
            return true;
        }
        return false;
    }

    /**
     * Returns true ONCE when liveness fail streak reaches the threshold.
     */
    shouldNotifyLivenessFail(threshold) {
        if (this.livenessFailStreak >= threshold && !this._livenessFailNotified) {
            this._livenessFailNotified = true;
            return true;
        }
        return false;
    }

    getConsecutiveCount(userId) {
        let count = 0;
        let gaps = 0;
        for (let i = this.history.length - 1; i >= 0; i--) {
            if (this.history[i].userId === userId) {
                count++;
                gaps = 0;
            } else {
                gaps++;
                if (gaps > this.maxGap) break;
            }
        }
        return count;
    }

    getAverageDistance(userId) {
        const matches = this.history
            .filter(h => h.userId === userId)
            .slice(-this.requiredConsecutive);
        if (matches.length === 0) return Infinity;
        return matches.reduce((sum, m) => sum + m.distance, 0) / matches.length;
    }

    isVerified(userId) {
        return this.getConsecutiveCount(userId) >= this.requiredConsecutive;
    }

    reset() {
        this.history = [];
    }
    
}

// ================================================================
// MAIN: FaceRecognitionSystem
// ================================================================
class FaceRecognitionSystem {
    constructor(options = {}) {
        this.modelPath = options.modelPath || '../assets/models';
        this.matchThreshold = options.matchThreshold || 0.4;
        this.minConfidence = options.minConfidence || 0.6;
        this.csrfToken = options.csrfToken || '';
        this.modelsLoaded = false;
        this.isProcessing = false;
        this.videoElement = null;
        this.canvasElement = null;
        this.stream = null;
        this.knownDescriptors = []; // [{userId, name, studentId, descriptor}]
        this.onDetection = options.onDetection || null;
        this.onError = options.onError || null;
        this.onStatusChange = options.onStatusChange || null;
        this.detectionInterval = null;
        this.detectionIntervalMs = options.detectionIntervalMs || 250;
        this.useWorkerScheduler = options.useWorkerScheduler !== false;
        this.tickWorkerPath = options.tickWorkerPath || '../assets/js/frame_tick_worker.js';
        this._detectTickWorker = null;
        // SSD input size: higher = more accurate descriptors for matching
        // 128/160/224/320/416/512/608 — 320 recommended for gate matching
        this.ssdInputSize = options.ssdInputSize || 320;
        // Adaptive SSD: use a larger input size on full-detection frames (more accurate)
        this.ssdInputSizeFull = options.ssdInputSizeFull || 320;
        // Cached SSD options (avoid recreating every frame)
        this._ssdOptions = null;
        this._ssdOptionsFull = null;
        this._isWarmedUp = false;
        // Selected camera device ID (null = browser default)
        this.selectedDeviceId = null;

        // Anti-spoofing & multi-frame verification
        this.livenessEnabled = options.livenessEnabled !== false;
        this.requiredConsecutiveFrames = options.requiredConsecutiveFrames || 5;
        this.minFaceSizeRatio = options.minFaceSizeRatio || 0.08;
        this.minFaceSizePx = options.minFaceSizePx || 80;
        this.minDistanceGap = options.minDistanceGap || 0.12;
        // After this many consecutive no-match frames, fire "unrecognized" notification
        this.unrecognizedFramesThreshold = options.unrecognizedFramesThreshold || 8;
        // After this many frames of liveness failing (photo/screen detected), fire notification
        this.livenessFailFramesThreshold = options.livenessFailFramesThreshold || 15;

        this._livenessDetector = new LivenessDetector({
            minFramesRequired: options.livenessMinFrames || 8,
            blinkThreshold: 0.21,
            movementThreshold: 1.2,
            maxFrames: 40
        });
        this._matchAccumulator = new MatchAccumulator({
            requiredConsecutive: this.requiredConsecutiveFrames,
            maxGap: options.matchAccumulatorMaxGap != null ? options.matchAccumulatorMaxGap : 1
        });

        // Descriptor load chunk size to keep UI responsive during initialization.
        this.descriptorChunkSize = options.descriptorChunkSize || 40;

        // ── Face Tracker (detect → track → embed pipeline) ──
        this.trackingEnabled = options.trackingEnabled !== false;
        // Run full ML pipeline every N-th frame (others use tracker prediction)
        this.fullDetectionInterval = options.fullDetectionInterval || 3;
        this._tracker = null; // FaceTracker instance, set on first use
        this._frameCounter = 0;
        this._lastDetectionResult = null; // cache last full detection for tracked frames

        // ── Web Worker Matching ──
        this.matchWorkerPath = options.matchWorkerPath || '../assets/js/face-match-worker.js';
        this._matchWorker = null;
        this._matchWorkerReady = false;
        this._matchCallbacks = new Map(); // queryId → resolve
        this._matchQueryCounter = 0;

        // ── Incremental Sync ──
        this._lastSyncVersion = 0;

        // ── Anti-replay ring buffer ──
        this._recentAcceptedDescriptors = [];
        this._maxReplayBuffer = 50;
        this._replayDistanceThreshold = 0.05;

        // ── Embedder abstraction (Phase 6 prep) ──
        this._embedder = options.embedder || null; // { extractDescriptor(source, detection) → Float32Array }
    }

    _stopDetectionScheduler() {
        if (this.detectionInterval) {
            clearTimeout(this.detectionInterval);
            clearInterval(this.detectionInterval);
            this.detectionInterval = null;
        }

        if (this._detectTickWorker) {
            try {
                this._detectTickWorker.postMessage({ type: 'stop' });
            } catch (e) {}
            try {
                this._detectTickWorker.terminate();
            } catch (e) {}
            this._detectTickWorker = null;
        }

        if (this._visChangeHandler) {
            document.removeEventListener('visibilitychange', this._visChangeHandler);
            this._visChangeHandler = null;
        }
    }

    _startDetectionScheduler(detectLoop) {
        if (this.useWorkerScheduler && typeof Worker !== 'undefined') {
            try {
                this._detectTickWorker = new Worker(this.tickWorkerPath);
                this._detectTickWorker.onmessage = (ev) => {
                    if (!this._continuousRunning) return;
                    if (ev && ev.data && ev.data.type === 'tick') {
                        detectLoop();
                    }
                };
                this._detectTickWorker.onerror = () => {
                    // Fallback to timer scheduler if worker fails for any reason.
                    if (this._detectTickWorker) {
                        try { this._detectTickWorker.terminate(); } catch (e) {}
                        this._detectTickWorker = null;
                    }
                    if (!this.detectionInterval) {
                        this.detectionInterval = setInterval(() => {
                            if (!this._continuousRunning) return;
                            detectLoop();
                        }, this.detectionIntervalMs);
                    }
                };

                this._detectTickWorker.postMessage({
                    type: 'start',
                    intervalMs: this.detectionIntervalMs
                });
                return;
            } catch (e) {
                if (this._detectTickWorker) {
                    try { this._detectTickWorker.terminate(); } catch (err) {}
                    this._detectTickWorker = null;
                }
            }
        }

        // Default/fallback scheduler
        this.detectionInterval = setInterval(() => {
            if (!this._continuousRunning) return;
            detectLoop();
        }, this.detectionIntervalMs);
    }

    _yieldToUI() {
        return new Promise(resolve => setTimeout(resolve, 0));
    }

    /**
     * Load face-api.js models (pre-trained)
     * Models: SSD MobileNet v1 (detection), Face Landmark 68 (alignment), Face Recognition (128-dim descriptor)
     */
    async loadModels() {
        this._setStatus('loading', 'Loading face recognition models...');
        
        try {
            await Promise.all([
                faceapi.nets.ssdMobilenetv1.loadFromUri(this.modelPath),
                faceapi.nets.faceLandmark68Net.loadFromUri(this.modelPath),
                faceapi.nets.faceRecognitionNet.loadFromUri(this.modelPath),
            ]);
            
            this.modelsLoaded = true;
            // Pre-create SSD options for reuse (avoids object creation every frame)
            this._ssdOptions = new faceapi.SsdMobilenetv1Options({
                inputSize: this.ssdInputSize,
                minConfidence: this.minConfidence,
                maxResults: 1
            });
            // Full-resolution SSD options for embedding frames (more accurate)
            this._ssdOptionsFull = new faceapi.SsdMobilenetv1Options({
                inputSize: this.ssdInputSizeFull,
                minConfidence: this.minConfidence,
                maxResults: 1
            });

            // Initialize Face Tracker if available
            if (this.trackingEnabled && typeof FaceTracker !== 'undefined') {
                this._tracker = new FaceTracker({
                    iouThreshold: 0.35,
                    maxStaleFrames: this.fullDetectionInterval + 2
                });
            }

            // Initialize Match Worker
            this._initMatchWorker();

            this._setStatus('ready', 'Face recognition models loaded successfully');
            return true;
        } catch (error) {
            this._handleError('Failed to load face recognition models. Ensure model files exist in: ' + this.modelPath, error);
            return false;
        }
    }

    /**
     * Return list of available video input devices.
     * Note: Device labels are only populated after getUserMedia permission is granted.
     * @returns {Promise<Array<{deviceId: string, label: string}>>}
     */
    async getAvailableCameras() {
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            return devices
                .filter(device => device.kind === 'videoinput')
                .map((device, index) => ({
                    deviceId: device.deviceId,
                    label: device.label || `Camera ${index + 1}`
                }));
        } catch (err) {
            return [];
        }
    }

    /**
     * Start webcam video stream
     * @param {HTMLVideoElement} videoEl - Video element for camera preview
     * @param {HTMLCanvasElement} canvasEl - Canvas for drawing detection boxes
     * @param {string|null} deviceId - Specific camera deviceId, or null for default
     */
    async startCamera(videoEl, canvasEl, deviceId = null) {
        this.videoElement = videoEl;
        this.canvasElement = canvasEl;
        if (deviceId !== null) this.selectedDeviceId = deviceId;

        try {
            let videoConstraints;
            if (this.selectedDeviceId) {
                videoConstraints = { deviceId: { exact: this.selectedDeviceId } };
            } else {
                videoConstraints = {
                    width: { ideal: 480 },
                    height: { ideal: 360 },
                    facingMode: 'user',
                    frameRate: { ideal: 15, max: 30 }
                };
            }

            this.stream = await navigator.mediaDevices.getUserMedia({
                video: videoConstraints,
                audio: false
            });

            this.videoElement.srcObject = this.stream;
            await this.videoElement.play();

            // Warm up the model in background so camera preview appears immediately.
            this._warmUpDetector();

            const displaySize = {
                width: this.videoElement.videoWidth,
                height: this.videoElement.videoHeight
            };
            faceapi.matchDimensions(this.canvasElement, displaySize);

            this._setStatus('camera_active', 'Camera active - ready for face detection');
            return true;
        } catch (error) {
            // If specific camera selection fails, retry once with default camera.
            // This prevents Start from appearing broken when the selected device
            // is disconnected, locked by another app, or returns stale IDs.
            if (this.selectedDeviceId) {
                try {
                    this.selectedDeviceId = null;
                    this.stream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            width: { ideal: 480 },
                            height: { ideal: 360 },
                            facingMode: 'user',
                            frameRate: { ideal: 15, max: 30 }
                        },
                        audio: false
                    });

                    this.videoElement.srcObject = this.stream;
                    await this.videoElement.play();
                    this._warmUpDetector();

                    const displaySize = {
                        width: this.videoElement.videoWidth,
                        height: this.videoElement.videoHeight
                    };
                    faceapi.matchDimensions(this.canvasElement, displaySize);

                    this._setStatus('camera_active', 'Camera active (fallback default camera)');
                    return true;
                } catch (fallbackError) {
                    error = fallbackError;
                }
            }

            if (error.name === 'NotAllowedError') {
                this._handleError('Camera access denied. Please allow camera permission.', error);
            } else if (error.name === 'NotFoundError') {
                this._handleError('No camera found. Please connect a webcam.', error);
            } else {
                this._handleError('Failed to start camera: ' + error.message, error);
            }
            return false;
        }
    }

    /**
     * Run one throwaway inference after camera starts to warm up TF.js graph/JIT.
     * This removes the multi-second delay on the very first face scan.
     */
    async _warmUpDetector() {
        if (this._isWarmedUp || !this.videoElement || !this.modelsLoaded) return;
        try {
            // Warm up with the default SSD input size
            const options = this._ssdOptions || new faceapi.SsdMobilenetv1Options({
                inputSize: this.ssdInputSize,
                minConfidence: this.minConfidence,
                maxResults: 1
            });
            await faceapi
                .detectSingleFace(this.videoElement, options)
                .withFaceLandmarks()
                .withFaceDescriptor();

            // Also warm up the full-size SSD path if different
            if (this._ssdOptionsFull && this.ssdInputSizeFull !== this.ssdInputSize) {
                await faceapi
                    .detectSingleFace(this.videoElement, this._ssdOptionsFull)
                    .withFaceLandmarks()
                    .withFaceDescriptor();
            }
            this._isWarmedUp = true;
        } catch (e) {
            // Non-fatal: warmup failure should not block camera usage
        }
    }

    /**
     * Switch to a different camera device.
     *
     * Chrome on Windows can occasionally return a stream from the previous camera
     * even when a different deviceId was requested. This method verifies the
     * actual active device from track settings and retries before reporting success.
     *
     * @param {string} newDeviceId - deviceId of the camera to switch to
     * @returns {Promise<boolean>} true if switched successfully
     */
    async switchCamera(newDeviceId) {
        if (!this.videoElement || !this.canvasElement || !newDeviceId) return false;

        this._continuousRunning = false;
        if (this.detectionInterval) {
            clearTimeout(this.detectionInterval);
            this.detectionInterval = null;
        }

        if (this.stream) {
            this.stream.getTracks().forEach(track => {
                track.enabled = false;
                track.stop();
            });
            this.stream = null;
        }

        const parent = this.videoElement.parentNode;
        const oldVideo = this.videoElement;
        const marker = oldVideo.nextSibling;

        const newVideo = document.createElement('video');
        newVideo.id = oldVideo.id;
        newVideo.className = oldVideo.className;
        newVideo.autoplay = true;
        newVideo.muted = true;
        newVideo.playsInline = true;
        newVideo.setAttribute('playsinline', '');

        oldVideo.pause();
        oldVideo.srcObject = null;
        oldVideo.remove();
        if (marker) {
            parent.insertBefore(newVideo, marker);
        } else {
            parent.appendChild(newVideo);
        }
        this.videoElement = newVideo;

        const maxAttempts = 3;
        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                const candidateStream = await navigator.mediaDevices.getUserMedia({
                    video: { deviceId: { exact: newDeviceId } },
                    audio: false
                });

                const track = candidateStream.getVideoTracks()[0];
                const actualId = track?.getSettings?.().deviceId || '';

                if (actualId && actualId !== newDeviceId) {
                    candidateStream.getTracks().forEach(t => t.stop());
                    await new Promise(resolve => setTimeout(resolve, 250 * attempt));
                    continue;
                }

                this.stream = candidateStream;
                this.selectedDeviceId = newDeviceId;
                this.videoElement.srcObject = this.stream;
                await this.videoElement.play();

                const displaySize = {
                    width: this.videoElement.videoWidth,
                    height: this.videoElement.videoHeight
                };
                faceapi.matchDimensions(this.canvasElement, displaySize);

                this._setStatus('camera_active', 'Camera switched successfully');
                return true;
            } catch (error) {
                if (attempt === maxAttempts) {
                    this._handleError('Failed to switch camera: ' + error.message, error);
                    return false;
                }
                await new Promise(resolve => setTimeout(resolve, 250 * attempt));
            }
        }

        this._handleError('Failed to switch camera: browser returned a different device than requested.');
        return false;
    }

    /**
     * Stop webcam stream and clean up
     */
    stopCamera() {
        this._continuousRunning = false;
        if (this.detectionInterval) {
            clearTimeout(this.detectionInterval);
            this.detectionInterval = null;
        }

        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
            this.stream = null;
        }

        if (this.videoElement) {
            // Stop playback and clear the source — do NOT call video.load() here.
            // load() triggers an async internal reset that races with any subsequent
            // srcObject assignment in startCamera(), causing the browser to reuse
            // the old device instead of opening the newly requested one.
            this.videoElement.pause();
            this.videoElement.srcObject = null;
        }

        if (this.canvasElement) {
            const ctx = this.canvasElement.getContext('2d');
            ctx.clearRect(0, 0, this.canvasElement.width, this.canvasElement.height);
        }

        this._setStatus('stopped', 'Camera stopped');
    }

    /**
     * Detect a single face from the current video frame
     * Used during face registration (admin side)
     * @returns {Object|null} {detection, descriptor, landmarks} or null
     */
    async detectSingleFace() {
        if (!this.modelsLoaded || !this.videoElement) {
            this._handleError('Models not loaded or camera not started');
            return null;
        }

        try {
            const options = this._ssdOptions || new faceapi.SsdMobilenetv1Options({
                minConfidence: this.minConfidence,
                maxResults: 1
            });

            const detection = await faceapi
                .detectSingleFace(this.videoElement, options)
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (!detection) {
                return null;
            }

            // Draw detection on canvas
            this._drawDetection(detection);

            return {
                descriptor: Array.from(detection.descriptor),
                score: detection.detection.score,
                box: detection.detection.box,
                landmarks: detection.landmarks
            };
        } catch (error) {
            this._handleError('Face detection error', error);
            return null;
        }
    }

    /**
     * Start continuous face detection for gate monitoring.
     * Integrates multi-frame verification + liveness anti-spoofing.
     * Fires onDetection with matched:true on verified match,
     * or matched:false when an unrecognized face persists or liveness fails.
     */
    startContinuousDetection() {
        this._stopDetectionScheduler();

        this._setStatus('detecting', 'Continuous face detection active');
        this._continuousRunning = true;
        this._paused = false;
        this._livenessDetector.reset();
        this._matchAccumulator.reset();
        this._frameCounter = 0;
        this._lastDetectionResult = null;
        if (this._tracker) this._tracker.reset();

        let _cycleGen = 0;
        let _cycleStart = 0;

        const detectLoop = async () => {
            if (!this._continuousRunning) return;
            if (this._paused) return;

            // When the tab is hidden, browsers freeze canvas/video
            // rendering so ML inference returns null. Skip the cycle
            // entirely to preserve accumulated match & liveness state;
            // the tick worker keeps running and we resume instantly on
            // the next visible tick.
            if (document.hidden) return;

            // Safety: if the previous cycle exceeds 2 seconds, force-release
            // the lock and invalidate the stale cycle so it cannot interfere.
            if (this.isProcessing && _cycleStart > 0 && (Date.now() - _cycleStart) > 2000) {
                _cycleGen++;
                this.isProcessing = false;
                _cycleStart = 0;
            }

            if (!this.isProcessing) {
                this.isProcessing = true;
                const myGen = ++_cycleGen;
                _cycleStart = Date.now();
                try {
                    this._frameCounter++;
                    const isFullFrame = !this._tracker ||
                        !this._tracker.isTracking() ||
                        (this._frameCounter % this.fullDetectionInterval === 0);

                    if (isFullFrame) {
                        // FULL DETECTION FRAME: run ML pipeline → landmarks → descriptor → match
                        const detection = await this._detectFaceRaw(true);

                        // Discard result if this cycle was invalidated by timeout guard
                        if (myGen !== _cycleGen) return;

                        if (detection && detection.descriptor) {
                            // Update tracker with detected box
                            if (this._tracker) {
                                this._tracker.update(detection.box);
                            }
                            this._lastDetectionResult = detection;

                            // Anti-spoofing: reject faces that are too small
                            if (!this._checkFaceSize(detection.box)) {
                                this._drawSmallFaceWarning(detection);
                                this._matchAccumulator.addNoMatch();
                            } else {
                                // Feed landmarks + video to liveness detector
                                if (detection.landmarks) {
                                    this._livenessDetector.addFrame(
                                        detection.landmarks,
                                        detection.box,
                                        this.videoElement
                                    );
                                }

                                // Per-person matching: use Worker if available, else fallback
                                const match = await this._findBestMatchAsync(detection.descriptor);

                                // Discard if cycle invalidated
                                if (myGen !== _cycleGen) return;

                                this._processMatchResult(detection, match);
                            }
                        } else {
                            // No face detected — reset tracker
                            if (this._tracker) this._tracker.reset();
                            this._lastDetectionResult = null;
                            if (this.canvasElement) {
                                const ctx = this.canvasElement.getContext('2d');
                                ctx.clearRect(0, 0, this.canvasElement.width, this.canvasElement.height);
                            }
                        }
                    } else {
                        // TRACKED FRAME: use tracker prediction for visual feedback, skip ML
                        const predictedBox = this._tracker ? this._tracker.predict() : null;

                        if (predictedBox && this._lastDetectionResult) {
                            // Draw tracked bounding box (lighter visual to indicate tracking)
                            this._drawTrackedBox(predictedBox, this._lastDetectionResult);
                        } else {
                            // Tracker lost the face — force a full detection next frame
                            if (this._tracker) this._tracker.reset();
                            this._lastDetectionResult = null;
                        }
                    }
                } catch (error) {
                    console.error('Detection cycle error:', error);
                } finally {
                    if (myGen === _cycleGen) {
                        this.isProcessing = false;
                        _cycleStart = 0;
                    }
                }
            }

        };

        // When the tab regains focus, trigger a cycle immediately for
        // instant resume instead of waiting for the next worker tick.
        // Also reset the stale-cycle guard so the first visible tick
        // is not blocked by a phantom isProcessing lock.
        this._visChangeHandler = () => {
            if (!document.hidden && this._continuousRunning) {
                // Force-release any stale lock from a cycle that was
                // mid-flight when the tab went hidden.
                if (this.isProcessing && _cycleStart > 0) {
                    _cycleGen++;
                    this.isProcessing = false;
                    _cycleStart = 0;
                }
                detectLoop();
            }
        };
        document.addEventListener('visibilitychange', this._visChangeHandler);

        // Kick off immediately once, then schedule periodic ticks.
        detectLoop();
        this._startDetectionScheduler(detectLoop);
    }

    /**
     * Process a match result from either Worker or synchronous matching.
     * Handles multi-frame verification, liveness checking, and notifications.
     * @private
     */
    _processMatchResult(detection, match) {
        if (match) {
            this._matchAccumulator.addMatch(match.userId, match.distance);
            const frames = this._matchAccumulator.getConsecutiveCount(match.userId);
            const liveness = this.livenessEnabled
                ? this._livenessDetector.checkLiveness()
                : { live: true, score: 1, reasons: ['disabled'] };

            this._drawVerificationProgress(detection, match, frames, liveness);

            // Check for liveness failure on a matched face (photo/screen attack)
            if (frames >= this.requiredConsecutiveFrames) {
                if (this.livenessEnabled && !liveness.live) {
                    // Enough frames matched but liveness keeps failing → likely a photo
                    this._matchAccumulator.addLivenessFail();
                    if (this._matchAccumulator.shouldNotifyLivenessFail(this.livenessFailFramesThreshold)) {
                        if (this.onDetection) {
                            this.onDetection({
                                matched: false,
                                reason: 'liveness_failed',
                                message: 'Photo or screen detected — live face required',
                                timestamp: new Date().toISOString()
                            });
                        }
                    }
                } else {
                    // Full verification passed!
                    this._matchAccumulator.resetLivenessFail();
                    const avgDist = this._matchAccumulator.getAverageDistance(match.userId);

                    // Anti-replay check
                    if (this._checkReplay(detection.descriptor)) {
                        console.warn('[FaceRec] Replay detected — same descriptor recently accepted');
                        return;
                    }

                    // Store in replay buffer
                    this._addToReplayBuffer(detection.descriptor);

                    if (this.onDetection) {
                        this.onDetection({
                            matched: true,
                            match: { ...match, confidence: Math.max(0, 1 - avgDist).toFixed(4) },
                            queryDescriptor: Array.from(detection.descriptor),
                            liveness: liveness,
                            verifiedFrames: frames,
                            timestamp: new Date().toISOString()
                        });
                    }
                    this._matchAccumulator.reset();
                    this._livenessDetector.reset();
                }
            }
        } else {
            this._matchAccumulator.addNoMatch();
            this._drawNoMatch(detection);

            // After enough consecutive no-match frames, notify that face is unrecognized
            if (this._matchAccumulator.shouldNotifyUnrecognized(this.unrecognizedFramesThreshold)) {
                this._drawUnrecognizedAlert(detection);
                if (this.onDetection) {
                    this.onDetection({
                        matched: false,
                        reason: 'not_recognized',
                        message: 'Person is NOT registered in the system',
                        timestamp: new Date().toISOString()
                    });
                }
            }
        }
    }

    /**
     * Stop continuous detection
     */
    stopContinuousDetection() {
        this._continuousRunning = false;
        this._paused = false;
        this._stopDetectionScheduler();
        this._setStatus('paused', 'Detection paused');
    }

    /**
     * Pause detection loop without tearing down scheduler or accumulators.
     * Used when another window takes over recognition temporarily.
     */
    pauseContinuousDetection() {
        this._paused = true;
    }

    /**
     * Resume a paused detection loop.
     */
    resumeContinuousDetection() {
        if (this._continuousRunning) {
            this._paused = false;
        }
    }

    /**
     * Load known face descriptors from server
     * Descriptors are decrypted server-side and sent over HTTPS
     * @param {string} apiUrl - URL to fetch descriptors
     */
    async loadKnownFaces(apiUrl) {
        this._setStatus('loading_faces', 'Loading registered faces...');

        try {
            const response = await fetch(apiUrl, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.csrfToken,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`Server returned ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to load face descriptors');
            }

            const raw = data.descriptors || [];
            this.knownDescriptors = [];
            this._descriptorsByUser = new Map();

            const chunkSize = Math.max(10, this.descriptorChunkSize | 0);
            for (let i = 0; i < raw.length; i += chunkSize) {
                const end = Math.min(i + chunkSize, raw.length);
                for (let j = i; j < end; j++) {
                    const d = raw[j];
                    const entry = {
                        userId: d.user_id,
                        name: d.name,
                        studentId: d.student_id,
                        email: d.email,
                        course: d.course,
                        profilePicture: d.profile_picture || null,
                        violationCount: d.violation_count || 0,
                        label: d.label,
                        descriptor: new Float32Array(d.descriptor)
                    };
                    this.knownDescriptors.push(entry);

                    let arr = this._descriptorsByUser.get(entry.userId);
                    if (!arr) {
                        arr = [];
                        this._descriptorsByUser.set(entry.userId, arr);
                    }
                    arr.push(entry);
                }

                // Yield regularly so UI interactions stay responsive.
                if (end < raw.length) {
                    if (i === 0 || (i / chunkSize) % 3 === 0) {
                        this._setStatus('loading_faces', `Loading registered faces... ${end}/${raw.length}`);
                    }
                    await this._yieldToUI();
                }
            }

            // Save latest version for incremental sync
            if (data.latest_version != null) {
                this._lastSyncVersion = data.latest_version;
            }

            // Post full descriptor set to match Worker
            this._postDescriptorsToWorker();

            this._setStatus('faces_loaded', `Loaded ${this.knownDescriptors.length} registered faces`);
            return this.knownDescriptors.length;
        } catch (error) {
            this._handleError('Failed to load known faces', error);
            return 0;
        }
    }

    /**
     * Incremental sync: fetch only changed descriptors since last version.
     * Call periodically instead of full loadKnownFaces reload.
     * @param {string} apiUrl - URL to get_face_updates.php
     * @returns {Promise<boolean>} true if sync succeeded
     */
    async loadKnownFacesIncremental(apiUrl) {
        try {
            const url = apiUrl + (apiUrl.includes('?') ? '&' : '?') + 'since_version=' + this._lastSyncVersion;
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.csrfToken,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            if (!response.ok) return false;

            const data = await response.json();
            if (!data.success) return false;

            // If server says full reload needed, delegate to loadKnownFaces
            if (data.full_reload_needed) {
                const baseUrl = apiUrl.replace('get_face_updates.php', 'get_face_descriptors.php');
                await this.loadKnownFaces(baseUrl);
                return { added: this.knownDescriptors.length, removed: 0 };
            }

            const added = data.added || [];
            const removed = data.removed || [];

            // Remove deactivated descriptors
            if (removed.length > 0) {
                const removeSet = new Set(removed);
                this.knownDescriptors = this.knownDescriptors.filter(d => !removeSet.has(d.descriptorId));
                // Update per-user groups
                for (const [userId, descs] of this._descriptorsByUser) {
                    const filtered = descs.filter(d => !removeSet.has(d.descriptorId));
                    if (filtered.length === 0) {
                        this._descriptorsByUser.delete(userId);
                    } else {
                        this._descriptorsByUser.set(userId, filtered);
                    }
                }
            }

            // Add new descriptors
            for (const d of added) {
                const entry = {
                    userId: d.user_id,
                    descriptorId: d.descriptor_id,
                    name: d.name,
                    studentId: d.student_id,
                    course: d.course,
                    profilePicture: d.profile_picture || null,
                    violationCount: d.violation_count || 0,
                    label: d.label,
                    descriptor: new Float32Array(d.descriptor)
                };
                this.knownDescriptors.push(entry);
                let arr = this._descriptorsByUser.get(entry.userId);
                if (!arr) {
                    arr = [];
                    this._descriptorsByUser.set(entry.userId, arr);
                }
                arr.push(entry);
            }

            // Update sync version
            if (data.latest_version != null) {
                this._lastSyncVersion = data.latest_version;
            }

            // Push incremental update to match Worker
            if (this._matchWorker && this._matchWorkerReady) {
                const workerAdded = added.map(d => ({
                    userId: d.user_id,
                    descriptorId: d.descriptor_id,
                    name: d.name,
                    studentId: d.student_id,
                    course: d.course,
                    profilePicture: d.profile_picture,
                    violationCount: d.violation_count || 0,
                    label: d.label,
                    descriptor: Array.from(new Float32Array(d.descriptor))
                }));
                this._matchWorker.postMessage({
                    type: 'update',
                    added: workerAdded,
                    removed: removed
                });
            }

            if (added.length > 0 || removed.length > 0) {
                console.log(`[FaceRec] Incremental sync: +${added.length} -${removed.length} descriptors (v${this._lastSyncVersion})`);
            }

            return { added: added.length, removed: removed.length };
        } catch (error) {
            console.error('[FaceRec] Incremental sync failed:', error);
            return false;
        }
    }

    /**
     * Register a face descriptor to the server
     * @param {number} studentId - Student user ID
     * @param {Array} descriptor - 128-dimensional descriptor array
     * @param {string} label - Descriptor label (front, left, right)
     * @param {number} qualityScore - Detection confidence score
     * @param {string} apiUrl - Registration API URL
     */
    async registerFace(studentId, descriptor, label, qualityScore, apiUrl) {
        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    student_id: studentId,
                    descriptor: descriptor,
                    label: label,
                    quality_score: qualityScore
                })
            });

            let data = null;
            try {
                data = await response.json();
            } catch (_e) {
                data = null;
            }

            if (!response.ok) {
                const serverMessage = data && typeof data.error === 'string' ? data.error : null;
                throw new Error(serverMessage || `Server returned ${response.status}`);
            }

            if (!data) {
                throw new Error('Invalid server response while registering face descriptor');
            }
            return data;
        } catch (error) {
            this._handleError('Failed to register face', error);
            return { success: false, error: error.message };
        }
    }

    /**
     * Verify a face match server-side before logging entry.
     * @param {number} userId - Claimed matched user ID
     * @param {Array<number>} queryDescriptor - The 128-dim query descriptor
     * @param {number} confidence - Browser-side match confidence
     * @param {string} apiUrl - URL to verify_face_match.php
     * @returns {Promise<{verified: boolean, server_confidence?: number}>}
     */
    async verifyFaceMatch(userId, queryDescriptor, confidence, apiUrl) {
        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    user_id: userId,
                    query_descriptor: queryDescriptor,
                    confidence_score: confidence,
                    match_threshold: this.matchThreshold
                })
            });
            if (!response.ok) return { verified: false };
            return await response.json();
        } catch (error) {
            console.error('[FaceRec] Server verification error:', error);
            return { verified: false };
        }
    }

    /**
     * Log a face-based entry at the gate
     * @param {number} userId - Matched user ID
     * @param {number} confidence - Match confidence score
     * @param {string} apiUrl - Entry logging API URL
     * @param {Array<number>} [queryDescriptor] - Optional query descriptor for forensic audit
     */
    async logFaceEntry(userId, confidence, apiUrl, queryDescriptor) {
        try {
            const body = {
                user_id: userId,
                confidence_score: confidence,
                match_threshold: this.matchThreshold
            };
            if (queryDescriptor) {
                body.query_descriptor = queryDescriptor;
            }

            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify(body)
            });

            if (!response.ok) {
                throw new Error(`Server returned ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            this._handleError('Failed to log face entry', error);
            return { success: false, error: error.message };
        }
    }

    /**
     * Delete all face descriptors for a student
     * @param {number} studentId - Student user ID
     * @param {string} apiUrl - Delete API URL
     */
    async deleteFace(studentId, apiUrl) {
        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    student_id: studentId
                })
            });

            if (!response.ok) {
                throw new Error(`Server returned ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            this._handleError('Failed to delete face data', error);
            return { success: false, error: error.message };
        }
    }

    // ========================================
    // PRIVATE METHODS
    // ========================================

    /**
     * Low-level face detection (no drawing). Used by the continuous detection loop
     * so that drawing is controlled by the caller based on verification state.
     * @param {boolean} fullFrame - If true, use higher-res SSD input for better accuracy
     */
    async _detectFaceRaw(fullFrame = false) {
        if (!this.modelsLoaded || !this.videoElement) return null;
        try {
            // Use adaptive SSD: full frames get higher resolution for better embeddings
            const options = (fullFrame && this._ssdOptionsFull)
                ? this._ssdOptionsFull
                : (this._ssdOptions || new faceapi.SsdMobilenetv1Options({
                    inputSize: this.ssdInputSize,
                    minConfidence: this.minConfidence,
                    maxResults: 1
                }));
            const detection = await faceapi
                .detectSingleFace(this.videoElement, options)
                .withFaceLandmarks()
                .withFaceDescriptor();
            if (!detection) return null;
            return {
                descriptor: detection.descriptor,
                score: detection.detection.score,
                box: detection.detection.box,
                landmarks: detection.landmarks,
                _raw: detection
            };
        } catch (error) {
            return null;
        }
    }

    /**
     * Initialize the Web Worker for off-thread descriptor matching.
     * Called once from loadModels(). Sets _matchWorkerReady when the Worker
     * acknowledges loading its initial (empty) state.
     * @private
     */
    _initMatchWorker() {
        if (typeof Worker === 'undefined') return;
        try {
            this._matchWorker = new Worker(this.matchWorkerPath);
            this._matchWorker.onmessage = (e) => {
                const msg = e.data;
                if (msg.type === 'loaded' || msg.type === 'updated') {
                    this._matchWorkerReady = true;
                } else if (msg.type === 'result') {
                    const cb = this._matchCallbacks.get(msg.queryId);
                    if (cb) {
                        this._matchCallbacks.delete(msg.queryId);
                        cb(msg.match); // null or match object
                    }
                }
            };
            this._matchWorker.onerror = (err) => {
                console.warn('[FaceRec] Match Worker error, falling back to main thread', err);
                this._matchWorkerReady = false;
            };
            // Send initial config
            this._matchWorker.postMessage({
                type: 'config',
                threshold: this.matchThreshold,
                minGap: this.minDistanceGap,
                minConsensus: this.minDescriptorConsensus,
                maxAverageDistance: this.maxAverageDistance
            });
        } catch (err) {
            console.warn('[FaceRec] Could not create Match Worker:', err);
            this._matchWorker = null;
        }
    }

    /**
     * Send the full set of known descriptors to the Match Worker.
     * Called after loadKnownFaces() finishes parsing descriptors.
     * @private
     */
    _postDescriptorsToWorker() {
        if (!this._matchWorker) return;
        const serialized = this.knownDescriptors.map(d => ({
            userId: d.userId,
            studentId: d.studentId,
            name: d.name,
            course: d.course || '',
            violationCount: d.violationCount || 0,
            label: d.label || d.name,
            descriptor: Array.from(d.descriptor)
        }));
        this._matchWorker.postMessage({
            type: 'load',
            descriptors: serialized,
            threshold: this.matchThreshold,
            minGap: this.minDistanceGap,
            minConsensus: this.minDescriptorConsensus,
            maxAverageDistance: this.maxAverageDistance
        });
    }

    /**
     * Post a match query to the Worker and return a Promise.
     * Falls back to synchronous _findBestMatch() if Worker is not ready.
     * @param {Float32Array|Array} descriptor
     * @returns {Promise<Object|null>}
     * @private
     */
    _findBestMatchAsync(descriptor) {
        if (this._matchWorkerReady && this._matchWorker) {
            return new Promise((resolve) => {
                const queryId = ++this._matchQueryCounter;
                this._matchCallbacks.set(queryId, resolve);
                this._matchWorker.postMessage({
                    type: 'match',
                    queryId: queryId,
                    query: descriptor instanceof Float32Array
                        ? Array.from(descriptor) : descriptor,
                    threshold: this.matchThreshold,
                    minGap: this.minDistanceGap,
                    minConsensus: this.minDescriptorConsensus,
                    maxAverageDistance: this.maxAverageDistance
                });
                // Timeout: if Worker doesn't respond within 500ms, resolve null
                setTimeout(() => {
                    if (this._matchCallbacks.has(queryId)) {
                        this._matchCallbacks.delete(queryId);
                        resolve(this._findBestMatch(descriptor));
                    }
                }, 500);
            });
        }
        // Synchronous fallback
        return Promise.resolve(this._findBestMatch(descriptor));
    }

    /**
     * Draw a lighter bounding box for tracked (non-ML) frames.
     * Provides visual continuity without running full ML inference.
     * @param {{x, y, width, height}} predictedBox - Tracker-predicted position
     * @param {Object} lastDetection - Last full detection result for labels
     * @private
     */
    _drawTrackedBox(predictedBox, lastDetection) {
        if (!this.canvasElement) return;
        const displaySize = this._getVideoDisplaySize();
        const ctx = this.canvasElement.getContext('2d');
        ctx.clearRect(0, 0, this.canvasElement.width, this.canvasElement.height);

        // Scale the predicted box to display dimensions
        const scaleX = displaySize.width / (this.videoElement.videoWidth || displaySize.width);
        const scaleY = displaySize.height / (this.videoElement.videoHeight || displaySize.height);
        const box = {
            x: predictedBox.x * scaleX,
            y: predictedBox.y * scaleY,
            width: predictedBox.width * scaleX,
            height: predictedBox.height * scaleY
        };

        // Semi-transparent dashed box to indicate tracking (not full detection)
        ctx.strokeStyle = 'rgba(0, 180, 255, 0.6)';
        ctx.lineWidth = 2;
        ctx.setLineDash([6, 4]);
        ctx.strokeRect(box.x, box.y, box.width, box.height);
        ctx.setLineDash([]);

        // Show "Tracking..." label
        ctx.fillStyle = 'rgba(0, 0, 0, 0.5)';
        ctx.fillRect(box.x, box.y - 18, 70, 16);
        ctx.fillStyle = 'rgba(0, 180, 255, 0.8)';
        ctx.font = 'bold 10px Arial';
        ctx.fillText('Tracking...', box.x + 3, box.y - 6);
    }

    /**
     * Check if a descriptor matches one recently accepted (anti-replay).
     * Prevents the same face descriptor from triggering successive entries.
     * @param {Float32Array|Array} descriptor
     * @returns {boolean} true if replay detected
     * @private
     */
    _checkReplay(descriptor) {
        if (this._recentAcceptedDescriptors.length === 0) return false;
        for (const recent of this._recentAcceptedDescriptors) {
            const dist = faceapi.euclideanDistance(descriptor, recent);
            if (dist < this._replayDistanceThreshold) return true;
        }
        return false;
    }

    /**
     * Add a descriptor to the anti-replay ring buffer.
     * @param {Float32Array|Array} descriptor
     * @private
     */
    _addToReplayBuffer(descriptor) {
        const copy = descriptor instanceof Float32Array
            ? new Float32Array(descriptor)
            : new Float32Array(descriptor);
        this._recentAcceptedDescriptors.push(copy);
        if (this._recentAcceptedDescriptors.length > this._maxReplayBuffer) {
            this._recentAcceptedDescriptors.shift();
        }
    }

    /**
     * Assess the quality of a face detection for enrollment suitability.
     * Returns a score 0–1 and individual quality factors.
     * Used during enrollment to ensure high-quality descriptors.
     * @param {Object} detection - Detection result from detectSingleFace
     * @returns {{score: number, factors: Object, acceptable: boolean}}
     */
    assessFaceQuality(detection) {
        if (!detection) return { score: 0, factors: {}, acceptable: false };

        // Handle both formats:
        // - Raw face-api.js: detection.detection.box, detection.detection.score
        // - Flattened (from detectSingleFace): detection.box, detection.score
        const box = detection.detection ? detection.detection.box : detection.box;
        const detectionScore = detection.detection ? detection.detection.score : detection.score;
        const landmarks = detection.landmarks;

        if (!box) return { score: 0, factors: {}, acceptable: false };

        const vw = this.videoElement ? (this.videoElement.videoWidth || 640) : 640;
        const vh = this.videoElement ? (this.videoElement.videoHeight || 480) : 480;

        // 1. Face size (larger = better quality descriptors)
        const faceArea = (box.width * box.height) / (vw * vh);
        const sizeScore = Math.min(1, faceArea / 0.12); // 12% of frame = perfect

        // 2. Face centering (centered faces have less distortion)
        const centerX = (box.x + box.width / 2) / vw;
        const centerY = (box.y + box.height / 2) / vh;
        const offCenter = Math.sqrt(Math.pow(centerX - 0.5, 2) + Math.pow(centerY - 0.5, 2));
        const centerScore = Math.max(0, 1 - offCenter * 2.5);

        // 3. Detection confidence
        const confidenceScore = Math.min(1, (detectionScore || 0) / 0.95);

        // 4. Face angle (frontal faces produce better descriptors)
        let angleScore = 1;
        if (detection.landmarks) {
            const angle = this.estimateFaceAngle(detection.landmarks);
            const yawPenalty = Math.abs(angle.yaw) / 30; // >30deg = bad
            const pitchPenalty = Math.abs(angle.pitch) / 25; // >25deg = bad
            angleScore = Math.max(0, 1 - Math.max(yawPenalty, pitchPenalty));
        }

        // 5. Face aspect ratio (should be roughly 0.7–0.85 width/height)
        const aspect = box.width / box.height;
        const idealAspect = 0.75;
        const aspectDev = Math.abs(aspect - idealAspect);
        const aspectScore = Math.max(0, 1 - aspectDev * 4);

        const factors = {
            size: +sizeScore.toFixed(3),
            centering: +centerScore.toFixed(3),
            confidence: +confidenceScore.toFixed(3),
            angle: +angleScore.toFixed(3),
            aspect: +aspectScore.toFixed(3)
        };

        const weights = { size: 0.3, centering: 0.15, confidence: 0.2, angle: 0.25, aspect: 0.1 };
        const qualityScore = Object.keys(weights).reduce((sum, k) => sum + weights[k] * factors[k], 0);

        return {
            score: +qualityScore.toFixed(3),
            factors,
            acceptable: qualityScore >= 0.55
        };
    }

    /**
     * Estimate face yaw/pitch from 68-point landmarks.
     * Uses nose tip, nose bridge, and eye positions for geometric estimation.
     * @param {Object} landmarks - Face landmarks from face-api.js
     * @returns {{yaw: number, pitch: number}} Angles in degrees
     */
    estimateFaceAngle(landmarks) {
        if (!landmarks || !landmarks.positions || landmarks.positions.length < 68) {
            return { yaw: 0, pitch: 0 };
        }
        const pts = landmarks.positions;
        // Nose tip (index 30), left eye center (average of 36-41), right eye center (average of 42-47)
        const noseTip = pts[30];
        const leftEye = {
            x: (pts[36].x + pts[39].x) / 2,
            y: (pts[36].y + pts[39].y) / 2
        };
        const rightEye = {
            x: (pts[42].x + pts[45].x) / 2,
            y: (pts[42].y + pts[45].y) / 2
        };

        // Yaw: nose tip horizontal position relative to eye midpoint
        const eyeMidX = (leftEye.x + rightEye.x) / 2;
        const eyeWidth = Math.abs(rightEye.x - leftEye.x);
        const yawRatio = (noseTip.x - eyeMidX) / (eyeWidth || 1);
        const yaw = yawRatio * 45; // Approximate degrees

        // Pitch: nose tip vertical position relative to eye line
        const eyeMidY = (leftEye.y + rightEye.y) / 2;
        const chin = pts[8]; // Bottom of chin
        const faceHeight = chin.y - eyeMidY;
        const pitchRatio = (noseTip.y - eyeMidY) / (faceHeight || 1) - 0.4; // 0.4 = neutral
        const pitch = pitchRatio * 60; // Approximate degrees

        return {
            yaw: +yaw.toFixed(1),
            pitch: +pitch.toFixed(1)
        };
    }

    /**
     * Check how similar a new descriptor is to existing descriptors for the same student.
     * Used during enrollment to ensure new descriptors are consistent but not duplicates.
     * @param {Float32Array|Array} newDescriptor
     * @param {Array<Float32Array>} existingDescriptors
     * @returns {{avgDistance: number, minDistance: number, isDuplicate: boolean, isConsistent: boolean}}
     */
    checkInterDescriptorSimilarity(newDescriptor, existingDescriptors) {
        if (!existingDescriptors || existingDescriptors.length === 0) {
            return { avgDistance: 0, minDistance: 0, isDuplicate: false, isConsistent: true };
        }

        let totalDist = 0;
        let minDist = Infinity;
        for (const existing of existingDescriptors) {
            const dist = faceapi.euclideanDistance(newDescriptor, existing);
            totalDist += dist;
            if (dist < minDist) minDist = dist;
        }
        const avgDist = totalDist / existingDescriptors.length;

        return {
            avgDistance: +avgDist.toFixed(4),
            minDistance: +minDist.toFixed(4),
            isDuplicate: minDist < 0.15, // Nearly identical — likely same frame
            isConsistent: avgDist < 0.5   // Reasonable variance for same person
        };
    }

    /**
     * Find the best matching known face using per-person aggregation.
     * Groups descriptors by userId, picks the minimum distance per person,
     * then applies strict threshold + distance gap between best and 2nd best.
     * Optimized: uses pre-indexed groups and linear top-2 scan (no sort/spread).
     */
    _findBestMatch(queryDescriptor) {
        if (this.knownDescriptors.length === 0) return null;

        // Reuse a single Float32Array buffer when descriptor comes as a plain Array
        let query;
        if (queryDescriptor instanceof Float32Array) {
            query = queryDescriptor;
        } else {
            if (!this._queryBuf) this._queryBuf = new Float32Array(128);
            for (let i = 0; i < 128; i++) this._queryBuf[i] = queryDescriptor[i];
            query = this._queryBuf;
        }

        // Use pre-indexed groups if available, else iterate all
        const groups = this._descriptorsByUser || null;
        let best1 = null;  // best match
        let dist1 = Infinity;
        let best2Dist = Infinity; // 2nd best distance (for gap check)

        if (groups) {
            // Fast path: iterate pre-grouped map
            for (const [userId, descriptors] of groups) {
                let minDist = Infinity;
                let minDesc = null;
                for (const known of descriptors) {
                    const d = faceapi.euclideanDistance(query, known.descriptor);
                    if (d < minDist) { minDist = d; minDesc = known; }
                }
                if (minDist < dist1) {
                    best2Dist = dist1;
                    dist1 = minDist;
                    best1 = minDesc;
                } else if (minDist < best2Dist) {
                    best2Dist = minDist;
                }
            }
        } else {
            // Fallback: group on the fly with linear top-2 tracking
            const personBest = new Map();
            for (const known of this.knownDescriptors) {
                const distance = faceapi.euclideanDistance(query, known.descriptor);
                const existing = personBest.get(known.userId);
                if (!existing || distance < existing.distance) {
                    personBest.set(known.userId, { descriptor: known, distance });
                }
            }
            for (const entry of personBest.values()) {
                if (entry.distance < dist1) {
                    best2Dist = dist1;
                    dist1 = entry.distance;
                    best1 = entry.descriptor;
                } else if (entry.distance < best2Dist) {
                    best2Dist = entry.distance;
                }
            }
        }

        if (!best1 || dist1 >= this.matchThreshold) return null;

        // Distance gap: best must be clearly better than 2nd-best when multiple people enrolled
        if (best2Dist < Infinity) {
            const gap = best2Dist - dist1;
            if (gap < this.minDistanceGap) return null;
        }

        return {
            ...best1,
            distance: dist1,
            confidence: Math.max(0, 1 - dist1).toFixed(4)
        };
    }

    /**
     * Validate that the detected face is large enough in the frame.
     * Rejects tiny faces from physical ID photos held up to the camera.
     */
    _checkFaceSize(box) {
        if (!this.videoElement || !box) return false;
        const vw = this.videoElement.videoWidth || this.videoElement.width || this.videoElement.clientWidth || 640;
        const vh = this.videoElement.videoHeight || this.videoElement.height || this.videoElement.clientHeight || 480;
        if (box.width < this.minFaceSizePx || box.height < this.minFaceSizePx) return false;
        if ((box.width * box.height) / (vw * vh) < this.minFaceSizeRatio) return false;
        return true;
    }

    /**
     * Reset anti-spoofing accumulators (call when starting a new scan session)
     */
    resetAccumulators() {
        this._livenessDetector.reset();
        this._matchAccumulator.reset();
    }

    // --- Drawing helpers for the continuous detection loop ---

    _getVideoDisplaySize() {
        const el = this.videoElement;
        return {
            width: el.videoWidth || el.width || el.clientWidth || 640,
            height: el.videoHeight || el.height || el.clientHeight || 480
        };
    }

    _drawVerificationProgress(detection, match, frameCount, liveness) {
        if (!this.canvasElement || !detection._raw) return;
        const displaySize = this._getVideoDisplaySize();
        const resized = faceapi.resizeResults(detection._raw, displaySize);
        const ctx = this.canvasElement.getContext('2d');
        ctx.clearRect(0, 0, this.canvasElement.width, this.canvasElement.height);

        const box = resized.detection.box;

        // Green bounding box
        ctx.strokeStyle = '#00ff00';
        ctx.lineWidth = 3;
        ctx.strokeRect(box.x, box.y, box.width, box.height);

        // Name + RECOGNIZED label above box
        const label = match.name;
        ctx.font = 'bold 11px Arial';
        const nameW = ctx.measureText(label).width;
        ctx.font = 'bold 10px Arial';
        const recW = ctx.measureText('RECOGNIZED').width;
        const bgW = Math.max(nameW, recW) + 10;
        ctx.fillStyle = 'rgba(0,0,0,0.65)';
        ctx.fillRect(box.x, box.y - 38, bgW, 36);
        ctx.font = 'bold 11px Arial';
        ctx.fillStyle = '#ffffff';
        ctx.fillText(label, box.x + 5, box.y - 23);
        ctx.font = 'bold 10px Arial';
        ctx.fillStyle = '#00ff00';
        ctx.fillText('RECOGNIZED', box.x + 5, box.y - 9);
    }

    _drawNoMatch(detection) {
        if (!this.canvasElement || !detection._raw) return;
        const displaySize = this._getVideoDisplaySize();
        const resized = faceapi.resizeResults(detection._raw, displaySize);
        const ctx = this.canvasElement.getContext('2d');
        ctx.clearRect(0, 0, this.canvasElement.width, this.canvasElement.height);
        const box = resized.detection.box;
        ctx.strokeStyle = '#ff4444';
        ctx.lineWidth = 2;
        ctx.strokeRect(box.x, box.y, box.width, box.height);
        ctx.fillStyle = '#ff4444';
        ctx.font = 'bold 12px Arial';
        ctx.fillText('Not recognized', box.x, box.y - 8);
    }

    _drawSmallFaceWarning(detection) {
        if (!this.canvasElement || !detection._raw) return;
        const displaySize = this._getVideoDisplaySize();
        const resized = faceapi.resizeResults(detection._raw, displaySize);
        const ctx = this.canvasElement.getContext('2d');
        ctx.clearRect(0, 0, this.canvasElement.width, this.canvasElement.height);
        const box = resized.detection.box;
        ctx.strokeStyle = '#ff8800';
        ctx.lineWidth = 2;
        ctx.setLineDash([5, 5]);
        ctx.strokeRect(box.x, box.y, box.width, box.height);
        ctx.setLineDash([]);
        ctx.fillStyle = '#ff8800';
        ctx.font = 'bold 12px Arial';
        ctx.fillText('Too small — move closer', box.x, box.y - 8);
    }

    _drawUnrecognizedAlert(detection) {
        if (!this.canvasElement || !detection._raw) return;
        const displaySize = this._getVideoDisplaySize();
        const resized = faceapi.resizeResults(detection._raw, displaySize);
        const ctx = this.canvasElement.getContext('2d');
        ctx.clearRect(0, 0, this.canvasElement.width, this.canvasElement.height);
        const box = resized.detection.box;

        // Thick red box
        ctx.strokeStyle = '#ff0000';
        ctx.lineWidth = 4;
        ctx.strokeRect(box.x, box.y, box.width, box.height);

        // Red banner above face
        const bannerH = 30;
        ctx.fillStyle = 'rgba(220, 0, 0, 0.85)';
        ctx.fillRect(box.x, box.y - bannerH - 4, box.width, bannerH);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 14px Arial';
        ctx.textAlign = 'center';
        ctx.fillText('⚠ NOT REGISTERED', box.x + box.width / 2, box.y - 12);
        ctx.textAlign = 'start';

        // Red label below
        ctx.fillStyle = '#ff0000';
        ctx.font = 'bold 11px Arial';
        ctx.fillText('This person is not in the system', box.x, box.y + box.height + 16);
    }

    _drawLivenessFailAlert(detection) {
        if (!this.canvasElement || !detection._raw) return;
        const displaySize = this._getVideoDisplaySize();
        const resized = faceapi.resizeResults(detection._raw, displaySize);
        const ctx = this.canvasElement.getContext('2d');
        ctx.clearRect(0, 0, this.canvasElement.width, this.canvasElement.height);
        const box = resized.detection.box;

        // Orange/red dashed box
        ctx.strokeStyle = '#ff4400';
        ctx.lineWidth = 3;
        ctx.setLineDash([8, 4]);
        ctx.strokeRect(box.x, box.y, box.width, box.height);
        ctx.setLineDash([]);

        // Banner
        const bannerH = 30;
        ctx.fillStyle = 'rgba(200, 60, 0, 0.85)';
        ctx.fillRect(box.x, box.y - bannerH - 4, box.width, bannerH);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 13px Arial';
        ctx.textAlign = 'center';
        ctx.fillText('⚠ PHOTO/SCREEN DETECTED', box.x + box.width / 2, box.y - 12);
        ctx.textAlign = 'start';

        ctx.fillStyle = '#ff4400';
        ctx.font = 'bold 11px Arial';
        ctx.fillText('Live face required — no photos', box.x, box.y + box.height + 16);
    }

    /**
     * Draw face detection box and landmarks on canvas (used by detectSingleFace for registration)
     */
    _drawDetection(detection) {
        if (!this.canvasElement) return;

        const displaySize = this._getVideoDisplaySize();

        const resized = faceapi.resizeResults(detection, displaySize);
        const ctx = this.canvasElement.getContext('2d');
        ctx.clearRect(0, 0, this.canvasElement.width, this.canvasElement.height);

        // Draw bounding box
        const box = resized.detection.box;
        ctx.strokeStyle = '#00ff00';
        ctx.lineWidth = 3;
        ctx.strokeRect(box.x, box.y, box.width, box.height);

        // Draw confidence score
        ctx.fillStyle = '#00ff00';
        ctx.font = 'bold 14px Arial';
        const score = (detection.detection.score * 100).toFixed(1);
        ctx.fillText(`${score}%`, box.x, box.y - 8);
    }

    /**
     * Set system status and notify callback
     */
    _setStatus(status, message) {
        console.log(`[FaceRec] ${status}: ${message}`);
        if (this.onStatusChange) {
            this.onStatusChange(status, message);
        }
    }

    /**
     * Handle errors and notify callback
     */
    _handleError(message, error = null) {
        console.error(`[FaceRec Error] ${message}`, error || '');
        if (this.onError) {
            this.onError(message, error);
        }
    }
}

// Export for module systems, also available as global
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FaceRecognitionSystem;
}
