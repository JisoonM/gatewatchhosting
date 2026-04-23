-- Add google_id column to users table for Google Sign-In integration
ALTER TABLE users 
ADD COLUMN google_id VARCHAR(255) DEFAULT NULL AFTER email,
ADD UNIQUE KEY unique_google_id (google_id);

-- Add index for faster Google ID lookups
CREATE INDEX idx_google_id ON users(google_id);
