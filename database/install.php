<?php
/**
 * Fresh-install script (Phase 1): creates the database, runs schema.sql, then seed.sql.
 *
 *   php database/install.php
 *
 * For a NEW development database only (SCHEMA.md §3). It refuses to run if the
 * `users` table already exists and never drops anything. The database name comes
 * from DB_NAME in .env; the CREATE DATABASE / USE lines in schema.sql are skipped
 * because this script creates and selects DB_NAME itself.
 *
 * Equivalent manual route: import database/schema.sql then database/seed.sql
 * with phpMyAdmin or the mysql client.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

/** Split a SQL file into statements (statements end with ';' at end of line). */
function split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '--')) {
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with($trim, ';')) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

$dbName = db_config()['name'];
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
    fwrite(STDERR, "DB_NAME may contain only letters, digits, and underscores.\n");
    exit(1);
}

try {
    $pdo = db_connect(false);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to MySQL: " . $e->getMessage() . "\nStart MySQL and check DB_* in .env.\n");
    exit(1);
}

$exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
$exists->execute([$dbName, 'users']);
if ((int) $exists->fetchColumn() > 0) {
    fwrite(STDERR, "Database `$dbName` already contains the schema. Nothing was changed.\n"
        . "Use a new database name in .env for a fresh install.\n");
    exit(1);
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$dbName`");

$count = 0;
foreach (['schema.sql', 'seed.sql'] as $file) {
    $sql = file_get_contents(__DIR__ . '/' . $file);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read database/$file\n");
        exit(1);
    }
    foreach (split_sql($sql) as $stmt) {
        if (preg_match('/^(CREATE DATABASE|USE)\b/i', $stmt)) {
            continue; // handled above using DB_NAME
        }
        try {
            $pdo->exec($stmt);
            $count++;
        } catch (PDOException $e) {
            fwrite(STDERR, "Failed in $file: " . $e->getMessage() . "\n");
            exit(1);
        }
    }
}

echo "Installed `$dbName`: $count statements run (schema + role seed).\n";
echo "Next: php database/create_admin.php\n";
