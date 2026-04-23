<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script can only be run from CLI.\n");
}

require_once __DIR__ . '/../db.php';

$files = [
    __DIR__ . '/../migrations/add_qr_scan_challenges.sql',
    __DIR__ . '/../migrations/add_qr_face_binding.sql',
];

try {
    $pdo = pdo();
    foreach ($files as $file) {
        if (!is_file($file)) {
            throw new RuntimeException('Migration file not found: ' . $file);
        }
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Unable to read migration file: ' . $file);
        }

        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql);
        foreach ($parts as $part) {
            $stmt = trim($part);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $pdo->exec($stmt);
        }
    }

    echo "QR binding migrations applied successfully\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
