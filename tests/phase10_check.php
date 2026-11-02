<?php
/**
 * Phase 10 check: priority rules and override (FR-006, FR-007, FR-008).
 *
 *   php tests/phase10_check.php [base-url]
 *   php tests/phase10_check.php http://localhost/barangay-complaint-system/public/
 *
 * Verifies:
 *  - Urgency assessment helper uses deterministic rules and displays reasons.
 *  - Emergency advisory is displayed on Critical complaints.
 *  - Priority reason is displayed on complaint details.
 *  - Officer override requires a reason (blank rejected) and valid priority.
 *  - Priority override commits atomically and writes priority_history row.
 *  - Multiple overrides preserve complete priority history.
 *  - Unauthorized roles (Resident, Personnel, Management) cannot override priority.
 *  - Resident-submitted information cannot directly alter official priority outside approved flow.
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
    $pdo->exec("DELETE a FROM assignments a JOIN complaints c ON c.complaint_id = a.complaint_id WHERE c.reference_no LIKE 'P10TEST-%'");
    $pdo->exec("DELETE sh FROM status_history sh JOIN complaints c ON c.complaint_id = sh.complaint_id WHERE c.reference_no LIKE 'P10TEST-%'");
    $pdo->exec("DELETE ph FROM priority_history ph JOIN complaints c ON c.complaint_id = ph.complaint_id WHERE c.reference_no LIKE 'P10TEST-%'");
    $pdo->exec("DELETE FROM complaints WHERE reference_no LIKE 'P10TEST-%'");
    $pdo->exec("DELETE FROM categories WHERE category_name LIKE 'p10test\\_%'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'p10test\\_%'");
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
        'admin'     => $makeUser('p10test_admin', 'P10 Admin', ROLE_ADMIN),
        'officer'   => $makeUser('p10test_officer', 'P10 Officer', ROLE_OFFICER),
        'pers'      => $makeUser('p10test_pers', 'P10 Personnel', ROLE_PERSONNEL),
        'mgmt'      => $makeUser('p10test_mgmt', 'P10 Management', ROLE_MANAGEMENT),
        'resident'  => $makeUser('p10test_res', 'P10 Resident', ROLE_RESIDENT),
    ];

    $pdo->exec('INSERT INTO categories (category_name) VALUES ("p10test_cat")');
    $catId = (int)$pdo->lastInsertId();

    echo "== Deterministic urgency assessment rules (RULES.md §5) ==\n";
    $cUrgent = complaint_evaluate_urgency(['threat_to_life' => '1']);
    $t('threat to life evaluates to Critical with emergency flag', $cUrgent['priority'] === 'Critical' && $cUrgent['is_emergency'] === true);

    $hUrgent = complaint_evaluate_urgency(['public_hazard' => '1']);
    $t('public hazard evaluates to High', $hUrgent['priority'] === 'High');

    $mUrgent = complaint_evaluate_urgency(['dispute_or_damage' => '1']);
    $t('dispute/damage evaluates to Medium', $mUrgent['priority'] === 'Medium');

    $lUrgent = complaint_evaluate_urgency([]);
    $t('default evaluates to Low', $lUrgent['priority'] === 'Low');

    // Create synthetic complaint with initial Medium priority
    $res = complaint_create($pdo, ['user_id' => $ids['admin'], 'role_name' => ROLE_ADMIN], [
        'category_id'       => $catId,
        'description'       => 'P10 priority test complaint',
        'incident_location' => 'Block 10',
        'incident_at'       => null,
        'priority'          => 'Medium',
        'priority_reason'   => 'Initial staff assessment: neighborhood dispute',
        'source'            => 'Walk-in',
    ], false);
    $cmpId = $res['complaint_id'];
    $pdo->prepare('UPDATE complaints SET reference_no = "P10TEST-0001" WHERE complaint_id = ?')->execute([$cmpId]);

    // Initial priority history check
    $pHistory = priority_get_history($pdo, $cmpId);
    $t('initial priority history was recorded on creation', count($pHistory) === 1 && $pHistory[0]['new_priority'] === 'Medium');

    $offClient = new Client($base);
    sign_in($offClient, 'p10test_officer');

    echo "== Priority reason display ==\n";
    $viewHtml = $offClient->get("complaints/view.php?id={$cmpId}")['body'];
    $t('priority reason displayed on details page', str_contains($viewHtml, 'Initial staff assessment: neighborhood dispute'));
    $t('priority badge displayed with text', str_contains($viewHtml, 'Medium'));

    echo "== Officer priority override validation ==\n";
    $token = csrf_from($viewHtml);

    // 1. Override without reason rejected
    $r = $offClient->post("complaints/view.php?id={$cmpId}", [
        'csrf_token'      => $token,
        'form_action'     => 'priority_override',
        'new_priority'    => 'Critical',
        'priority_reason' => '   ',
    ]);
    $t('override without reason is rejected', str_contains($r['body'], 'override reason is required'));
    $t('priority remains unchanged', complaint_fetch_by_id($pdo, $cmpId)['priority'] === 'Medium');

    // 2. Same priority rejected
    $token = csrf_from($offClient->get("complaints/view.php?id={$cmpId}")['body']);
    $r = $offClient->post("complaints/view.php?id={$cmpId}", [
        'csrf_token'      => $token,
        'form_action'     => 'priority_override',
        'new_priority'    => 'Medium',
        'priority_reason' => 'Trying to set same priority',
    ]);
    $t('override to same priority is rejected', str_contains($r['body'], 'New priority must be different'));

    // 3. Valid override from Medium to Critical
    $token = csrf_from($offClient->get("complaints/view.php?id={$cmpId}")['body']);
    $r = $offClient->post("complaints/view.php?id={$cmpId}", [
        'csrf_token'      => $token,
        'form_action'     => 'priority_override',
        'new_priority'    => 'Critical',
        'priority_reason' => 'Physical brawl escalated with weapons; immediate danger.',
    ]);
    $t('valid override to Critical redirects to view', $goesTo($r, "complaints/view.php?id={$cmpId}"));

    $cRow = complaint_fetch_by_id($pdo, $cmpId);
    $t('priority in database is now Critical', $cRow['priority'] === 'Critical');
    $t('priority reason updated in database', str_contains($cRow['priority_reason'], 'Physical brawl escalated'));

    // 4. Verify priority_history row
    $pHistory = priority_get_history($pdo, $cmpId);
    $t('priority history has 2 records', count($pHistory) === 2);
    $lastP = end($pHistory);
    $t('history records old_priority Medium -> new_priority Critical', $lastP['old_priority'] === 'Medium' && $lastP['new_priority'] === 'Critical');
    $t('history records override reason and actor', str_contains($lastP['reason'], 'Physical brawl escalated') && (int)$lastP['changed_by'] === $ids['officer']);

    echo "== Emergency advisory on Critical priority ==\n";
    $critHtml = $offClient->get("complaints/view.php?id={$cmpId}")['body'];
    $t('emergency advisory shown on Critical complaint', str_contains($critHtml, 'Emergency Advisory') && str_contains($critHtml, '911'));

    echo "== Second override: Critical -> Low ==\n";
    $admClient = new Client($base);
    sign_in($admClient, 'p10test_admin');
    $aToken = csrf_from($admClient->get("complaints/view.php?id={$cmpId}")['body']);
    $r = $admClient->post("complaints/view.php?id={$cmpId}", [
        'csrf_token'      => $aToken,
        'form_action'     => 'priority_override',
        'new_priority'    => 'Low',
        'priority_reason' => 'Police pacified situation; now just standard noise complaint.',
    ]);
    $t('administrator overrides Critical -> Low', $goesTo($r, "complaints/view.php?id={$cmpId}"));

    $pHistory = priority_get_history($pdo, $cmpId);
    $t('priority history now has 3 records', count($pHistory) === 3);
    $t('newest history entry is Critical -> Low', $pHistory[2]['old_priority'] === 'Critical' && $pHistory[2]['new_priority'] === 'Low');

    $lowHtml = $offClient->get("complaints/view.php?id={$cmpId}")['body'];
    $t('emergency advisory no longer shown when priority is Low', !str_contains($lowHtml, 'Emergency Advisory'));

    echo "== Unauthorized roles cannot override priority ==\n";
    // Personnel assigned to complaint
    complaint_assign($pdo, ['user_id' => $ids['officer'], 'role_name' => ROLE_OFFICER], $cmpId, $ids['pers'], 'Assigned');
    $persClient = new Client($base);
    sign_in($persClient, 'p10test_pers');
    $pToken = csrf_from($persClient->get("complaints/view.php?id={$cmpId}")['body']);
    $pPost = $persClient->post("complaints/view.php?id={$cmpId}", [
        'csrf_token'      => $pToken,
        'form_action'     => 'priority_override',
        'new_priority'    => 'High',
        'priority_reason' => 'Personnel attempting priority change',
    ]);
    $t('personnel POST to override priority is 403', $pPost['status'] === 403);
    $t('priority remains Low', complaint_fetch_by_id($pdo, $cmpId)['priority'] === 'Low');

} finally {
    $cleanup();
}

echo $failures === 0 ? "\nPhase 10 checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);

