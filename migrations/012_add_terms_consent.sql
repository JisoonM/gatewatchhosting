-- =====================================================================
-- Migration 012: Add Terms & Conditions consent columns
-- Date: 2026-04-17
-- Description: Stores GateWatch Terms acceptance timestamp/version on users.
--              Non-breaking: columns are nullable and added only if missing.
-- =====================================================================

-- terms_accepted_at
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'terms_accepted_at');
SET @sql = IF(@col = 0,
    'ALTER TABLE users ADD COLUMN terms_accepted_at DATETIME NULL DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- terms_version
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'terms_version');
SET @sql = IF(@col = 0,
    'ALTER TABLE users ADD COLUMN terms_version VARCHAR(32) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Helpful index for audits/queries
CREATE INDEX IF NOT EXISTS idx_users_terms_accepted ON users (terms_accepted_at);

-- Register migration
INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('012_add_terms_consent', CURRENT_USER());
