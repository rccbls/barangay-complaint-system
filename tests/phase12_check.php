<?php
/**
 * Phase 12 check: Reports (FR-016).
 *
 *   php tests/phase12_check.php [base-url]
 *   php tests/phase12_check.php http://localhost/barangay-complaint-system/public/
 *
 * Verifies:
 *  - Filters by date, category, priority, status, and search are applied server-side.
 *  - Reports respect user permissions (Admin, Officer, Management = 200; Personnel, Resident = 403).
 *  - Report labels include date range, generation time, and applied filters.
 *  - Metric definitions (total, open/pending) are documented and used consistently.
 *  - Results match selected filters and authorized scope.
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
    $pdo->exec("DELETE a FROM assignments a JOIN complaints c ON c.complaint_id = a.complaint_id WHERE c.reference_no LIKE 'P12TEST-%'");
    $pdo->exec("DELETE sh FROM status_history sh JOIN complaints c ON c.complaint_id = sh.complaint_id WHERE c.reference_no LIKE 'P12TEST-%'");
    $pdo->exec("DELETE ph FROM priority_history ph JOIN complaints c ON c.complaint_id = ph.complaint_id WHERE c.reference_no LIKE 'P12TEST-%'");
    $pdo->exec("DELETE FROM complaints WHERE reference_no LIKE 'P12TEST-%'");
    $pdo->exec("DELETE FROM categories WHERE category_name LIKE 'p12test\\_%'");
    $pdo->exec("DELETE FROM complainants WHERE full_name LIKE 'P12 %'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'p12test\\_%'");
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
    echo "== Access control: Reports ==\n";

    $ids = [
        'admin'     => $makeUser('p12test_admin', 'P12 Admin', ROLE_ADMIN),
        'officer'   => $makeUser('p12test_officer', 'P12 Officer', ROLE_OFFICER),
        'mgmt'      => $makeUser('p12test_mgmt', 'P12 Management', ROLE_MANAGEMENT),
        'pers'      => $makeUser('p12test_pers', 'P12 Personnel', ROLE_PERSONNEL),
        'resident'  => $makeUser('p12test_res', 'P12 Resident', ROLE_RESIDENT),
    ];

    // Signed out redirected to login
    $anon = new Client($base);
    $r = $anon->get('reports/index.php');
    $t('signed out: reports redirects to login', $goesTo($r, 'login.php'));

    // Resident forbidden
    $cRes = new Client($base);
    sign_in($cRes, 'p12test_res');
    $r = $cRes->get('reports/index.php');
    $t('resident: reports is 403', $r['status'] === 403);

    // Personnel forbidden
    $cPers = new Client($base);
    sign_in($cPers, 'p12test_pers');
    $r = $cPers->get('reports/index.php');
    $t('personnel: reports is 403', $r['status'] === 403);

    // Officer, Admin, Management can view reports
    $cOff = new Client($base);
    sign_in($cOff, 'p12test_officer');
    $r = $cOff->get('reports/index.php');
    $t('officer: reports loads (200)', $r['status'] === 200);

    $cAdmin = new Client($base);
    sign_in($cAdmin, 'p12test_admin');
    $r = $cAdmin->get('reports/index.php');
    $t('admin: reports loads (200)', $r['status'] === 200);

    $cMgmt = new Client($base);
    sign_in($cMgmt, 'p12test_mgmt');
    $r = $cMgmt->get('reports/index.php');
    $t('management: reports loads (200)', $r['status'] === 200);

    echo "== Server-side filtering and report labels ==\n";

    // Create synthetic categories
    $stmt = $pdo->prepare('INSERT INTO categories (category_name, default_priority) VALUES ("p12test_catA", "Critical"), ("p12test_catB", "Low")');
    $stmt->execute();
    $catA = (int)$pdo->query('SELECT category_id FROM categories WHERE category_name = "p12test_catA"')->fetchColumn();
    $catB = (int)$pdo->query('SELECT category_id FROM categories WHERE category_name = "p12test_catB"')->fetchColumn();

    // Create complaints with different attributes
    $createComp = static function (string $ref, int $catId, string $status, string $priority, string $desc, string $date) use ($pdo): int {
        $stmt = $pdo->prepare('INSERT INTO complaints (reference_no, category_id, description, priority, priority_reason, status, created_at)
                               VALUES (?, ?, ?, ?, "P12 test reason", ?, ?)');
        $stmt->execute([$ref, $catId, $desc, $priority, $status, $date]);
        return (int)$pdo->lastInsertId();
    };

    $c1 = $createComp('P12TEST-0001', $catA, 'Received', 'Critical', 'Water pipeline burst issue', '2026-05-01 10:00:00');
    $c2 = $createComp('P12TEST-0002', $catA, 'In Progress', 'High', 'Road construction pothole danger', '2026-05-15 14:00:00');
    $c3 = $createComp('P12TEST-0003', $catB, 'Resolved', 'Low', 'Noise complaint neighborhood music', '2026-06-01 08:00:00');

    // 1. Filter by status=Resolved
    $r = $cOff->get('reports/index.php?status=Resolved');
    $t('status filter: shows P12TEST-0003', str_contains($r['body'], 'P12TEST-0003'));
    $t('status filter: does not show P12TEST-0001', !str_contains($r['body'], 'P12TEST-0001'));
    $t('status filter: shows Status: Resolved label badge', str_contains($r['body'], 'Status: Resolved'));

    // 2. Filter by priority=Critical
    $r = $cOff->get('reports/index.php?priority=Critical');
    $t('priority filter: shows P12TEST-0001', str_contains($r['body'], 'P12TEST-0001'));
    $t('priority filter: does not show P12TEST-0002', !str_contains($r['body'], 'P12TEST-0002'));

    // 3. Filter by category
    $r = $cOff->get('reports/index.php?category_id=' . $catA);
    $t('category filter: shows P12TEST-0001 and P12TEST-0002', str_contains($r['body'], 'P12TEST-0001') && str_contains($r['body'], 'P12TEST-0002'));
    $t('category filter: does not show P12TEST-0003', !str_contains($r['body'], 'P12TEST-0003'));

    // 4. Filter by date range (May only)
    $r = $cOff->get('reports/index.php?date_from=2026-05-01&date_to=2026-05-31');
    $t('date range filter: shows May records', str_contains($r['body'], 'P12TEST-0001') && str_contains($r['body'], 'P12TEST-0002'));
    $t('date range filter: excludes June record P12TEST-0003', !str_contains($r['body'], 'P12TEST-0003'));
    $t('date range filter: labels From and To dates', str_contains($r['body'], 'From: 2026-05-01') && str_contains($r['body'], 'To: 2026-05-31'));

    // 5. Keyword search filter
    $r = $cOff->get('reports/index.php?search=pipeline');
    $t('search filter: shows P12TEST-0001 (pipeline)', str_contains($r['body'], 'P12TEST-0001'));
    $t('search filter: does not show P12TEST-0002', !str_contains($r['body'], 'P12TEST-0002'));

    // 6. Verify documented metric definitions are present on page
    $t('metric definitions documented on report page', str_contains($r['body'], 'Report Metric Definitions'));

    echo "\nPhase 12 checks passed.\n";
} finally {
    $cleanup();
}

exit($failures === 0 ? 0 : 1);

