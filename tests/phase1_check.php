<?php
/**
 * Phase 1 command-line check.
 *
 *   php tests/phase1_check.php
 *
 * Verifies the "Done when" conditions: tables and foreign keys exist, roles are
 * seeded, and exactly one administrator exists with a hashed password.
 * Exit code 0 = all checks passed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " - $detail" : '');
};

try {
    $pdo = db_connect(true);
} catch (PDOException $e) {
    fwrite(STDERR, 'Cannot connect: ' . $e->getMessage() . "\nRun php database/install.php first.\n");
    exit(1);
}
$schema = db_config()['name'];

// Tables (SCHEMA §3)
$tables = ['roles', 'users', 'complainants', 'categories', 'complaints', 'assignments',
           'status_history', 'priority_history', 'complaint_attachments'];
$stmt = $pdo->prepare('SELECT table_name, engine, table_collation FROM information_schema.tables WHERE table_schema = ?');
$stmt->execute([$schema]);
$found = [];
foreach ($stmt as $row) {
    $found[strtolower($row['table_name'] ?? $row['TABLE_NAME'])] = $row;
}
foreach ($tables as $t) {
    $ok = isset($found[$t]);
    $detail = $ok ? ($found[$t]['engine'] ?? $found[$t]['ENGINE']) . ', ' . ($found[$t]['table_collation'] ?? $found[$t]['TABLE_COLLATION']) : 'missing';
    $check("table $t", $ok && strcasecmp((string) ($found[$t]['engine'] ?? $found[$t]['ENGINE']), 'InnoDB') === 0, $detail);
}

// Foreign keys (named in SCHEMA §3)
$expectedFks = [
    'fk_users_role', 'fk_complainants_user', 'fk_categories_default_assignee',
    'fk_complaints_complainant', 'fk_complaints_submitted_by', 'fk_complaints_category', 'fk_complaints_received_by',
    'fk_assignments_complaint', 'fk_assignments_assigned_to', 'fk_assignments_assigned_by',
    'fk_status_history_complaint', 'fk_status_history_user',
    'fk_priority_history_complaint', 'fk_priority_history_user',
    'fk_attachments_complaint', 'fk_attachments_user',
];
$stmt = $pdo->prepare("SELECT constraint_name FROM information_schema.table_constraints WHERE table_schema = ? AND constraint_type = 'FOREIGN KEY'");
$stmt->execute([$schema]);
$fks = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
$missing = array_diff($expectedFks, $fks);
$check(count($expectedFks) . ' foreign keys exist', $missing === [], $missing ? 'missing: ' . implode(', ', $missing) : '');

// Roles (SCHEMA §4)
$roles = $pdo->query('SELECT role_name FROM roles ORDER BY role_id')->fetchAll(PDO::FETCH_COLUMN);
$check('five roles seeded in order', $roles === ['Resident', 'Administrator', 'Complaint Officer', 'Personnel', 'Management'], implode(', ', $roles));

// First administrator
$rows = $pdo->query(
    "SELECT u.username, u.password_hash, u.is_active FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Administrator'"
)->fetchAll();
$check('exactly one Administrator user', count($rows) === 1, count($rows) . ' found');
if (count($rows) === 1) {
    $hash = $rows[0]['password_hash'];
    $info = password_get_info($hash);
    $check('password stored as a recognised password_hash() value', $info['algo'] !== null && $info['algo'] !== 0, (string) ($info['algoName'] ?? ''));
    $check('administrator is active', (int) $rows[0]['is_active'] === 1);
}
$total = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$check('no other user rows (synthetic data only)', $total === 1, "$total user rows");

echo $failures === 0 ? "\nPhase 1 checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
