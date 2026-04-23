<?php
/**
 * Schema Validation Script
 * 
 * Validates that the database schema matches the expected production state.
 * Run this at application startup or as a health check to detect schema drift.
 * 
 * Usage: php scripts/validate_schema.php
 * Returns exit code 0 on success, 1 on failure.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script can only be run from CLI.\n");
}

require_once __DIR__ . '/../db.php';

$errors = [];
$warnings = [];

try {
    $pdo = pdo();
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    
    echo "=== PCU RFID2 Schema Validation ===\n";
    echo "Database: {$dbName}\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

    // -------------------------------------------------------
    // 1. Required tables
    // -------------------------------------------------------
    $requiredTables = [
        // Governance
        'schema_migrations',
        // Identity & Auth
        'users', 'auth_providers', 'user_auth_methods', 'password_resets', 'student_profiles',
        // RFID
        'rfid_cards', 'rfid_status_history',
        // Face
        'face_descriptors', 'face_entry_logs', 'face_registration_log',
        // Violations
        'violations', 'violation_categories', 'student_violations',
        // Notifications
        'guardians', 'student_guardians', 'notification_settings', 'notification_queue', 'notification_logs',
        // Audit
        'audit_log', 'audit_logs', 'auth_audit_log',
        // RBAC
        'roles', 'permissions', 'role_permissions', 'user_roles', 'user_permission_overrides', 'permission_audit_log',
        // QR / Security
        'qr_entry_logs', 'used_qr_tokens', 'qr_scan_challenges', 'qr_face_pending', 'qr_security_events',
        // System
        'ip_rate_limits', 'system_settings', 'security_alert_log',
    ];

    $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($requiredTables as $table) {
        if (!in_array($table, $existingTables)) {
            $errors[] = "MISSING TABLE: {$table}";
        }
    }

    // -------------------------------------------------------
    // 2. Required columns on users table
    // -------------------------------------------------------
    $requiredUserColumns = [
        'id', 'student_id', 'name', 'email', 'course', 'google_id', 'password',
        'role', 'status', 'locked_until', 'failed_attempts', 'last_login',
        'created_at', 'updated_at', 'profile_picture', 'profile_picture_uploaded_at',
        'rfid_uid', 'rfid_registered_at', 'violation_count', 'active_violations_count',
        'gate_mark_count', 'face_registered', 'face_registered_at', 'deleted_at',
    ];

    if (in_array('users', $existingTables)) {
        $stmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
        $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($requiredUserColumns as $col) {
            if (!in_array($col, $existingColumns)) {
                $errors[] = "MISSING COLUMN: users.{$col}";
            }
        }
    }

    // -------------------------------------------------------
    // 3. Required triggers
    // -------------------------------------------------------
    $requiredTriggers = [
        'after_rfid_insert',
        'after_rfid_update',
        'after_profile_update',
        'after_violation_insert',
        'after_student_violation_insert',
        'after_student_violation_update',
        'after_student_violation_delete',
        'trg_audit_logs_block_update',
        'trg_audit_logs_block_delete',
        'trg_permission_audit_log_block_update',
        'trg_permission_audit_log_block_delete',
    ];

    $stmt = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()");
    $existingTriggers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($requiredTriggers as $trigger) {
        if (!in_array($trigger, $existingTriggers)) {
            $warnings[] = "MISSING TRIGGER: {$trigger}";
        }
    }

    // -------------------------------------------------------
    // 4. Required views
    // -------------------------------------------------------
    $requiredViews = ['v_active_rfid_cards', 'v_students_complete'];

    $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()");
    $existingViews = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($requiredViews as $view) {
        if (!in_array($view, $existingViews)) {
            $warnings[] = "MISSING VIEW: {$view}";
        }
    }

    // -------------------------------------------------------
    // 5. Counter parity check
    // -------------------------------------------------------
    if (in_array('users', $existingTables) && in_array('student_violations', $existingTables)) {
        $stmt = $pdo->query("
            SELECT u.id, u.student_id, u.active_violations_count AS cached,
                   COALESCE(sv.actual, 0) AS actual
            FROM users u
            LEFT JOIN (
                SELECT user_id, COUNT(*) AS actual
                FROM student_violations
                WHERE status IN ('active', 'pending_reparation')
                GROUP BY user_id
            ) sv ON u.id = sv.user_id
            WHERE u.role = 'Student'
            HAVING cached != actual
        ");
        $drifted = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($drifted) > 0) {
            foreach ($drifted as $row) {
                $warnings[] = "COUNTER DRIFT: user {$row['student_id']} active_violations_count={$row['cached']} but actual={$row['actual']}";
            }
        }
    }

    // -------------------------------------------------------
    // 6. Migration ledger check
    // -------------------------------------------------------
    if (in_array('schema_migrations', $existingTables)) {
        $stmt = $pdo->query("SELECT migration_name FROM schema_migrations ORDER BY applied_at");
        $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Applied migrations: " . count($applied) . "\n";
        foreach ($applied as $m) {
            echo "  - {$m}\n";
        }
        echo "\n";
    } else {
        $warnings[] = "schema_migrations table not found - migration governance not active";
    }

    // -------------------------------------------------------
    // 7. Orphan check (FK integrity)
    // -------------------------------------------------------
    $orphanChecks = [
        ['student_violations', 'user_id', 'users', 'id'],
        ['rfid_cards', 'user_id', 'users', 'id'],
        ['face_descriptors', 'user_id', 'users', 'id'],
        ['student_profiles', 'user_id', 'users', 'id'],
        ['user_auth_methods', 'user_id', 'users', 'id'],
    ];

    foreach ($orphanChecks as [$child, $childCol, $parent, $parentCol]) {
        if (in_array($child, $existingTables) && in_array($parent, $existingTables)) {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM `{$child}` c
                LEFT JOIN `{$parent}` p ON c.`{$childCol}` = p.`{$parentCol}`
                WHERE p.`{$parentCol}` IS NULL
            ");
            $orphans = (int)$stmt->fetchColumn();
            if ($orphans > 0) {
                $warnings[] = "ORPHAN ROWS: {$orphans} rows in {$child} have no matching {$parent}.{$parentCol}";
            }
        }
    }

    // -------------------------------------------------------
    // Report
    // -------------------------------------------------------
    echo "--- Results ---\n";

    if (empty($errors) && empty($warnings)) {
        echo "PASS: Schema is valid and production-ready.\n";
        exit(0);
    }

    if (!empty($errors)) {
        echo "\nERRORS (must fix):\n";
        foreach ($errors as $e) {
            echo "  [ERROR] {$e}\n";
        }
    }

    if (!empty($warnings)) {
        echo "\nWARNINGS (should fix):\n";
        foreach ($warnings as $w) {
            echo "  [WARN]  {$w}\n";
        }
    }

    echo "\nTotal: " . count($errors) . " errors, " . count($warnings) . " warnings\n";
    exit(count($errors) > 0 ? 1 : 0);

} catch (PDOException $e) {
    echo "FATAL: Database connection failed - " . $e->getMessage() . "\n";
    exit(1);
}
