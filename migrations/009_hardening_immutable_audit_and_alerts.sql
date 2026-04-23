-- =====================================================================
-- Migration 009: Hardening - Immutable Audit + Centralized Security Alerts
-- Date: 2026-04-06
-- =====================================================================

CREATE TABLE IF NOT EXISTS security_alert_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_type VARCHAR(50) NOT NULL,
    action_key VARCHAR(120) NOT NULL,
    identifier VARCHAR(191) NOT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    threshold INT UNSIGNED NOT NULL DEFAULT 0,
    blocked_until DATETIME DEFAULT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    context_json LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_security_alert_created (created_at),
    KEY idx_security_alert_type (alert_type, severity, created_at),
    KEY idx_security_alert_action (action_key, created_at),
    KEY idx_security_alert_identifier (identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_audit_logs_block_update;
CREATE TRIGGER trg_audit_logs_block_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is immutable: updates are not allowed';

DROP TRIGGER IF EXISTS trg_audit_logs_block_delete;
CREATE TRIGGER trg_audit_logs_block_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is immutable: deletes are not allowed';

DROP TRIGGER IF EXISTS trg_permission_audit_log_block_update;
CREATE TRIGGER trg_permission_audit_log_block_update
BEFORE UPDATE ON permission_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'permission_audit_log is immutable: updates are not allowed';

DROP TRIGGER IF EXISTS trg_permission_audit_log_block_delete;
CREATE TRIGGER trg_permission_audit_log_block_delete
BEFORE DELETE ON permission_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'permission_audit_log is immutable: deletes are not allowed';

INSERT INTO system_settings (setting_key, value, description)
VALUES
('audit_immutable_enabled', '1', 'Enable immutable audit chain protections'),
('session_isolation_on_privilege_change', '1', 'Rotate/revoke sessions after privilege changes'),
('ratelimit_policy_mode', 'centralized', 'Rate-limit policy mode: legacy or centralized')
ON DUPLICATE KEY UPDATE
    value = VALUES(value),
    description = VALUES(description);

INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('009_hardening_immutable_audit_and_alerts', CURRENT_USER());
