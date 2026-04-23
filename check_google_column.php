<?php

use Exception;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script can only be run from CLI.\n");
}

require_once 'db.php';

try {
    $pdo = pdo();
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'google_id'");
    $result = $stmt->fetch();
    
    if ($result) {
        echo "✓ google_id column EXISTS\n";
        print_r($result);
    } else {
        echo "✗ google_id column MISSING - Need to run add_google_id_column.sql\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
