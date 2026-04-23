-- Add RFID column to users table
ALTER TABLE users
ADD COLUMN rfid_uid VARCHAR(50) DEFAULT NULL,
ADD UNIQUE KEY unique_rfid (rfid_uid);

-- Add RFID registration timestamp
ALTER TABLE users
ADD COLUMN rfid_registered_at TIMESTAMP NULL DEFAULT NULL;