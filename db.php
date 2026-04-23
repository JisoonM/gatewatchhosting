<?php
// db.php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

// Configure secure session settings BEFORE starting the session
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,                // Session cookie — expires when browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,         // Only send over HTTPS in production
        'httponly'  => true,             // Prevent JavaScript access
        'samesite' => 'Lax',            // Allows OAuth redirect callbacks while still blocking CSRF on POST/AJAX
    ]);

    ini_set('session.use_strict_mode', '1');     // Reject uninitialized session IDs
    ini_set('session.use_only_cookies', '1');     // Prevent session fixation via URL

    session_start();
}

// CSRF token helper
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

/**
 * Send HTTP headers that prevent the browser from caching the page.
 * Call this at the top of every protected page so the browser back/forward
 * buttons cannot display stale content after logout.
 */
function send_no_cache_headers(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

/**
 * Send standard security headers on every response.
 * Call this once at the top of every page that outputs HTML.
 * It is safe to call multiple times — PHP silently replaces duplicate headers.
 */
function send_security_headers(): void {
    // Prevent MIME-type sniffing
    header('X-Content-Type-Options: nosniff');
    // Deny framing entirely (clickjacking protection)
    header('X-Frame-Options: DENY');
    // Do not send the Referer header when navigating away
    header('Referrer-Policy: no-referrer');
    // Legacy XSS filter (belt-and-suspenders for old browsers)
    header('X-XSS-Protection: 1; mode=block');

    // Content Security Policy
    // DEVELOPMENT MODE: 'unsafe-inline' is allowed because Tailwind CDN and
    // inline <style>/<script> tags are used throughout the project.
    // When you later compile Tailwind and extract inline scripts, tighten this.
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://unpkg.com; " .
        "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://fonts.googleapis.com https://cdnjs.cloudflare.com; " .
        "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
        "img-src 'self' data: https://pcu.edu.ph https://lh3.googleusercontent.com; " .
        "connect-src 'self'; " .
        "frame-ancestors 'none';"
    );

    // Restrict browser feature access. The face recognition feature needs 'camera'.
    header(
        "Permissions-Policy: " .
        "camera=(self), " .
        "microphone=(), " .
        "geolocation=(), " .
        "payment=(), " .
        "usb=(self)"
    );

    // HSTS: Only send over HTTPS. In development this is skipped automatically.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Get the real client IP address, safely handling reverse proxies.
 *
 * Security note: X-Forwarded-For can be spoofed by clients. We only trust it
 * when the connection comes from a known trusted proxy. For development,
 * set TRUSTED_PROXY_IPS in .env to a comma-separated list (e.g., "127.0.0.1").
 * Leave it empty to always use REMOTE_ADDR (safe for direct connections).
 *
 * @return string The best-available client IP address
 */
function get_client_ip(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Get list of trusted proxy IPs from .env
    $trustedProxies = array_filter(array_map(
        'trim',
        explode(',', (string)env('TRUSTED_PROXY_IPS', ''))
    ));

    // Only trust X-Forwarded-For if the direct connection is from a trusted proxy
    if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            // X-Forwarded-For can be a comma-separated list: client, proxy1, proxy2
            // The leftmost IP is the original client
            $ips = array_map('trim', explode(',', $forwarded));
            $clientIp = $ips[0] ?? '';
            // Validate it is a real IP (not spoofed garbage)
            if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                return $clientIp;
            }
        }
    }

    return $remoteAddr;
}

/**
 * Fully destroy the current session: clear data, delete cookie, destroy storage.
 * After calling this the client has no valid session.
 */
function destroy_session_completely(): void {
    // Unset all session variables
    $_SESSION = [];

    // Delete the session cookie
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'],
            $p['secure'],
            $p['httponly']
        );
    }

    // Destroy the session storage
    session_destroy();
}

// ──────────────────────────────────────────────────────────────────────────────
// ROLE-SPECIFIC LOGOUT HELPERS
// Each function unsets only the keys belonging to that role, so simultaneously
// active sessions for other roles (e.g. admin open in another tab while a
// student is also logged in) are not affected.
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Log out the student role only. Other active roles are not disturbed.
 */
function logout_student_session(): void {
    foreach (['user', 'toast', 'info', 'error'] as $k) {
        unset($_SESSION[$k]);
    }
    _cleanup_session_if_no_active_role();
}

/**
 * Log out the admin role only. Other active roles are not disturbed.
 */
function logout_admin_session(): void {
    foreach (['admin_logged_in', 'admin_id', 'admin_name', 'user_id', 'role'] as $k) {
        unset($_SESSION[$k]);
    }
    _cleanup_session_if_no_active_role();
}

/**
 * Log out the security role only. Other active roles are not disturbed.
 */
function logout_security_session(): void {
    foreach (['security_logged_in', 'security_id', 'security_username', 'created_at'] as $k) {
        unset($_SESSION[$k]);
    }
    _cleanup_session_if_no_active_role();
}

/**
 * Log out the superadmin role only. Other active roles are not disturbed.
 */
function logout_superadmin_session(): void {
    foreach (['superadmin_logged_in', 'superadmin_id', 'superadmin_name', 'superadmin_email'] as $k) {
        unset($_SESSION[$k]);
    }
    _cleanup_session_if_no_active_role();
}

/**
 * After a role-specific logout: if no other role is still active, clear shared
 * session keys (last_activity, csrf_token) so the session is truly empty.
 * Always regenerates the session ID to prevent session fixation.
 */
function _cleanup_session_if_no_active_role(): void {
    $anyRoleActive = !empty($_SESSION['user'])
        || !empty($_SESSION['admin_logged_in'])
        || !empty($_SESSION['security_logged_in'])
        || !empty($_SESSION['superadmin_logged_in']);

    if (!$anyRoleActive) {
        unset($_SESSION['last_activity'], $_SESSION['csrf_token']);
    }

    // Regenerate session ID on every logout for security (prevents session fixation)
    session_regenerate_id(true);
}

/**
 * Require an authenticated student session.
 * Redirects to login.php if not logged in, session expired, or account inactive.
 */
function require_student_auth(): void {
    send_no_cache_headers();

    if (!isset($_SESSION['user']) || empty($_SESSION['user']['id']) || empty($_SESSION['user']['email'])) {
        destroy_session_completely();
        session_start();
        $_SESSION['toast'] = 'Please log in to access the system';
        header('Location: login.php');
        exit;
    }

    // Session timeout after 30 minutes of inactivity
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        destroy_session_completely();
        session_start();
        $_SESSION['toast'] = 'Session expired. Please log in again';
        header('Location: login.php');
        exit;
    }
    $_SESSION['last_activity'] = time();

    // Verify account still exists and is active in the database
    try {
        $pdo = pdo();
        $stmt = $pdo->prepare('SELECT id, status FROM users WHERE id = ? AND email = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$_SESSION['user']['id'], $_SESSION['user']['email']]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'Active') {
            destroy_session_completely();
            session_start();
            $_SESSION['toast'] = 'Your account is no longer active. Please contact support.';
            header('Location: login.php');
            exit;
        }
    } catch (\Throwable $e) {
        error_log('Session DB verification failed: ' . $e->getMessage());
        destroy_session_completely();
        session_start();
        $_SESSION['toast'] = 'System error. Please try again later.';
        header('Location: login.php');
        exit;
    }
}

/**
 * Require an authenticated admin session.
 * Redirects to admin_login.php if not logged in or session is invalid.
 */
function require_admin_auth(): void {
    send_no_cache_headers();

    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        destroy_session_completely();
        session_start();
        header('Location: admin_login.php');
        exit;
    }

    if (!isset($_SESSION['admin_id'])) {
        destroy_session_completely();
        session_start();
        $_SESSION['error'] = 'Session invalid. Please log in again.';
        header('Location: admin_login.php');
        exit;
    }

    // Session timeout after 30 minutes of inactivity
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        destroy_session_completely();
        session_start();
        $_SESSION['error'] = 'Session expired. Please log in again.';
        header('Location: admin_login.php');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Require an authenticated security guard session.
 * Redirects to security_login.php if not logged in or session expired.
 */
function require_security_auth(): void {
    send_no_cache_headers();

    if (!isset($_SESSION['security_logged_in']) || $_SESSION['security_logged_in'] !== true) {
        destroy_session_completely();
        session_start();
        header('Location: security_login.php');
        exit;
    }

    // Session timeout after 30 minutes of inactivity
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        destroy_session_completely();
        session_start();
        header('Location: security_login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();

    // Periodic session ID regeneration (every 5 minutes)
    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    } elseif (time() - $_SESSION['created_at'] > 300) {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }
}

/**
 * Require an authenticated super-admin session.
 * Redirects to superadmin_login.php if not logged in.
 */
function require_superadmin_auth(): void {
    send_no_cache_headers();

    if (!isset($_SESSION['superadmin_logged_in']) || $_SESSION['superadmin_logged_in'] !== true) {
        destroy_session_completely();
        session_start();
        header('Location: superadmin_login.php');
        exit;
    }

    // Session timeout after 30 minutes of inactivity
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        destroy_session_completely();
        session_start();
        $_SESSION['error'] = 'Session expired. Please log in again.';
        header('Location: superadmin_login.php');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Read a value from system_settings with in-request caching.
 * Returns the default when the table is unavailable.
 */
function get_system_setting(string $key, ?string $default = null): ?string {
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $pdo = pdo();
        $stmt = $pdo->prepare('SELECT value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $cache[$key] = ($value !== false) ? (string)$value : $default;
        return $cache[$key];
    } catch (\Throwable $e) {
        $cache[$key] = $default;
        return $default;
    }
}

/**
 * Rotate CSRF token after a critical state-changing operation when enabled.
 */
function rotate_csrf_after_critical_action(): void {
    $enabledRaw = (string)env(
        'CSRF_ROTATE_ON_CRITICAL',
        (string)get_system_setting('csrf_rotate_on_critical', '0')
    );
    $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);
    if ($enabled) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * Current RBAC mode.
 * legacy = bypass checks, dual = evaluate+log only, enforce = block by tier.
 */
function get_rbac_mode(): string {
    $mode = strtolower(trim((string)env('RBAC_MODE', (string)get_system_setting('rbac_mode', 'legacy'))));
    if (!in_array($mode, ['legacy', 'dual', 'enforce'], true)) {
        return 'legacy';
    }
    return $mode;
}

/**
 * Highest RBAC tier currently enforced.
 * 0 = no enforcement, 1 = critical, 2 = high, 3 = medium.
 */
function get_rbac_enforce_tier(): int {
    $tier = (int)env('RBAC_ENFORCE_TIER', (string)get_system_setting('rbac_enforce_tier', '0'));
    if ($tier < 0) {
        return 0;
    }
    if ($tier > 3) {
        return 3;
    }
    return $tier;
}

function is_rbac_decision_logging_enabled(): bool {
    $raw = (string)env('RBAC_LOG_DECISIONS', (string)get_system_setting('rbac_log_decisions', '1'));
    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
}

function is_rbac_fail_closed(): bool {
    $raw = (string)env('RBAC_FAIL_CLOSED', (string)get_system_setting('rbac_fail_closed', '0'));
    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
}

function is_session_isolation_on_privilege_change_enabled(): bool {
    $raw = (string)env(
        'SESSION_ISOLATION_ON_PRIVILEGE_CHANGE',
        (string)get_system_setting('session_isolation_on_privilege_change', '0')
    );
    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Regenerate session id after privilege-changing operations.
 * This reduces fixation/reuse risk during sensitive account mutations.
 */
function apply_session_isolation_on_privilege_change(array $context = []): void {
    if (!is_session_isolation_on_privilege_change_enabled()) {
        return;
    }

    try {
        session_regenerate_id(true);
        $_SESSION['privilege_context_rotated_at'] = time();
        if (isset($context['target_user_id'])) {
            $_SESSION['last_privilege_change_target_user_id'] = (int)$context['target_user_id'];
        }
        if (isset($context['target_role'])) {
            $_SESSION['last_privilege_change_target_role'] = (string)$context['target_role'];
        }
    } catch (\Throwable $e) {
        error_log('Session isolation rotate error: ' . $e->getMessage());
    }
}

function is_centralized_ratelimit_alerting_enabled(): bool {
    $mode = strtolower(trim((string)env(
        'RATE_LIMIT_POLICY_MODE',
        (string)get_system_setting('ratelimit_policy_mode', 'legacy')
    )));
    return ($mode === 'centralized');
}

/**
 * Best-effort centralized alert write for rate-limit events.
 */
function log_rate_limit_security_alert(
    string $action,
    string $identifier,
    string $ip,
    int $attempts,
    int $maxAttempts,
    int $blockedUntil,
    string $severity,
    array $context = []
): void {
    if (!is_centralized_ratelimit_alerting_enabled()) {
        return;
    }

    static $tableAvailable = null;
    static $dedupe = [];

    $now = time();
    $dedupeKey = $identifier . '|' . $severity;
    if (isset($dedupe[$dedupeKey]) && ($now - $dedupe[$dedupeKey]) < 30) {
        return;
    }
    $dedupe[$dedupeKey] = $now;

    try {
        $pdo = pdo();

        if ($tableAvailable === null) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_alert_log'"
            );
            $stmt->execute();
            $tableAvailable = ((int)$stmt->fetchColumn() > 0);
        }

        if (!$tableAvailable) {
            return;
        }

        $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES);
        if ($contextJson === false) {
            $contextJson = '{}';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO security_alert_log
             (alert_type, action_key, identifier, ip_address, attempts, threshold, blocked_until, severity, context_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'rate_limit',
            $action,
            $identifier,
            $ip,
            $attempts,
            $maxAttempts,
            ($blockedUntil > 0 ? date('Y-m-d H:i:s', $blockedUntil) : null),
            $severity,
            $contextJson,
        ]);
    } catch (\Throwable $e) {
        error_log('Rate-limit alert log error: ' . $e->getMessage());
    }
}

/**
 * Static fallback map used when permissions table is unavailable.
 */
function get_static_permission_tiers(): array {
    return [
        'student.verify'            => 1,
        'student.update'            => 1,
        'student.delete'            => 1,
        'rfid.register'             => 1,
        'rfid.unregister'           => 1,
        'rfid.mark_lost'            => 1,
        'face.register'             => 1,
        'face.delete'               => 1,
        'violation.record'          => 1,
        'violation.clear'           => 1,
        'audit.export'              => 1,
        'admin.create'              => 1,
        'admin.update'              => 1,
        'admin.delete'              => 1,
        'face.verify'               => 2,
        'audit.read'                => 2,
        'qr.scan'                   => 2,
        'gate.scan.rfid'            => 2,
        'student.profile.view'      => 3,
        'student.profile.update'    => 3,
        'student.violations.read_own' => 3,
        'student.digital_id.view'   => 3,
    ];
}

function infer_actor_role_from_request_path(): ?string {
    $script = strtolower(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
    if (strpos($script, '/superadmin/') !== false) {
        return 'superadmin';
    }
    if (strpos($script, '/admin/') !== false) {
        return 'admin';
    }
    if (strpos($script, '/security/') !== false) {
        return 'security';
    }
    return null;
}

/**
 * Resolve the current authenticated actor.
 * Supports explicit role hints to avoid ambiguity when multiple role sessions are active.
 */
function get_current_auth_actor(?string $preferredRole = null): array {
    $actors = [];

    if (!empty($_SESSION['user']) && !empty($_SESSION['user']['id'])) {
        $actors['student'] = [
            'authenticated' => true,
            'role_key' => 'student',
            'user_id' => (int)$_SESSION['user']['id'],
        ];
    }
    if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        $actors['admin'] = [
            'authenticated' => true,
            'role_key' => 'admin',
            'user_id' => isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null,
        ];
    }
    if (!empty($_SESSION['security_logged_in']) && $_SESSION['security_logged_in'] === true) {
        $actors['security'] = [
            'authenticated' => true,
            'role_key' => 'security',
            'user_id' => null,
        ];
    }
    if (!empty($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true) {
        $actors['superadmin'] = [
            'authenticated' => true,
            'role_key' => 'superadmin',
            'user_id' => isset($_SESSION['superadmin_id']) ? (int)$_SESSION['superadmin_id'] : null,
        ];
    }

    $preferred = strtolower(trim((string)$preferredRole));
    if ($preferred !== '' && isset($actors[$preferred])) {
        return $actors[$preferred];
    }

    $inferred = infer_actor_role_from_request_path();
    if ($inferred !== null && isset($actors[$inferred])) {
        return $actors[$inferred];
    }

    if (count($actors) === 1) {
        return array_values($actors)[0];
    }

    foreach (['superadmin', 'admin', 'security', 'student'] as $roleKey) {
        if (isset($actors[$roleKey])) {
            return $actors[$roleKey];
        }
    }

    return [
        'authenticated' => false,
        'role_key' => 'guest',
        'user_id' => null,
    ];
}

function rbac_table_exists(\PDO $pdo, string $tableName): bool {
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$tableName]);
    $cache[$tableName] = ((int)$stmt->fetchColumn() > 0);

    return $cache[$tableName];
}

function rbac_tables_available(\PDO $pdo): bool {
    return rbac_table_exists($pdo, 'roles')
        && rbac_table_exists($pdo, 'permissions')
        && rbac_table_exists($pdo, 'role_permissions');
}

function get_permission_tier(\PDO $pdo, string $permissionKey): int {
    static $cache = [];

    if (array_key_exists($permissionKey, $cache)) {
        return $cache[$permissionKey];
    }

    $fallback = get_static_permission_tiers();
    $defaultTier = (int)($fallback[$permissionKey] ?? 3);

    if (!rbac_table_exists($pdo, 'permissions')) {
        $cache[$permissionKey] = $defaultTier;
        return $defaultTier;
    }

    $stmt = $pdo->prepare('SELECT enforce_tier FROM permissions WHERE permission_key = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$permissionKey]);
    $row = $stmt->fetchColumn();

    if ($row === false) {
        $cache[$permissionKey] = $defaultTier;
        return $defaultTier;
    }

    $tier = (int)$row;
    if ($tier < 1 || $tier > 3) {
        $tier = $defaultTier;
    }
    $cache[$permissionKey] = $tier;
    return $tier;
}

function rbac_role_permission_decision(\PDO $pdo, string $roleKey, string $permissionKey): bool {
    $stmt = $pdo->prepare(
        'SELECT rp.is_allowed
         FROM role_permissions rp
         INNER JOIN roles r ON r.id = rp.role_id AND r.is_active = 1
         INNER JOIN permissions p ON p.id = rp.permission_id AND p.is_active = 1
         WHERE r.role_key = ? AND p.permission_key = ?
         LIMIT 1'
    );
    $stmt->execute([$roleKey, $permissionKey]);
    $value = $stmt->fetchColumn();

    // Missing mapping defaults to deny once a tier is enforced.
    if ($value === false) {
        return false;
    }

    return ((int)$value === 1);
}

function rbac_user_override_decision(\PDO $pdo, int $userId, string $permissionKey): ?bool {
    if (!rbac_table_exists($pdo, 'user_permission_overrides')) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT upo.is_allowed
         FROM user_permission_overrides upo
         INNER JOIN permissions p ON p.id = upo.permission_id
         WHERE upo.user_id = ?
           AND p.permission_key = ?
           AND (upo.expires_at IS NULL OR upo.expires_at > NOW())
         ORDER BY upo.created_at DESC
         LIMIT 1'
    );
    $stmt->execute([$userId, $permissionKey]);
    $value = $stmt->fetchColumn();

    if ($value === false) {
        return null;
    }

    return ((int)$value === 1);
}

function rbac_log_permission_decision(
    array $actor,
    string $permissionKey,
    bool $allowed,
    string $decisionSource,
    string $mode,
    bool $isEnforced,
    array $details = []
): void {
    if (!is_rbac_decision_logging_enabled()) {
        return;
    }

    try {
        $pdo = pdo();
        if (!rbac_table_exists($pdo, 'permission_audit_log')) {
            return;
        }

        $detailsJson = json_encode($details, JSON_UNESCAPED_SLASHES);
        if ($detailsJson === false) {
            $detailsJson = '{}';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO permission_audit_log
             (actor_role_key, actor_user_id, permission_key, decision, decision_source, rbac_mode, is_enforced, request_method, request_uri, ip_address, details_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (string)($actor['role_key'] ?? 'guest'),
            isset($actor['user_id']) && $actor['user_id'] !== null ? (int)$actor['user_id'] : null,
            $permissionKey,
            $allowed ? 'allow' : 'deny',
            $decisionSource,
            $mode,
            $isEnforced ? 1 : 0,
            $_SERVER['REQUEST_METHOD'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null,
            get_client_ip(),
            $detailsJson,
        ]);
    } catch (\Throwable $e) {
        error_log('RBAC decision log error: ' . $e->getMessage());
    }
}

/**
 * Evaluate a permission in legacy, dual, or enforce mode.
 * Returns an array containing allow/deny, source, tier, and enforcement info.
 */
function evaluate_permission(string $permissionKey, ?string $preferredRole = null): array {
    $mode = get_rbac_mode();
    $enforceTier = get_rbac_enforce_tier();
    $actor = get_current_auth_actor($preferredRole);

    $result = [
        'allowed' => false,
        'decision_source' => 'unauthenticated',
        'mode' => $mode,
        'tier' => (int)(get_static_permission_tiers()[$permissionKey] ?? 3),
        'is_enforced' => false,
        'actor' => $actor,
        'rbac_decision' => null,
    ];

    if (empty($actor['authenticated'])) {
        rbac_log_permission_decision($actor, $permissionKey, false, 'unauthenticated', $mode, true);
        return $result;
    }

    if ($mode === 'legacy') {
        $result['allowed'] = true;
        $result['decision_source'] = 'legacy';
        rbac_log_permission_decision($actor, $permissionKey, true, 'legacy', $mode, false, ['reason' => 'legacy_mode']);
        return $result;
    }

    $rbacDecision = null;
    $decisionSource = 'fallback';

    try {
        $pdo = pdo();
        $result['tier'] = get_permission_tier($pdo, $permissionKey);

        if (rbac_tables_available($pdo)) {
            $rbacDecision = rbac_role_permission_decision($pdo, (string)$actor['role_key'], $permissionKey);
            $decisionSource = 'rbac';

            $userId = isset($actor['user_id']) && $actor['user_id'] !== null ? (int)$actor['user_id'] : 0;
            if ($userId > 0 && in_array((string)$actor['role_key'], ['student', 'admin'], true)) {
                $override = rbac_user_override_decision($pdo, $userId, $permissionKey);
                if ($override !== null) {
                    $rbacDecision = $override;
                    $decisionSource = 'rbac_override';
                }
            }
        } else {
            $decisionSource = 'fallback';
        }
    } catch (\Throwable $e) {
        $decisionSource = 'error';
        $rbacDecision = null;
        error_log('RBAC evaluation error: ' . $e->getMessage());
    }

    $isEnforced = ($mode === 'enforce' && $enforceTier >= (int)$result['tier']);
    $result['is_enforced'] = $isEnforced;
    $result['rbac_decision'] = $rbacDecision;

    if ($mode === 'dual') {
        // Non-breaking shadow mode: always allow after successful authentication.
        $result['allowed'] = true;
        if ($decisionSource === 'rbac' || $decisionSource === 'rbac_override') {
            $result['decision_source'] = ((bool)$rbacDecision) ? $decisionSource : 'fallback';
        } else {
            $result['decision_source'] = $decisionSource;
        }
    } elseif ($isEnforced) {
        if ($rbacDecision === null) {
            $result['allowed'] = !is_rbac_fail_closed();
            $result['decision_source'] = $result['allowed'] ? 'fallback' : 'error';
        } else {
            $result['allowed'] = (bool)$rbacDecision;
            $result['decision_source'] = ($decisionSource === 'rbac_override') ? 'rbac_override' : 'rbac';
        }
    } else {
        // Enforce mode is active, but this permission tier is not yet cut over.
        $result['allowed'] = true;
        $result['decision_source'] = 'tier_not_enforced';
    }

    rbac_log_permission_decision(
        $actor,
        $permissionKey,
        (bool)$result['allowed'],
        (string)$result['decision_source'],
        $mode,
        $isEnforced,
        [
            'rbac_decision' => $rbacDecision,
            'enforce_tier' => $enforceTier,
            'permission_tier' => $result['tier'],
        ]
    );

    return $result;
}

/**
 * Permission gate wrapper with response handling.
 * In legacy mode this allows by default to avoid behavior changes.
 */
function require_permission(string $permissionKey, array $options = []): bool {
    $preferredRole = isset($options['actor_role']) ? (string)$options['actor_role'] : null;
    $eval = evaluate_permission($permissionKey, $preferredRole);

    if (!empty($eval['allowed'])) {
        return true;
    }

    $status = isset($options['status']) ? (int)$options['status'] : 403;
    $message = (string)($options['message'] ?? 'Forbidden');
    $response = strtolower((string)($options['response'] ?? 'auto'));

    if ($response === 'auto') {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $response = (strpos($accept, 'application/json') !== false || $xhr === 'xmlhttprequest') ? 'json' : 'http';
    }

    if ($response === 'redirect') {
        $redirect = (string)($options['redirect'] ?? 'login.php');
        http_response_code($status);
        header('Location: ' . $redirect);
        exit;
    }

    if ($response === 'json') {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'permission' => $permissionKey,
        ]);
        exit;
    }

    http_response_code($status);
    echo $message;
    exit;
}

/**
 * Output a small inline <script> that forces the browser to reload the page
 * (hitting the server) whenever the user navigates back/forward.
 * Because the server will send no-cache headers AND the session is destroyed
 * on logout, the reload will trigger the auth check and redirect to login.
 *
 * Call this inside the <head> of every protected HTML page.
 *
 * @param string $loginUrl  Where to redirect if session is gone
 */
function session_guard_script(string $loginUrl = 'login.php'): void {
    $url = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<script>
// Force reload when navigating via browser back/forward so server-side
// auth checks run again instead of showing stale cached content.
window.addEventListener('pageshow', function(e) {
    if (e.persisted) { window.location.reload(); }
});
</script>
HTML;
}

function get_raw_request_body(): string {
    static $rawBody = null;

    if ($rawBody === null) {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) {
            $rawBody = '';
        }
    }

    return $rawBody;
}

function get_json_input(): array {
    static $decodedBody = null;

    if ($decodedBody === null) {
        $decodedBody = json_decode(get_raw_request_body(), true);
        if (!is_array($decodedBody)) {
            $decodedBody = [];
        }
    }

    return $decodedBody;
}

function generate_temporary_student_id(\PDO $pdo): string {
    // Keep within users.student_id VARCHAR(20): "TEMP-" + 10-digit unix time + 4-digit random = 19 chars
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $candidate = 'TEMP-' . time() . (string)random_int(1000, 9999);
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE student_id = ? LIMIT 1');
        $stmt->execute([$candidate]);

        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
    }

    // Rare fallback with deterministic length control.
    return 'TEMP-' . substr(bin2hex(random_bytes(8)), 0, 12);
}

function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check for CSRF token in POST data, JSON body, or HTTP header
        $token = $_POST['csrf_token'] ?? '';
        
        // If not in POST, check JSON body
        if (empty($token)) {
            $json = get_json_input();
            $token = $json['csrf_token'] ?? '';
        }
        
        // If not in JSON body, check HTTP header
        if (empty($token)) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }
        
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            exit('Invalid CSRF token.');
        }
    }
}

function get_jwt_secret(): string {
    $secret = (string)env('JWT_SECRET', '');
    $legacyFallbackSecret = 'pcurfid2-default-secret-change-in-production';

    // Keep explicitly configured secrets for backward compatibility,
    // but log when they are weak so administrators can rotate them.
    if ($secret !== '' && $secret !== $legacyFallbackSecret) {
        if (strlen($secret) < 32) {
            error_log('[PCU RFID] Weak JWT_SECRET detected (length < 32). Configure a stronger secret in .env.');
        }
        return $secret;
    }

    // Avoid a globally known static fallback. Derive a per-install secret
    // from local configuration so existing deployments keep functioning.
    $derivedSeed = implode('|', [
        DB_HOST,
        DB_NAME,
        DB_USER,
        DB_PASS,
        __DIR__,
        (string)env('APP_URL', ''),
    ]);
    $derivedFallback = hash('sha256', $derivedSeed);

    error_log('[PCU RFID] Missing or legacy JWT_SECRET detected. Using deployment-derived fallback secret. Configure JWT_SECRET in .env.');
    return $derivedFallback;
}

function is_password_hash_string(string $value): bool {
    $info = password_get_info($value);
    return !empty($info['algo']);
}

function normalize_env_password(string $value): string {
    return is_password_hash_string($value) ? $value : password_hash($value, PASSWORD_ARGON2ID);
}

function apply_cors_headers(array $methods, array $headers = []): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $currentOrigin = '';
    if (!empty($_SERVER['HTTP_HOST'])) {
        $currentOrigin = ($isHttps ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    }

    $configuredOrigins = (string)env('APP_ALLOWED_ORIGINS', env('APP_URL', $currentOrigin));
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $configuredOrigins))));

    if (!in_array($origin, $allowedOrigins, true)) {
        header('Vary: Origin');
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
    if ($headers !== []) {
        header('Access-Control-Allow-Headers: ' . implode(', ', $headers));
    }
}

function send_api_security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function require_same_origin_api_request(): void {
    $enforce = filter_var(env('API_ENFORCE_SAME_ORIGIN', 'true'), FILTER_VALIDATE_BOOLEAN);
    if (!$enforce) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $currentOrigin = '';
    if (!empty($_SERVER['HTTP_HOST'])) {
        $currentOrigin = ($isHttps ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    }

    $configuredOrigins = (string)env('APP_ALLOWED_ORIGINS', env('APP_URL', $currentOrigin));
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $configuredOrigins))));
    if ($currentOrigin !== '' && !in_array($currentOrigin, $allowedOrigins, true)) {
        $allowedOrigins[] = $currentOrigin;
    }

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));

    $candidateOrigin = '';
    if ($origin !== '') {
        $candidateOrigin = $origin;
    } elseif ($referer !== '') {
        $parts = parse_url($referer);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $candidateOrigin = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $candidateOrigin .= ':' . $parts['port'];
            }
        }
    }

    if ($candidateOrigin === '' || !in_array($candidateOrigin, $allowedOrigins, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'FORBIDDEN_REQUEST']);
        exit;
    }
}

/**
 * XSS Protection Helper - Escape output for safe HTML display
 * Use this for ALL user-generated content displayed in HTML
 * 
 * @param mixed $string The value to escape (handles null safely)
 * @return string The escaped string safe for HTML output
 */
function e($string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Load environment variables from .env file
 */
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $env = parse_ini_file($envFile);
} else {
    // Fallback for backward compatibility
    $env = [];
}

/**
 * Helper function to get environment variable with fallback
 */
if (!function_exists('env')) {
    function env($key, $default = '') {
        global $env;
        return isset($env[$key]) ? $env[$key] : $default;
    }
}

// Database config (load from .env for security)
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_NAME', env('DB_NAME', 'pcu_rfid2'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// App debug toggle for safe error display
define('APP_DEBUG', filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));

// Enable error logging
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

// SMTP config (load from .env for security)
define('SMTP_HOST', env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', env('SMTP_PORT', 587));
define('SMTP_USER', env('SMTP_USER', 'jeysidelima04@gmail.com'));
define('SMTP_PASS', env('SMTP_PASS', ''));
define('SMTP_FROM', env('SMTP_FROM', 'jeysidelima04@gmail.com'));
define('SMTP_FROM_NAME', env('SMTP_FROM_NAME', 'PCU RFID System'));

function pdo(): \PDO {
    static $pdo;
    if ($pdo instanceof \PDO) return $pdo;

    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        
        $pdo = new \PDO($dsn, DB_USER, DB_PASS, $options);
        
        return $pdo;
    } catch (\PDOException $e) {
        error_log("[PCU RFID] Database error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Auto-setup admin account from .env configuration.
 *
 * SECURITY: This function is intentionally DISABLED unless ADMIN_AUTO_SETUP=true
 * is explicitly set in .env. Setting that flag on a running production server
 * allows anyone who can write to .env to silently elevate a DB account.
 * Only enable during initial installation, then set ADMIN_AUTO_SETUP=false.
 */
function setup_admin_account(\PDO $pdo): void {
    // Hard gate — must be explicitly opted-in
    if (!filter_var(env('ADMIN_AUTO_SETUP', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        return;
    }

    try {
        $adminEmail = env('ADMIN_EMAIL', '');
        $adminPassword = env('ADMIN_PASSWORD', '');
        $adminName = env('ADMIN_NAME', 'System Administrator');
        $normalizedAdminPassword = normalize_env_password($adminPassword);
        
        // Only proceed if admin credentials are configured
        if (empty($adminEmail) || empty($adminPassword)) {
            return;
        }
        
        // Check if admin already exists
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE email = ? AND role = 'Admin' LIMIT 1");
        $stmt->execute([strtolower(trim($adminEmail))]);
        $existingAdmin = $stmt->fetch();
        
        if ($existingAdmin) {
            // Admin exists - check if password needs updating
            $passwordMatches = is_password_hash_string($adminPassword)
                ? hash_equals($normalizedAdminPassword, $existingAdmin['password'])
                : password_verify($adminPassword, $existingAdmin['password']);

            if (!$passwordMatches) {
                // Update password
                $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $updateStmt->execute([$normalizedAdminPassword, $existingAdmin['id']]);
                error_log("[PCU RFID] Admin account password updated from .env configuration");
            }
        } else {
            // Create new admin account
            $hashedPassword = $normalizedAdminPassword;
            
            // Check which columns exist in users table
            $columnsStmt = $pdo->query("SHOW COLUMNS FROM users");
            $columns = $columnsStmt->fetchAll(\PDO::FETCH_COLUMN);
            $hasFirstName = in_array('first_name', $columns);
            
            if ($hasFirstName) {
                // New schema with first_name/last_name
                $nameParts = explode(' ', $adminName, 2);
                $firstName = $nameParts[0];
                $lastName = $nameParts[1] ?? '';
                
                $insertStmt = $pdo->prepare("
                    INSERT INTO users (email, password, first_name, last_name, role, status, google_id, student_id) 
                    VALUES (?, ?, ?, ?, 'Admin', 'Active', NULL, 'ADMIN-001')
                ");
                $insertStmt->execute([strtolower(trim($adminEmail)), $hashedPassword, $firstName, $lastName]);
            } else {
                // Old schema with name column
                $insertStmt = $pdo->prepare("
                    INSERT INTO users (email, password, name, role, status, google_id, student_id) 
                    VALUES (?, ?, ?, 'Admin', 'Active', NULL, 'ADMIN-001')
                ");
                $insertStmt->execute([strtolower(trim($adminEmail)), $hashedPassword, $adminName]);
            }
            error_log("[PCU RFID] Admin account created from .env configuration");
        }
    } catch (\PDOException $e) {
        // Silently fail - don't break the application if admin setup fails
        error_log("[PCU RFID] Admin setup error: " . $e->getMessage());
    }
}

// Minimal mailer via PHPMailer
// Install with: composer require phpmailer/phpmailer
// Require Composer autoload if available; otherwise provide a helpful error when used.
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

function sendMail(string $to, string $subject, string $htmlBody, bool $returnError = false) {
    // If PHPMailer isn't installed via Composer, log and return false.
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        $msg = 'PHPMailer not installed. Run: composer require phpmailer/phpmailer';
        error_log($msg);
        return $returnError ? $msg : false;
    }

    try {
        // PHPMailer is loaded via Composer autoload - suppress IDE warnings
        $phpMailerClass = '\\PHPMailer\\PHPMailer\\PHPMailer';
        /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
        $mail = new $phpMailerClass(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = SMTP_PORT;

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Subject  = $subject;
        $mail->Body     = $htmlBody;
        $mail->AltBody  = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $htmlBody));

        $mail->send();
        return true;
    } catch (\Exception $e) {
        $msg = 'Mail error: ' . $e->getMessage();
        error_log($msg);
        return $returnError ? $msg : false;
    }
}

/**
 * Rate limiting function - prevents brute force attacks.
 * Primary: IP-based tracking stored in the database (survives session rotation).
 * Fallback: Session-based tracking (used if DB table is not yet created).
 *
 * @param string $action       Action identifier (e.g. 'login', 'reset_password')
 * @param int    $max_attempts Maximum allowed attempts within the window
 * @param int    $time_window  Time window in seconds (default 15 minutes)
 * @return bool True if the request is allowed, false if rate-limited
 */
function check_rate_limit(string $action, int $max_attempts = 5, int $time_window = 900): bool {
    $ip = get_client_ip();
    $identifier = $action . '|' . $ip;
    $now = time();

    // ---- PRIMARY: DB-backed IP rate limiting ----
    try {
        $pdo = pdo();

        // Clean expired rows periodically (1-in-100 chance per request, avoids a scheduled job)
        if (mt_rand(1, 100) === 1) {
            $pdo->prepare("DELETE FROM ip_rate_limits WHERE blocked_until > 0 AND blocked_until < ?")
                ->execute([$now]);
        }

        $stmt = $pdo->prepare("SELECT * FROM ip_rate_limits WHERE identifier = ? LIMIT 1");
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();

        if ($row) {
            // Currently blocked?
            if ($row['blocked_until'] > $now) {
                $remaining = $row['blocked_until'] - $now;
                error_log("Rate limit (DB): {$action} from {$ip} blocked for {$remaining}s");
                log_rate_limit_security_alert(
                    $action,
                    $identifier,
                    $ip,
                    (int)($row['attempts'] ?? 0),
                    $max_attempts,
                    (int)$row['blocked_until'],
                    'warning',
                    ['source' => 'db_blocked_window']
                );
                return false;
            }

            // Window expired — reset the counter
            if (($now - $row['first_attempt']) > $time_window) {
                $pdo->prepare("UPDATE ip_rate_limits SET attempts = 1, first_attempt = ?, blocked_until = 0 WHERE identifier = ?")
                    ->execute([$now, $identifier]);
                return true;
            }

            // Increment
            $newAttempts = (int)$row['attempts'] + 1;
            if ($newAttempts > $max_attempts) {
                $blockedUntil = $now + $time_window;
                $pdo->prepare("UPDATE ip_rate_limits SET attempts = ?, blocked_until = ? WHERE identifier = ?")
                    ->execute([$newAttempts, $blockedUntil, $identifier]);
                error_log("Rate limit (DB): {$action} from {$ip} exceeded {$max_attempts} attempts — blocked until " . date('H:i:s', $blockedUntil));
                log_rate_limit_security_alert(
                    $action,
                    $identifier,
                    $ip,
                    $newAttempts,
                    $max_attempts,
                    $blockedUntil,
                    'critical',
                    ['source' => 'db_threshold_exceeded']
                );
                return false;
            }

            $pdo->prepare("UPDATE ip_rate_limits SET attempts = ? WHERE identifier = ?")
                ->execute([$newAttempts, $identifier]);
        } else {
            // First attempt for this identifier
            $pdo->prepare("INSERT INTO ip_rate_limits (identifier, attempts, first_attempt, blocked_until) VALUES (?, 1, ?, 0)")
                ->execute([$identifier, $now]);
        }

        return true;

    } catch (\Throwable $e) {
        error_log("Rate limit DB error (falling back to session): " . $e->getMessage());
        // ---- FALLBACK: session-based rate limiting ----
    }

    // ---- FALLBACK: Session-based rate limiting ----
    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }
    $sessionKey = $action . '_' . $ip;
    if (!isset($_SESSION['rate_limits'][$sessionKey])) {
        $_SESSION['rate_limits'][$sessionKey] = ['attempts' => 0, 'first_attempt' => $now, 'blocked_until' => 0];
    }
    $limit = &$_SESSION['rate_limits'][$sessionKey];

    if ($limit['blocked_until'] > $now) {
        log_rate_limit_security_alert(
            $action,
            $identifier,
            $ip,
            (int)$limit['attempts'],
            $max_attempts,
            (int)$limit['blocked_until'],
            'warning',
            ['source' => 'session_blocked_window']
        );
        return false;
    }
    if ($now - $limit['first_attempt'] > $time_window) {
        $limit = ['attempts' => 0, 'first_attempt' => $now, 'blocked_until' => 0];
    }
    $limit['attempts']++;
    if ($limit['attempts'] > $max_attempts) {
        $limit['blocked_until'] = $now + $time_window;
        log_rate_limit_security_alert(
            $action,
            $identifier,
            $ip,
            (int)$limit['attempts'],
            $max_attempts,
            (int)$limit['blocked_until'],
            'critical',
            ['source' => 'session_threshold_exceeded']
        );
        return false;
    }
    return true;
}

/**
 * Reset rate limit for successful action.
 * Clears both the DB record and the session fallback.
 */
function reset_rate_limit(string $action): void {
    $ip      = get_client_ip();
    $identifier = $action . '|' . $ip;

    // Clear DB record
    try {
        $pdo = pdo();
        $pdo->prepare("DELETE FROM ip_rate_limits WHERE identifier = ?")
            ->execute([$identifier]);
    } catch (\Throwable $e) {
        error_log("Rate limit reset DB error: " . $e->getMessage());
    }

    // Clear session fallback
    $sessionKey = $action . '_' . $ip;
    if (isset($_SESSION['rate_limits'][$sessionKey])) {
        unset($_SESSION['rate_limits'][$sessionKey]);
    }
}

// ============================================================================
// PHASE 1: LOST RFID TRACKING HELPER FUNCTIONS
// ============================================================================

/**
 * Check if RFID card is active and NOT lost for a student
 * Used by gate_scan.php to determine if violations should be recorded
 * 
 * @param int $studentId User ID of the student
 * @param string $rfidUid RFID card UID
 * @return bool True if card is Active and not lost, false otherwise
 */
function is_rfid_active_for_student(int $studentId, string $rfidUid): bool {
    try {
        $pdo = pdo();
        $stmt = $pdo->prepare("
            SELECT is_active, is_lost 
            FROM rfid_cards 
            WHERE user_id = ? AND rfid_uid = ?
            LIMIT 1
        ");
        $stmt->execute([$studentId, $rfidUid]);
        $card = $stmt->fetch();
        
        if (!$card) {
            return false;
        }
        
        // Card must be active AND not lost
        return ($card['is_active'] == 1 && $card['is_lost'] == 0);
    } catch (\PDOException $e) {
        error_log("Error checking RFID status: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if an RFID card is marked as lost
 * 
 * @param string $rfidUid RFID card UID
 * @return array|null Returns card info if lost, null otherwise
 */
function is_rfid_lost(string $rfidUid): ?array {
    try {
        $pdo = pdo();
        $stmt = $pdo->prepare("
            SELECT r.*, u.student_id, u.name,
                   admin.name AS reported_by_name
            FROM rfid_cards r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN users admin ON r.lost_reported_by = admin.id
            WHERE r.rfid_uid = ? AND r.is_lost = 1
            LIMIT 1
        ");
        $stmt->execute([$rfidUid]);
        $card = $stmt->fetch();
        
        return $card ?: null;
    } catch (\PDOException $e) {
        error_log("Error checking if RFID is lost: " . $e->getMessage());
        return null;
    }
}

/**
 * Mark an RFID card as lost
 * 
 * @param int $cardId RFID card ID
 * @param int $adminId Admin user ID who is marking it lost
 * @param string $reason Reason for marking as lost
 * @return bool True on success, false on failure
 */
function mark_rfid_lost(int $cardId, int $adminId, string $reason): bool {
    try {
        $pdo = pdo();
        $pdo->beginTransaction();
        
        // Update rfid_cards table
        $stmt = $pdo->prepare("
            UPDATE rfid_cards 
            SET is_lost = 1, 
                lost_at = NOW(), 
                lost_reason = ?, 
                lost_reported_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$reason, $adminId, $cardId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            return false;
        }
        
        // Log to audit history
        $stmt = $pdo->prepare("
            INSERT INTO rfid_status_history 
            (rfid_card_id, user_id, status_change, changed_at, changed_by, reason, ip_address)
            SELECT id, user_id, 'LOST', NOW(), ?, ?, ?
            FROM rfid_cards WHERE id = ?
        ");
        $stmt->execute([$adminId, $reason, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $cardId]);
        
        $pdo->commit();
        return true;
    } catch (\PDOException $e) {
        $pdo->rollBack();
        error_log("Error marking RFID as lost: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark an RFID card as found (unmark lost)
 * 
 * @param int $cardId RFID card ID
 * @param int $adminId Admin user ID who is marking it found
 * @return bool True on success, false on failure
 */
function mark_rfid_found(int $cardId, int $adminId): bool {
    try {
        $pdo = pdo();
        $pdo->beginTransaction();
        
        // Get lost reason for audit log
        $stmt = $pdo->prepare("SELECT lost_reason FROM rfid_cards WHERE id = ?");
        $stmt->execute([$cardId]);
        $previousReason = $stmt->fetchColumn() ?: '';
        
        // Update rfid_cards table
        $stmt = $pdo->prepare("
            UPDATE rfid_cards 
            SET is_lost = 0, 
                found_at = NOW(), 
                found_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$adminId, $cardId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            return false;
        }
        
        // Log to audit history
        $stmt = $pdo->prepare("
            INSERT INTO rfid_status_history 
            (rfid_card_id, user_id, status_change, changed_at, changed_by, reason, notes, ip_address)
            SELECT id, user_id, 'FOUND', NOW(), ?, ?, 'Previously lost', ?
            FROM rfid_cards WHERE id = ?
        ");
        $stmt->execute([$adminId, $previousReason, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $cardId]);
        
        $pdo->commit();
        return true;
    } catch (\PDOException $e) {
        $pdo->rollBack();
        error_log("Error marking RFID as found: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// PHASE 2: GUARDIAN NOTIFICATION HELPER FUNCTIONS
// ============================================================================

/**
 * Check if guardian notifications are globally enabled
 * 
 * @return bool True if enabled, false otherwise
 */
function are_guardian_notifications_enabled(): bool {
    try {
        $pdo = pdo();
        $stmt = $pdo->query("SELECT value FROM system_settings WHERE setting_key = 'guardian_notifications_enabled' LIMIT 1");
        $result = $stmt->fetchColumn();
        return $result === '1' || $result === 'true';
    } catch (\PDOException $e) {
        // If table doesn't exist yet (before Phase 2 migration), return false
        return false;
    }
}

/**
 * Check if a notification can be sent (rate limiting - 10 minutes)
 * 
 * @param int $studentId Student user ID
 * @param string $type Notification type (e.g., 'entry')
 * @return bool True if notification can be sent, false if rate limited
 */
function can_send_guardian_notification(int $studentId, string $type = 'entry'): bool {
    try {
        $pdo = pdo();
        $stmt = $pdo->prepare("
            SELECT sent_at 
            FROM notification_logs 
            WHERE student_id = ? AND notification_type = ? 
            ORDER BY sent_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$studentId, $type]);
        $lastSent = $stmt->fetchColumn();
        
        if (!$lastSent) {
            return true; // No previous notification
        }
        
        // Check if 10 minutes have passed
        $lastSentTime = strtotime($lastSent);
        $currentTime = time();
        $timeDiff = $currentTime - $lastSentTime;
        
        return $timeDiff >= 600; // 10 minutes = 600 seconds
    } catch (\PDOException $e) {
        error_log("Error checking notification rate limit: " . $e->getMessage());
        return false; // Fail safe - don't send if error
    }
}

/**
 * Send entry notification to student's guardians
 * 
 * @param int $studentId Student user ID
 * @param string $entryTime Entry timestamp
 * @return bool True if at least one notification sent, false otherwise
 */
function send_guardian_entry_notification(int $studentId, string $entryTime): bool {
    // Check global setting
    if (!are_guardian_notifications_enabled()) {
        return false;
    }
    
    // Check rate limiting
    if (!can_send_guardian_notification($studentId, 'entry')) {
        error_log("Guardian notification rate limited for student ID: $studentId");
        return false;
    }
    
    try {
        $pdo = pdo();
        
        // Get student info
        $stmt = $pdo->prepare("
            SELECT first_name, last_name, student_id, course, year_level 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        
        if (!$student) {
            return false;
        }
        
        // Get active guardians with notifications enabled
        $stmt = $pdo->prepare("
            SELECT g.id, g.email, g.first_name, g.last_name, ns.entry_notification
            FROM guardians g
            JOIN student_guardians sg ON g.id = sg.guardian_id
            LEFT JOIN notification_settings ns ON g.id = ns.guardian_id
            WHERE sg.student_id = ? 
              AND sg.is_primary = 1
              AND (ns.entry_notification IS NULL OR ns.entry_notification = 1)
        ");
        $stmt->execute([$studentId]);
        $guardians = $stmt->fetchAll();
        
        if (empty($guardians)) {
            return false;
        }
        
        $sentCount = 0;
        foreach ($guardians as $guardian) {
            // Queue notification
            $stmt = $pdo->prepare("
                INSERT INTO notification_queue 
                (student_id, guardian_id, notification_type, scheduled_for, data)
                VALUES (?, ?, 'entry', NOW(), ?)
            ");
            
            $data = json_encode([
                'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                'student_id' => $student['student_id'],
                'course' => $student['course'],
                'year_level' => $student['year_level'],
                'entry_time' => $entryTime
            ]);
            
            $stmt->execute([$studentId, $guardian['id'], $data]);
            
            // Send email immediately
            $subject = "Campus Entry Alert - " . $student['first_name'] . " " . $student['last_name'];
            $body = "
                <h2>Campus Entry Notification</h2>
                <p>Dear {$guardian['first_name']} {$guardian['last_name']},</p>
                <p>This is to inform you that your child/ward has entered the campus:</p>
                <ul>
                    <li><strong>Student Name:</strong> {$student['first_name']} {$student['last_name']}</li>
                    <li><strong>Student ID:</strong> {$student['student_id']}</li>
                    <li><strong>Course:</strong> {$student['course']}</li>
                    <li><strong>Year Level:</strong> {$student['year_level']}</li>
                    <li><strong>Entry Time:</strong> $entryTime</li>
                </ul>
                <p>This is an automated notification from the PCU RFID System.</p>
                <p>To manage notification preferences, please contact the admin office.</p>
            ";
            
            $emailSent = sendMail($guardian['email'], $subject, $body);
            
            if ($emailSent) {
                // Log successful notification
                $stmt = $pdo->prepare("
                    INSERT INTO notification_logs 
                    (student_id, guardian_id, notification_type, sent_at, status)
                    VALUES (?, ?, 'entry', NOW(), 'sent')
                ");
                $stmt->execute([$studentId, $guardian['id']]);
                
                // Update queue status
                $stmt = $pdo->prepare("
                    UPDATE notification_queue 
                    SET status = 'sent', sent_at = NOW() 
                    WHERE student_id = ? AND guardian_id = ? AND notification_type = 'entry' AND status = 'pending'
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$studentId, $guardian['id']]);
                
                $sentCount++;
            } else {
                // Log failed notification
                $stmt = $pdo->prepare("
                    INSERT INTO notification_logs 
                    (student_id, guardian_id, notification_type, sent_at, status)
                    VALUES (?, ?, 'entry', NOW(), 'failed')
                ");
                $stmt->execute([$studentId, $guardian['id']]);
            }
        }
        
        return $sentCount > 0;
    } catch (\PDOException $e) {
        error_log("Error sending guardian notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Send violation notification to a student's primary parent/guardian.
 *
 * @param int $studentId Student user ID
 * @param array<string,mixed> $notice Notice payload (student/violation/disciplinary fields)
 * @return bool True if at least one email was sent
 */
function send_guardian_violation_notification(int $studentId, array $notice): bool {
    // Check global setting
    if (!are_guardian_notifications_enabled()) {
        return false;
    }

    try {
        $pdo = pdo();

        // Get primary guardians with violation notifications enabled
        $stmt = $pdo->prepare("
            SELECT g.id, g.email, g.first_name, g.last_name, ns.violation_notification
            FROM guardians g
            JOIN student_guardians sg ON g.id = sg.guardian_id
            LEFT JOIN notification_settings ns ON g.id = ns.guardian_id
            WHERE sg.student_id = ?
              AND sg.is_primary = 1
              AND (ns.violation_notification IS NULL OR ns.violation_notification = 1)
        ");
        $stmt->execute([$studentId]);
        $guardians = $stmt->fetchAll();

        if (empty($guardians)) {
            return false;
        }

        // Load the email template library only when needed.
        require_once __DIR__ . '/includes/email_templates.php';

        $studentName = (string)($notice['student_name'] ?? 'Student');
        $studentNumber = (string)($notice['student_id'] ?? (string)$studentId);
        $violationName = (string)($notice['violation_name'] ?? 'Violation');
        $violationTypeLabel = (string)($notice['violation_type_label'] ?? 'Unspecified');
        $offenseNumber = (int)($notice['offense_number'] ?? 1);
        $semester = (string)($notice['semester'] ?? '');
        $schoolYear = (string)($notice['school_year'] ?? '');
        $timestamp = (string)($notice['recorded_at'] ?? date('F j, Y g:i A'));

        $disciplinaryCode = (string)($notice['disciplinary_code'] ?? '');
        $disciplinaryTitle = (string)($notice['disciplinary_title'] ?? '');
        $disciplinaryMessage = (string)($notice['disciplinary_message'] ?? '');
        $disciplinaryAction = (string)($notice['disciplinary_action'] ?? '');
        $categoryRationale = (string)($notice['category_rationale'] ?? '');
        $interventionIntent = (string)($notice['intervention_intent'] ?? '');
        $incidentNotes = (string)($notice['description'] ?? '');

        $sentCount = 0;

        foreach ($guardians as $guardian) {
            $guardianEmail = (string)($guardian['email'] ?? '');
            if ($guardianEmail === '') {
                continue;
            }

            $guardianName = trim((string)($guardian['first_name'] ?? '') . ' ' . (string)($guardian['last_name'] ?? ''));
            if ($guardianName === '') {
                $guardianName = 'Parent/Guardian';
            }

            // Queue notification
            $queueStmt = $pdo->prepare("
                INSERT INTO notification_queue
                (student_id, guardian_id, notification_type, scheduled_for, data)
                VALUES (?, ?, 'violation', NOW(), ?)
            ");
            $queueStmt->execute([
                $studentId,
                (int)$guardian['id'],
                json_encode($notice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $subject = "SSO Violation Notice - {$studentName} - {$violationName} (Offense #" . max(1, $offenseNumber) . ")";

            $html = emailGuardianViolationNotice(
                $guardianName,
                $studentName,
                $studentNumber,
                $violationName,
                $violationTypeLabel,
                $offenseNumber,
                $semester,
                $schoolYear,
                $timestamp,
                $disciplinaryCode,
                $disciplinaryTitle,
                $disciplinaryMessage,
                $disciplinaryAction,
                $categoryRationale,
                $interventionIntent,
                $incidentNotes
            );

            $emailSent = sendMail($guardianEmail, $subject, $html);

            if ($emailSent) {
                $logStmt = $pdo->prepare("
                    INSERT INTO notification_logs
                    (student_id, guardian_id, notification_type, sent_at, status)
                    VALUES (?, ?, 'violation', NOW(), 'sent')
                ");
                $logStmt->execute([$studentId, (int)$guardian['id']]);

                $updateQueueStmt = $pdo->prepare("
                    UPDATE notification_queue
                    SET status = 'sent', sent_at = NOW()
                    WHERE student_id = ? AND guardian_id = ? AND notification_type = 'violation' AND status = 'pending'
                    ORDER BY id DESC LIMIT 1
                ");
                $updateQueueStmt->execute([$studentId, (int)$guardian['id']]);

                $sentCount++;
            } else {
                $logStmt = $pdo->prepare("
                    INSERT INTO notification_logs
                    (student_id, guardian_id, notification_type, sent_at, status)
                    VALUES (?, ?, 'violation', NOW(), 'failed')
                ");
                $logStmt->execute([$studentId, (int)$guardian['id']]);
            }
        }

        return $sentCount > 0;
    } catch (Throwable $e) {
        error_log('Error sending guardian violation notification: ' . $e->getMessage());
        return false;
    }
}
