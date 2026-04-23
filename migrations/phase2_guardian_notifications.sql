-- migrations/phase2_guardian_notifications.sql
-- PHASE 2: Guardian Notifications System (Fully Normalized - 3NF/BCNF)
-- This migration adds tables for guardian notifications following strict normalization principles

-- 1. Guardians table (Parent/Guardian information)
CREATE TABLE IF NOT EXISTS guardians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone_number VARCHAR(20) NULL,
    relationship ENUM('Mother', 'Father', 'Guardian', 'Other') NOT NULL DEFAULT 'Guardian',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Student-Guardian relationship table (Many-to-Many)
-- Allows one student to have multiple guardians and one guardian to have multiple students
CREATE TABLE IF NOT EXISTS student_guardians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    guardian_id INT NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 1, -- Primary contact for notifications
    relationship_notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (guardian_id) REFERENCES guardians(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_guardian (student_id, guardian_id),
    INDEX idx_student (student_id),
    INDEX idx_guardian (guardian_id),
    INDEX idx_primary (is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Notification settings table (Per-guardian preferences)
-- Normalized: Each guardian has their own notification preferences
CREATE TABLE IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guardian_id INT NOT NULL UNIQUE,
    entry_notification TINYINT(1) NOT NULL DEFAULT 1, -- Send email on campus entry
    exit_notification TINYINT(1) NOT NULL DEFAULT 0, -- Future: Send email on campus exit
    violation_notification TINYINT(1) NOT NULL DEFAULT 1, -- Send email on violations
    daily_summary TINYINT(1) NOT NULL DEFAULT 0, -- Future: Daily summary email
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (guardian_id) REFERENCES guardians(id) ON DELETE CASCADE,
    INDEX idx_entry_enabled (entry_notification)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Notification logs table (Audit trail of all sent notifications)
-- Tracks every notification sent for rate limiting and audit purposes
CREATE TABLE IF NOT EXISTS notification_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    guardian_id INT NOT NULL,
    notification_type ENUM('entry', 'exit', 'violation', 'daily_summary') NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('sent', 'failed', 'queued') NOT NULL DEFAULT 'sent',
    error_message TEXT NULL,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (guardian_id) REFERENCES guardians(id) ON DELETE CASCADE,
    INDEX idx_student_type_sent (student_id, notification_type, sent_at),
    INDEX idx_guardian_sent (guardian_id, sent_at),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Notification queue table (For async email sending)
-- Stores pending notifications that haven't been sent yet
CREATE TABLE IF NOT EXISTS notification_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    guardian_id INT NOT NULL,
    notification_type ENUM('entry', 'exit', 'violation', 'daily_summary') NOT NULL,
    scheduled_for TIMESTAMP NOT NULL,
    sent_at TIMESTAMP NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    retry_count INT NOT NULL DEFAULT 0,
    data JSON NULL, -- Flexible storage for notification data
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (guardian_id) REFERENCES guardians(id) ON DELETE CASCADE,
    INDEX idx_status_scheduled (status, scheduled_for),
    INDEX idx_student (student_id),
    INDEX idx_guardian (guardian_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. System settings table (Global notification settings)
-- Allows admin to enable/disable notifications globally
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

-- Insert default system setting for guardian notifications
INSERT INTO system_settings (setting_key, value, description) 
VALUES ('guardian_notifications_enabled', '1', 'Enable/disable guardian entry notifications globally')
ON DUPLICATE KEY UPDATE value = value;

-- Verification: Show created tables
SELECT 'Phase 2 Migration Complete' AS status,
       'Created tables: guardians, student_guardians, notification_settings, notification_logs, notification_queue, system_settings' AS message;
