<?php
/**
 * Controlled first-administrator setup (Phase 1).
 *
 *   php database/create_admin.php
 *
 * - Runs only from the command line.
 * - Asks for a username, full name, and password (typed twice).
 * - Stores ONLY a password_hash() value; the password is never written to SQL,
 *   files, logs, or the screen.
 * - Refuses to run if an Administrator already exists. Further staff accounts are
 *   created through the application in Phase 4.
 *
 * Use a synthetic name/username for development (RULES.md §10.8).
 *
 * Password input is hidden on Linux/macOS terminals. On Windows (XAMPP) PHP cannot
 * hide input, so the password is visible while typed; clear the screen afterwards.
 * For unattended test setups only, the password may instead be supplied in the
 * CREATE_ADMIN_PASSWORD environment variable.
 *
 * PLACEHOLDER password rule (not client-approved): at least 12 characters.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

const ADMIN_MIN_PASSWORD_LENGTH = 12; // placeholder pending client policy

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/** Character count without needing the mbstring extension; null if not valid UTF-8. */
function char_length(string $value): ?int
{
    $n = preg_match_all('/./su', $value);
    return $n === false ? null : $n;
}

function ask(string $label, bool $hidden = false): string
{
    echo $label;
    $canHide = $hidden && DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    if ($canHide) {
        system('stty -echo');
    }
    $value = fgets(STDIN);
    if ($canHide) {
        system('stty echo');
        echo "\n";
    }
    return $value === false ? '' : rtrim($value, "\r\n");
}

try {
    $pdo = db_connect(true);
} catch (PDOException $e) {
    fail('Cannot connect to the database: ' . $e->getMessage() . "\nRun php database/install.php first.");
}

try {
    $roleStmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
    $roleStmt->execute(['Administrator']);
    $roleId = $roleStmt->fetchColumn();
    if ($roleId === false) {
        fail('The Administrator role is missing. Run php database/install.php (it seeds the roles).');
    }

    $existing = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = ?');
    $existing->execute([(int) $roleId]);
    if ((int) $existing->fetchColumn() > 0) {
        fail('An Administrator account already exists. Nothing was changed.');
    }
} catch (PDOException $e) {
    fail('Database error: ' . $e->getMessage() . "\nRun php database/install.php first.");
}

$username = ask('Administrator username (3-60 letters, digits, . _ -): ');
if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
    fail('Invalid username.');
}

$fullName = trim(ask('Full name (synthetic for development): '));
$nameLen = char_length($fullName);
if ($fullName === '' || $nameLen === null || $nameLen > 150) {
    fail('Full name is required (max 150 characters).');
}

$envPassword = getenv('CREATE_ADMIN_PASSWORD');
if ($envPassword !== false && $envPassword !== '') {
    $password = $envPassword;
} else {
    $password = ask('Password (min ' . ADMIN_MIN_PASSWORD_LENGTH . ' characters): ', true);
    if (ask('Repeat password: ', true) !== $password) {
        fail('Passwords do not match.');
    }
}

$pwLen = char_length($password);
if ($pwLen === null || $pwLen < ADMIN_MIN_PASSWORD_LENGTH) {
    fail('Password must be at least ' . ADMIN_MIN_PASSWORD_LENGTH . ' characters.');
}
if (strcasecmp($password, $username) === 0) {
    fail('Password must not equal the username.');
}

$hash = password_hash($password, PASSWORD_DEFAULT);
unset($password, $envPassword);

try {
    $insert = $pdo->prepare(
        'INSERT INTO users (role_id, full_name, username, password_hash, is_active) VALUES (?, ?, ?, ?, 1)'
    );
    $insert->execute([(int) $roleId, $fullName, $username, $hash]);
} catch (PDOException $e) {
    fail('Could not create the administrator: ' . $e->getMessage());
}

echo "Administrator '$username' created (user_id " . $pdo->lastInsertId() . ").\n";
echo "Store the password safely; it cannot be recovered from the database.\n";
