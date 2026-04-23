-- QR gate challenge nonces (anti-replay)
CREATE TABLE IF NOT EXISTS qr_scan_challenges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    challenge_id VARCHAR(64) NOT NULL UNIQUE,
    guard_session_hash CHAR(64) NOT NULL,
    guard_username VARCHAR(100) DEFAULT NULL,
    status ENUM('active','consumed','expired') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    consumed_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_qr_challenge_status_expires (status, expires_at),
    INDEX idx_qr_challenge_guard_session (guard_session_hash, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
