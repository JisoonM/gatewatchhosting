<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * RBAC smoke suite:
 * 1) Verify critical Tier 1/2 endpoint files contain permission guards.
 * 2) Evaluate allow/deny matrix across roles.
 * 3) Print telemetry summary generated during this run.
 */

function print_line(string $line = ''): void {
    fwrite(STDOUT, $line . PHP_EOL);
}

function fail_line(string $line): void {
    fwrite(STDERR, $line . PHP_EOL);
}

function reset_actor_session(): void {
    $_SESSION = [];
}

function set_actor_session(string $role): void {
    reset_actor_session();

    if ($role === 'student') {
        $_SESSION['user'] = [
            'id' => 202232903,
            'email' => 'student-smoke@example.test',
        ];
        return;
    }

    if ($role === 'admin') {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = 3;
        return;
    }

    if ($role === 'security') {
        $_SESSION['security_logged_in'] = true;
        $_SESSION['security_id'] = 1;
        $_SESSION['security_username'] = 'security-smoke';
        return;
    }

    if ($role === 'superadmin') {
        $_SESSION['superadmin_logged_in'] = true;
        $_SESSION['superadmin_id'] = 1;
        return;
    }
}

function endpoint_guard_checks(): array {
    $checks = [
        ['file' => 'security/gate_scan.php', 'needle' => "require_permission('gate.scan.rfid'"],
        ['file' => 'security/qr_scan.php', 'needle' => "require_permission('qr.scan'"],
        ['file' => 'api/verify_face_match.php', 'needle' => "require_permission('face.verify'"],
        ['file' => 'admin/filter_audit_logs.php', 'needle' => "require_permission('audit.read'"],
        ['file' => 'admin/analytics_data.php', 'needle' => "require_permission('audit.read'"],
        ['file' => 'admin/filter_qr_security_events.php', 'needle' => "require_permission('audit.read'"],
        ['file' => 'api/get_face_descriptors.php', 'needle' => 'require_permission($permissionKey'],
        ['file' => 'api/get_face_updates.php', 'needle' => 'require_permission($permissionKey'],
    ];

    $results = [];
    foreach ($checks as $check) {
        $path = __DIR__ . '/../' . $check['file'];
        if (!is_file($path)) {
            $results[] = [
                'ok' => false,
                'file' => $check['file'],
                'reason' => 'file_missing',
            ];
            continue;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            $results[] = [
                'ok' => false,
                'file' => $check['file'],
                'reason' => 'file_unreadable',
            ];
            continue;
        }

        $ok = (strpos($content, $check['needle']) !== false);
        $results[] = [
            'ok' => $ok,
            'file' => $check['file'],
            'reason' => $ok ? 'present' : 'missing_guard',
        ];
    }

    return $results;
}

function role_permission_mapping_check(PDO $pdo): array {
    $expectedAllow = [
        'admin' => [
            'student.delete',
            'rfid.register',
            'rfid.unregister',
            'face.register',
            'violation.clear',
            'audit.read',
            'audit.export',
        ],
        'security' => [
            'gate.scan.rfid',
            'qr.scan',
            'face.verify',
            'violation.record',
        ],
        'student' => [
            'student.profile.view',
            'student.profile.update',
            'student.violations.read_own',
            'student.digital_id.view',
        ],
        'superadmin' => [
            'admin.create',
            'admin.update',
            'admin.delete',
            'audit.read',
            'rfid.mark_lost',
            'gate.scan.rfid',
        ],
    ];

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM role_permissions rp
         INNER JOIN roles r ON r.id = rp.role_id
         INNER JOIN permissions p ON p.id = rp.permission_id
         WHERE r.role_key = ? AND p.permission_key = ? AND rp.is_allowed = 1 AND r.is_active = 1 AND p.is_active = 1'
    );

    $results = [];
    foreach ($expectedAllow as $role => $permissions) {
        foreach ($permissions as $permissionKey) {
            $stmt->execute([$role, $permissionKey]);
            $exists = ((int)$stmt->fetchColumn() > 0);
            $results[] = [
                'ok' => $exists,
                'role' => $role,
                'permission' => $permissionKey,
                'reason' => $exists ? 'mapped' : 'missing_mapping',
            ];
        }
    }

    return $results;
}

function evaluation_matrix(): array {
    return [
        ['role' => 'admin', 'permission' => 'student.delete', 'expect' => true],
        ['role' => 'admin', 'permission' => 'rfid.register', 'expect' => true],
        ['role' => 'admin', 'permission' => 'audit.read', 'expect' => true],
        ['role' => 'admin', 'permission' => 'audit.export', 'expect' => true],
        ['role' => 'admin', 'permission' => 'gate.scan.rfid', 'expect' => false],
        ['role' => 'admin', 'permission' => 'qr.scan', 'expect' => false],

        ['role' => 'security', 'permission' => 'gate.scan.rfid', 'expect' => true],
        ['role' => 'security', 'permission' => 'qr.scan', 'expect' => true],
        ['role' => 'security', 'permission' => 'face.verify', 'expect' => true],
        ['role' => 'security', 'permission' => 'violation.record', 'expect' => true],
        ['role' => 'security', 'permission' => 'student.delete', 'expect' => false],
        ['role' => 'security', 'permission' => 'audit.export', 'expect' => false],

        ['role' => 'student', 'permission' => 'student.delete', 'expect' => false],
        ['role' => 'student', 'permission' => 'audit.read', 'expect' => false],
        ['role' => 'student', 'permission' => 'violation.record', 'expect' => false],
        ['role' => 'student', 'permission' => 'gate.scan.rfid', 'expect' => false],

        ['role' => 'superadmin', 'permission' => 'admin.create', 'expect' => true],
        ['role' => 'superadmin', 'permission' => 'admin.update', 'expect' => true],
        ['role' => 'superadmin', 'permission' => 'admin.delete', 'expect' => true],
        ['role' => 'superadmin', 'permission' => 'gate.scan.rfid', 'expect' => true],
    ];
}

function run_evaluation_matrix(string $startedAt): array {
    $checks = evaluation_matrix();
    $results = [];

    foreach ($checks as $check) {
        $role = $check['role'];
        $permission = $check['permission'];
        $expect = (bool)$check['expect'];

        set_actor_session($role);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/rbac-smoke/' . $role . '/' . $permission;
        $_SERVER['SCRIPT_NAME'] = '/' . $role . '/smoke.php';

        $eval = evaluate_permission($permission, $role);
        $allowed = !empty($eval['allowed']);

        $results[] = [
            'ok' => ($allowed === $expect),
            'role' => $role,
            'permission' => $permission,
            'expect' => $expect,
            'actual' => $allowed,
            'decision_source' => (string)($eval['decision_source'] ?? ''),
            'is_enforced' => !empty($eval['is_enforced']),
            'tier' => (int)($eval['tier'] ?? 0),
            'started_at' => $startedAt,
        ];
    }

    reset_actor_session();
    return $results;
}

function print_summary_block(string $title, array $rows, callable $formatter): int {
    print_line('');
    print_line('=== ' . $title . ' ===');

    $failures = 0;
    foreach ($rows as $row) {
        $line = $formatter($row);
        if (!empty($row['ok'])) {
            print_line('[PASS] ' . $line);
        } else {
            fail_line('[FAIL] ' . $line);
            $failures++;
        }
    }

    return $failures;
}

try {
    $pdo = pdo();
    $suiteStartedAt = date('Y-m-d H:i:s');

    $settingsStmt = $pdo->query("SELECT setting_key, value FROM system_settings WHERE setting_key IN ('rbac_mode','rbac_enforce_tier','rbac_log_decisions','rbac_fail_closed') ORDER BY setting_key");
    $settings = $settingsStmt ? $settingsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    print_line('=== RBAC SMOKE SUITE ===');
    print_line('started_at=' . $suiteStartedAt);
    foreach ($settings as $setting) {
        print_line('setting.' . $setting['setting_key'] . '=' . $setting['value']);
    }

    $guardResults = endpoint_guard_checks();
    $mappingResults = role_permission_mapping_check($pdo);
    $evalResults = run_evaluation_matrix($suiteStartedAt);

    $guardFails = print_summary_block(
        'Endpoint Guard Presence',
        $guardResults,
        static function (array $row): string {
            return $row['file'] . ' :: ' . $row['reason'];
        }
    );

    $mappingFails = print_summary_block(
        'Role-Permission Mapping',
        $mappingResults,
        static function (array $row): string {
            return $row['role'] . ' -> ' . $row['permission'] . ' :: ' . $row['reason'];
        }
    );

    $evalFails = print_summary_block(
        'Permission Evaluation Matrix',
        $evalResults,
        static function (array $row): string {
            return $row['role']
                . ' -> ' . $row['permission']
                . ' expect=' . ($row['expect'] ? 'allow' : 'deny')
                . ' actual=' . ($row['actual'] ? 'allow' : 'deny')
                . ' source=' . $row['decision_source']
                . ' tier=' . $row['tier']
                . ' enforced=' . ($row['is_enforced'] ? '1' : '0');
        }
    );

    print_line('');
    print_line('=== Telemetry Since Suite Start ===');
    $telemetryStmt = $pdo->prepare(
        'SELECT permission_key, decision, COUNT(*) AS c
         FROM permission_audit_log
         WHERE created_at >= ?
         GROUP BY permission_key, decision
         ORDER BY c DESC, permission_key ASC'
    );
    $telemetryStmt->execute([$suiteStartedAt]);
    $telemetryRows = $telemetryStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$telemetryRows) {
        print_line('telemetry.none=1');
    } else {
        foreach ($telemetryRows as $t) {
            print_line('telemetry.' . $t['permission_key'] . '.' . $t['decision'] . '=' . $t['c']);
        }
    }

    $totalFails = $guardFails + $mappingFails + $evalFails;
    print_line('');
    print_line('summary.total_failures=' . $totalFails);
    exit($totalFails > 0 ? 1 : 0);
} catch (Throwable $e) {
    fail_line('[FATAL] ' . $e->getMessage());
    exit(2);
}
