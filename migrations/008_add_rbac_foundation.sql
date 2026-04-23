-- =====================================================================
-- Migration 008: RBAC Foundation (Additive, Non-Breaking)
-- Date: 2026-04-06
-- Description:
--   Adds baseline RBAC tables, permission audit trail, and default
--   feature flags for conservative rollout. This migration is additive
--   and preserves existing role/session logic.
-- =====================================================================

-- -------------------------------------------------------
-- 0. Ensure system_settings exists for feature flags
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    value TEXT NOT NULL,
    description VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 1. RBAC core tables
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_key VARCHAR(64) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_role_key (role_key),
    KEY idx_roles_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    enforce_tier TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '1=critical,2=high,3=medium',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_permission_key (permission_key),
    KEY idx_permissions_tier_active (enforce_tier, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    is_allowed TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_role_permission (role_id, permission_id),
    KEY idx_role_permissions_permission (permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    assigned_by INT(11) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_roles_user (user_id),
    KEY idx_user_roles_role (role_id),
    KEY idx_user_roles_assigned_by (assigned_by),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_roles_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permission_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    is_allowed TINYINT(1) NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    assigned_by INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user_permission_overrides_user (user_id),
    KEY idx_user_permission_overrides_permission (permission_id),
    KEY idx_user_permission_overrides_expires (expires_at),
    KEY idx_user_permission_overrides_lookup (user_id, permission_id, expires_at),
    CONSTRAINT fk_user_permission_overrides_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permission_overrides_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permission_overrides_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permission_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_role_key VARCHAR(64) NOT NULL,
    actor_user_id INT(11) DEFAULT NULL,
    permission_key VARCHAR(100) NOT NULL,
    decision ENUM('allow','deny') NOT NULL,
    decision_source VARCHAR(50) NOT NULL COMMENT 'legacy|rbac|fallback|error|tier_not_enforced',
    rbac_mode VARCHAR(20) NOT NULL COMMENT 'legacy|dual|enforce',
    is_enforced TINYINT(1) NOT NULL DEFAULT 0,
    request_method VARCHAR(10) DEFAULT NULL,
    request_uri VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    details_json TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_permission_audit_created (created_at),
    KEY idx_permission_audit_actor (actor_role_key, actor_user_id, created_at),
    KEY idx_permission_audit_permission (permission_key, created_at),
    KEY idx_permission_audit_decision (decision, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 2. Seed baseline roles (idempotent)
-- -------------------------------------------------------
INSERT INTO roles (role_key, display_name, description, is_active) VALUES
('student', 'Student', 'Student self-service role', 1),
('admin', 'Admin', 'School administrator role', 1),
('security', 'Security', 'Security guard role', 1),
('superadmin', 'Super Admin', 'System super administrator role', 1)
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    description = VALUES(description),
    is_active = VALUES(is_active);

-- -------------------------------------------------------
-- 3. Seed baseline permissions (action-level, idempotent)
-- -------------------------------------------------------
INSERT INTO permissions (permission_key, description, enforce_tier, is_active) VALUES
('student.profile.view', 'View own student profile', 3, 1),
('student.profile.update', 'Update own student profile', 3, 1),
('student.violations.read_own', 'View own violations', 3, 1),
('student.digital_id.view', 'View own digital ID', 3, 1),
('student.verify', 'Approve or deny pending student account', 1, 1),
('student.update', 'Update student records', 1, 1),
('student.delete', 'Delete student account', 1, 1),
('rfid.register', 'Register RFID to student', 1, 1),
('rfid.unregister', 'Unregister RFID from student', 1, 1),
('rfid.mark_lost', 'Mark RFID as lost or found', 1, 1),
('face.register', 'Register face descriptors', 1, 1),
('face.delete', 'Delete face descriptors', 1, 1),
('face.verify', 'Verify face matching at gate', 2, 1),
('violation.record', 'Record student violation', 1, 1),
('violation.clear', 'Resolve or clear student violations', 1, 1),
('audit.read', 'Read audit logs', 2, 1),
('audit.export', 'Export audit logs', 1, 1),
('admin.create', 'Create admin account', 1, 1),
('admin.update', 'Update admin account', 1, 1),
('admin.delete', 'Delete admin account', 1, 1),
('qr.scan', 'Scan and validate QR code', 2, 1),
('gate.scan.rfid', 'Scan and validate RFID at gate', 2, 1)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    enforce_tier = VALUES(enforce_tier),
    is_active = VALUES(is_active);

-- -------------------------------------------------------
-- 4. Seed role-permission mappings (idempotent)
-- -------------------------------------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_allowed)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'student.profile.view',
    'student.profile.update',
    'student.violations.read_own',
    'student.digital_id.view'
)
WHERE r.role_key = 'student';

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_allowed)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'student.verify',
    'student.update',
    'student.delete',
    'rfid.register',
    'rfid.unregister',
    'rfid.mark_lost',
    'face.register',
    'face.delete',
    'violation.clear',
    'audit.read',
    'audit.export'
)
WHERE r.role_key = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_allowed)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'qr.scan',
    'gate.scan.rfid',
    'face.verify',
    'violation.record'
)
WHERE r.role_key = 'security';

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_allowed)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.is_active = 1
WHERE r.role_key = 'superadmin';

-- -------------------------------------------------------
-- 5. Backfill users -> user_roles from existing users.role enum
-- -------------------------------------------------------
INSERT INTO user_roles (user_id, role_id, assigned_by, note)
SELECT
    u.id,
    (
        SELECT r2.id
        FROM roles r2
        WHERE r2.role_key = CASE u.role
            WHEN 'Admin' THEN 'admin'
            WHEN 'Student' THEN 'student'
            ELSE ''
        END
        LIMIT 1
    ) AS role_id,
    NULL,
    'Backfilled from users.role enum'
FROM users u
WHERE u.role IN ('Admin', 'Student')
  AND (
        SELECT r2.id
        FROM roles r2
        WHERE r2.role_key = CASE u.role
            WHEN 'Admin' THEN 'admin'
            WHEN 'Student' THEN 'student'
            ELSE ''
        END
        LIMIT 1
      ) IS NOT NULL
ON DUPLICATE KEY UPDATE
    role_id = VALUES(role_id),
    note = VALUES(note);

-- -------------------------------------------------------
-- 6. Seed conservative rollout feature flags in system_settings
-- -------------------------------------------------------
INSERT INTO system_settings (setting_key, value, description)
VALUES
('rbac_mode', 'legacy', 'RBAC mode: legacy, dual, enforce'),
('rbac_enforce_tier', '0', 'RBAC enforcement tier: 0=none,1=critical,2=high,3=medium'),
('rbac_log_decisions', '1', 'Log RBAC authorization decisions'),
('rbac_fail_closed', '0', 'If 1, deny when RBAC storage is unavailable during enforce mode'),
('csrf_rotate_on_critical', '0', 'Rotate CSRF token after critical state-changing operations'),
('audit_immutable_enabled', '0', 'Enable immutable audit chain protections'),
('session_isolation_on_privilege_change', '0', 'Rotate/revoke sessions after privilege changes'),
('ratelimit_policy_mode', 'legacy', 'Rate-limit policy mode: legacy or centralized')
ON DUPLICATE KEY UPDATE
    value = value;

-- Register migration
INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('008_add_rbac_foundation', CURRENT_USER());
