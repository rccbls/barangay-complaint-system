<?php
/**
 * Phase 9 check: personnel workspace (FR-011).
 *
 *   php tests/phase9_check.php [base-url]
 *   php tests/phase9_check.php http://localhost/barangay-complaint-system/public/
 *
 * Verifies:
 *  - "My Assignments" page is accessible only by Personnel.
 *  - Personnel user sees ONLY complaints assigned to them.
 *  - Personnel user CANNOT view or edit complaints outside their assigned scope (403).
 *  - Permitted status transitions by Personnel:
 *      Assigned -> In Progress
 *      In Progress -> On Hold (requires remarks)
 *      On Hold -> In Progress
 *      In Progress -> Resolved (requires resolution details)
 *  - Personnel CANNOT close complaints.
 *  - Officer review and close flow:
 *      Officer can reopen Resolved to In Progress (with reason).
 *      Officer can close Resolved complaint to Closed.
 *      Closed complaints are read-only.
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
    $pdo->exec("DELETE a FROM assignments a JOIN complaints c ON c.complaint_id = a.complaint_id WHERE c.reference_no LIKE 'P9TEST-%'");
    $pdo->exec("DELETE sh FROM status_history sh JOIN complaints c ON c.complaint_id = sh.complaint_id WHERE c.reference_no LIKE 'P9TEST-%'");
    $pdo->exec("DELETE ph FROM priority_history ph JOIN complaints c ON c.complaint_id = ph.complaint_id WHERE c.reference_no LIKE 'P9TEST-%'");
    $pdo->exec("DELETE FROM complaints WHERE reference_no LIKE 'P9TEST-%'");
    $pdo->exec("DELETE FROM categories WHERE category_name LIKE 'p9test\\_%'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'p9test\\_%'");
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
    $ids = [
        'admin'     => $makeUser('p9test_admin', 'P9 Admin', ROLE_ADMIN),
        'officer'   => $makeUser('p9test_officer', 'P9 Officer', ROLE_OFFICER),
        'pers1'     => $makeUser('p9test_pers1', 'P9 Personnel One', ROLE_PERSONNEL),
        'pers2'     => $makeUser('p9test_pers2', 'P9 Personnel Two', ROLE_PERSONNEL),
        'mgmt'      => $makeUser('p9test_mgmt', 'P9 Management', ROLE_MANAGEMENT),
        'resident'  => $makeUser('p9test_res', 'P9 Resident', ROLE_RESIDENT),
    ];

    $pdo->exec('INSERT INTO categories (category_name) VALUES ("p9test_cat")');
    $catId = (int)$pdo->lastInsertId();

    // Create 3 complaints:
    // C1: assigned to pers1
    // C2: assigned to pers2
    // C3: unassigned
    $c1Res = complaint_create($pdo, ['user_id' => $ids['admin'], 'role_name' => ROLE_ADMIN], [
        'category_id' => $catId, 'description' => 'Complaint for Pers1', 'incident_location' => null,
        'incident_at' => null, 'priority' => 'Medium', 'priority_reason' => 'P9 test', 'source' => 'Walk-in'
    ], false);
    $c1Id = $c1Res['complaint_id'];
    $pdo->prepare('UPDATE complaints SET reference_no = "P9TEST-0001" WHERE complaint_id = ?')->execute([$c1Id]);
    complaint_assign($pdo, ['user_id' => $ids['officer'], 'role_name' => ROLE_OFFICER], $c1Id, $ids['pers1'], 'Assigned to Pers 1');

    $c2Res = complaint_create($pdo, ['user_id' => $ids['admin'], 'role_name' => ROLE_ADMIN], [
        'category_id' => $catId, 'description' => 'Complaint for Pers2', 'incident_location' => null,
        'incident_at' => null, 'priority' => 'Medium', 'priority_reason' => 'P9 test', 'source' => 'Walk-in'
    ], false);
    $c2Id = $c2Res['complaint_id'];
    $pdo->prepare('UPDATE complaints SET reference_no = "P9TEST-0002" WHERE complaint_id = ?')->execute([$c2Id]);
    complaint_assign($pdo, ['user_id' => $ids['officer'], 'role_name' => ROLE_OFFICER], $c2Id, $ids['pers2'], 'Assigned to Pers 2');

    $c3Res = complaint_create($pdo, ['user_id' => $ids['admin'], 'role_name' => ROLE_ADMIN], [
        'category_id' => $catId, 'description' => 'Unassigned Complaint', 'incident_location' => null,
        'incident_at' => null, 'priority' => 'Medium', 'priority_reason' => 'P9 test', 'source' => 'Walk-in'
    ], false);
    $c3Id = $c3Res['complaint_id'];
    $pdo->prepare('UPDATE complaints SET reference_no = "P9TEST-0003" WHERE complaint_id = ?')->execute([$c3Id]);

    echo "== Access control: My Assignments (assignments/my.php) ==\n";
    $anon = new Client($base);
    $t('signed out: my assignments redirects to login', $goesTo($anon->get('assignments/my.php'), 'login.php'));

    $resClient = new Client($base);
    sign_in($resClient, 'p9test_res');
    $t('resident: my assignments is 403', $resClient->get('assignments/my.php')['status'] === 403);

    $offClient = new Client($base);
    sign_in($offClient, 'p9test_officer');
    $t('officer: my assignments is 403', $offClient->get('assignments/my.php')['status'] === 403);

    $admClient = new Client($base);
    sign_in($admClient, 'p9test_admin');
    $t('administrator: my assignments is 403', $admClient->get('assignments/my.php')['status'] === 403);

    $mgmtClient = new Client($base);
    sign_in($mgmtClient, 'p9test_mgmt');
    $t('management: my assignments is 403', $mgmtClient->get('assignments/my.php')['status'] === 403);

    $p1Client = new Client($base);
    sign_in($p1Client, 'p9test_pers1');
    $r1 = $p1Client->get('assignments/my.php');
    $t('personnel 1: my assignments loads (200)', $r1['status'] === 200);

    echo "== Personnel scope isolation in My Assignments ==\n";
    $t('personnel 1 sees assigned complaint P9TEST-0001', str_contains($r1['body'], 'P9TEST-0001'));
    $t('personnel 1 does NOT see P9TEST-0002 (assigned to pers2)', !str_contains($r1['body'], 'P9TEST-0002'));
    $t('personnel 1 does NOT see P9TEST-0003 (unassigned)', !str_contains($r1['body'], 'P9TEST-0003'));

    $p2Client = new Client($base);
    sign_in($p2Client, 'p9test_pers2');
    $r2 = $p2Client->get('assignments/my.php');
    $t('personnel 2 sees assigned complaint P9TEST-0002', str_contains($r2['body'], 'P9TEST-0002'));
    $t('personnel 2 does NOT see P9TEST-0001 (assigned to pers1)', !str_contains($r2['body'], 'P9TEST-0001'));

    echo "== Personnel scope enforcement on complaint details (complaints/view.php) ==\n";
    $view1 = $p1Client->get("complaints/view.php?id={$c1Id}");
    $t('personnel 1 can view own assigned complaint P9TEST-0001 (200)', $view1['status'] === 200 && str_contains($view1['body'], 'P9TEST-0001'));

    $view2 = $p1Client->get("complaints/view.php?id={$c2Id}");
    $t('personnel 1 CANNOT view P9TEST-0002 assigned to pers2 (403)', $view2['status'] === 403);

    $view3 = $p1Client->get("complaints/view.php?id={$c3Id}");
    $t('personnel 1 CANNOT view unassigned complaint P9TEST-0003 (403)', $view3['status'] === 403);

    echo "== Personnel permitted status updates ==\n";
    $token = csrf_from($view1['body']);

    // 1. Assigned -> In Progress
    $r = $p1Client->post("complaints/view.php?id={$c1Id}", [
        'csrf_token'  => $token,
        'form_action' => 'status',
        'new_status'  => 'In Progress',
        'remarks'     => 'Started initial inspection.',
    ]);
    $t('personnel starts work: Assigned -> In Progress', $goesTo($r, "complaints/view.php?id={$c1Id}") && complaint_fetch_by_id($pdo, $c1Id)['status'] === 'In Progress');

    // 2. In Progress -> On Hold without remarks rejected
    $token = csrf_from($p1Client->get("complaints/view.php?id={$c1Id}")['body']);
    $r = $p1Client->post("complaints/view.php?id={$c1Id}", [
        'csrf_token'  => $token,
        'form_action' => 'status',
        'new_status'  => 'On Hold',
        'remarks'     => '',
    ]);
    $t('On Hold without remarks is rejected', str_contains($r['body'], 'Reason for putting complaint on hold is required'));
    $t('status remains In Progress', complaint_fetch_by_id($pdo, $c1Id)['status'] === 'In Progress');

    // 3. In Progress -> On Hold with remarks succeeds
    $token = csrf_from($p1Client->get("complaints/view.php?id={$c1Id}")['body']);
    $r = $p1Client->post("complaints/view.php?id={$c1Id}", [
        'csrf_token'  => $token,
        'form_action' => 'status',
        'new_status'  => 'On Hold',
        'remarks'     => 'Awaiting specialized equipment from city hall.',
    ]);
    $t('In Progress -> On Hold with remarks succeeds', $goesTo($r, "complaints/view.php?id={$c1Id}") && complaint_fetch_by_id($pdo, $c1Id)['status'] === 'On Hold');

    // 4. On Hold -> In Progress succeeds
    $token = csrf_from($p1Client->get("complaints/view.php?id={$c1Id}")['body']);
    $r = $p1Client->post("complaints/view.php?id={$c1Id}", [
        'csrf_token'  => $token,
        'form_action' => 'status',
        'new_status'  => 'In Progress',
        'remarks'     => 'Equipment received, resuming work.',
    ]);
    $t('On Hold -> In Progress resumes work', $goesTo($r, "complaints/view.php?id={$c1Id}") && complaint_fetch_by_id($pdo, $c1Id)['status'] === 'In Progress');

    // 5. In Progress -> Resolved requires resolution details
    $token = csrf_from($p1Client->get("complaints/view.php?id={$c1Id}")['body']);
    $r = $p1Client->post("complaints/view.php?id={$c1Id}", [
        'csrf_token'         => $token,
        'form_action'        => 'status',
        'new_status'         => 'Resolved',
        'resolution_details' => '',
    ]);
    $t('Resolved without resolution details is rejected', str_contains($r['body'], 'Resolution details are required'));
    $t('status remains In Progress', complaint_fetch_by_id($pdo, $c1Id)['status'] === 'In Progress');

    // 6. In Progress -> Resolved with resolution details succeeds
    $token = csrf_from($p1Client->get("complaints/view.php?id={$c1Id}")['body']);
    $r = $p1Client->post("complaints/view.php?id={$c1Id}", [
        'csrf_token'         => $token,
        'form_action'        => 'status',
        'new_status'         => 'Resolved',
        'resolution_details' => 'Cleared canal obstruction and restored normal drainage flow.',
    ]);
    $t('In Progress -> Resolved submits resolution details', $goesTo($r, "complaints/view.php?id={$c1Id}"));
    $c1After = complaint_fetch_by_id($pdo, $c1Id);
    $t('status is Resolved and resolved_at timestamp is set', $c1After['status'] === 'Resolved' && !empty($c1After['resolved_at']));
    $t('resolution details persisted in database', str_contains((string)$c1After['resolution_details'], 'Cleared canal obstruction'));

    // 7. Personnel CANNOT close complaints
    $token = csrf_from($p1Client->get("complaints/view.php?id={$c1Id}")['body']);
    $r = $p1Client->post("complaints/view.php?id={$c1Id}", [
        'csrf_token'  => $token,
        'form_action' => 'status',
        'new_status'  => 'Closed',
    ]);
    $t('personnel cannot close complaint (rejected)', str_contains($r['body'], 'not allowed for your role'));
    $t('complaint status remains Resolved', complaint_fetch_by_id($pdo, $c1Id)['status'] === 'Resolved');

    echo "== Officer review and close flow ==\n";
    $offView = $offClient->get("complaints/view.php?id={$c1Id}");
    $t('officer sees resolution details', str_contains($offView['body'], 'Cleared canal obstruction'));

    // Officer reopens to In Progress if needed (with reason)
    $oToken = csrf_from($offView['body']);
    $r = $offClient->post("complaints/view.php?id={$c1Id}", [
        'csrf_token'  => $oToken,
        'form_action' => 'status',
        'new_status'  => 'In Progress',
        'remarks'     => 'Complainant reports debris still left on sidewalk.',
    ]);
    $t('officer can reopen Resolved to In Progress', $goesTo($r, "complaints/view.php?id={$c1Id}") && complaint_fetch_by_id($pdo, $c1Id)['status'] === 'In Progress');

    // Personnel marks resolved again
    $token = csrf_from($p1Client->get("complaints/view.php?id={$c1Id}")['body']);
    $p1Client->post("complaints/view.php?id={$c1Id}", [
        'csrf_token'         => $token,
        'form_action'        => 'status',
        'new_status'         => 'Resolved',
        'resolution_details' => 'Sidewalk swept and debris completely carted away.',
    ]);

    // Officer closes case
    $oToken = csrf_from($offClient->get("complaints/view.php?id={$c1Id}")['body']);
    $r = $offClient->post("complaints/view.php?id={$c1Id}", [
        'csrf_token'  => $oToken,
        'form_action' => 'status',
        'new_status'  => 'Closed',
        'remarks'     => 'Confirmed resolved with resident. Case closed.',
    ]);
    $t('officer closes complaint: Resolved -> Closed', $goesTo($r, "complaints/view.php?id={$c1Id}"));
    $closedRow = complaint_fetch_by_id($pdo, $c1Id);
    $t('status is Closed and closed_at is set', $closedRow['status'] === 'Closed' && !empty($closedRow['closed_at']));

    // Closed complaints are read-only for Personnel
    $pViewClosed = $p1Client->get("complaints/view.php?id={$c1Id}");
    $t('closed complaint shows no status update form for personnel', !str_contains($pViewClosed['body'], 'name="new_status"'));

} finally {
    $cleanup();
}

echo $failures === 0 ? "\nPhase 9 checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);

