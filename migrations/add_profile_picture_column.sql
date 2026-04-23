-- Add profile_picture column to users table
ALTER TABLE users 
ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL AFTER email,
ADD COLUMN profile_picture_uploaded_at DATETIME DEFAULT NULL AFTER profile_picture;

-- Create index for faster queries
CREATE INDEX idx_profile_picture ON users(profile_picture);
