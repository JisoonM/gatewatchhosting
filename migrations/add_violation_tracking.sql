-- Add violation_count column to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS violation_count INT NOT NULL DEFAULT 0;

-- Create violations tracking table
CREATE TABLE IF NOT EXISTS violations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    rfid_uid VARCHAR(50) NOT NULL,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_scanned_at (scanned_at)
);

-- Add violation tracking for existing violations table
-- This will help track when students scan their cards multiple times
