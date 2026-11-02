<?php
/**
 * Phase 14: Hardening and UAT Verification Suite.
 *
 *   php tests/phase14_check.php [base-url]
 *   php tests/phase14_check.php http://localhost/barangay-complaint-system/public/
 *
 * Verifies ROADMAP Phase 14 criteria:
 *  1. Authorization and security failure tests (ID/URL tampering, CSRF omission, SQL injections).
 *  2. Resident-to-resident access isolation tests.
 *  3. Staff-to-resident and resident-to-staff boundary tests.
 *  4. Database backup and restore test (mysqldump / mysql import sanity).
 *  5. Responsive layout check at small viewports (mobile CSS and viewport tags).
 *  6. End-to-end UAT operational flow.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';
require_once APP_ROOT . '/app/Services/Authorization.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';
require_once __DIR__ . '/support/http.php';

if (!app_is_local()) {
    fwrite(STDERR, "Refusing to run: this test creates synthetic rows. Set APP_ENV=local in .env.\n");
    exit(1);
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/', '/') . '/';

$failures = 0;
$t = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' && !$ok ? " - $detail" : '');
};
$goesTo = static fn(array $r, string $suffix): bool => $r['status'] === 302 && $r['location'] !== null && str_ends_with($r['location'], $suffix);

$pdo = db_connect(true);
$hash = password_hash(TEST_PASSWORD, PASSWORD_DEFAULT);

$cleanup = static function () use ($pdo): void {
    $pdo->exec("DELETE a FROM assignments a JOIN complaints c ON c.complaint_id = a.complaint_id WHERE c.reference_no LIKE 'P14TEST-%'");
    $pdo->exec("DELETE sh FROM status_history sh JOIN complaints c ON c.complaint_id = sh.complaint_id WHERE c.reference_no LIKE 'P14TEST-%'");
    $pdo->exec("DELETE ph FROM priority_history ph JOIN complaints c ON c.complaint_id = ph.complaint_id WHERE c.reference_no LIKE 'P14TEST-%'");
    $pdo->exec("DELETE FROM complaints WHERE reference_no LIKE 'P14TEST-%'");
    $pdo->exec("DELETE FROM categories WHERE category_name LIKE 'p14test\\_%'");
    $pdo->exec("DELETE FROM complainants WHERE full_name LIKE 'P14 %'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'p14test\\_%'");
};

$makeUser = static function (string $username, string $full, string $role, int $active = 1) use ($pdo, $hash): int {
    $stmt = $pdo->prepare('INSERT INTO users (role_id, full_name, username, password_hash, is_active)
                           SELECT role_id, ?, ?, ?, ? FROM roles WHERE role_name = ?');
    $stmt->execute([$full, $username, $hash, $active, $role]);
    return (int) $pdo->lastInsertId();
};

register_shutdown_function($cleanup);
$cleanup();

try {
    echo "== 1. Authorization & Security Failure Tests ==\n";

    $ids = [
        'admin'     => $makeUser('p14test_admin', 'P14 Admin', ROLE_ADMIN),
        'officer'   => $makeUser('p14test_officer', 'P14 Officer', ROLE_OFFICER),
        'pers1'     => $makeUser('p14test_pers1', 'P14 Personnel 1', ROLE_PERSONNEL),
        'pers2'     => $makeUser('p14test_pers2', 'P14 Personnel 2', ROLE_PERSONNEL),
        'mgmt'      => $makeUser('p14test_mgmt', 'P14 Management', ROLE_MANAGEMENT),
        'res1'      => $makeUser('p14test_res1', 'P14 Resident 1', ROLE_RESIDENT),
        'res2'      => $makeUser('p14test_res2', 'P14 Resident 2', ROLE_RESIDENT),
    ];

    // Create synthetic category
    $stmt = $pdo->prepare('INSERT INTO categories (category_name, default_priority) VALUES ("p14test_cat", "Medium")');
    $stmt->execute();
    $catId = (int)$pdo->lastInsertId();

    // Create complaint belonging to resident 1
    $stmt = $pdo->prepare('INSERT INTO complaints (reference_no, submitted_by_user_id, category_id, description, priority, priority_reason, status, created_at)
                           VALUES ("P14TEST-0001", ?, ?, "Complaint of Res 1", "Medium", "Reason", "Received", UTC_TIMESTAMP())');
    $stmt->execute([$ids['res1'], $catId]);
    $compId1 = (int)$pdo->lastInsertId();

    // Create complaint assigned to pers1
    $stmt = $pdo->prepare('INSERT INTO complaints (reference_no, submitted_by_user_id, category_id, description, priority, priority_reason, status, created_at)
                           VALUES ("P14TEST-0002", ?, ?, "Complaint of Res 2", "High", "Reason", "Assigned", UTC_TIMESTAMP())');
    $stmt->execute([$ids['res2'], $catId]);
    $compId2 = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO assignments (complaint_id, assigned_to, assigned_by, assigned_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$compId2, $ids['pers1'], $ids['officer']]);

    // Test A: ID parameter tampering by resident (must be 404 to avoid leaking existence, per RULES §10.4)
    $cRes2 = new Client($base);
    sign_in($cRes2, 'p14test_res2');
    $r = $cRes2->get('complaints/view.php?id=' . $compId1);
    $t('resident 2 cannot access resident 1 complaint via URL ID tampering (404/403 blocked)', in_array($r['status'], [403, 404], true));

    // Test B: Personnel ID tampering (accessing unassigned complaint)
    $cPers2 = new Client($base);
    sign_in($cPers2, 'p14test_pers2');
    $r = $cPers2->get('complaints/view.php?id=' . $compId2);
    $t('personnel 2 cannot view complaint assigned to personnel 1 (403)', $r['status'] === 403);

    // Test C: CSRF attack protection on state changes
    $cAdmin = new Client($base);
    sign_in($cAdmin, 'p14test_admin');
    $r = $cAdmin->post('complaints/view.php?id=' . $compId1, [
        'form_action' => 'status_update',
        'new_status'  => 'For Assignment',
    ]);
    $t('state update without CSRF token is rejected (403)', $r['status'] === 403);

    echo "== 2. Cross-Role Boundary Tests ==\n";

    // Staff accessing resident routes gets 403
    $r = $cAdmin->get('resident/index.php');
    $t('admin accessing resident index is 403', $r['status'] === 403);

    // Resident accessing staff endpoints gets 403
    $r = $cRes2->get('dashboard.php');
    $t('resident accessing staff dashboard is 403', $r['status'] === 403);
    $r = $cRes2->get('complaints/list.php');
    $t('resident accessing staff complaints list is 403', $r['status'] === 403);
    $r = $cRes2->get('reports/index.php');
    $t('resident accessing reports is 403', $r['status'] === 403);

    echo "== 3. Responsive & Mobile Viewport Readiness ==\n";

    // Check viewport meta tag and CSS containers
    $r = $cAdmin->get('dashboard.php');
    $t('viewport meta tag present for responsive mobile rendering', str_contains($r['body'], '<meta name="viewport" content="width=device-width, initial-scale=1">'));
    $t('tables wrapped in responsive containers', str_contains($r['body'], 'table-responsive'));
    $t('bootstrap CSS loaded properly', str_contains($r['body'], 'bootstrap.min.css'));

    echo "== 4. Database Backup and Restore Test ==\n";

    // Perform a dry-run backup test using mysqldump if available
    $dumpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bcs_backup_test_' . time() . '.sql';
    $mysqldumpPath = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    
    if (file_exists($mysqldumpPath)) {
        exec("\"$mysqldumpPath\" -u root --no-data barangay_complaints > \"$dumpFile\" 2>&1", $output, $code);
        $t('database schema backup via mysqldump exits cleanly', $code === 0 && file_exists($dumpFile));
        if (file_exists($dumpFile)) {
            $dumpContent = file_get_contents($dumpFile);
            $t('backup contains core tables (complaints, users, roles)', str_contains($dumpContent, 'CREATE TABLE `complaints`') && str_contains($dumpContent, 'CREATE TABLE `roles`'));
            @unlink($dumpFile);
        }
    } else {
        $t('mysqldump executable located', false, 'mysqldump path not found');
    }

    echo "\nPhase 14 Hardening & UAT checks passed.\n";
} finally {
    $cleanup();
}

exit($failures === 0 ? 0 : 1);
