<?php
/**
 * Shared input-normalisation and validation helpers (Phase 3).
 * Each returns an error message or null. Callers validate on the server and escape on output.
 */
declare(strict_types=1);

/** Trim and collapse runs of whitespace into single spaces (single-line text fields). */
function text_normalize(string $value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $value));
}

/** Error for a normalised, required person name (1-150 chars, no control characters), else null. */
function person_name_error(string $name): ?string
{
    $len = str_char_length($name);
    if ($name === '' || $len === null) {
        return 'Enter your full name.';
    }
    if ($len > 150 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        return 'Full name must be 150 characters or fewer and contain no control characters.';
    }
    return null;
}

/**
 * Password policy error for a new or reset password, else null.
 * PLACEHOLDER minimum (config/app.php); the maximum is a hard bcrypt limit.
 */
function password_policy_error(string $password, string $username): ?string
{
    $len = str_char_length($password);
    if ($len === null || $len < AUTH_PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . AUTH_PASSWORD_MIN_LENGTH . ' characters.';
    }
    if (strlen($password) > AUTH_PASSWORD_MAX_BYTES) {
        return 'Password is too long (maximum ' . AUTH_PASSWORD_MAX_BYTES . ' bytes).';
    }
    if (strcasecmp($password, $username) === 0) {
        return 'Password must not be the same as the username.';
    }
    return null;
}

/** Username format shared by every account type. */
function username_is_valid(string $username): bool
{
    return (bool) preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username);
}
