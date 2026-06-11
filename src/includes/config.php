<?php
// =====================================
// Portal Config
// =====================================

// Load .env file into $_ENV (putenv not required)
(function () {
    $locations = [
        dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . '/.env',
        __DIR__ . '/../../.env',
        __DIR__ . '/../.env',
    ];
    foreach ($locations as $path) {
        if (!is_readable($path)) continue;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            if ($key !== '' && !isset($_ENV[$key])) $_ENV[$key] = $val;
        }
        break;
    }
})();

// Helper: read from $_ENV first, then process env, then fallback
function _env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? (getenv($key) ?: $default);
}

// App environment
define('APP_ENV', _env('APP_ENV', 'local'));
define('IS_PRODUCTION', APP_ENV === 'production');

// Session security
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
// ⚠️ FOR PRODUCTION: enable secure cookies (requires HTTPS)
if (IS_PRODUCTION) {
    ini_set('session.cookie_secure', 1);
}

// Error reporting
if (IS_PRODUCTION) {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
}

// Database
define('DB_HOST', _env('DB_HOST', 'db'));
define('DB_NAME', _env('DB_NAME', 'portal_db'));
define('DB_USER', _env('DB_USER', 'portal_user'));
define('DB_PASS', _env('DB_PASS', 'portal_pass'));

// Monday.com API
define('MONDAY_API_TOKEN', _env('MONDAY_API_TOKEN'));
define('MONDAY_API_URL',   'https://api.monday.com/v2');
define('MONDAY_DOMAIN',    _env('MONDAY_DOMAIN'));
define('MOCK_MONDAY', filter_var(_env('MOCK_MONDAY', 'false'), FILTER_VALIDATE_BOOLEAN));

// App
define('APP_SECRET',  _env('APP_SECRET',  'change_me'));
define('APP_NAME',    _env('APP_NAME',    'Client Portal'));
define('APP_URL',     _env('APP_URL'));
define('AGENCY_NAME', _env('AGENCY_NAME', 'Agency'));

// Brand colors
define('BRAND_PRIMARY_COLOR',   _env('BRAND_PRIMARY_COLOR',   '#6366f1'));
define('BRAND_SECONDARY_COLOR', _env('BRAND_SECONDARY_COLOR', '#111827'));
define('BRAND_PRIMARY_LIGHT',   _env('BRAND_PRIMARY_LIGHT',   '#eef2ff'));

// Brand logo & favicon
define('BRAND_LOGO_INITIALS', _env('BRAND_LOGO_INITIALS', 'A'));
define('BRAND_LOGO_PATH',     _env('BRAND_LOGO_PATH'));
define('FAVICON_URL',         _env('FAVICON_URL'));

// Returns the img src for the agency logo, or null to fall back to initials.
function brand_logo_src(): ?string {
    $path = BRAND_LOGO_PATH;
    if (!$path) return null;
    // External URL — use directly
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    // Local path — only use if the file actually exists
    $abs = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/' . ltrim($path, '/');
    return file_exists($abs) ? $path : null;
}

// Timezone
date_default_timezone_set('Europe/Belgrade');
