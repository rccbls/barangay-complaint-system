<?php
/**
 * Application bootstrap (Phase 0).
 *
 * Loaded by every entry point. Keeps only cross-cutting setup:
 *   - .env loading (credentials stay out of source control)
 *   - timezone convention (see config/app.php)
 *   - error display policy
 *   - URL/base-path helper
 *
 * Authentication, CSRF, and the output-escaping helper are Phase 2 and are
 * intentionally NOT defined here.
 */
declare(strict_types=1);

if (defined('APP_ROOT')) {
    return;
}

define('APP_ROOT', dirname(__DIR__));

/**
 * Parse a simple KEY=VALUE file. Lines starting with # are comments.
 * Matching single or double quotes around a value are stripped.
 *
 * @return array<string,string>
 */
function parse_env_file(string $file): array
{
    $values = [];
    if (!file_exists($file)) {
        return $values;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') {
            $values[$key] = $value;
        }
    }
    return $values;
}

/** Read a setting from .env, then the OS environment, then the default. */
function env(string $key, ?string $default = null): ?string
{
    static $values = null;
    if ($values === null) {
        $values = parse_env_file(APP_ROOT . '/.env');
    }
    if (array_key_exists($key, $values)) {
        return $values[$key];
    }
    $os = getenv($key);
    return $os !== false ? $os : $default;
}

/** 'local' enables developer conveniences. Anything else is treated as production. */
function app_is_local(): bool
{
    return env('APP_ENV', 'production') === 'local';
}

require_once APP_ROOT . '/config/app.php';

// Timezone convention: PHP works in UTC; display converts to Philippine time.
date_default_timezone_set(APP_STORAGE_TIMEZONE);

// Error policy: show errors only in local development; always log.
error_reporting(E_ALL);
ini_set('display_errors', app_is_local() ? '1' : '0');
ini_set('log_errors', '1');

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
}

/** Output-escaping helper (defined once, used by every view). Escape at output time. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Character count that does not need the mbstring extension; null if not valid UTF-8. */
function str_char_length(string $value): ?int
{
    $n = preg_match_all('/./su', $value);
    return $n === false ? null : $n;
}

/** Read a request value as a string; arrays or missing keys become ''. */
function input_string(array $source, string $key): string
{
    $v = $source[$key] ?? '';
    return is_string($v) ? $v : '';
}

/** Base URL path prefix (e.g. "/barangay-complaint-system/public" under XAMPP htdocs; "" with a virtual host). */
function app_base_path(): string
{
    return rtrim((string) env('APP_BASE_PATH', ''), '/');
}

/** Build a URL path under the application's base path. */
function app_url(string $path = ''): string
{
    return app_base_path() . '/' . ltrim($path, '/');
}

/** Redirect to a path under the application base path and stop. Internal paths only. */
function redirect(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

/**
 * Convert a stored UTC datetime string (as written by MySQL) to the display timezone.
 * Returns '' for null/empty input.
 */
function format_display_datetime(?string $utcDatetime, string $format = 'Y-m-d H:i'): string
{
    if ($utcDatetime === null || $utcDatetime === '') {
        return '';
    }
    $dt = new DateTimeImmutable($utcDatetime, new DateTimeZone(APP_STORAGE_TIMEZONE));
    return $dt->setTimezone(new DateTimeZone(APP_DISPLAY_TIMEZONE))->format($format);
}
