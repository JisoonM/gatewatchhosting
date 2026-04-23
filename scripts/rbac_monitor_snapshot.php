<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI only\n");
}

$minutes = 15;
foreach ($argv as $arg) {
    if (strpos($arg, '--minutes=') === 0) {
        $value = (int)substr($arg, strlen('--minutes='));
        if ($value >= 1 && $value <= 1440) {
            $minutes = $value;
        }
    }
}

function line_out(string $line = ''): void {
    fwrite(STDOUT, $line . PHP_EOL);
}

try {
    $pdo = pdo();

    line_out('=== RBAC Monitor Snapshot ===');
    line_out('window_minutes=' . $minutes);
    line_out('captured_at=' . date('Y-m-d H:i:s'));

    $settingsStmt = $pdo->query(
        "SELECT setting_key, value
         FROM system_settings
         WHERE setting_key IN ('rbac_mode','rbac_enforce_tier','rbac_fail_closed','rbac_log_decisions')
         ORDER BY setting_key"
    );
    $settings = $settingsStmt ? $settingsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    line_out('');
    line_out('--- Settings ---');
    foreach ($settings as $row) {
        line_out($row['setting_key'] . '=' . $row['value']);
    }

    $decisionStmt = $pdo->prepare(
        "SELECT decision, COUNT(*) AS c
         FROM permission_audit_log
         WHERE created_at >= NOW() - INTERVAL ? MINUTE
         GROUP BY decision"
    );
    $decisionStmt->execute([$minutes]);
    $decisionRows = $decisionStmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = ['allow' => 0, 'deny' => 0];
    foreach ($decisionRows as $row) {
        $key = (string)$row['decision'];
        if (isset($totals[$key])) {
            $totals[$key] = (int)$row['c'];
        }
    }

    line_out('');
    line_out('--- Decision Totals ---');
    line_out('allow=' . $totals['allow']);
    line_out('deny=' . $totals['deny']);

    $errorSourceStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM permission_audit_log
         WHERE created_at >= NOW() - INTERVAL ? MINUTE
           AND decision_source = 'error'"
    );
    $errorSourceStmt->execute([$minutes]);
    $errorSourceCount = (int)$errorSourceStmt->fetchColumn();

    line_out('decision_source.error=' . $errorSourceCount);

    $topDenyStmt = $pdo->prepare(
        "SELECT permission_key, COUNT(*) AS c
         FROM permission_audit_log
         WHERE created_at >= NOW() - INTERVAL ? MINUTE
           AND decision = 'deny'
         GROUP BY permission_key
         ORDER BY c DESC, permission_key ASC
         LIMIT 10"
    );
    $topDenyStmt->execute([$minutes]);
    $topDeny = $topDenyStmt->fetchAll(PDO::FETCH_ASSOC);

    line_out('');
    line_out('--- Top Denied Permissions ---');
    if (!$topDeny) {
        line_out('none');
    } else {
        foreach ($topDeny as $row) {
            line_out($row['permission_key'] . '=' . $row['c']);
        }
    }

    $roleDenyStmt = $pdo->prepare(
        "SELECT actor_role_key, COUNT(*) AS c
         FROM permission_audit_log
         WHERE created_at >= NOW() - INTERVAL ? MINUTE
           AND decision = 'deny'
         GROUP BY actor_role_key
         ORDER BY c DESC, actor_role_key ASC"
    );
    $roleDenyStmt->execute([$minutes]);
    $roleDeny = $roleDenyStmt->fetchAll(PDO::FETCH_ASSOC);

    line_out('');
    line_out('--- Denies By Actor Role ---');
    if (!$roleDeny) {
        line_out('none');
    } else {
        foreach ($roleDeny as $row) {
            line_out($row['actor_role_key'] . '=' . $row['c']);
        }
    }

    $alertStmt = $pdo->prepare(
        "SELECT severity, COUNT(*) AS c
         FROM security_alert_log
         WHERE created_at >= NOW() - INTERVAL ? MINUTE
         GROUP BY severity
         ORDER BY FIELD(severity,'critical','warning','info')"
    );
    $alertStmt->execute([$minutes]);
    $alerts = $alertStmt->fetchAll(PDO::FETCH_ASSOC);

    line_out('');
    line_out('--- Security Alerts ---');
    if (!$alerts) {
        line_out('none');
    } else {
        foreach ($alerts as $row) {
            line_out($row['severity'] . '=' . $row['c']);
        }
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'snapshot_error=' . $e->getMessage() . PHP_EOL);
    exit(1);
}
