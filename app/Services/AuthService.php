<?php
/**
 * Authentication logic (Phase 2): resident registration, sign-in, sign-out, current user.
 * RULES.md §2 and §3.
 */
declare(strict_types=1);

/** Valid bcrypt hash used to keep sign-in timing similar when the username does not exist. */
const AUTH_DUMMY_HASH = '$2y$10$16KbueLJcOBv7nya0RCckefz78SNA1IxquPwU8wPPVOTqXDw/9U/m';

/**
 * Validate resident registration input. Fields are a PLACEHOLDER minimum
 * (full name, username, password) until the client approves registration fields.
 *
 * @return array{0: array<string,string>, 1: array{full_name:string,username:string,password:string}}
 *         [errors keyed by field, cleaned values]
 */
function auth_validate_registration(array $input): array
{
    $errors = [];

    $fullName = text_normalize(input_string($input, 'full_name'));
    if (($nameError = person_name_error($fullName)) !== null) {
        $errors['full_name'] = $nameError;
    }

    $username = trim(input_string($input, 'username'));
    if (!username_is_valid($username)) {
        $errors['username'] = 'Use 3 to 60 letters, numbers, dots, underscores, or hyphens.';
    }

    $password = input_string($input, 'password');
    $confirm  = input_string($input, 'password_confirm');
    if (($pwError = password_policy_error($password, $username)) !== null) {
        $errors['password'] = $pwError;
    } elseif ($confirm !== $password) {
        $errors['password_confirm'] = 'Passwords do not match.';
    }

    return [$errors, ['full_name' => $fullName, 'username' => $username, 'password' => $password]];
}

/**
 * Create a Resident account from validated input.
 * @return array{ok:bool, errors?:array<string,string>}
 */
function auth_register_resident(PDO $pdo, array $clean): array
{
    $hash = password_hash($clean['password'], PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        // Account and resident profile are created together or not at all (Phase 3).
        $userId = user_create_resident($pdo, $clean['full_name'], $clean['username'], $hash);
        resident_profile_create($pdo, $userId, $clean['full_name']);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'errors' => ['username' => 'That username is already taken.']];
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true];
}

/**
 * Attempt sign-in. On success the session ID is regenerated (prevents session fixation).
 * @return array{ok:bool, error?:string}
 */
function auth_login(PDO $pdo, string $username, string $password): array
{
    $username = trim($username);
    $generic  = ['ok' => false, 'error' => 'Incorrect username or password.'];

    $user = null;
    if ($username !== '' && strlen($username) <= 60 && strlen($password) <= 1000) {
        $user = user_find_for_login($pdo, $username);
    }

    // Always run a password_verify so a missing user costs about the same as a wrong password.
    $verified = password_verify($password, $user['password_hash'] ?? AUTH_DUMMY_HASH);
    if ($user === null || !$verified) {
        return $generic;
    }

    // Reached only with the correct password, so this message does not reveal accounts to guessers.
    if ((int) $user['is_active'] !== 1) {
        return ['ok' => false, 'error' => 'This account is inactive. Please contact the barangay office.'];
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        user_update_password_hash($pdo, (int) $user['user_id'], password_hash($password, PASSWORD_DEFAULT));
    }

    session_boot();
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int) $user['user_id']];   // fresh session data; new CSRF token on next use
    current_user(true);
    return ['ok' => true];
}

/** End the session on the server and expire the cookie. */
function auth_logout(): void
{
    session_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
    current_user(true);
    session_boot(); // brand-new session (used for the "signed out" flash message)
}

/**
 * The signed-in user, reloaded from the database on every request so that deactivation
 * or a role change takes effect immediately (RULES §2.9). Returns null when signed out.
 *
 * @return array{user_id:int,full_name:string,username:string,is_active:int,role_name:string}|null
 */
function current_user(bool $refresh = false): ?array
{
    static $loaded = false;
    static $user = null;
    if ($refresh) {
        $loaded = false;
        $user = null;
    }
    if ($loaded) {
        return $user;
    }
    $loaded = true;

    session_boot();
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id)) {
        return null;
    }
    $found = user_find_by_id(db(), $id);
    if ($found === null || (int) $found['is_active'] !== 1) {
        // Account removed/deactivated: invalidate this session.
        $_SESSION = [];
        session_destroy();
        session_boot();
        return null;
    }
    return $user = $found;
}
