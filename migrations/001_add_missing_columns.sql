-- =====================================================================
-- Migration 001: Add Missing Columns to Users Table
-- Date: 2026-03-25
-- Description: Adds columns that currently only exist via runtime DDL
--              in admin/homepage.php, security/gate_scan.php,
--              api/log_face_entry.php, and admin/clear_violation.php.
--              All additions are non-breaking (nullable or have defaults).
-- =====================================================================

-- gate_mark_count: tracks how many times a student was flagged at the gate
-- Currently added at runtime in security/gate_scan.php:105 and api/log_face_entry.php:136
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'gate_mark_count');
SET @sql = IF(@col = 0,
    'ALTER TABLE users ADD COLUMN gate_mark_count INT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- active_violations_count: count of active (unresolved) student_violations
-- Currently added at runtime in admin/homepage.php:118
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'active_violations_count');
SET @sql = IF(@col = 0,
    'ALTER TABLE users ADD COLUMN active_violations_count INT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- locked_until: account lockout expiry timestamp
-- Referenced in auth flows for progressive lockout
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'locked_until');
SET @sql = IF(@col = 0,
    'ALTER TABLE users ADD COLUMN locked_until DATETIME DEFAULT NULL AFTER status',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add CHECK constraints for new columns
-- (safe to run even if they already exist — MariaDB silently ignores duplicate named constraints)
ALTER TABLE users
  ADD CONSTRAINT chk_users_gate_mark_count CHECK (gate_mark_count >= 0);

ALTER TABLE users
  ADD CONSTRAINT chk_users_active_violations CHECK (active_violations_count >= 0);

-- Add index for gate_mark_count queries (used in gate_scan and violation clearing)
CREATE INDEX IF NOT EXISTS idx_users_gate_mark ON users (gate_mark_count);

-- Register migration
INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('001_add_missing_columns', CURRENT_USER());
