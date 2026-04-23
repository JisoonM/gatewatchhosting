-- =====================================================================
-- Migration 005: Add Soft-Delete Support
-- Date: 2026-03-25
-- Description: Adds deleted_at column to users table to support
--              soft-delete instead of hard-delete. This preserves FK
--              integrity and audit trail. Active queries should filter
--              WHERE deleted_at IS NULL.
-- =====================================================================

-- Add deleted_at column to users
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'deleted_at');
SET @sql = IF(@col = 0,
    'ALTER TABLE users ADD COLUMN deleted_at DATETIME DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for soft-delete filtering (most queries will add WHERE deleted_at IS NULL)
CREATE INDEX IF NOT EXISTS idx_users_deleted_at ON users (deleted_at);

-- Composite index: active students lookup (very common pattern)
CREATE INDEX IF NOT EXISTS idx_users_active_students ON users (role, status, deleted_at);

-- Register migration
INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('005_add_soft_delete', CURRENT_USER());
