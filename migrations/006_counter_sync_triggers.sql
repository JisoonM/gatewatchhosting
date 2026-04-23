-- =====================================================================
-- Migration 006: Counter Sync Triggers
-- Date: 2026-03-25
-- Description: Adds trigger-based synchronization for
--              active_violations_count on the student_violations table.
--              The existing violation_count trigger on the violations
--              table is preserved for backward compatibility.
--
--              This ensures active_violations_count stays accurate
--              without relying on manual counter writes in PHP.
-- =====================================================================

-- -------------------------------------------------------
-- 1. Trigger: after INSERT on student_violations → recalc count
-- -------------------------------------------------------
DROP TRIGGER IF EXISTS after_student_violation_insert;

DELIMITER $$
CREATE TRIGGER after_student_violation_insert
AFTER INSERT ON student_violations
FOR EACH ROW
BEGIN
    UPDATE users
    SET active_violations_count = (
        SELECT COUNT(*)
        FROM student_violations
        WHERE user_id = NEW.user_id AND status = 'active'
    )
    WHERE id = NEW.user_id;
END$$
DELIMITER ;

-- -------------------------------------------------------
-- 2. Trigger: after UPDATE on student_violations → recalc count
--    (fires when status changes, e.g., active → apprehended)
-- -------------------------------------------------------
DROP TRIGGER IF EXISTS after_student_violation_update;

DELIMITER $$
CREATE TRIGGER after_student_violation_update
AFTER UPDATE ON student_violations
FOR EACH ROW
BEGIN
    IF NEW.status != OLD.status THEN
        UPDATE users
        SET active_violations_count = (
            SELECT COUNT(*)
            FROM student_violations
            WHERE user_id = NEW.user_id AND status = 'active'
        )
        WHERE id = NEW.user_id;
    END IF;
END$$
DELIMITER ;

-- -------------------------------------------------------
-- 3. Trigger: after DELETE on student_violations → recalc count
--    (safety net — records should not be deleted, but if they are)
-- -------------------------------------------------------
DROP TRIGGER IF EXISTS after_student_violation_delete;

DELIMITER $$
CREATE TRIGGER after_student_violation_delete
AFTER DELETE ON student_violations
FOR EACH ROW
BEGIN
    UPDATE users
    SET active_violations_count = (
        SELECT COUNT(*)
        FROM student_violations
        WHERE user_id = OLD.user_id AND status = 'active'
    )
    WHERE id = OLD.user_id;
END$$
DELIMITER ;

-- -------------------------------------------------------
-- 4. One-time sync to fix any existing drift
-- -------------------------------------------------------
UPDATE users u
LEFT JOIN (
    SELECT user_id, COUNT(*) as cnt
    FROM student_violations
    WHERE status = 'active'
    GROUP BY user_id
) sv ON u.id = sv.user_id
SET u.active_violations_count = COALESCE(sv.cnt, 0)
WHERE u.role = 'Student';

-- Register migration
INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('006_counter_sync_triggers', CURRENT_USER());
