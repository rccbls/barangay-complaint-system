<?php
/**
 * Phase 3 check: resident profile and account management.
 *
 *   php tests/phase3_check.php [base-url]
 *   php tests/phase3_check.php http://localhost/barangay-complaint-system/public/
 *
 * Needs the web server running, the Phase 1 database, and APP_ENV=local. It creates SYNTHETIC
 * users named "p3test_*" and deletes them afterwards. It also runs database/backfill_resident_profiles.php,
 * which (idempotently) gives every Resident account that lacks a profile one, as intended.
 * Exit code 0 = all checks passed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';
require_once APP_ROOT . '/app/Services/Authorization.php';
require_once __DIR__ . '/support/http.php';

if (!app_is_local()) {
    fwrite(STDERR, "Refusing to run: this test creates synthetic users. Set APP_ENV=local in .env.\n");
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

$pdo  = db_connect(true);
$hash = password_hash(TEST_PASSWORD, PASSWORD_DEFAULT);

$cleanup = static function () use ($pdo): void {
    $pdo->exec("DELETE FROM complaints WHERE reference_no LIKE 'P3TEST-%'");
    $pdo->exec("DELETE FROM categories WHERE category_name = 'p3test_category'");
    $pdo->exec("DELETE c FROM complainants c JOIN users u ON u.user_id = c.user_id WHERE u.username LIKE 'p3test\\_%'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'p3test\\_%'");
};
$makeUser = static function (string $username, string $full, string $role, int $active = 1) use ($pdo, $hash): int {
    $stmt = $pdo->prepare('INSERT INTO users (role_id, full_name, username, password_hash, is_active)
                           SELECT role_id, ?, ?, ?, ? FROM roles WHERE role_name = ?');
    $stmt->execute([$full, $username, $hash, $active, $role]);
    return (int) $pdo->lastInsertId();
};
$makeProfile = static function (int $userId, string $full, ?string $contact, ?string $address) use ($pdo): int {
    $pdo->prepare('INSERT INTO complainants (user_id, full_name, contact_number, address) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $full, $contact, $address]);
    return (int) $pdo->lastInsertId();
};
$scalar = static function (string $sql, array $params = []) use ($pdo) {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchColumn();
};
$profileCount = static fn(int $uid): int => (int) $scalar('SELECT COUNT(*) FROM complainants WHERE user_id = ?', [$uid]);
$isActive     = static fn(int $uid): int => (int) $scalar('SELECT is_active FROM users WHERE user_id = ?', [$uid]);

$cleanup();
try {
    $A_CONTACT = '0917 111 2222';
    $B_CONTACT = '0928 333 4444';
    $ids = [
        'a'       => $makeUser('p3test_res_a', 'P3 Resident A', ROLE_RESIDENT),
        'b'       => $makeUser('p3test_res_b', 'P3 Resident B', ROLE_RESIDENT),
        'legacy'  => $makeUser('p3test_legacy', 'P3 Legacy Resident', ROLE_RESIDENT),   // no profile: pre-Phase-3 style
        'admin'   => $makeUser('p3test_admin', 'P3 Admin', ROLE_ADMIN),
        'officer' => $makeUser('p3test_officer', 'P3 Officer', ROLE_OFFICER),
        'person'  => $makeUser('p3test_personnel', 'P3 Personnel', ROLE_PERSONNEL),
        'mgmt'    => $makeUser('p3test_mgmt', 'P3 Management', ROLE_MANAGEMENT),
    ];
    $profA = $makeProfile($ids['a'], 'P3 Resident A', $A_CONTACT, '1 Alpha Street');
    $profB = $makeProfile($ids['b'], 'P3 Resident B', $B_CONTACT, '2 Bravo Avenue');
    for ($i = 1; $i <= 22; $i++) {
        $makeUser(sprintf('p3test_pg%02d', $i), sprintf('P3 Pager %02d', $i), ROLE_RESIDENT);
    }

    echo "== Profile structure: exactly one profile per account ==\n";
    $dupBlocked = false;
    try {
        $makeProfile($ids['a'], 'Duplicate', null, null);
    } catch (PDOException $e) {
        $dupBlocked = (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
    $t('database rejects a second profile for the same account', $dupBlocked && $profileCount($ids['a']) === 1);
    $t('staff accounts have no resident profile', (int) $scalar("SELECT COUNT(*) FROM complainants WHERE user_id IN (?,?,?,?)", [$ids['admin'], $ids['officer'], $ids['person'], $ids['mgmt']]) === 0);

    echo "== Registration creates the account AND its profile ==\n";
    $c = new Client($base);
    $reg = static function (Client $c, array $f) {
        $f['csrf_token'] = csrf_from($c->get('register.php')['body']);
        return $c->post('register.php', $f);
    };
    $good = ['full_name' => 'P3 Registered', 'username' => 'p3test_reg1', 'password' => TEST_PASSWORD, 'password_confirm' => TEST_PASSWORD];
    $before = (int) $scalar('SELECT COUNT(*) FROM complainants');
    $r = $reg($c, ['username' => 'p3test_res_a'] + $good);
    $t('duplicate-username registration is rejected', str_contains($r['body'], 'already taken'));
    $t('...and leaves no orphan profile row', (int) $scalar('SELECT COUNT(*) FROM complainants') === $before);
    $r = $reg($c, $good);
    $t('valid registration redirects to sign-in', $goesTo($r, 'login.php'));
    $regId = (int) $scalar("SELECT user_id FROM users WHERE username = 'p3test_reg1'");
    $p = $pdo->prepare('SELECT * FROM complainants WHERE user_id = ?');
    $p->execute([$regId]);
    $rows = $p->fetchAll();
    $t('registered resident has exactly one profile linked to the account', count($rows) === 1 && (int) $rows[0]['user_id'] === $regId);
    $t('profile name copied; contact number and address left empty', $rows && $rows[0]['full_name'] === 'P3 Registered' && $rows[0]['contact_number'] === null && $rows[0]['address'] === null);
    $r = sign_in($c, 'p3test_reg1');
    $t('newly registered resident can authenticate', $goesTo($r, 'resident/index.php'));

    echo "== Resident profile page: own data only ==\n";
    $cA = new Client($base);
    sign_in($cA, 'p3test_res_a');
    $r = $cA->get('resident/profile/index.php');
    $t('resident A sees own profile data', $r['status'] === 200 && str_contains($r['body'], $A_CONTACT) && str_contains($r['body'], '1 Alpha Street'));
    $t('resident A does NOT see resident B data', !str_contains($r['body'], $B_CONTACT) && !str_contains($r['body'], '2 Bravo Avenue') && !str_contains($r['body'], 'P3 Resident B'));
    $r = $cA->get('resident/profile/index.php?id=' . $ids['b'] . '&user_id=' . $ids['b'] . '&complainant_id=' . $profB);
    $t('ID parameters in the URL cannot select another resident\'s profile', !str_contains($r['body'], $B_CONTACT) && str_contains($r['body'], $A_CONTACT));
    $t('profile page sends Cache-Control: no-store', stripos(implode("\n", $r['headers']), 'Cache-Control: no-store') !== false);

    echo "== Legacy account (no profile) gets exactly one on first use ==\n";
    $cL = new Client($base);
    sign_in($cL, 'p3test_legacy');
    $t('legacy resident starts with no profile', $profileCount($ids['legacy']) === 0);
    $r = $cL->get('resident/profile/index.php');
    $t('profile page works and creates it', $r['status'] === 200 && $profileCount($ids['legacy']) === 1);
    $cL->get('resident/profile/index.php');
    $t('opening it again does not create a duplicate', $profileCount($ids['legacy']) === 1);

    echo "== Editing the profile ==\n";
    $post = static function (Client $c, array $f) {
        $f['csrf_token'] = csrf_from($c->get('resident/profile/index.php')['body']);
        return $c->post('resident/profile/index.php', $f);
    };
    $snapshotB = $pdo->query("SELECT full_name, contact_number, address FROM complainants WHERE user_id = {$ids['b']}")->fetch();
    $r = $cA->post('resident/profile/index.php', ['full_name' => 'X', 'contact_number' => '1', 'address' => 'x']);
    $t('profile POST without CSRF token is rejected (403)', $r['status'] === 403);

    $r = $post($cA, ['full_name' => '   ', 'contact_number' => $A_CONTACT, 'address' => '1 Alpha Street']);
    $t('blank name rejected', $r['status'] === 200 && str_contains($r['body'], 'Enter your full name'));
    $r = $post($cA, ['full_name' => 'P3 Resident A', 'contact_number' => 'call me', 'address' => '1 Alpha Street']);
    $t('invalid contact number rejected', str_contains($r['body'], 'valid contact number'));
    $r = $post($cA, ['full_name' => 'P3 Resident A', 'contact_number' => $A_CONTACT, 'address' => str_repeat('a', 256)]);
    $t('address over 255 characters rejected', str_contains($r['body'], '255 characters'));
    $t('rejected submissions changed nothing', $scalar('SELECT contact_number FROM complainants WHERE user_id = ?', [$ids['a']]) === $A_CONTACT
        && $scalar('SELECT address FROM complainants WHERE user_id = ?', [$ids['a']]) === '1 Alpha Street');

    $r = $post($cA, ['full_name' => 'P3 Resident A Renamed', 'contact_number' => '+63 917 555 6666', 'address' => 'Unit 5, Gamma St <b>x</b>',
        // tampering attempts: none of these may be honoured
        'user_id' => (string) $ids['b'], 'complainant_id' => (string) $profB, 'role' => 'Administrator', 'is_active' => '0']);
    $t('valid update redirects back to the profile', $goesTo($r, 'resident/profile/index.php'));
    $rowA = $pdo->query("SELECT full_name, contact_number, address FROM complainants WHERE user_id = {$ids['a']}")->fetch();
    $t('own profile updated', $rowA['full_name'] === 'P3 Resident A Renamed' && $rowA['contact_number'] === '+63 917 555 6666');
    $t('account display name kept in step with profile name', $scalar('SELECT full_name FROM users WHERE user_id = ?', [$ids['a']]) === 'P3 Resident A Renamed');
    $t('tampered user_id/complainant_id did not change resident B', $pdo->query("SELECT full_name, contact_number, address FROM complainants WHERE user_id = {$ids['b']}")->fetch() === $snapshotB);
    $t('tampered role/is_active fields ignored', $scalar("SELECT r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?", [$ids['a']]) === 'Resident' && $isActive($ids['a']) === 1);
    $body = $cA->get('resident/profile/index.php')['body'];
    $t('HTML in the address is escaped on the page', str_contains($body, '&lt;b&gt;x&lt;/b&gt;') && !str_contains($body, '<b>x</b>'));
    $t('header shows the updated name', str_contains($cA->get('resident/index.php')['body'], 'P3 Resident A Renamed'));
    $r = $post($cA, ['full_name' => 'P3 Resident A', 'contact_number' => '', 'address' => '']);
    $rowA = $pdo->query("SELECT contact_number, address FROM complainants WHERE user_id = {$ids['a']}")->fetch();
    $t('optional fields can be cleared (stored as NULL)', $rowA['contact_number'] === null && $rowA['address'] === null);
    $pdo->prepare('UPDATE complainants SET contact_number = ?, address = ? WHERE user_id = ?')->execute([$A_CONTACT, '1 Alpha Street', $ids['a']]);

    echo "== Other roles and signed-out visitors cannot use the resident profile page ==\n";
    $t('signed out: redirected to sign-in', $goesTo((new Client($base))->get('resident/profile/index.php'), 'login.php'));
    foreach (['admin', 'officer', 'person', 'mgmt'] as $k) {
        $cx = new Client($base);
        sign_in($cx, ['admin' => 'p3test_admin', 'officer' => 'p3test_officer', 'person' => 'p3test_personnel', 'mgmt' => 'p3test_mgmt'][$k]);
        $r = $cx->get('resident/profile/index.php');
        $t("$k typing the resident profile URL gets 403", $r['status'] === 403 && !str_contains($r['body'], $A_CONTACT));
    }

    echo "== Administrator: resident accounts list ==\n";
    $cAdmin = new Client($base);
    sign_in($cAdmin, 'p3test_admin');
    $r = $cAdmin->get('residents/index.php?q=p3test_res');
    $t('administrator can open the resident list', $r['status'] === 200 && str_contains($r['body'], 'p3test_res_a') && str_contains($r['body'], 'p3test_res_b'));
    $t('list shows NO contact numbers or addresses', !str_contains($r['body'], $A_CONTACT) && !str_contains($r['body'], $B_CONTACT) && !str_contains($r['body'], 'Alpha Street'));
    $r = $cAdmin->get('residents/index.php?q=p3test_');
    $t('staff accounts never appear in the resident list', !str_contains($r['body'], 'p3test_officer') && !str_contains($r['body'], 'p3test_admin'));
    $r = $cAdmin->get('residents/index.php?q=p3test_res_b');
    $t('search narrows the list', str_contains($r['body'], 'p3test_res_b') && !str_contains($r['body'], 'p3test_res_a'));
    $r = $cAdmin->get('residents/index.php?q=%25');
    $t('search treats % literally (no wildcard)', !str_contains($r['body'], 'p3test_'));
    $p1 = $cAdmin->get('residents/index.php?q=p3test_pg');
    $p2 = $cAdmin->get('residents/index.php?q=p3test_pg&page=2');
    $t('pagination: page 1 shows 20 of 22', preg_match_all('/<td>p3test_pg\d\d<\/td>/', $p1['body']) === 20 && str_contains($p1['body'], 'of 22'));
    $t('pagination: page 2 shows the remaining 2', preg_match_all('/<td>p3test_pg\d\d<\/td>/', $p2['body']) === 2);
    $pdo->prepare("UPDATE users SET is_active = 0 WHERE username = 'p3test_pg01'")->execute();
    $r = $cAdmin->get('residents/index.php?q=p3test_pg&status=inactive');
    $t('status filter works', preg_match_all('/<td>p3test_pg\d\d<\/td>/', $r['body']) === 1 && str_contains($r['body'], 'p3test_pg01'));

    echo "== Only an Administrator may manage resident accounts ==\n";
    $t('signed out: list redirects to sign-in', $goesTo((new Client($base))->get('residents/index.php'), 'login.php'));
    $statusUrl = 'residents/status.php?id=' . $ids['b'] . '&action=deactivate';
    foreach ([['a', 'p3test_res_a'], ['officer', 'p3test_officer'], ['person', 'p3test_personnel'], ['mgmt', 'p3test_mgmt']] as [$k, $u]) {
        $cx = new Client($base);
        sign_in($cx, $u);
        $token = csrf_from($cx->get($k === 'a' ? 'resident/index.php' : 'dashboard.php')['body']);
        $t("$k: resident list is 403", $cx->get('residents/index.php')['status'] === 403);
        $t("$k: confirmation page is 403", $cx->get($statusUrl)['status'] === 403);
        $r = $cx->post('residents/status.php', ['csrf_token' => $token, 'user_id' => (string) $ids['b'], 'action' => 'deactivate']);
        $t("$k: direct POST is 403 and changes nothing", $r['status'] === 403 && $isActive($ids['b']) === 1);
    }

    echo "== Administrator deactivates and reactivates a resident ==\n";
    $r = $cAdmin->get($statusUrl);
    $t('GET shows a confirmation page and changes nothing', $r['status'] === 200 && str_contains($r['body'], 'P3 Resident B') && $isActive($ids['b']) === 1);
    $r = $cAdmin->post('residents/status.php', ['user_id' => (string) $ids['b'], 'action' => 'deactivate']);
    $t('POST without CSRF token is rejected', $r['status'] === 403 && $isActive($ids['b']) === 1);

    // a complaint linked to resident B's profile, to prove records survive deactivation
    $pdo->exec("INSERT INTO categories (category_name) VALUES ('p3test_category')");
    $catId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO complaints (reference_no, complainant_id, submitted_by_user_id, category_id, description, source, priority, priority_reason)
                   VALUES ('P3TEST-B', ?, ?, ?, 'synthetic', 'Online', 'Low', 'synthetic test')")->execute([$profB, $ids['b'], $catId]);
    $t('complaint is linked to the resident profile, which is linked to the account',
        (int) $scalar('SELECT u.user_id FROM complaints c JOIN complainants p ON p.complainant_id = c.complainant_id JOIN users u ON u.user_id = p.user_id WHERE c.reference_no = ?', ['P3TEST-B']) === $ids['b']);

    $cB = new Client($base);
    sign_in($cB, 'p3test_res_b');
    $t('resident B is signed in before deactivation', $cB->get('resident/profile/index.php')['status'] === 200);

    $token = csrf_from($cAdmin->get($statusUrl)['body']);
    $r = $cAdmin->post('residents/status.php', ['csrf_token' => $token, 'user_id' => (string) $ids['b'], 'action' => 'deactivate']);
    $t('administrator deactivates resident B', $goesTo($r, 'residents/index.php') && $isActive($ids['b']) === 0);
    $t('deactivated resident is cut off on the next request', $goesTo($cB->get('resident/profile/index.php'), 'login.php'));
    $r = sign_in(new Client($base), 'p3test_res_b');
    $t('deactivated resident cannot sign in', $r['status'] === 200 && str_contains($r['body'], 'This account is inactive'));
    $t('profile and complaint are kept', $profileCount($ids['b']) === 1 && (int) $scalar("SELECT COUNT(*) FROM complaints WHERE reference_no = 'P3TEST-B'") === 1);
    $r = $cAdmin->get('residents/index.php?q=p3test_res_b');
    $t('list now shows B as Inactive', str_contains($r['body'], 'Inactive'));

    $token = csrf_from($cAdmin->get('residents/status.php?id=' . $ids['b'] . '&action=deactivate')['body']);
    $cAdmin->post('residents/status.php', ['csrf_token' => $token, 'user_id' => (string) $ids['b'], 'action' => 'deactivate']);
    $t('deactivating an already inactive account is harmless', $isActive($ids['b']) === 0);

    $token = csrf_from($cAdmin->get('residents/status.php?id=' . $ids['b'] . '&action=activate')['body']);
    $r = $cAdmin->post('residents/status.php', ['csrf_token' => $token, 'user_id' => (string) $ids['b'], 'action' => 'activate']);
    $t('administrator reactivates resident B', $goesTo($r, 'residents/index.php') && $isActive($ids['b']) === 1);
    $r = sign_in(new Client($base), 'p3test_res_b');
    $t('reactivated resident can sign in again', $goesTo($r, 'resident/index.php'));

    echo "== The status endpoint cannot touch staff accounts or bad input ==\n";
    $token = csrf_from($cAdmin->get('residents/index.php')['body']);
    foreach (['officer', 'admin'] as $k) {
        $r = $cAdmin->post('residents/status.php', ['csrf_token' => $token, 'user_id' => (string) $ids[$k], 'action' => 'deactivate']);
        $t("staff account ($k) cannot be deactivated through the resident endpoint", $r['status'] === 404 && $isActive($ids[$k]) === 1);
    }
    $r = $cAdmin->post('residents/status.php', ['csrf_token' => $token, 'user_id' => '999999999', 'action' => 'deactivate']);
    $t('nonexistent account gives 404', $r['status'] === 404);
    $r = $cAdmin->post('residents/status.php', ['csrf_token' => $token, 'user_id' => (string) $ids['b'], 'action' => 'delete']);
    $t('unknown action gives 404 and changes nothing', $r['status'] === 404 && $isActive($ids['b']) === 1);

    echo "== Backfill script ==\n";
    $bf = $makeUser('p3test_backfill', 'P3 Backfill', ROLE_RESIDENT);
    $run = static function () {
        return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/database/backfill_resident_profiles.php') . ' 2>&1');
    };
    $out1 = $run();
    $t('backfill creates the missing profile (name copied)', $profileCount($bf) === 1 && $scalar('SELECT full_name FROM complainants WHERE user_id = ?', [$bf]) === 'P3 Backfill', trim($out1));
    $t('running it again creates nothing and no duplicates', str_contains($run(), 'Created 0') && $profileCount($bf) === 1);

    echo "== Whole-database invariants ==\n";
    $t('no account has more than one profile', (int) $scalar('SELECT COUNT(*) FROM (SELECT user_id FROM complainants WHERE user_id IS NOT NULL GROUP BY user_id HAVING COUNT(*) > 1) d') === 0);
    $t('no staff account is linked to a profile', (int) $scalar("SELECT COUNT(*) FROM complainants c JOIN users u ON u.user_id = c.user_id JOIN roles r ON r.role_id = u.role_id WHERE r.role_name <> 'Resident'") === 0);
    $t('no Resident account is without a profile after backfill', (int) $scalar("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Resident' AND NOT EXISTS (SELECT 1 FROM complainants c WHERE c.user_id = u.user_id)") === 0);
} finally {
    $cleanup();
    $left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'p3test\\_%'")->fetchColumn();
    printf("(cleanup: %d synthetic test users remain)\n", $left);
}

echo $failures === 0 ? "\nPhase 3 checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
