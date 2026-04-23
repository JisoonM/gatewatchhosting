-- =====================================================================
-- Migration 007: Improve Views
-- Date: 2026-03-25
-- Description: Replaces the v_students_complete view which used
--              expensive correlated subqueries with a JOIN-based
--              approach. Output columns are preserved for backward
--              compatibility with existing PHP code.
-- =====================================================================

-- -------------------------------------------------------
-- 1. Replace v_students_complete with optimized version
--    Old: used 2 correlated subqueries (COUNT + MAX on violations)
--    New: uses a single LEFT JOIN on a pre-aggregated subquery
-- -------------------------------------------------------
DROP VIEW IF EXISTS v_students_complete;

CREATE SQL SECURITY INVOKER VIEW v_students_complete AS
SELECT
    u.id,
    u.student_id,
    u.name,
    u.email,
    u.role,
    u.status,
    u.created_at,
    u.last_login,
    u.profile_picture,
    u.profile_picture_uploaded_at,
    u.rfid_uid,
    u.rfid_registered_at,
    u.violation_count,
    sp.bio,
    sp.phone,
    sp.emergency_contact,
    sp.emergency_phone,
    COALESCE(va.total_violations, 0)  AS total_violations,
    va.last_violation_date
FROM users u
LEFT JOIN student_profiles sp ON u.id = sp.user_id
LEFT JOIN (
    SELECT
        user_id,
        COUNT(*)          AS total_violations,
        MAX(scanned_at)   AS last_violation_date
    FROM violations
    GROUP BY user_id
) va ON u.id = va.user_id
WHERE u.role = 'Student';

-- Register migration
INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('007_improve_views', CURRENT_USER());
