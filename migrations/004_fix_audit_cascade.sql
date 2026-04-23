-- =====================================================================
-- Migration 004: Fix Audit Table CASCADE to RESTRICT
-- Date: 2026-03-25
-- Description: Audit/log tables should use ON DELETE RESTRICT (not
--              CASCADE) so deleting a user cannot silently destroy
--              the audit trail. This migration changes the FK behavior
--              for audit-related tables.
--
-- IMPORTANT: This is a BREAKING change if you currently rely on
--            deleting users and having their audit rows auto-deleted.
--            After this migration, you must soft-delete users or
--            explicitly handle audit preservation before user deletion.
-- =====================================================================

-- -------------------------------------------------------
-- 1. face_entry_logs: user_id should RESTRICT (not CASCADE)
-- -------------------------------------------------------
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'face_entry_logs'
    AND CONSTRAINT_NAME = 'face_entry_logs_ibfk_1');
SET @sql = IF(@fk IS NOT NULL,
    'ALTER TABLE face_entry_logs DROP FOREIGN KEY face_entry_logs_ibfk_1',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE face_entry_logs
  ADD CONSTRAINT fk_face_entry_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT;

-- -------------------------------------------------------
-- 2. face_registration_log: user_id should RESTRICT (not CASCADE)
-- -------------------------------------------------------
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'face_registration_log'
    AND CONSTRAINT_NAME = 'face_registration_log_ibfk_1');
SET @sql = IF(@fk IS NOT NULL,
    'ALTER TABLE face_registration_log DROP FOREIGN KEY face_registration_log_ibfk_1',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE face_registration_log
  ADD CONSTRAINT fk_face_reg_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT;

-- -------------------------------------------------------
-- 3. rfid_status_history: user_id should RESTRICT (not CASCADE)
-- -------------------------------------------------------
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rfid_status_history'
    AND CONSTRAINT_NAME = 'rfid_status_history_ibfk_2');
SET @sql = IF(@fk IS NOT NULL,
    'ALTER TABLE rfid_status_history DROP FOREIGN KEY rfid_status_history_ibfk_2',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE rfid_status_history
  ADD CONSTRAINT fk_rfid_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT;

-- -------------------------------------------------------
-- 4. notification_logs: student_id should RESTRICT (not CASCADE)
-- -------------------------------------------------------
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_logs'
    AND CONSTRAINT_NAME = 'notification_logs_ibfk_1');
SET @sql = IF(@fk IS NOT NULL,
    'ALTER TABLE notification_logs DROP FOREIGN KEY notification_logs_ibfk_1',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE notification_logs
  ADD CONSTRAINT fk_notification_logs_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT;

-- Register migration
INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('004_fix_audit_cascade', CURRENT_USER());
