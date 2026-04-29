-- =====================================================================
-- Migration 013: Student safety fields, archival metadata, and QR identity hardening
-- Date: 2026-04-25
-- Description:
--   - Adds DOB/age/guardian fields to users (student records)
--   - Adds archival metadata for soft-archive workflow
--   - Adds QR identity token for compact non-guessable QR payloads
--   - Creates consent_logs table for registration consent evidence
-- =====================================================================

-- users.dob
SET @col = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'dob'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE users ADD COLUMN dob DATE NULL DEFAULT NULL AFTER student_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.computed_age
SET @col = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'computed_age'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE users ADD COLUMN computed_age TINYINT UNSIGNED NULL DEFAULT NULL AFTER dob',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.guardian_name
SET @col = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'guardian_name'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE users ADD COLUMN guardian_name VARCHAR(150) NULL DEFAULT NULL AFTER computed_age',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.guardian_contact
SET @col = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'guardian_contact'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE users ADD COLUMN guardian_contact VARCHAR(20) NULL DEFAULT NULL AFTER guardian_name',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.guardian_email
SET @col = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'guardian_email'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE users ADD COLUMN guardian_email VARCHAR(150) NULL DEFAULT NULL AFTER guardian_contact',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.is_archived
SET @col = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'is_archived'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE users ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER deleted_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.archived_at
SET @col = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'archived_at'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE users ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER is_archived',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.archived_by
SET @col = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'archived_by'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE users ADD COLUMN archived_by INT NULL DEFAULT NULL AFTER archived_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.dob_review_required
SET @col = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'dob_review_required'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE users ADD COLUMN dob_review_required TINYINT(1) NOT NULL DEFAULT 0 AFTER archived_by',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.qr_identity_token
SET @col = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'qr_identity_token'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE users ADD COLUMN qr_identity_token CHAR(36) NULL DEFAULT NULL AFTER dob_review_required',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS consent_logs (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    dob DATE NOT NULL,
    age_at_registration TINYINT UNSIGNED NOT NULL,
    parental_consent_required TINYINT(1) NOT NULL DEFAULT 0,
    terms_version VARCHAR(20) NOT NULL DEFAULT 'v1.0',
    accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_consent_logs_user_id (user_id),
    KEY idx_consent_logs_student_id (student_id),
    KEY idx_consent_logs_accepted_at (accepted_at),
    CONSTRAINT fk_consent_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_users_is_archived ON users (is_archived);
CREATE INDEX IF NOT EXISTS idx_users_course_archived ON users (course, is_archived);
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_qr_identity_token ON users (qr_identity_token);

-- Backfill computed_age for existing students with DOB, if any.
UPDATE users
SET computed_age = TIMESTAMPDIFF(YEAR, dob, CURDATE())
WHERE dob IS NOT NULL;

-- Register migration
INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('013_student_safety_archive_and_qr_hardening', CURRENT_USER());
