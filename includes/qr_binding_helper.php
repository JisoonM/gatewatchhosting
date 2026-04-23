<?php

declare(strict_types=1);

function qr_binding_enabled(): bool {
    return filter_var(env('QR_FACE_BINDING_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function qr_binding_strict(): bool {
    return filter_var(env('QR_FACE_BINDING_STRICT', 'true'), FILTER_VALIDATE_BOOLEAN);
}

function qr_guard_session_hash(): string {
    return hash('sha256', session_id());
}

function qr_datetime_is_expired(?string $dt): bool {
    if (!$dt) {
        return true;
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return true;
    }
    return $ts <= time();
}

function ensure_qr_binding_tables(PDO $pdo): void {
    // Tables are now created via migrations (002_add_runtime_tables.sql).
    // This function is kept for backward compatibility but no longer creates tables.
    // If tables are missing, run: php scripts/validate_schema.php
    $check = $pdo->query("SHOW TABLES LIKE 'qr_scan_challenges'")->fetch();
    if (!$check) {
        error_log('[PCU RFID] QR binding tables not found. Run migrations first.');
    }
}

function qr_binding_expire_stale_rows(PDO $pdo): void {
    $now = date('Y-m-d H:i:s');

    $pdo->prepare("UPDATE qr_scan_challenges
        SET status = 'expired'
        WHERE status = 'active' AND expires_at <= ?")
        ->execute([$now]);

    $pdo->prepare("UPDATE qr_face_pending
        SET status = 'expired', resolved_at = NOW()
        WHERE status = 'pending' AND expires_at <= ?")
        ->execute([$now]);
}

function qr_binding_get_pending(PDO $pdo, string $guardSessionHash): ?array {
    $now = date('Y-m-d H:i:s');

    $pdo->prepare("UPDATE qr_face_pending
        SET status = 'expired', resolved_at = NOW()
        WHERE guard_session_hash = ? AND status = 'pending' AND expires_at <= ?")
        ->execute([$guardSessionHash, $now]);

    $stmt = $pdo->prepare("SELECT * FROM qr_face_pending
        WHERE guard_session_hash = ? AND status = 'pending' AND expires_at > ?
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$guardSessionHash, $now]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function qr_binding_clear_pending(PDO $pdo, string $guardSessionHash, string $reason = 'manual_clear'): int {
    $stmt = $pdo->prepare("UPDATE qr_face_pending
        SET status = 'rejected', reject_reason = ?, resolved_at = NOW()
        WHERE guard_session_hash = ? AND status = 'pending'");
    $stmt->execute([$reason, $guardSessionHash]);
    return $stmt->rowCount();
}

function qr_binding_log_event(PDO $pdo, string $eventType, ?int $userId = null, ?string $studentId = null, ?string $challengeId = null, ?string $tokenHash = null, array $details = []): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO qr_security_events
            (event_type, guard_session_hash, guard_username, user_id, student_id, challenge_id, token_hash, details_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $eventType,
            qr_guard_session_hash(),
            (string)($_SESSION['security_username'] ?? 'Unknown'),
            $userId,
            $studentId,
            $challengeId,
            $tokenHash,
            empty($details) ? null : json_encode($details)
        ]);
    } catch (Throwable $e) {
        error_log('qr_binding_log_event error: ' . $e->getMessage());
    }
}
