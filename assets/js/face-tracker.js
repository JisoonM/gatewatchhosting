/**
 * Face Tracker — Lightweight IOU-based bounding box tracker
 * 
 * Tracks a detected face between full ML inference runs.
 * Uses simple IOU overlap and position prediction to maintain
 * visual feedback without running the neural network every frame.
 * 
 * No ML dependencies — pure vanilla JS (~100 lines).
 */
class FaceTracker {
    constructor(options = {}) {
        /** Minimum IOU overlap to consider the same face */
        this.iouThreshold = options.iouThreshold || 0.35;
        /** Maximum frames without an ML update before we lose tracking */
        this.maxStaleFrames = options.maxStaleFrames || 8;
        /** Smoothing factor for velocity estimation (0-1, higher = more responsive) */
        this.velocitySmoothing = options.velocitySmoothing || 0.4;

        this._box = null;       // { x, y, width, height }
        this._velocity = null;  // { dx, dy, dw, dh }
        this._staleFrames = 0;
        this._lastUpdateTime = 0;
    }

    /**
     * Update tracker with a new ML detection result.
     * Call this on full-detection frames.
     * @param {{ x: number, y: number, width: number, height: number }} box
     */
    update(box) {
        if (!box) {
            this.reset();
            return;
        }

        const now = performance.now();
        const newBox = { x: box.x, y: box.y, width: box.width, height: box.height };

        if (this._box) {
            const dt = Math.max(1, now - this._lastUpdateTime);
            const rawVel = {
                dx: (newBox.x - this._box.x) / dt,
                dy: (newBox.y - this._box.y) / dt,
                dw: (newBox.width - this._box.width) / dt,
                dh: (newBox.height - this._box.height) / dt
            };

            if (this._velocity) {
                const s = this.velocitySmoothing;
                this._velocity = {
                    dx: s * rawVel.dx + (1 - s) * this._velocity.dx,
                    dy: s * rawVel.dy + (1 - s) * this._velocity.dy,
                    dw: s * rawVel.dw + (1 - s) * this._velocity.dw,
                    dh: s * rawVel.dh + (1 - s) * this._velocity.dh
                };
            } else {
                this._velocity = rawVel;
            }
        }

        this._box = newBox;
        this._staleFrames = 0;
        this._lastUpdateTime = now;
    }

    /**
     * Predict the current face box based on last known position and velocity.
     * Call this on tracked-only frames (between full ML detections).
     * @returns {{ x: number, y: number, width: number, height: number } | null}
     */
    predict() {
        if (!this._box) return null;

        this._staleFrames++;
        if (this._staleFrames > this.maxStaleFrames) {
            this.reset();
            return null;
        }

        if (!this._velocity) return { ...this._box };

        const now = performance.now();
        const dt = now - this._lastUpdateTime;

        return {
            x: this._box.x + this._velocity.dx * dt,
            y: this._box.y + this._velocity.dy * dt,
            width: Math.max(20, this._box.width + this._velocity.dw * dt),
            height: Math.max(20, this._box.height + this._velocity.dh * dt)
        };
    }

    /**
     * Check if a new detection box matches the currently tracked face (IOU test).
     * @param {{ x: number, y: number, width: number, height: number }} newBox
     * @returns {boolean}
     */
    matches(newBox) {
        if (!this._box || !newBox) return false;
        return this._computeIOU(this._box, newBox) >= this.iouThreshold;
    }

    /**
     * @returns {boolean} Whether the tracker has a valid tracked face.
     */
    isTracking() {
        return this._box !== null;
    }

    /**
     * Get the last known box (without prediction).
     * @returns {{ x: number, y: number, width: number, height: number } | null}
     */
    getBox() {
        return this._box ? { ...this._box } : null;
    }

    /**
     * Reset tracker state.
     */
    reset() {
        this._box = null;
        this._velocity = null;
        this._staleFrames = 0;
        this._lastUpdateTime = 0;
    }

    /**
     * Compute Intersection over Union between two boxes.
     * @private
     */
    _computeIOU(a, b) {
        const x1 = Math.max(a.x, b.x);
        const y1 = Math.max(a.y, b.y);
        const x2 = Math.min(a.x + a.width, b.x + b.width);
        const y2 = Math.min(a.y + a.height, b.y + b.height);

        if (x2 <= x1 || y2 <= y1) return 0;

        const intersection = (x2 - x1) * (y2 - y1);
        const areaA = a.width * a.height;
        const areaB = b.width * b.height;
        const union = areaA + areaB - intersection;

        return union > 0 ? intersection / union : 0;
    }
}

// Export for both module and script tag usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FaceTracker;
}
