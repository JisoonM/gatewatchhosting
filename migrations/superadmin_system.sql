-- ============================================
-- Super Admin System - Database Migration
-- ============================================
-- This migration creates tables for the Super Admin feature
-- which allows management of admin accounts
-- ============================================

USE pcu_rfid2;

-- ============================================
-- SUPER ADMIN TABLES
-- ============================================

-- Super Admins table - Stores super admin credentials (separate from regular users)
CREATE TABLE IF NOT EXISTS super_admins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    last_login DATETIME DEFAULT NULL,
    login_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin Accounts table - Manages admin personnel accounts with audit trail
CREATE TABLE IF NOT EXISTS admin_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    created_by INT NOT NULL COMMENT 'Super Admin who created this admin',
    status ENUM('Active', 'Inactive', 'Suspended') NOT NULL DEFAULT 'Active',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES super_admins(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_user (user_id),
    INDEX idx_user_id (user_id),
    INDEX idx_created_by (created_by),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Super Admin Audit Log table - Tracks all super admin actions for security
CREATE TABLE IF NOT EXISTS superadmin_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    super_admin_id INT NOT NULL,
    action ENUM('LOGIN', 'LOGOUT', 'CREATE_ADMIN', 'UPDATE_ADMIN', 'DELETE_ADMIN', 'SUSPEND_ADMIN', 'ACTIVATE_ADMIN') NOT NULL,
    target_admin_id INT DEFAULT NULL COMMENT 'The admin account affected',
    details TEXT DEFAULT NULL COMMENT 'JSON details of the action',
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (super_admin_id) REFERENCES super_admins(id) ON DELETE CASCADE,
    INDEX idx_super_admin_id (super_admin_id),
    INDEX idx_action (action),
    INDEX idx_target_admin (target_admin_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INSERT DEFAULT SUPER ADMIN
-- ============================================

-- Insert the default super admin with the specified credentials
-- Password: ./@superAdmin (hashed with bcrypt)
INSERT INTO super_admins (username, email, password, status) VALUES
('Super Admin', 'jeysidelima04@gmail.com', '$2y$10$YourHashedPasswordHere', 'Active')
ON DUPLICATE KEY UPDATE username = VALUES(username);

-- ============================================
-- END OF MIGRATION
-- ============================================
