<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI only\n");
}

$usage = "Usage: php scripts/rbac_set_fail_closed.php --enable|--disable\n";

$enable = in_array('--enable', $argv, true);
$disable = in_array('--disable', $argv, true);

if (($enable && $disable) || (!$enable && !$disable)) {
    fwrite(STDERR, $usage);
    exit(1);
}

$target = $enable ? '1' : '0';

try {
    $pdo = pdo();

    $stmt = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, value, description) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'rbac_fail_closed',
        $target,
        'If 1, deny when RBAC storage is unavailable during enforce mode',
    ]);

    $settingsStmt = $pdo->query(
        "SELECT setting_key, value
         FROM system_settings
         WHERE setting_key IN ('rbac_mode','rbac_enforce_tier','rbac_fail_closed','rbac_log_decisions')
         ORDER BY setting_key"
    );

    foreach (($settingsStmt ? $settingsStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        echo $row['setting_key'] . '=' . $row['value'] . PHP_EOL;
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'set_fail_closed_error=' . $e->getMessage() . PHP_EOL);
    exit(1);
}
