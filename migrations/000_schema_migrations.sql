-- =====================================================================
-- Migration 000: Schema Migrations Governance Table
-- Date: 2026-03-25
-- Description: Creates the schema_migrations ledger table to track
--              all applied migrations and prevent re-execution.
--              This must be run FIRST before any other numbered migration.
-- =====================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checksum CHAR(64) DEFAULT NULL COMMENT 'SHA-256 of migration file for drift detection',
    execution_time_ms INT UNSIGNED DEFAULT NULL COMMENT 'How long the migration took',
    applied_by VARCHAR(100) DEFAULT NULL COMMENT 'User or script that applied the migration',
    UNIQUE KEY uk_migration_name (migration_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Self-register this migration
INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('000_schema_migrations', 'initial_setup');
