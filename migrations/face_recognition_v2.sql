-- ============================================
-- FACE RECOGNITION V2 - Performance & Security Upgrade
-- Migration: Add descriptor versioning, dimension tracking, quality metadata
-- Prerequisite: add_face_recognition.sql must have been applied
-- ============================================

-- 1. Add version column for incremental sync
-- Each insert/update/soft-delete bumps the version to enable delta queries
ALTER TABLE face_descriptors
    ADD COLUMN IF NOT EXISTS version BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Monotonic version for incremental sync';

ALTER TABLE face_descriptors
    ADD INDEX IF NOT EXISTS idx_version (version);

-- 2. Add descriptor dimension tracking for future model upgrades
ALTER TABLE face_descriptors
    ADD COLUMN IF NOT EXISTS descriptor_dimension SMALLINT UNSIGNED NOT NULL DEFAULT 128
        COMMENT 'Dimensionality of the face embedding vector';

-- 3. Add quality_checks JSON field for detailed quality metrics from enrollment
ALTER TABLE face_descriptors
    ADD COLUMN IF NOT EXISTS quality_checks JSON DEFAULT NULL
        COMMENT 'Detailed quality metrics: sharpness, brightness, centering, face_size, angle';

-- 4. Bootstrap existing rows: set version = id so they all have unique versions
UPDATE face_descriptors SET version = id WHERE version = 0;

-- 5. Add query_descriptor_hash to face_entry_logs for forensic audit
ALTER TABLE face_entry_logs
    ADD COLUMN IF NOT EXISTS query_descriptor_hash VARCHAR(64) DEFAULT NULL
        COMMENT 'SHA-256 hash of the query descriptor used for matching';

ALTER TABLE face_entry_logs
    ADD COLUMN IF NOT EXISTS server_verified TINYINT(1) DEFAULT NULL
        COMMENT 'Whether the match was verified server-side (NULL=legacy, 1=pass, 0=fail)';

-- 6. Add enrollment_wave for tracking re-enrollment campaigns
ALTER TABLE face_descriptors
    ADD COLUMN IF NOT EXISTS enrollment_wave INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Re-enrollment campaign number';

-- 7. Global version counter table for atomic version generation
CREATE TABLE IF NOT EXISTS face_descriptor_version_counter (
    id INT PRIMARY KEY DEFAULT 1,
    current_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT single_row CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize the counter from the max existing version
INSERT INTO face_descriptor_version_counter (id, current_version)
SELECT 1, COALESCE(MAX(version), 0) FROM face_descriptors
ON DUPLICATE KEY UPDATE current_version = GREATEST(current_version, (SELECT COALESCE(MAX(version), 0) FROM face_descriptors));
