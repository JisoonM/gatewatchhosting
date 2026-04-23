<?php
declare(strict_types=1);

/**
 * Shared helper for scan-to-violation token binding and immutable write attribution.
 */

function security_scan_guard_session_hash(): string {
    $sessionId = session_id();
    $guardId = (string) ($_SESSION['security_id'] ?? '0');
    $username = (string) ($_SESSION['security_username'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    return hash('sha256', $sessionId . '|' . $guardId . '|' . $username . '|' . $ua);
}

/**
 * Legacy hash used by older flows before scan-token hashing was unified.
 */
function security_scan_guard_session_hash_legacy(): string {
    return hash('sha256', session_id());
}

/**
 * @return list<string>
 */
function security_scan_guard_session_hash_candidates(?string $preferred = null): array {
    $candidates = [];

    if (is_string($preferred) && preg_match('/^[a-f0-9]{64}$/i', $preferred)) {
        $candidates[] = strtolower($preferred);
    }

    $candidates[] = strtolower(security_scan_guard_session_hash());
    // Backward compatibility for tokens issued while using session-id-only binding.
    $candidates[] = strtolower(security_scan_guard_session_hash_legacy());

    return array_values(array_unique($candidates));
}

function ensure_security_scan_tokens_table(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS security_scan_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        guard_session_hash CHAR(64) NOT NULL,
        guard_id INT NULL,
        guard_username VARCHAR(120) NULL,
        user_id INT NOT NULL,
        scan_source VARCHAR(16) NOT NULL,
        issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME NULL,
        consumed_by_guard_id INT NULL,
        consumed_ip VARCHAR(45) NULL,
        INDEX idx_sst_guard_session (guard_session_hash),
        INDEX idx_sst_user (user_id),
        INDEX idx_sst_expires (expires_at),
        INDEX idx_sst_source (scan_source)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Best-effort pruning keeps table size bounded without affecting request success.
    try {
        $pdo->exec("DELETE FROM security_scan_tokens WHERE issued_at < NOW() - INTERVAL 7 DAY");
    } catch (Throwable $e) {
        error_log('security_scan_tokens prune error: ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * @return array{token:string, token_id:int, expires_at:string, token_hash:string}
 */
function issue_security_scan_token(PDO $pdo, int $userId, string $scanSource, ?int $guardId, string $guardUsername, ?string $guardSessionHash = null): array {
    $allowedSources = ['rfid', 'qr', 'face'];
    if (!in_array($scanSource, $allowedSources, true)) {
        throw new RuntimeException('Unsupported scan source for token issue', 400);
    }

    $ttl = (int) env('SECURITY_SCAN_TOKEN_TTL_SECONDS', '120');
    if ($ttl < 30) {
        $ttl = 30;
    }
    if ($ttl > 300) {
        $ttl = 300;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $sessionHash = $guardSessionHash ?: security_scan_guard_session_hash();
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

    $stmt = $pdo->prepare('INSERT INTO security_scan_tokens (token_hash, guard_session_hash, guard_id, guard_username, user_id, scan_source, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $tokenHash,
        $sessionHash,
        $guardId,
        $guardUsername,
        $userId,
        $scanSource,
        $expiresAt,
    ]);

    return [
        'token' => $token,
        'token_id' => (int) $pdo->lastInsertId(),
        'expires_at' => $expiresAt,
        'token_hash' => $tokenHash,
    ];
}

/**
 * @return array{id:int, token_hash:string}
 */
function consume_security_scan_token(PDO $pdo, string $plainToken, string $expectedGuardSessionHash, int $expectedUserId, string $expectedSource, ?int $guardId = null): array {
    if (!preg_match('/^[a-f0-9]{64}$/i', $plainToken)) {
        throw new RuntimeException('Invalid scan token format', 400);
    }

    $allowedSources = ['rfid', 'qr', 'face'];
    if (!in_array($expectedSource, $allowedSources, true)) {
        throw new RuntimeException('Invalid scan source', 400);
    }

    $tokenHash = hash('sha256', $plainToken);

    $stmt = $pdo->prepare('SELECT id, guard_session_hash, user_id, scan_source, expires_at, consumed_at FROM security_scan_tokens WHERE token_hash = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('Scan verification token not found. Please rescan.', 403);
    }

    if (!empty($row['consumed_at'])) {
        throw new RuntimeException('Scan verification token already used. Please rescan.', 409);
    }

    $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    if (!$expiresAt || $expiresAt <= time()) {
        throw new RuntimeException('Scan verification token expired. Please rescan.', 409);
    }

    $storedGuardSessionHash = strtolower((string) ($row['guard_session_hash'] ?? ''));
    $acceptableGuardHashes = security_scan_guard_session_hash_candidates($expectedGuardSessionHash);
    $guardSessionMatch = false;
    foreach ($acceptableGuardHashes as $candidateHash) {
        if (hash_equals($storedGuardSessionHash, $candidateHash)) {
            $guardSessionMatch = true;
            break;
        }
    }

    if (!$guardSessionMatch) {
        throw new RuntimeException('Scan token does not belong to this guard session.', 403);
    }

    if ((int) $row['user_id'] !== $expectedUserId) {
        throw new RuntimeException('Scan token student mismatch. Please rescan.', 403);
    }

    if ((string) $row['scan_source'] !== $expectedSource) {
        throw new RuntimeException('Scan token source mismatch. Please rescan.', 403);
    }

    $consumeStmt = $pdo->prepare('UPDATE security_scan_tokens SET consumed_at = NOW(), consumed_by_guard_id = ?, consumed_ip = ? WHERE id = ? AND consumed_at IS NULL');
    $consumeStmt->execute([
        $guardId,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        (int) $row['id'],
    ]);

    if ($consumeStmt->rowCount() !== 1) {
        throw new RuntimeException('Unable to consume scan token. Please retry scan.', 409);
    }

    return [
        'id' => (int) $row['id'],
        'token_hash' => $tokenHash,
    ];
}

function ensure_violation_record_audit_table(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS violation_record_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        violation_id INT NOT NULL,
        user_id INT NOT NULL,
        category_id INT NOT NULL,
        recorded_by INT NULL,
        scan_source VARCHAR(16) NOT NULL,
        guard_session_hash CHAR(64) NOT NULL,
        scan_token_id BIGINT UNSIGNED NOT NULL,
        scan_token_hash CHAR(64) NOT NULL,
        notes_length INT NOT NULL DEFAULT 0,
        request_ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vra_violation (violation_id),
        INDEX idx_vra_user (user_id),
        INDEX idx_vra_guard_session (guard_session_hash),
        INDEX idx_vra_scan_token_id (scan_token_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ensured = true;
}

/**
 * @param array<string,mixed> $data
 */
function write_violation_record_audit(PDO $pdo, array $data): void {
    $stmt = $pdo->prepare('INSERT INTO violation_record_audit (violation_id, user_id, category_id, recorded_by, scan_source, guard_session_hash, scan_token_id, scan_token_hash, notes_length, request_ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        (int) ($data['violation_id'] ?? 0),
        (int) ($data['user_id'] ?? 0),
        (int) ($data['category_id'] ?? 0),
        isset($data['recorded_by']) ? (int) $data['recorded_by'] : null,
        (string) ($data['scan_source'] ?? ''),
        (string) ($data['guard_session_hash'] ?? ''),
        (int) ($data['scan_token_id'] ?? 0),
        (string) ($data['scan_token_hash'] ?? ''),
        (int) ($data['notes_length'] ?? 0),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}
