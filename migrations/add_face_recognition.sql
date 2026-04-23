-- ============================================
-- FACE RECOGNITION TABLES
-- Migration: Add face recognition support
-- Follows existing normalization (3NF/BCNF)
-- ============================================

-- Face descriptors table (normalized)
-- Stores encrypted 128-dimensional face descriptor vectors
-- Each student can have multiple face descriptors for accuracy
CREATE TABLE IF NOT EXISTS face_descriptors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    descriptor_data TEXT NOT NULL COMMENT 'AES-256-GCM encrypted face descriptor (128-dim float array)',
    descriptor_iv VARCHAR(48) NOT NULL COMMENT 'Initialization vector for AES decryption',
    descriptor_tag VARCHAR(48) NOT NULL COMMENT 'Authentication tag for AES-GCM',
    label VARCHAR(100) DEFAULT NULL COMMENT 'Label for this descriptor (e.g., front, left, right)',
    quality_score FLOAT DEFAULT NULL COMMENT 'Detection confidence score 0.0-1.0',
    registered_by INT DEFAULT NULL COMMENT 'Admin who registered the face',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (registered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_is_active (is_active),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Face recognition entry logs (normalized)
-- Tracks all face-based gate entries separately from RFID violations
CREATE TABLE IF NOT EXISTS face_entry_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    confidence_score FLOAT NOT NULL COMMENT 'Match confidence 0.0-1.0',
    match_threshold FLOAT NOT NULL COMMENT 'Threshold used for this match',
    gate_location VARCHAR(100) DEFAULT NULL COMMENT 'Which security gate',
    security_guard_id INT DEFAULT NULL COMMENT 'Guard on duty',
    entry_type ENUM('face_match', 'face_violation', 'face_denied') NOT NULL DEFAULT 'face_match',
    snapshot_path VARCHAR(255) DEFAULT NULL COMMENT 'Optional snapshot for audit',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (security_guard_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_entry_type (entry_type),
    INDEX idx_confidence (confidence_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Face registration audit trail
-- Tracks when faces are registered/deactivated
CREATE TABLE IF NOT EXISTS face_registration_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    action ENUM('registered', 'deactivated', 'reactivated', 'deleted') NOT NULL,
    descriptor_count INT DEFAULT 0 COMMENT 'Number of descriptors affected',
    performed_by INT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add face_registered column to users table for backward compatibility
-- (same pattern as rfid_uid denormalization)
ALTER TABLE users 
    ADD COLUMN IF NOT EXISTS face_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER rfid_registered_at,
    ADD COLUMN IF NOT EXISTS face_registered_at TIMESTAMP NULL DEFAULT NULL AFTER face_registered;

-- Index for face recognition queries
CREATE INDEX IF NOT EXISTS idx_users_face_lookup ON users(face_registered, role, status);
