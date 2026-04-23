<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script can only be run from CLI.\n");
}

require_once __DIR__ . '/../db.php';

$inputPath = $argv[1] ?? '';
if ($inputPath === '') {
    fwrite(STDERR, "Usage: php scripts/apply_migration_file.php <migration-file>\n");
    exit(1);
}

$path = $inputPath;
if (!preg_match('/^[A-Za-z]:\\\\|^\//', $path)) {
    $path = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $path), '/');
}

if (!is_file($path)) {
    fwrite(STDERR, 'Migration file not found: ' . $path . PHP_EOL);
    exit(1);
}

$migrationName = pathinfo($path, PATHINFO_FILENAME);

try {
    $pdo = pdo();

    $hasLedgerStmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'");
    $hasLedger = ((int)$hasLedgerStmt->fetchColumn() > 0);
    if (!$hasLedger) {
        fwrite(STDERR, "schema_migrations table is missing. Run migrations/000_schema_migrations.sql first.\n");
        exit(1);
    }

    $alreadyStmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration_name = ?');
    $alreadyStmt->execute([$migrationName]);
    if ((int)$alreadyStmt->fetchColumn() > 0) {
        fwrite(STDOUT, "Migration already applied: {$migrationName}\n");
        exit(0);
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Unable to read migration file: ' . $path);
    }

    // Strip block comments and single-line SQL comments for predictable splitting.
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
    $lines = preg_split('/\R/', $sql) ?: [];
    $cleanLines = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $cleanLines[] = $line;
    }
    $cleanSql = implode("\n", $cleanLines);

    $parts = preg_split('/;\s*(?:\r?\n|$)/', $cleanSql) ?: [];
    $executed = 0;

    foreach ($parts as $part) {
        $stmt = trim($part);
        if ($stmt === '') {
            continue;
        }
        $pdo->exec($stmt);
        $executed++;
    }

    $checkStmt = $pdo->prepare('SELECT applied_at FROM schema_migrations WHERE migration_name = ? LIMIT 1');
    $checkStmt->execute([$migrationName]);
    $appliedAt = $checkStmt->fetchColumn();

    if ($appliedAt === false) {
        throw new RuntimeException('Migration executed but not registered in schema_migrations: ' . $migrationName);
    }

    fwrite(STDOUT, "Applied migration: {$migrationName}\n");
    fwrite(STDOUT, "Statements executed: {$executed}\n");
    fwrite(STDOUT, "Applied at: {$appliedAt}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
