-- Strict QR-to-Face binding support
CREATE TABLE IF NOT EXISTS qr_face_pending (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    guard_session_hash CHAR(64) NOT NULL,
    guard_username VARCHAR(100) DEFAULT NULL,
    challenge_id VARCHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    user_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    status ENUM('pending','verified','rejected','expired') NOT NULL DEFAULT 'pending',
    reject_reason VARCHAR(80) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    resolved_at DATETIME DEFAULT NULL,
    resolved_by_user_id INT DEFAULT NULL,
    INDEX idx_qr_pending_guard_status (guard_session_hash, status),
    INDEX idx_qr_pending_expires (status, expires_at),
    INDEX idx_qr_pending_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qr_security_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(64) NOT NULL,
    guard_session_hash CHAR(64) DEFAULT NULL,
    guard_username VARCHAR(100) DEFAULT NULL,
    user_id INT DEFAULT NULL,
    student_id VARCHAR(50) DEFAULT NULL,
    challenge_id VARCHAR(64) DEFAULT NULL,
    token_hash CHAR(64) DEFAULT NULL,
    details_json TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_qr_events_type_time (event_type, created_at),
    INDEX idx_qr_events_guard (guard_session_hash, created_at),
    INDEX idx_qr_events_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
