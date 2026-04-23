/**
 * Face Match Worker
 * 
 * Offloads Euclidean distance matching of face descriptors from the main thread.
 * Descriptors are grouped by userId for per-person aggregation.
 * Uses Float32Array for fast distance computation.
 *
 * Messages:
 *   → { type: 'load', descriptors: Array<{userId, descriptor, name, ...}>, threshold, minGap }
 *   → { type: 'update', added: Array<{userId, descriptor, ...}>, removed: Array<descriptorId> }
 *   → { type: 'match', queryId, query: number[], threshold?, minGap? }
 *   → { type: 'config', threshold, minGap }
 *   ← { type: 'loaded', count, userCount }
 *   ← { type: 'updated', count, userCount }
 *   ← { type: 'result', queryId, match: {...}|null }
 */

// ---- State ----
/** @type {Map<number, Array<{userId:number, descriptor:Float32Array, name:string, studentId:string, label:string}>>} */
const descriptorsByUser = new Map();
let totalCount = 0;
let matchThreshold = 0.6;
let minDistanceGap = 0.12;

// ---- Euclidean distance (inlined for Worker scope, no faceapi dependency) ----
function euclideanDistance(a, b) {
    let sum = 0;
    for (let i = 0; i < a.length; i++) {
        const diff = a[i] - b[i];
        sum += diff * diff;
    }
    return Math.sqrt(sum);
}

// ---- Load all descriptors (full reset) ----
function handleLoad(data) {
    descriptorsByUser.clear();
    totalCount = 0;

    if (data.threshold != null) matchThreshold = data.threshold;
    if (data.minGap != null) minDistanceGap = data.minGap;

    const descriptors = data.descriptors || [];
    for (const d of descriptors) {
        addDescriptor(d);
    }

    self.postMessage({
        type: 'loaded',
        count: totalCount,
        userCount: descriptorsByUser.size
    });
}

// ---- Incremental update ----
function handleUpdate(data) {
    const removed = data.removed || [];
    const added = data.added || [];

    // Remove deactivated descriptors by descriptor_id
    if (removed.length > 0) {
        const removeSet = new Set(removed);
        for (const [userId, descs] of descriptorsByUser) {
            const filtered = descs.filter(d => !removeSet.has(d.descriptorId));
            if (filtered.length === 0) {
                descriptorsByUser.delete(userId);
            } else if (filtered.length !== descs.length) {
                descriptorsByUser.set(userId, filtered);
            }
        }
        totalCount = recountTotal();
    }

    // Add new descriptors
    for (const d of added) {
        addDescriptor(d);
    }

    self.postMessage({
        type: 'updated',
        count: totalCount,
        userCount: descriptorsByUser.size
    });
}

// ---- Match a query descriptor against all known ----
function handleMatch(data) {
    const queryId = data.queryId;
    const threshold = data.threshold != null ? data.threshold : matchThreshold;
    const minGap = data.minGap != null ? data.minGap : minDistanceGap;
    // Convert query to Float32Array if it isn't already
    const query = data.query instanceof Float32Array
        ? data.query
        : new Float32Array(data.query);

    if (descriptorsByUser.size === 0) {
        self.postMessage({ type: 'result', queryId, match: null });
        return;
    }

    let best1 = null;
    let dist1 = Infinity;
    let best2Dist = Infinity;

    for (const [userId, descriptors] of descriptorsByUser) {
        let minDist = Infinity;
        let minDesc = null;
        for (const known of descriptors) {
            const d = euclideanDistance(query, known.descriptor);
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

    if (!best1 || dist1 >= threshold) {
        self.postMessage({ type: 'result', queryId, match: null });
        return;
    }

    // Distance gap check: best must be clearly better than 2nd-best
    if (best2Dist < Infinity) {
        const gap = best2Dist - dist1;
        if (gap < minGap) {
            self.postMessage({ type: 'result', queryId, match: null });
            return;
        }
    }

    self.postMessage({
        type: 'result',
        queryId,
        match: {
            userId: best1.userId,
            name: best1.name,
            studentId: best1.studentId,
            course: best1.course,
            profilePicture: best1.profilePicture,
            violationCount: best1.violationCount,
            label: best1.label,
            distance: dist1,
            confidence: Math.max(0, 1 - dist1)
        }
    });
}

// ---- Helpers ----
function addDescriptor(d) {
    const userId = d.userId || d.user_id;
    const entry = {
        userId: userId,
        descriptorId: d.descriptorId || d.descriptor_id || 0,
        descriptor: d.descriptor instanceof Float32Array
            ? d.descriptor
            : new Float32Array(d.descriptor),
        name: d.name || '',
        studentId: d.studentId || d.student_id || '',
        course: d.course || '',
        profilePicture: d.profilePicture || d.profile_picture || null,
        violationCount: d.violationCount || d.violation_count || 0,
        label: d.label || 'front'
    };

    if (!descriptorsByUser.has(userId)) {
        descriptorsByUser.set(userId, []);
    }
    descriptorsByUser.get(userId).push(entry);
    totalCount++;
}

function recountTotal() {
    let count = 0;
    for (const descs of descriptorsByUser.values()) {
        count += descs.length;
    }
    return count;
}

// ---- Message dispatcher ----
self.onmessage = function (e) {
    const data = e.data;
    switch (data.type) {
        case 'load':
            handleLoad(data);
            break;
        case 'update':
            handleUpdate(data);
            break;
        case 'match':
            handleMatch(data);
            break;
        case 'config':
            if (data.threshold != null) matchThreshold = data.threshold;
            if (data.minGap != null) minDistanceGap = data.minGap;
            break;
        default:
            break;
    }
};
