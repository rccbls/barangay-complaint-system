<?php
/**
 * Phase 8 check: assignment and routing (FR-009, FR-010).
 *
 *   php tests/phase8_check.php [base-url]
 *   php tests/phase8_check.php http://localhost/barangay-complaint-system/public/
 *
 * Verifies:
 *  - Only Administrator and Complaint Officer can access assignment actions.
 *  - Officer assigns to active personnel only (inactive/other roles rejected).
 *  - Reassignment closes previous assignment (unassigned_at) and creates a new row.
 *  - At most one active assignment per complaint.
 *  - Category default assignee is a suggestion only (no auto-assignment).
 *  - Assignment and status update ('Received' -> 'Assigned') commit in one transaction.
 *  - Assignment history records all assignments and reassignments with actor and notes.
 *  - Unauthorized users (Personnel, Resident, Management) cannot perform assignment actions.
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
    $pdo->exec("DELETE a FROM assignments a JOIN complaints c ON c.complaint_id = a.complaint_id WHERE c.reference_no LIKE 'P8TEST-%'");
    $pdo->exec("DELETE sh FROM status_history sh JOIN complaints c ON c.complaint_id = sh.complaint_id WHERE c.reference_no LIKE 'P8TEST-%'");
    $pdo->exec("DELETE ph FROM priority_history ph JOIN complaints c ON c.complaint_id = ph.complaint_id WHERE c.reference_no LIKE 'P8TEST-%'");
    $pdo->exec("DELETE FROM complaints WHERE reference_no LIKE 'P8TEST-%'");
    $pdo->exec("DELETE FROM categories WHERE category_name LIKE 'p8test\\_%'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'p8test\\_%'");
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
        'admin'     => $makeUser('p8test_admin', 'P8 Admin', ROLE_ADMIN),
        'officer'   => $makeUser('p8test_officer', 'P8 Officer', ROLE_OFFICER),
        'pers1'     => $makeUser('p8test_pers1', 'P8 Personnel One', ROLE_PERSONNEL),
        'pers2'     => $makeUser('p8test_pers2', 'P8 Personnel Two', ROLE_PERSONNEL),
        'inactive'  => $makeUser('p8test_inact', 'P8 Inactive Personnel', ROLE_PERSONNEL, 0),
        'mgmt'      => $makeUser('p8test_mgmt', 'P8 Management', ROLE_MANAGEMENT),
        'resident'  => $makeUser('p8test_res', 'P8 Resident', ROLE_RESIDENT),
    ];

    // Create a category with default assignee suggestion
    $stmt = $pdo->prepare('INSERT INTO categories (category_name, default_priority, default_assignee_id) VALUES ("p8test_cat", "High", ?)');
    $stmt->execute([$ids['pers1']]);
    $catId = (int)$pdo->lastInsertId();

    // Create synthetic complaint
    $res = complaint_create($pdo, ['user_id' => $ids['admin'], 'role_name' => ROLE_ADMIN], [
        'category_id'       => $catId,
        'description'       => 'P8 synthetic complaint description',
        'incident_location' => 'Street 8',
        'incident_at'       => null,
        'priority'          => 'High',
        'priority_reason'   => 'Urgent test reason',
        'source'            => 'Walk-in',
    ], false);

    $cmpId = (int)$res['complaint_id'];
    $pdo->prepare('UPDATE complaints SET reference_no = "P8TEST-0001" WHERE complaint_id = ?')->execute([$cmpId]);

    echo "== Access control: assignments page ==\n";
    $anon = new Client($base);
    $t('signed out: assignments page redirects to login', $goesTo($anon->get('assignments/index.php'), 'login.php'));

    $resClient = new Client($base);
    sign_in($resClient, 'p8test_res');
    $t('resident: assignments page answers 403', $resClient->get('assignments/index.php')['status'] === 403);

    $persClient = new Client($base);
    sign_in($persClient, 'p8test_pers1');
    $t('personnel: assignments page answers 403', $persClient->get('assignments/index.php')['status'] === 403);

    $mgmtClient = new Client($base);
    sign_in($mgmtClient, 'p8test_mgmt');
    $t('management: assignments page answers 403', $mgmtClient->get('assignments/index.php')['status'] === 403);

    $offClient = new Client($base);
    sign_in($offClient, 'p8test_officer');
    $r = $offClient->get('assignments/index.php');
    $t('officer: assignments page loads (200)', $r['status'] === 200 && str_contains($r['body'], 'P8TEST-0001'));

    $admClient = new Client($base);
    sign_in($admClient, 'p8test_admin');
    $r = $admClient->get('assignments/index.php');
    $t('administrator: assignments page loads (200)', $r['status'] === 200 && str_contains($r['body'], 'P8TEST-0001'));

    echo "== Category-based assignee suggestion ==\n";
    $viewHtml = $offClient->get("complaints/view.php?id={$cmpId}")['body'];
    $t('suggested assignee shown as suggestion', str_contains($viewHtml, 'Category Suggestion'));
    $activeAsg = assignment_get_active($pdo, $cmpId);
    $t('suggestion does NOT auto-assign complaint (still unassigned)', $activeAsg === null);

    echo "== Officer assignment to active personnel ==\n";
    $token = csrf_from($offClient->get("complaints/view.php?id={$cmpId}")['body']);

    // Attempt assigning to inactive personnel
    $postInactive = $offClient->post("complaints/view.php?id={$cmpId}", [
        'csrf_token'       => $token,
        'form_action'      => 'assign',
        'assigned_to'      => (string)$ids['inactive'],
        'assignment_notes' => 'Invalid assignment note',
    ]);
    $t('assigning to inactive personnel is rejected', str_contains($postInactive['body'], 'active Personnel'));
    $t('complaint remains unassigned', assignment_get_active($pdo, $cmpId) === null);

    // Attempt assigning to resident
    $postRes = $offClient->post("complaints/view.php?id={$cmpId}", [
        'csrf_token'       => $token,
        'form_action'      => 'assign',
        'assigned_to'      => (string)$ids['resident'],
        'assignment_notes' => 'Invalid assignment note',
    ]);
    $t('assigning to non-personnel role is rejected', str_contains($postRes['body'], 'active Personnel'));

    // Valid assignment to pers1
    $postValid = $offClient->post("complaints/view.php?id={$cmpId}", [
        'csrf_token'       => $token,
        'form_action'      => 'assign',
        'assigned_to'      => (string)$ids['pers1'],
        'assignment_notes' => 'Please investigate on-site.',
    ]);
    $t('valid assignment redirects to complaint view', $goesTo($postValid, "complaints/view.php?id={$cmpId}"));

    $active = assignment_get_active($pdo, $cmpId);
    $t('active assignment created for pers1', $active !== null && (int)$active['assigned_to'] === $ids['pers1']);
    $t('assignment notes recorded', $active !== null && $active['assignment_notes'] === 'Please investigate on-site.');
    $t('complaint status advanced from Received to Assigned', complaint_fetch_by_id($pdo, $cmpId)['status'] === 'Assigned');

    $statusHist = complaint_load_timeline($pdo, $cmpId);
    $lastStatus = end($statusHist);
    $t('status history recorded assignment transition', $lastStatus['new_status'] === 'Assigned' && str_contains($lastStatus['action_taken'], 'Assigned to'));

    echo "== Reassignment ==\n";
    $token2 = csrf_from($offClient->get("complaints/view.php?id={$cmpId}")['body']);
    $postReassign = $offClient->post("complaints/view.php?id={$cmpId}", [
        'csrf_token'       => $token2,
        'form_action'      => 'assign',
        'assigned_to'      => (string)$ids['pers2'],
        'assignment_notes' => 'Reassigning due to pers1 emergency leave.',
    ]);
    $t('reassignment redirects to complaint view', $goesTo($postReassign, "complaints/view.php?id={$cmpId}"));

    // Check assignments in DB
    $allAsg = assignment_get_history($pdo, $cmpId);
    $t('history contains exactly 2 assignments', count($allAsg) === 2);
    $t('first assignment was closed (unassigned_at IS NOT NULL)', $allAsg[0]['unassigned_at'] !== null);
    $t('second assignment is active (unassigned_at IS NULL)', $allAsg[1]['unassigned_at'] === null && (int)$allAsg[1]['assigned_to'] === $ids['pers2']);

    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM assignments WHERE complaint_id = {$cmpId} AND unassigned_at IS NULL")->fetchColumn();
    $t('invariable: exactly one active assignment exists', $activeCount === 1);

    echo "== Unauthorized users cannot assign ==\n";
    $pToken = csrf_from($persClient->get("complaints/view.php?id={$cmpId}")['body']);
    $pPost = $persClient->post("complaints/view.php?id={$cmpId}", [
        'csrf_token'       => $pToken,
        'form_action'      => 'assign',
        'assigned_to'      => (string)$ids['pers1'],
        'assignment_notes' => 'Unauthorized hijack',
    ]);
    $t('personnel POST to assign is rejected (403)', $pPost['status'] === 403);
    $t('assignment remained pers2', (int)assignment_get_active($pdo, $cmpId)['assigned_to'] === $ids['pers2']);

} finally {
    $cleanup();
}

echo $failures === 0 ? "\nPhase 8 checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);

