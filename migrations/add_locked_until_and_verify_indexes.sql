-- ============================================================
-- Migration: Verify and add missing database indexes
-- Run this once on your local/staging database.
-- Safe to run multiple times (uses IF NOT EXISTS where supported).
-- ============================================================

-- Add locked_until column to users table for timed account lockouts (#4)
-- This is a new column; only add if it doesn't exist.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME = 'locked_until');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE users ADD COLUMN `locked_until` DATETIME NULL DEFAULT NULL AFTER `failed_attempts`', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Index verification queries (run SHOW INDEX first to check)
-- ============================================================

-- Users table indexes should already exist per database.sql:
--   UNIQUE KEY `email`
--   UNIQUE KEY `google_id`  
--   UNIQUE KEY `student_id`
--   KEY `idx_email`
--   KEY `idx_student_id`
-- These are confirmed present in the base schema.

-- rfid_cards: Verify indexes exist
-- SHOW INDEX FROM rfid_cards;

-- ip_rate_limits: Created automatically by check_rate_limit() with UNIQUE KEY uk_identifier
