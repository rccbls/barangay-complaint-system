<?php
/**
 * Session, CSRF token, and flash-message helpers (Phase 2). Defined once, reused everywhere.
 */
declare(strict_types=1);

/** Start the session with hardened cookie settings. Safe to call repeatedly. */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');   // reject session IDs the server did not create
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_name('BCS_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,                          // browser-session cookie
        'path'     => app_base_path() === '' ? '/' : app_base_path(),
        'secure'   => $secure,                    // true automatically when served over HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Per-session CSRF token (created on first use). */
function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden form field to place inside every state-changing form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $submitted): bool
{
    return is_string($submitted) && $submitted !== '' && hash_equals(csrf_token(), $submitted);
}

/** Queue a one-time message ($type: success|danger|warning|info). */
function flash_set(string $type, string $message): void
{
    session_boot();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Print and clear queued flash messages. */
function flash_render(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return;
    }
    $allowed = ['success', 'danger', 'warning', 'info'];
    foreach ($_SESSION['flash'] as $f) {
        $type = in_array($f['type'] ?? '', $allowed, true) ? $f['type'] : 'info';
        echo '<div class="alert alert-' . e($type) . '" role="status">' . e((string) ($f['message'] ?? '')) . '</div>';
    }
    unset($_SESSION['flash']);
}
