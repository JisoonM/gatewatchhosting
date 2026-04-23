<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script can only be run from CLI.\n");
}

require_once __DIR__ . '/../db.php';

try {
    $pdo = pdo();
    // Add RFID column (safe check via information_schema)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'rfid_uid'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN rfid_uid VARCHAR(50) DEFAULT NULL");
        echo "Added column rfid_uid\n";
    } else {
        echo "Column rfid_uid already exists\n";
    }

    // Add unique index for rfid_uid if it doesn't exist (safe: check for duplicates first)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'unique_rfid'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        // ensure there are no duplicate non-null rfid_uids
        $dup = $pdo->query("SELECT rfid_uid, COUNT(*) c FROM users WHERE rfid_uid IS NOT NULL GROUP BY rfid_uid HAVING c>1")->fetch(\PDO::FETCH_ASSOC);
        if ($dup) {
            echo "Found duplicate RFID values, cannot create unique index 'unique_rfid'\n";
        } else {
            $pdo->exec("CREATE UNIQUE INDEX unique_rfid ON users (rfid_uid)");
            echo "Created unique index unique_rfid\n";
        }
    } else {
        echo "Unique index unique_rfid already exists\n";
    }

    // Add RFID registration timestamp
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'rfid_registered_at'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN rfid_registered_at TIMESTAMP NULL DEFAULT NULL");
        echo "Added column rfid_registered_at\n";
    } else {
        echo "Column rfid_registered_at already exists\n";
    }

    echo "RFID migration completed\n";
    
} catch (\PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}