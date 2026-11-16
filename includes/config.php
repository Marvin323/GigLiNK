<?php
/**
 * GigLink runtime configuration.
 *
 * In production you can override any of these via environment variables
 * (set them in Apache's httpd.conf with `SetEnv` or in your hosting panel).
 * For local XAMPP, the defaults below "just work".
 */

/* ---------- Environment ---------- */
// 'dev' shows errors on screen; 'prod' logs them to a file instead.
define('GIGLINK_ENV', getenv('GIGLINK_ENV') ?: 'dev');

/* ---------- Database ---------- */
define('GIGLINK_DB_HOST', getenv('GIGLINK_DB_HOST') ?: '127.0.0.1');
define('GIGLINK_DB_PORT', (int)(getenv('GIGLINK_DB_PORT') ?: 3307));
define('GIGLINK_DB_NAME', getenv('GIGLINK_DB_NAME') ?: 'giglink');
define('GIGLINK_DB_USER', getenv('GIGLINK_DB_USER') ?: 'root');
define('GIGLINK_DB_PASS', getenv('GIGLINK_DB_PASS') ?: '');

/* ---------- Base URL (used by email/reset links, OG tags, deep links) ---------- */
// Leave null to auto-detect from the request; set explicitly in prod.
define('GIGLINK_BASE_URL', getenv('GIGLINK_BASE_URL') ?: null);

/* ---------- CORS allow-list for the JSON API ---------- */
// Capacitor WebViews load content from `capacitor://localhost` (iOS) and
// `http://localhost` (Android). List any web origins that embed the API too.
define('GIGLINK_CORS_ORIGINS', getenv('GIGLINK_CORS_ORIGINS')
    ?: 'capacitor://localhost,http://localhost,https://localhost');

/* ---------- Error handling ---------- */
if (GIGLINK_ENV === 'prod') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    @ini_set('log_errors', '1');
    $__log_dir = __DIR__ . '/../logs';
    if (!is_dir($__log_dir)) @mkdir($__log_dir, 0750, true);
    @ini_set('error_log', $__log_dir . '/php-error.log');
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

/* ---------- Base URL helper ---------- */
function base_url(): string {
    if (defined('GIGLINK_BASE_URL') && GIGLINK_BASE_URL) return rtrim(GIGLINK_BASE_URL, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
          || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $proto = $https ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $proto . '://' . $host . '/giglink';
}
