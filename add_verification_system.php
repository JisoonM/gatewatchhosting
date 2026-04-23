<?php

use PDOException;
use Exception;

/**
 * Add account verification system to database
 * Run this once to enable admin verification for new accounts
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script can only be run from CLI.\n");
}

require_once 'db.php';

try {
    $pdo = pdo();
    
    echo "Setting up account verification system...\n\n";
    
    // 1. Check if verification_status column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'verification_status'");
    $column_exists = $stmt->fetch();
    
    if (!$column_exists) {
        echo "Adding verification_status column...\n";
        $pdo->exec("
            ALTER TABLE users 
            ADD COLUMN verification_status ENUM('pending', 'approved', 'denied') DEFAULT 'pending' AFTER status
        ");
        echo "✓ verification_status column added\n";
    } else {
        echo "✓ verification_status column already exists\n";
    }
    
    // 2. Check if verified_at column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'verified_at'");
    $column_exists = $stmt->fetch();
    
    if (!$column_exists) {
        echo "Adding verified_at column...\n";
        $pdo->exec("
            ALTER TABLE users 
            ADD COLUMN verified_at DATETIME NULL AFTER verification_status
        ");
        echo "✓ verified_at column added\n";
    } else {
        echo "✓ verified_at column already exists\n";
    }
    
    // 3. Check if verified_by column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'verified_by'");
    $column_exists = $stmt->fetch();
    
    if (!$column_exists) {
        echo "Adding verified_by column...\n";
        $pdo->exec("
            ALTER TABLE users 
            ADD COLUMN verified_by INT NULL AFTER verified_at,
            ADD FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
        ");
        echo "✓ verified_by column added\n";
    } else {
        echo "✓ verified_by column already exists\n";
    }
    
    // 4. Update existing users to 'approved' status
    echo "\nUpdating existing user accounts...\n";
    $stmt = $pdo->exec("
        UPDATE users 
        SET verification_status = 'approved', 
            verified_at = created_at 
        WHERE verification_status = 'pending' AND status = 'Active'
    ");
    echo "✓ Updated $stmt existing accounts to 'approved' status\n";
    
    echo "\n✅ Account verification system setup complete!\n";
    echo "\nNew signups will now require admin verification before login.\n";
    
} catch (PDOException $e) {
    echo "✗ Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
