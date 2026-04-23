-- =====================================================================
-- Migration 011: Harden RFID UID Uniqueness and Canonicalization
-- Date: 2026-04-13
-- Description:
--   1) Canonicalizes stored RFID UIDs (remove whitespace, uppercase)
--   2) Fails fast if duplicates remain after canonicalization
--   3) Enforces unique indexes on users.rfid_uid and rfid_cards.rfid_uid
-- =====================================================================

SET @users_table_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
);

SET @rfid_cards_table_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rfid_cards'
);

SET @users_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'uk_users_rfid_uid_hardened'
);

SET @cards_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rfid_cards'
      AND INDEX_NAME = 'uk_rfid_cards_uid_hardened'
);

SET @sql_users_normalize := IF(
    @users_table_exists = 1,
    "UPDATE users SET rfid_uid = NULLIF(UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(rfid_uid), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), CHAR(160), '')), '') WHERE rfid_uid IS NOT NULL",
    'SELECT 1'
);
PREPARE stmt_users_normalize FROM @sql_users_normalize;
EXECUTE stmt_users_normalize;
DEALLOCATE PREPARE stmt_users_normalize;

SET @sql_cards_normalize := IF(
    @rfid_cards_table_exists = 1,
    "UPDATE rfid_cards SET rfid_uid = UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(rfid_uid), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), CHAR(160), '')) WHERE rfid_uid IS NOT NULL",
    'SELECT 1'
);
PREPARE stmt_cards_normalize FROM @sql_cards_normalize;
EXECUTE stmt_cards_normalize;
DEALLOCATE PREPARE stmt_cards_normalize;

SET @dup_users := IF(
    @users_table_exists = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT rfid_uid
            FROM users
            WHERE rfid_uid IS NOT NULL
            GROUP BY rfid_uid
            HAVING COUNT(*) > 1
        ) d
    ),
    0
);

SET @dup_cards := IF(
    @rfid_cards_table_exists = 1,
    (
        SELECT COUNT(*)
        FROM (
            SELECT rfid_uid
            FROM rfid_cards
            WHERE rfid_uid IS NOT NULL
            GROUP BY rfid_uid
            HAVING COUNT(*) > 1
        ) d
    ),
    0
);

SELECT IF(@dup_users = 0, 1, 1 / 0);
SELECT IF(@dup_cards = 0, 1, 1 / 0);

SET @sql_users_idx := IF(
    @users_table_exists = 1 AND @users_idx_exists = 0,
    'ALTER TABLE users ADD UNIQUE KEY uk_users_rfid_uid_hardened (rfid_uid)',
    'SELECT 1'
);
PREPARE stmt_users_idx FROM @sql_users_idx;
EXECUTE stmt_users_idx;
DEALLOCATE PREPARE stmt_users_idx;

SET @sql_cards_idx := IF(
    @rfid_cards_table_exists = 1 AND @cards_idx_exists = 0,
    'ALTER TABLE rfid_cards ADD UNIQUE KEY uk_rfid_cards_uid_hardened (rfid_uid)',
    'SELECT 1'
);
PREPARE stmt_cards_idx FROM @sql_cards_idx;
EXECUTE stmt_cards_idx;
DEALLOCATE PREPARE stmt_cards_idx;

INSERT IGNORE INTO schema_migrations (migration_name, applied_by)
VALUES ('011_harden_rfid_uid_uniqueness', CURRENT_USER());
