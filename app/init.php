<?php
/**
 * Entry-point include for every page that uses sessions/authentication (Phase 2).
 * Pages call: require_once dirname(__DIR__) . '/app/init.php';  (adjust depth)
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/app/Services/Session.php';
require_once APP_ROOT . '/app/Services/Authorization.php';
require_once APP_ROOT . '/app/Services/Validation.php';
require_once APP_ROOT . '/app/Repositories/UserRepository.php';
require_once APP_ROOT . '/app/Repositories/ResidentRepository.php';
require_once APP_ROOT . '/app/Repositories/StaffRepository.php';
require_once APP_ROOT . '/app/Repositories/CategoryRepository.php';
require_once APP_ROOT . '/app/Services/AuthService.php';
require_once APP_ROOT . '/app/Services/ResidentService.php';
require_once APP_ROOT . '/app/Services/StaffService.php';
require_once APP_ROOT . '/app/Services/CategoryService.php';
require_once APP_ROOT . '/app/Middleware/auth.php';

// Never show internals to users outside local development.
set_exception_handler(static function (Throwable $ex): void {
    error_log('Unhandled ' . get_class($ex) . ': ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><p>Something went wrong. Please try again later.</p>';
    if (app_is_local()) {
        echo '<pre>' . e((string) $ex) . '</pre>';
    }
});

session_boot();
