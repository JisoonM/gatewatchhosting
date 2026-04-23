-- ============================================
-- PHASE 1: Lost RFID Tracking (Normalized)
-- Migration: Add lost RFID functionality to existing rfid_cards table
-- Date: 2025-12-12
-- ============================================

-- BACKUP DATABASE BEFORE RUNNING THIS MIGRATION!
-- mysqldump -u root pcu_rfid2 > backup_before_lost_rfid_$(date +%Y%m%d).sql

USE pcu_rfid2;

-- Step 1: Add lost tracking columns to rfid_cards table
ALTER TABLE rfid_cards 
ADD COLUMN is_lost TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether RFID is marked as lost',
ADD COLUMN lost_at DATETIME NULL COMMENT 'When RFID was marked as lost',
ADD COLUMN lost_reason VARCHAR(255) NULL COMMENT 'Reason for marking as lost',
ADD COLUMN lost_reported_by INT NULL COMMENT 'Admin who marked it as lost',
ADD COLUMN found_at DATETIME NULL COMMENT 'When RFID was found/unmarked',
ADD COLUMN found_by INT NULL COMMENT 'Admin who unmarked it',
ADD INDEX idx_is_lost (is_lost),
ADD CONSTRAINT fk_rfid_lost_reported_by FOREIGN KEY (lost_reported_by) REFERENCES users(id) ON DELETE SET NULL,
ADD CONSTRAINT fk_rfid_found_by FOREIGN KEY (found_by) REFERENCES users(id) ON DELETE SET NULL;

-- Step 2: Create RFID status history table for full audit trail
CREATE TABLE IF NOT EXISTS rfid_status_history (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    rfid_card_id INT NOT NULL COMMENT 'FK to rfid_cards table',
    user_id INT NOT NULL COMMENT 'Student whose RFID this belongs to',
    status_change VARCHAR(50) NOT NULL COMMENT 'LOST, FOUND, ACTIVATED, DEACTIVATED',
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    changed_by INT NOT NULL COMMENT 'Admin who made the change',
    reason TEXT NULL COMMENT 'Reason for the status change',
    notes TEXT NULL COMMENT 'Additional notes',
    ip_address VARCHAR(45) NULL COMMENT 'IP address of admin who made change',
    
    FOREIGN KEY (rfid_card_id) REFERENCES rfid_cards(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_rfid_card_id (rfid_card_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status_change (status_change),
    INDEX idx_changed_at (changed_at),
    INDEX idx_changed_by (changed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail for all RFID card status changes';

-- Step 3: Verify migration success
SELECT 
    'rfid_cards columns added' AS verification,
    COUNT(*) AS count 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'pcu_rfid2' 
  AND TABLE_NAME = 'rfid_cards' 
  AND COLUMN_NAME IN ('is_lost', 'lost_at', 'lost_reason', 'lost_reported_by', 'found_at', 'found_by');

SELECT 
    'rfid_status_history table created' AS verification,
    COUNT(*) AS count 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'pcu_rfid2' 
  AND TABLE_NAME = 'rfid_status_history';

-- Migration complete!
-- Next steps:
-- 1. Update db.php with helper functions
-- 2. Modify security/gate_scan.php to check is_lost flag
-- 3. Create admin UI for marking RFID as lost/found
