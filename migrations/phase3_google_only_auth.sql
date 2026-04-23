-- migrations/phase3_google_only_auth.sql
-- PHASE 3: Google-Only Authentication System (Fully Normalized - 3NF/BCNF)
-- This migration adds tables for authentication provider tracking following strict normalization principles

-- 1. Authentication providers table (Master list of auth providers)
CREATE TABLE IF NOT EXISTS auth_providers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider_name VARCHAR(50) NOT NULL UNIQUE, -- 'google', 'facebook', 'manual', etc.
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_primary TINYINT(1) NOT NULL DEFAULT 0, -- Only one primary provider allowed
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enabled (is_enabled),
    INDEX idx_primary (is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default auth providers
INSERT INTO auth_providers (provider_name, is_enabled, is_primary) VALUES
('google', 1, 1),
('manual', 0, 0) -- Disable manual signup (password-based)
ON DUPLICATE KEY UPDATE provider_name = provider_name;

-- 2. User authentication methods table (Which auth methods each user has)
-- Normalized: Allows users to have multiple auth methods (e.g., Google + manual password)
CREATE TABLE IF NOT EXISTS user_auth_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider_id INT NOT NULL,
    provider_user_id VARCHAR(255) NULL, -- External ID from provider (e.g., Google ID)
    is_primary_method TINYINT(1) NOT NULL DEFAULT 0, -- User's preferred login method
    first_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    use_count INT NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES auth_providers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_provider (user_id, provider_id),
    INDEX idx_user (user_id),
    INDEX idx_provider (provider_id),
    INDEX idx_provider_user_id (provider_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Authentication audit log (Security tracking of all auth attempts)
-- Tracks every login attempt for security monitoring
CREATE TABLE IF NOT EXISTS auth_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL, -- NULL for failed login attempts
    provider_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    action ENUM('login_success', 'login_failed', 'logout', 'signup', 'link_account') NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (provider_id) REFERENCES auth_providers(id) ON DELETE CASCADE,
    INDEX idx_user_action (user_id, action),
    INDEX idx_created_at (created_at),
    INDEX idx_ip_address (ip_address),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing users to have 'google' auth method if they have google_id
-- This is for backward compatibility
INSERT INTO user_auth_methods (user_id, provider_id, provider_user_id, is_primary_method, first_used_at, last_used_at)
SELECT 
    u.id,
    (SELECT id FROM auth_providers WHERE provider_name = 'google'),
    u.google_id,
    1, -- Make Google primary
    u.created_at,
    u.updated_at
FROM users u
WHERE u.google_id IS NOT NULL
ON DUPLICATE KEY UPDATE last_used_at = VALUES(last_used_at);

-- Migrate existing users WITHOUT google_id to have 'manual' auth method (legacy password users)
-- This allows them to continue logging in with password until they link Google account
INSERT INTO user_auth_methods (user_id, provider_id, provider_user_id, is_primary_method, first_used_at)
SELECT 
    u.id,
    (SELECT id FROM auth_providers WHERE provider_name = 'manual'),
    NULL, -- No external ID for manual auth
    1, -- Make manual primary for now
    u.created_at
FROM users u
WHERE u.google_id IS NULL
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);

-- Verification: Show created tables and migration results
SELECT 'Phase 3 Migration Complete' AS status,
       'Created tables: auth_providers, user_auth_methods, auth_audit_log' AS message;

SELECT 
    'Migration Statistics' AS summary,
    (SELECT COUNT(*) FROM user_auth_methods WHERE provider_id = (SELECT id FROM auth_providers WHERE provider_name = 'google')) AS google_users,
    (SELECT COUNT(*) FROM user_auth_methods WHERE provider_id = (SELECT id FROM auth_providers WHERE provider_name = 'manual')) AS manual_users,
    (SELECT COUNT(*) FROM users) AS total_users;
