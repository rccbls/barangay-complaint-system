<?php
/**
 * Create the missing resident profile for Resident accounts registered before Phase 3.
 *
 *   php database/backfill_resident_profiles.php
 *
 * Idempotent: accounts that already have a profile are skipped, and complainants.user_id is
 * UNIQUE so a duplicate can never be created. The profile name is copied from the account name;
 * contact number and address stay empty until the resident adds them.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = db_connect(true);
} catch (PDOException $e) {
    fwrite(STDERR, 'Cannot connect: ' . $e->getMessage() . "\n");
    exit(1);
}

$created = $pdo->exec(
    "INSERT INTO complainants (user_id, full_name)
     SELECT u.user_id, u.full_name
       FROM users u JOIN roles r ON r.role_id = u.role_id
      WHERE r.role_name = 'Resident'
        AND NOT EXISTS (SELECT 1 FROM complainants c WHERE c.user_id = u.user_id)"
);
echo "Created $created resident profile(s).\n";
