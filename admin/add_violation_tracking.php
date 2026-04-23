<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script can only be run from CLI.\n");
}

require_once __DIR__ . '/../db.php';

try {
    $pdo = pdo();
    
    echo "Starting violation tracking migration...\n\n";
    
    // Check and add violation_count column to users table
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS 
                           WHERE TABLE_SCHEMA = DATABASE() 
                           AND TABLE_NAME = 'users' 
                           AND COLUMN_NAME = 'violation_count'");
    $stmt->execute();
    
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN violation_count INT NOT NULL DEFAULT 0");
        echo "✓ Added violation_count column to users table\n";
    } else {
        echo "• violation_count column already exists in users table\n";
    }
    
    // Create violations table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS violations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            rfid_uid VARCHAR(50) NOT NULL,
            scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_scanned_at (scanned_at)
        )
    ");
    
    // Check if violations table was created or already exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'violations'");
    if ($stmt->fetch()) {
        echo "✓ Violations table is ready\n";
    }
    
    echo "\n✓ Migration completed successfully!\n";
    echo "\nNotes:\n";
    echo "- Students will accumulate violations when their RFID cards are scanned\n";
    echo "- Maximum violation limit is set to 3 strikes\n";
    echo "- Admins can clear violations from the Notifications section\n";
    
} catch (\PDOException $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
