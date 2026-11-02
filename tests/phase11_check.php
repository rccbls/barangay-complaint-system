<?php
/**
 * Phase 11 check: Dashboard (FR-015).
 *
 *   php tests/phase11_check.php [base-url]
 *   php tests/phase11_check.php http://localhost/barangay-complaint-system/public/
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
    $pdo->exec("DELETE a FROM assignments a JOIN complaints c ON c.complaint_id = a.complaint_id WHERE c.reference_no LIKE 'P11TEST-%'");
    $pdo->exec("DELETE sh FROM status_history sh JOIN complaints c ON c.complaint_id = sh.complaint_id WHERE c.reference_no LIKE 'P11TEST-%'");
    $pdo->exec("DELETE ph FROM priority_history ph JOIN complaints c ON c.complaint_id = ph.complaint_id WHERE c.reference_no LIKE 'P11TEST-%'");
    $pdo->exec("DELETE FROM complaints WHERE reference_no LIKE 'P11TEST-%'");
    $pdo->exec("DELETE FROM categories WHERE category_name LIKE 'p11test\\_%'");
    $pdo->exec("DELETE FROM complainants WHERE full_name LIKE 'P11 %'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'p11test\\_%'");
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
    echo "== Access control: Dashboard ==\n";

    $ids = [
        'admin'     => $makeUser('p11test_admin', 'P11 Admin', ROLE_ADMIN),
        'officer'   => $makeUser('p11test_officer', 'P11 Officer', ROLE_OFFICER),
        'mgmt'      => $makeUser('p11test_mgmt', 'P11 Management', ROLE_MANAGEMENT),
        'pers'      => $makeUser('p11test_pers', 'P11 Personnel', ROLE_PERSONNEL),
        'res1'      => $makeUser('p11test_res1', 'P11 Resident One', ROLE_RESIDENT),
        'res2'      => $makeUser('p11test_res2', 'P11 Resident Two', ROLE_RESIDENT),
    ];

    $anon = new Client($base);
    $r = $anon->get('dashboard.php');
    $t('signed out: dashboard redirects to login', $goesTo($r, 'login.php'));

    // Resident forbidden from staff dashboard
    $cRes1 = new Client($base);
    sign_in($cRes1, 'p11test_res1');
    $r = $cRes1->get('dashboard.php');
    $t('resident: staff dashboard is 403', $r['status'] === 403);

    // Resident dashboard loads for resident
    $r = $cRes1->get('resident/index.php');
    $t('resident: resident dashboard loads (200)', $r['status'] === 200);
    $t('resident: displays scoped summary card', str_contains($r['body'], 'My Total Complaints'));

    // Personnel access to staff dashboard
    $cPers = new Client($base);
    sign_in($cPers, 'p11test_pers');
    $r = $cPers->get('dashboard.php');
    $t('personnel: staff dashboard loads (200)', $r['status'] === 200);

    // Officer, Admin, Management can load staff dashboard
    $cOff = new Client($base);
    sign_in($cOff, 'p11test_officer');
    $r = $cOff->get('dashboard.php');
    $t('officer: staff dashboard loads (200)', $r['status'] === 200);

    $cAdmin = new Client($base);
    sign_in($cAdmin, 'p11test_admin');
    $r = $cAdmin->get('dashboard.php');
    $t('admin: staff dashboard loads (200)', $r['status'] === 200);

    $cMgmt = new Client($base);
    sign_in($cMgmt, 'p11test_mgmt');
    $r = $cMgmt->get('dashboard.php');
    $t('management: staff dashboard loads (200)', $r['status'] === 200);

    echo "== Data accuracy and Resident Scoping ==\n";

    // Create synthetic category
    $stmt = $pdo->prepare('INSERT INTO categories (category_name, default_priority) VALUES ("p11test_cat", "High")');
    $stmt->execute();
    $catId = (int)$pdo->lastInsertId();

    // Create 2 complaints for res1, 1 for res2
    $createComplaint = static function (string $ref, int $uid, int $catId, string $status, string $priority) use ($pdo): int {
        $stmt = $pdo->prepare('INSERT INTO complaints (reference_no, submitted_by_user_id, category_id, description, priority, priority_reason, status, created_at)
                               VALUES (?, ?, ?, "P11 test desc", ?, "P11 test reason", ?, UTC_TIMESTAMP())');
        $stmt->execute([$ref, $uid, $catId, $priority, $status]);
        return (int)$pdo->lastInsertId();
    };

    $c1 = $createComplaint('P11TEST-0001', $ids['res1'], $catId, 'Received', 'Critical');
    $c2 = $createComplaint('P11TEST-0002', $ids['res1'], $catId, 'In Progress', 'High');
    $c3 = $createComplaint('P11TEST-0003', $ids['res2'], $catId, 'Resolved', 'Low');

    // Test staff summary counts against database query
    $summary = dashboard_staff_summary($pdo);
    $totalDb = (int)$pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn();
    $t('staff summary total matches SQL COUNT', $summary['total'] === $totalDb);

    // Test resident scoped summary
    $res1Summary = dashboard_resident_summary($pdo, $ids['res1']);
    $t('resident 1 summary total is exactly 2', $res1Summary['total'] === 2);
    $t('resident 1 open count is 2', $res1Summary['open'] === 2);

    $res2Summary = dashboard_resident_summary($pdo, $ids['res2']);
    $t('resident 2 summary total is exactly 1', $res2Summary['total'] === 1);

    // Verify resident dashboard HTML output does not leak other resident complaints
    $r = $cRes1->get('resident/index.php');
    $t('resident 1 dashboard shows P11TEST-0001', str_contains($r['body'], 'P11TEST-0001'));
    $t('resident 1 dashboard shows P11TEST-0002', str_contains($r['body'], 'P11TEST-0002'));
    $t('resident 1 dashboard does NOT leak P11TEST-0003 (res2 complaint)', !str_contains($r['body'], 'P11TEST-0003'));

    $cRes2 = new Client($base);
    sign_in($cRes2, 'p11test_res2');
    $r = $cRes2->get('resident/index.php');
    $t('resident 2 dashboard shows P11TEST-0003', str_contains($r['body'], 'P11TEST-0003'));
    $t('resident 2 dashboard does NOT leak P11TEST-0001', !str_contains($r['body'], 'P11TEST-0001'));

    echo "\nPhase 11 checks passed.\n";
} finally {
    $cleanup();
}

exit($failures === 0 ? 0 : 1);
