-- One-Time Use QR Code System Tables
-- Run this migration to enable one-time use QR codes

-- Table to track used QR tokens (prevents reuse)
CREATE TABLE IF NOT EXISTS used_qr_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash VARCHAR(64) NOT NULL UNIQUE,  -- SHA256 hash of JWT token
    user_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    used_at DATETIME NOT NULL,
    security_guard VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    INDEX idx_user_id (user_id),
    INDEX idx_used_at (used_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table to log all QR code entries (for reporting)
CREATE TABLE IF NOT EXISTS qr_entry_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    entry_type ENUM('QR_CODE', 'RFID') DEFAULT 'QR_CODE',
    scanned_at DATETIME NOT NULL,
    security_guard VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_student_id (student_id),
    INDEX idx_scanned_at (scanned_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Clean up old tokens (tokens older than 24 hours)
-- You can run this as a scheduled job or cron
-- DELETE FROM used_qr_tokens WHERE used_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
