<?php
/**
 * Google OAuth Configuration
 * Loads credentials from .env file for security
 */

// Load environment variables from .env file (if not already loaded)
if (!isset($env)) {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $env = parse_ini_file($envFile);
    } else {
        // Fallback for backward compatibility (if .env doesn't exist)
        $env = [];
    }
}

// Helper function to get environment variable with fallback (only declare if not exists)
if (!function_exists('env')) {
    function env($key, $default = '') {
        global $env;
        return isset($env[$key]) ? $env[$key] : $default;
    }
}

if (!function_exists('app_detect_origin')) {
    function app_detect_origin(): string {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }

        return ($isHttps ? 'https://' : 'http://') . $host;
    }
}

if (!function_exists('app_base_url')) {
    function app_base_url(): string {
        $configured = rtrim((string)env('APP_URL', ''), '/');
        if ($configured !== '') {
            return $configured;
        }

        $detected = rtrim(app_detect_origin(), '/');
        if ($detected !== '') {
            $basePath = trim((string)env('APP_BASE_PATH', '/pcuRFID2'));
            if ($basePath === '') {
                $basePath = '/pcuRFID2';
            }
            if ($basePath[0] !== '/') {
                $basePath = '/' . $basePath;
            }
            return $detected . rtrim($basePath, '/');
        }

        return 'http://localhost/pcuRFID2';
    }
}

// Google OAuth Client ID (from .env)
define('GOOGLE_CLIENT_ID', env('GOOGLE_CLIENT_ID'));

// Google OAuth Client Secret (from .env)
define('GOOGLE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET'));

// Redirect URI: explicit env override first, then base URL callback for current host.
define('GOOGLE_REDIRECT_URI', env('GOOGLE_REDIRECT_URI', app_base_url() . '/google_callback.php'));

// Application name (from .env)
define('GOOGLE_APP_NAME', env('GOOGLE_APP_NAME'));
?>
