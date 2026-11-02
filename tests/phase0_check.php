<?php
/**
 * Phase 0 command-line check.
 *
 *   php tests/phase0_check.php
 *
 * Verifies the "Done when" conditions that can be tested from the CLI.
 * Exit code 0 = all required checks passed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '', bool $required = true) use (&$failures): void {
    if (!$ok && $required) {
        $failures++;
    }
    printf("[%s] %s%s\n", $ok ? 'PASS' : ($required ? 'FAIL' : 'INFO'), $label, $detail !== '' ? " - $detail" : '');
};

$check('PHP 8.x', PHP_MAJOR_VERSION === 8, PHP_VERSION);
$check('pdo_mysql extension loaded', extension_loaded('pdo_mysql'));
$check('.env file present', is_file(APP_ROOT . '/.env'), 'copy .env.example to .env');

foreach (['public', 'public/assets', 'public/resident', 'public/complaints', 'public/assignments',
          'public/personnel', 'public/categories', 'public/reports', 'app/Controllers', 'app/Services',
          'app/Repositories', 'app/Middleware', 'app/Views', 'config', 'storage/uploads', 'database', 'tests'] as $dir) {
    $check("folder $dir", is_dir(APP_ROOT . '/' . $dir));
}
$check('Bootstrap CSS vendored', is_file(APP_ROOT . '/public/assets/vendor/bootstrap/bootstrap.min.css'));
$check('Bootstrap JS vendored',  is_file(APP_ROOT . '/public/assets/vendor/bootstrap/bootstrap.bundle.min.js'));

$health = db_health_check();
$check('MySQL server reachable', $health['server'], $health['version'] ?? (string) $health['error']);
$check('Database session time zone is UTC', ($health['session_tz'] ?? '') === '+00:00', (string) $health['session_tz']);
$check('Database exists (created in Phase 1)', $health['database'], 'not required in Phase 0', false);
$check('PHP default timezone is UTC', date_default_timezone_get() === 'UTC', date_default_timezone_get());
$check('Display conversion UTC -> Asia/Manila (+8h)', format_display_datetime('2026-01-01 00:00:00') === '2026-01-01 08:00');

echo $failures === 0 ? "\nPhase 0 CLI checks passed.\n" : "\n$failures required check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
