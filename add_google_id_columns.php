<?php

use PDOException;

/**
 * Script to add google_id column to users table
 * Run this once to enable Google Sign-In functionality
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script can only be run from CLI.\n");
}

require_once 'db.php';

try {
    $pdo = pdo();
    
    // Check if google_id column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'google_id'");
    $column_exists = $stmt->fetch();
    
    if ($column_exists) {
        echo "✓ google_id column already exists in users table.\n";
    } else {
        echo "Adding google_id column to users table...\n";
        
        // Add google_id column
        $pdo->exec("
            ALTER TABLE users 
            ADD COLUMN google_id VARCHAR(255) DEFAULT NULL AFTER email,
            ADD UNIQUE KEY unique_google_id (google_id)
        ");
        
        echo "✓ Successfully added google_id column with unique constraint.\n";
    }
    
    echo "\nGoogle Sign-In is now ready to use!\n";
    
} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
