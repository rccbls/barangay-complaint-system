<?php
/**
 * Database connection (Phase 0) — PDO, MySQL/MariaDB, utf8mb4.
 *
 * Credentials come from .env (never committed). All later data access must use
 * PDO prepared statements; emulated prepares are disabled so the server does
 * the parameter binding.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

/** @return array{host:string,port:string,name:string,user:string,pass:string} */
function db_config(): array
{
    return [
        'host' => (string) env('DB_HOST', '127.0.0.1'),
        'port' => (string) env('DB_PORT', '3306'),
        'name' => (string) env('DB_NAME', 'barangay_complaints'),
        'user' => (string) env('DB_USER', 'root'),
        'pass' => (string) env('DB_PASS', ''),
    ];
}

/**
 * Open a new PDO connection.
 *
 * @param bool $selectDatabase false = connect to the server only (used to tell
 *                             "server down" apart from "database not created yet").
 * @throws PDOException on failure
 */
function db_connect(bool $selectDatabase = true): PDO
{
    $c   = db_config();
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ';charset=utf8mb4';
    if ($selectDatabase) {
        $dsn .= ';dbname=' . $c['name'];
    }

    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Timezone convention: this session stores/reads UTC (see config/app.php).
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

/** Shared connection for the current request. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect(true);
    }
    return $pdo;
}

/**
 * Phase 0 connectivity check. Never throws.
 *
 * Note: the database itself is created by the Phase 1 schema, so "server
 * reachable but database missing" is the expected result at the end of Phase 0.
 *
 * @return array{server:bool,database:bool,version:?string,session_tz:?string,error:?string}
 */
function db_health_check(): array
{
    $result = ['server' => false, 'database' => false, 'version' => null, 'session_tz' => null, 'error' => null];

    try {
        $pdo = db_connect(true);
        $result['server']   = true;
        $result['database'] = true;
    } catch (PDOException $e) {
        // MySQL error 1049 = "Unknown database": the server is up, schema not created yet.
        if ((int) $e->getCode() === 1049) {
            try {
                $pdo = db_connect(false);
                $result['server'] = true;
            } catch (PDOException $e2) {
                $result['error'] = $e2->getMessage();
                return $result;
            }
        } else {
            $result['error'] = $e->getMessage();
            return $result;
        }
    }

    $result['version']    = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $result['session_tz'] = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
    return $result;
}
