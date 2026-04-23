<?php
/**
 * One-time migration script.
 * Moves existing profile pictures from the old web-root location to the secure
 * storage directory outside the web root, and renames each file to a random
 * 32-char hex name so filenames are no longer guessable or enumerable.
 *
 * Run ONCE from CLI, then delete this file.
 * Example CLI: php migrate_profile_pictures.php
 */
require_once __DIR__ . '/db.php';

// CLI-only execution.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'Forbidden: this script can only be run from CLI.';
    exit;
}

$oldDir = __DIR__ . '/assets/images/profiles/';
$newDir = defined('PROFILE_PICTURES_DIR')
    ? (string) constant('PROFILE_PICTURES_DIR')
    : (__DIR__ . '/assets/images/profiles/secure/');

if (!is_dir($newDir)) {
    mkdir($newDir, 0755, true);
}

$mimeToExtension = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

try {
    $pdo = pdo();

    // Fetch all users that have a non-empty profile_picture
    $stmt = $pdo->query("SELECT id, profile_picture FROM users WHERE profile_picture IS NOT NULL AND profile_picture != ''");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $migrated = 0;
    $skipped  = 0;
    $errors   = [];

    foreach ($rows as $row) {
        $oldFilename = $row['profile_picture'];

        // Already migrated (new names are exactly 32 hex chars + extension)
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $oldFilename)) {
            // File might be in old dir (copy not yet moved) or already in new dir.
            $srcPath = $oldDir . $oldFilename;
            $dstPath = $newDir . $oldFilename;
            if (!file_exists($dstPath) && file_exists($srcPath)) {
                rename($srcPath, $dstPath);
            }
            $skipped++;
            continue;
        }

        $srcPath = $oldDir . $oldFilename;

        if (!file_exists($srcPath)) {
            $errors[] = "User {$row['id']}: source file not found – $srcPath";
            continue;
        }

        // Detect real MIME type from file content
        $mime = mime_content_type($srcPath);
        $ext  = $mimeToExtension[$mime] ?? null;

        if ($ext === null) {
            // Fall back to extension from filename
            $ext = strtolower(pathinfo($oldFilename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $errors[] = "User {$row['id']}: unsupported file type '$mime' – $srcPath";
                continue;
            }
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
        }

        $newFilename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dstPath     = $newDir . $newFilename;

        if (!rename($srcPath, $dstPath)) {
            $errors[] = "User {$row['id']}: failed to move $srcPath → $dstPath";
            continue;
        }

        // Update the database
        $update = $pdo->prepare('UPDATE users SET profile_picture = ? WHERE id = ?');
        $update->execute([$newFilename, $row['id']]);

        $migrated++;
    }

    // Summary
    if ($isCli) {
        echo "Migration complete.\n";
        echo "  Migrated : $migrated\n";
        echo "  Skipped  : $skipped (already in new format)\n";
        echo "  Errors   : " . count($errors) . "\n";
        foreach ($errors as $e) {
            echo "  [ERROR] $e\n";
        }
    } else {
        header('Content-Type: text/plain');
        echo "Migration complete.\n";
        echo "Migrated : $migrated\n";
        echo "Skipped  : $skipped (already in new format)\n";
        echo "Errors   : " . count($errors) . "\n";
        foreach ($errors as $e) {
            echo "[ERROR] $e\n";
        }
        echo "\nDelete this file after confirming results.\n";
    }

} catch (Exception $e) {
    $msg = 'Migration failed: ' . $e->getMessage();
    if ($isCli) {
        fwrite(STDERR, $msg . "\n");
    } else {
        http_response_code(500);
        echo $msg;
    }
    exit(1);
}
