-- =====================================================================
-- Migration 003: Add Missing Foreign Keys and Composite Indexes
-- Date: 2026-03-25
-- Description: Adds FKs that were missing from the original schema and
--              composite indexes for common query patterns found in the
--              PHP codebase (gate scan, violations, dashboard, etc.).
-- =====================================================================

-- -------------------------------------------------------
-- 1. Missing FKs on student_violations
--    (resolved_by and recorded_by reference users.id but had no FK
--     in the admin/homepage.php runtime CREATE TABLE)
-- -------------------------------------------------------

-- Check if the FK already exists before adding (idempotent)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'student_violations'
    AND CONSTRAINT_NAME = 'fk_sv_resolved_by');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE student_violations ADD CONSTRAINT fk_sv_resolved_by FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'student_violations'
    AND CONSTRAINT_NAME = 'fk_sv_recorded_by');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE student_violations ADD CONSTRAINT fk_sv_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FK from student_violations.category_id → violation_categories.id  (RESTRICT, not CASCADE)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'student_violations'
    AND CONSTRAINT_NAME = 'fk_sv_category');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE student_violations ADD CONSTRAINT fk_sv_category FOREIGN KEY (category_id) REFERENCES violation_categories(id) ON DELETE RESTRICT',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------
-- 2. Composite indexes for common query patterns
-- -------------------------------------------------------

-- Gate scan: lookup user by rfid_uid where role=Student and status=Active
-- (already exists as idx_users_rfid_lookup — verified)

-- Violations dashboard: filter by user + status + created_at
CREATE INDEX IF NOT EXISTS idx_sv_user_status_date ON student_violations (user_id, status, created_at);

-- Notification queue processing: pending items ordered by scheduled time
CREATE INDEX IF NOT EXISTS idx_nq_status_scheduled ON notification_queue (status, scheduled_for, retry_count);

-- Face entry log: lookup by user + date range
CREATE INDEX IF NOT EXISTS idx_fel_user_created ON face_entry_logs (user_id, created_at);

-- QR entry log: lookup by user + date range
CREATE INDEX IF NOT EXISTS idx_qel_user_scanned ON qr_entry_logs (user_id, scanned_at);

-- Audit logs: admin actions by date
CREATE INDEX IF NOT EXISTS idx_al_admin_created ON audit_logs (admin_id, created_at);

-- RFID status history: card + date for timeline
CREATE INDEX IF NOT EXISTS idx_rsh_card_date ON rfid_status_history (rfid_card_id, changed_at);

-- Users: role + status combo (used in nearly every admin query)
CREATE INDEX IF NOT EXISTS idx_users_role_status ON users (role, status);

-- Register migration
INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('003_add_missing_fks_indexes', CURRENT_USER());
