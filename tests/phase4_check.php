<?php
/**
 * Phase 4 check: administrator user and category management.
 *
 *   php tests/phase4_check.php [base-url]
 *   php tests/phase4_check.php http://localhost/barangay-complaint-system/public/
 *
 * Needs the web server running, the Phase 1 database, and APP_ENV=local. Creates SYNTHETIC rows
 * (users "p4test_*", categories "p4test_*", complaints "P4TEST-*") and deletes them afterwards.
 * Rules that need a special database state (last Administrator, last Complaint Officer, only active
 * category) are tested inside transactions that are rolled back, so real data is never changed.
 * Exit code 0 = all checks passed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';
foreach (['Services/Authorization', 'Services/Validation', 'Repositories/UserRepository', 'Repositories/StaffRepository',
          'Repositories/CategoryRepository', 'Services/StaffService', 'Services/CategoryService'] as $f) {
    require_once APP_ROOT . "/app/$f.php";
}
require_once __DIR__ . '/support/http.php';

if (!app_is_local()) {
    fwrite(STDERR, "Refusing to run: this test creates synthetic rows. Set APP_ENV=local in .env.\n");
    exit(1);
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/', '/') . '/';
const NEW_PASSWORD = 'Another-Synth-Pass-456';

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
    $pdo->exec("DELETE a FROM assignments a JOIN complaints c ON c.complaint_id = a.complaint_id WHERE c.reference_no LIKE 'P4TEST-%'");
    $pdo->exec("DELETE FROM complaints WHERE reference_no LIKE 'P4TEST-%'");
    $pdo->exec("DELETE FROM categories WHERE category_name LIKE 'p4test\\_%' OR category_name LIKE 'P4TEST\\_%'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'p4test\\_%'");
};
$makeUser = static function (string $username, string $full, string $role, int $active = 1) use ($pdo, $hash): int {
    $stmt = $pdo->prepare('INSERT INTO users (role_id, full_name, username, password_hash, is_active)
                           SELECT role_id, ?, ?, ?, ? FROM roles WHERE role_name = ?');
    $stmt->execute([$full, $username, $hash, $active, $role]);
    return (int) $pdo->lastInsertId();
};
$one = static function (string $sql, array $params = []) use ($pdo) {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchColumn();
};
$row = static function (string $sql, array $params = []) use ($pdo) {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetch();
};
$userActive = static fn(int $id): int => (int) $one('SELECT is_active FROM users WHERE user_id = ?', [$id]);
$userRole   = static fn(int $id): string => (string) $one('SELECT r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?', [$id]);
$countUsers = static fn(): int => (int) $one('SELECT COUNT(*) FROM users');
$countCats  = static fn(): int => (int) $one('SELECT COUNT(*) FROM categories');
$catActive  = static fn(int $id): int => (int) $one('SELECT is_active FROM categories WHERE category_id = ?', [$id]);

register_shutdown_function($cleanup); // also removes synthetic rows if the run dies with a fatal error
$cleanup();
try {
    $ids = [
        'admin'  => $makeUser('p4test_admin', 'P4 Admin', ROLE_ADMIN),
        'admin2' => $makeUser('p4test_admin2', 'P4 Admin Two', ROLE_ADMIN),
        'off'    => $makeUser('p4test_officer', 'P4 Officer', ROLE_OFFICER),
        'p1'     => $makeUser('p4test_pers1', 'P4 Personnel One', ROLE_PERSONNEL),
        'p2'     => $makeUser('p4test_pers2', 'P4 Personnel Two', ROLE_PERSONNEL),
        'p3'     => $makeUser('p4test_pers3', 'P4 Personnel Three', ROLE_PERSONNEL),
        'pinact' => $makeUser('p4test_pinactive', 'P4 Inactive Personnel', ROLE_PERSONNEL, 0),
        'mgmt'   => $makeUser('p4test_mgmt', 'P4 Management', ROLE_MANAGEMENT),
        'res'    => $makeUser('p4test_res', 'P4 Resident', ROLE_RESIDENT),
    ];
    $pdo->exec("INSERT INTO categories (category_name) VALUES ('p4test_cat')");
    $catId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO complaints (reference_no, category_id, description, priority, priority_reason, status)
                   VALUES ('P4TEST-1', ?, 'synthetic', 'Low', 'synthetic test', 'In Progress')")->execute([$catId]);
    $cmpId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO assignments (complaint_id, assigned_to, assigned_by) VALUES (?, ?, ?)')->execute([$cmpId, $ids['p1'], $ids['off']]);
    $setComplaint = static fn(string $status) => $pdo->prepare('UPDATE complaints SET status = ? WHERE complaint_id = ?')->execute([$status, $cmpId]);

    echo "== Static: every admin page requires the Administrator role ==\n";
    $adminPages = [];
    foreach (['personnel', 'categories'] as $dir) {
        foreach (glob(APP_ROOT . "/public/$dir/*.php") as $f) {
            $adminPages[] = "$dir/" . basename($f);
        }
    }
    $weak = [];
    foreach ($adminPages as $p) {
        if (!preg_match('/\brequire_role\(\[ROLE_ADMIN\]\)/', (string) file_get_contents(APP_ROOT . '/public/' . $p))) {
            $weak[] = $p;
        }
    }
    $t(count($adminPages) . ' admin pages all call require_role([ROLE_ADMIN])', count($adminPages) === 8 && $weak === [], implode(', ', $weak));

    echo "== Non-administrators are blocked server-side ==\n";
    $getUrls = [
        'personnel/index.php', 'personnel/create.php', 'personnel/edit.php?id=' . $ids['p1'],
        'personnel/status.php?id=' . $ids['p1'] . '&action=deactivate',
        'categories/index.php', 'categories/create.php', 'categories/edit.php?id=' . $catId,
        'categories/status.php?id=' . $catId . '&action=deactivate',
    ];
    $usersBefore = $countUsers();
    $catsBefore  = $countCats();
    $p1Before    = $row('SELECT full_name, is_active FROM users WHERE user_id = ?', [$ids['p1']]);
    $catBefore   = $row('SELECT category_name, is_active FROM categories WHERE category_id = ?', [$catId]);
    foreach ($getUrls as $u) {
        $t("signed out: $u redirects to sign-in", $goesTo((new Client($base))->get($u), 'login.php'));
    }
    foreach ([['res', 'p4test_res', 'resident/index.php'], ['off', 'p4test_officer', 'dashboard.php'], ['p3', 'p4test_pers3', 'dashboard.php'], ['mgmt', 'p4test_mgmt', 'dashboard.php']] as [$k, $u, $home]) {
        $cx = new Client($base);
        sign_in($cx, $u);
        $token = csrf_from($cx->get($home)['body']);
        $denied = 0;
        foreach ($getUrls as $url) {
            $denied += $cx->get($url)['status'] === 403 ? 1 : 0;
        }
        $t("$k: all " . count($getUrls) . ' admin pages answer 403', $denied === count($getUrls), "$denied of " . count($getUrls));
        $posts = [
            ['personnel/create.php', ['full_name' => 'Hack', 'username' => 'p4test_hack', 'role' => 'Administrator', 'password' => TEST_PASSWORD, 'password_confirm' => TEST_PASSWORD]],
            ['personnel/edit.php', ['user_id' => (string) $ids['p1'], 'full_name' => 'HACKED', 'role' => 'Administrator']],
            ['personnel/status.php', ['user_id' => (string) $ids['p1'], 'action' => 'deactivate']],
            ['categories/create.php', ['category_name' => 'p4test_hack']],
            ['categories/edit.php', ['category_id' => (string) $catId, 'category_name' => 'HACKED']],
            ['categories/status.php', ['category_id' => (string) $catId, 'action' => 'deactivate']],
        ];
        $deniedPost = 0;
        foreach ($posts as [$path, $form]) {
            $deniedPost += $cx->post($path, $form + ['csrf_token' => $token])['status'] === 403 ? 1 : 0;
        }
        $t("$k: all 6 admin POSTs (with a valid CSRF token) answer 403", $deniedPost === 6, "$deniedPost of 6");
    }
    $t('nothing was created or changed by the blocked attempts',
        $countUsers() === $usersBefore && $countCats() === $catsBefore
        && $row('SELECT full_name, is_active FROM users WHERE user_id = ?', [$ids['p1']]) === $p1Before
        && $row('SELECT category_name, is_active FROM categories WHERE category_id = ?', [$catId]) === $catBefore);
    $t('service layer also refuses a non-administrator actor',
        (static function () use ($pdo, $ids) {
            try {
                staff_create_account($pdo, ['user_id' => $ids['off'], 'role_name' => ROLE_OFFICER], ['full_name' => 'x', 'username' => 'p4test_svc', 'role' => ROLE_ADMIN, 'password' => TEST_PASSWORD]);
            } catch (RuntimeException $e) {
                return true;
            }
            return false;
        })() && (int) $one("SELECT COUNT(*) FROM users WHERE username = 'p4test_svc'") === 0);

    $adm = new Client($base);
    sign_in($adm, 'p4test_admin');
    $T = csrf_from($adm->get('personnel/index.php')['body']);
    $post = static fn(string $path, array $f) => $adm->post($path, $f + ['csrf_token' => $T]);

    echo "== Administrator: create staff accounts ==\n";
    $r = $adm->get('personnel/index.php');
    $t('administrator sees the staff list with nav links', $r['status'] === 200 && str_contains($r['body'], 'p4test_pers1') && str_contains($r['body'], 'categories/index.php') && str_contains($r['body'], 'personnel/index.php'));
    $t('resident accounts never appear in the staff list', !str_contains($adm->get('personnel/index.php?q=p4test_res')['body'], '<td>p4test_res</td>'));
    $good = ['full_name' => 'P4 New Officer', 'username' => 'p4test_newofficer', 'role' => ROLE_OFFICER, 'password' => TEST_PASSWORD, 'password_confirm' => TEST_PASSWORD];
    $r = $adm->post('personnel/create.php', $good);
    $t('create without CSRF token is rejected (403)', $r['status'] === 403 && (int) $one("SELECT COUNT(*) FROM users WHERE username = 'p4test_newofficer'") === 0);
    $bad = [
        'short password'       => [['password' => 'short', 'password_confirm' => 'short'], 'at least'],
        'password mismatch'    => [['password_confirm' => 'Different-Pass-12345'], 'do not match'],
        'invalid username'     => [['username' => 'bad name!'], 'letters, numbers'],
        'blank name'           => [['full_name' => '   '], 'Enter the full name'],
        'Resident role'        => [['role' => 'Resident'], 'Choose a staff role'],
        'empty role'           => [['role' => ''], 'Choose a staff role'],
        'made-up role'         => [['role' => 'Superuser'], 'Choose a staff role'],
        'username of a resident' => [['username' => 'p4test_res'], 'already taken'],
        'username of a staff'  => [['username' => 'P4TEST_PERS1'], 'already taken'],
    ];
    foreach ($bad as $label => [$over, $msg]) {
        $r = $post('personnel/create.php', $over + $good);
        $t("$label is rejected", $r['status'] === 200 && str_contains($r['body'], $msg));
    }
    $t('no account was created by the rejected attempts', (int) $one("SELECT COUNT(*) FROM users WHERE username = 'p4test_newofficer'") === 0 && $countUsers() === $usersBefore);
    $r = $post('personnel/create.php', $good + ['is_active' => '0', 'user_id' => '1']);
    $t('valid creation redirects to the list', $goesTo($r, 'personnel/index.php'));
    $new = $row("SELECT u.user_id, u.is_active, u.password_hash, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.username = 'p4test_newofficer'");
    $t('new account has the chosen role, is active, password hashed', $new && $new['role_name'] === ROLE_OFFICER && (int) $new['is_active'] === 1 && $new['password_hash'] !== TEST_PASSWORD && password_verify(TEST_PASSWORD, $new['password_hash']));
    $t('staff accounts get no resident profile', (int) $one('SELECT COUNT(*) FROM complainants WHERE user_id = ?', [(int) $new['user_id']]) === 0);
    $cn = new Client($base);
    $t('the new staff member can sign in', $goesTo(sign_in($cn, 'p4test_newofficer'), 'dashboard.php') && str_contains($cn->get('dashboard.php')['body'], 'Complaint Officer'));
    $post('personnel/create.php', ['full_name' => '<img src=x onerror=alert(1)>', 'username' => 'p4test_xss'] + $good);
    $body = $adm->get('personnel/index.php?q=p4test_xss')['body'];
    $t('HTML in a staff name is escaped in the list', str_contains($body, '&lt;img src=x') && !str_contains($body, '<img src=x'));

    echo "== Administrator: edit staff accounts ==\n";
    $r = $adm->get('personnel/edit.php?id=' . $ids['p3']);
    $t('edit page loads with the username read-only', $r['status'] === 200 && str_contains($r['body'], 'p4test_pers3') && str_contains($r['body'], 'readonly'));
    $c3 = new Client($base);
    sign_in($c3, 'p4test_pers3');
    $t('before the change the user is Personnel', str_contains($c3->get('dashboard.php')['body'], '(Personnel)'));
    $r = $post('personnel/edit.php', ['user_id' => (string) $ids['p3'], 'full_name' => 'P4 Renamed Three', 'role' => ROLE_MANAGEMENT]);
    $t('name and role can be changed', $goesTo($r, 'personnel/index.php') && $userRole($ids['p3']) === ROLE_MANAGEMENT && $one('SELECT full_name FROM users WHERE user_id = ?', [$ids['p3']]) === 'P4 Renamed Three');
    $t('the new role applies to the user\'s existing session at once', str_contains($c3->get('dashboard.php')['body'], '(Management)'));
    $r = $post('personnel/edit.php', ['user_id' => (string) $ids['p3'], 'full_name' => 'P4 Renamed Three', 'role' => 'Resident']);
    $t('changing a staff account to Resident is rejected', $r['status'] === 200 && str_contains($r['body'], 'Choose a staff role') && $userRole($ids['p3']) === ROLE_MANAGEMENT);
    $oldHash = (string) $one('SELECT password_hash FROM users WHERE user_id = ?', [$ids['p3']]);
    $post('personnel/edit.php', ['user_id' => (string) $ids['p3'], 'full_name' => 'P4 Renamed Three', 'role' => ROLE_MANAGEMENT]);
    $t('blank password fields keep the current password', $one('SELECT password_hash FROM users WHERE user_id = ?', [$ids['p3']]) === $oldHash);
    $r = $post('personnel/edit.php', ['user_id' => (string) $ids['p3'], 'full_name' => 'P4 Renamed Three', 'role' => ROLE_MANAGEMENT, 'password' => 'short', 'password_confirm' => 'short']);
    $t('weak replacement password rejected', str_contains($r['body'], 'at least') && $one('SELECT password_hash FROM users WHERE user_id = ?', [$ids['p3']]) === $oldHash);
    $r = $post('personnel/edit.php', ['user_id' => (string) $ids['p3'], 'full_name' => 'P4 Renamed Three', 'role' => ROLE_MANAGEMENT, 'password' => NEW_PASSWORD, 'password_confirm' => 'nope']);
    $t('mismatched replacement password rejected', str_contains($r['body'], 'do not match'));
    $post('personnel/edit.php', ['user_id' => (string) $ids['p3'], 'full_name' => 'P4 Renamed Three', 'role' => ROLE_MANAGEMENT, 'password' => NEW_PASSWORD, 'password_confirm' => NEW_PASSWORD]);
    $t('password reset: old password stops working, new one works',
        str_contains(sign_in(new Client($base), 'p4test_pers3', TEST_PASSWORD)['body'], 'Incorrect username or password')
        && $goesTo(sign_in(new Client($base), 'p4test_pers3', NEW_PASSWORD), 'dashboard.php'));
    $r = $adm->get('personnel/edit.php?id=' . $ids['res']);
    $t('editing a RESIDENT account is 404', $r['status'] === 404);
    $resBefore = $row('SELECT full_name, is_active FROM users WHERE user_id = ?', [$ids['res']]);
    $r = $post('personnel/edit.php', ['user_id' => (string) $ids['res'], 'full_name' => 'HACKED', 'role' => ROLE_ADMIN, 'password' => NEW_PASSWORD, 'password_confirm' => NEW_PASSWORD]);
    $t('POST edit aimed at a resident is 404 and changes nothing', $r['status'] === 404 && $row('SELECT full_name, is_active FROM users WHERE user_id = ?', [$ids['res']]) === $resBefore
        && $userRole($ids['res']) === ROLE_RESIDENT && password_verify(TEST_PASSWORD, (string) $one('SELECT password_hash FROM users WHERE user_id = ?', [$ids['res']])));
    $t('editing an unknown id is 404', $adm->get('personnel/edit.php?id=999999999')['status'] === 404);
    $r = $post('personnel/edit.php', ['user_id' => (string) $ids['admin'], 'full_name' => 'P4 Admin', 'role' => ROLE_OFFICER]);
    $t('administrator cannot change their own role', $r['status'] === 200 && str_contains($r['body'], 'cannot change your own role') && $userRole($ids['admin']) === ROLE_ADMIN);
    $post('personnel/edit.php', ['user_id' => (string) $ids['admin'], 'full_name' => 'P4 Admin Renamed', 'role' => ROLE_ADMIN]);
    $t('administrator can rename themselves', $one('SELECT full_name FROM users WHERE user_id = ?', [$ids['admin']]) === 'P4 Admin Renamed');

    echo "== Guards: deactivation must not leave records without a handling path ==\n";
    $r = $adm->get('personnel/status.php?id=' . $ids['admin'] . '&action=deactivate');
    $t('confirmation page for yourself shows the blocker and no confirm button', $r['status'] === 200 && str_contains($r['body'], 'cannot deactivate your own account') && !str_contains($r['body'], 'name="action"'));
    $post('personnel/status.php', ['user_id' => (string) $ids['admin'], 'action' => 'deactivate']);
    $t('self-deactivation is refused', $userActive($ids['admin']) === 1 && str_contains($adm->get('personnel/index.php')['body'], 'was not deactivated'));

    $t('personnel with an open assignment: confirmation shows why', str_contains($adm->get('personnel/status.php?id=' . $ids['p1'] . '&action=deactivate')['body'], 'open assignment'));
    $post('personnel/status.php', ['user_id' => (string) $ids['p1'], 'action' => 'deactivate']);
    $t('...and deactivation is refused', $userActive($ids['p1']) === 1);
    $r = $post('personnel/edit.php', ['user_id' => (string) $ids['p1'], 'full_name' => 'P4 Personnel One', 'role' => ROLE_MANAGEMENT]);
    $t('...and moving them to another role is refused', $r['status'] === 200 && str_contains($r['body'], 'open assignment') && $userRole($ids['p1']) === ROLE_PERSONNEL);
    $setComplaint('Closed');
    $r = $post('personnel/status.php', ['user_id' => (string) $ids['p1'], 'action' => 'deactivate']);
    $t('once the complaint is closed, deactivation is allowed', $goesTo($r, 'personnel/index.php') && $userActive($ids['p1']) === 0);
    $t('deactivated staff cannot sign in', str_contains(sign_in(new Client($base), 'p4test_pers1')['body'], 'This account is inactive'));
    $post('personnel/status.php', ['user_id' => (string) $ids['p1'], 'action' => 'activate']);
    $t('reactivated staff can sign in again', $userActive($ids['p1']) === 1 && $goesTo(sign_in(new Client($base), 'p4test_pers1'), 'dashboard.php'));
    $setComplaint('In Progress');

    // staff signed in, then deactivated (pers2 has nothing blocking yet)
    $c2 = new Client($base);
    sign_in($c2, 'p4test_pers2');
    $t('staff member signed in before deactivation', $c2->get('dashboard.php')['status'] === 200);
    $post('personnel/status.php', ['user_id' => (string) $ids['p2'], 'action' => 'deactivate']);
    $t('deactivated staff are cut off on their next request', $userActive($ids['p2']) === 0 && $goesTo($c2->get('dashboard.php'), 'login.php'));
    $post('personnel/status.php', ['user_id' => (string) $ids['p2'], 'action' => 'activate']);

    echo "== Resident accounts are protected from the staff endpoints ==\n";
    $r = $adm->get('personnel/status.php?id=' . $ids['res'] . '&action=deactivate');
    $t('staff status page for a resident is 404', $r['status'] === 404);
    $r = $post('personnel/status.php', ['user_id' => (string) $ids['res'], 'action' => 'deactivate']);
    $t('POST to deactivate a resident here is 404 and changes nothing', $r['status'] === 404 && $userActive($ids['res']) === 1);
    $t('unknown action / id are 404', $post('personnel/status.php', ['user_id' => (string) $ids['p3'], 'action' => 'delete'])['status'] === 404 && $adm->get('personnel/status.php?id=999999999&action=activate')['status'] === 404);

    echo "== Administrator: categories ==\n";
    $r = $adm->get('categories/index.php');
    $t('administrator can open the category list', $r['status'] === 200 && str_contains($r['body'], 'p4test_cat') && str_contains($r['body'], 'suggestions only'));
    $goodCat = ['category_name' => 'p4test_Road Repair', 'description' => 'Potholes & drainage', 'default_priority' => 'High', 'default_assignee_id' => (string) $ids['p2']];
    $r = $adm->post('categories/create.php', $goodCat);
    $t('create category without CSRF token is rejected (403)', $r['status'] === 403 && (int) $one("SELECT COUNT(*) FROM categories WHERE category_name LIKE 'p4test_Road%'") === 0);
    $badCat = [
        'blank name'                  => [['category_name' => '  '], 'Enter a category name'],
        'name over 100 characters'    => [['category_name' => str_repeat('n', 101)], '100 characters'],
        'unknown priority'            => [['default_priority' => 'Urgent'], 'listed priority'],
        'assignee who is an officer'  => [['default_assignee_id' => (string) $ids['off']], 'active Personnel'],
        'assignee who is a resident'  => [['default_assignee_id' => (string) $ids['res']], 'active Personnel'],
        'assignee who is inactive'    => [['default_assignee_id' => (string) $ids['pinact']], 'active Personnel'],
        'non-numeric assignee'        => [['default_assignee_id' => 'abc'], 'active Personnel'],
        'nonexistent assignee'        => [['default_assignee_id' => '999999999'], 'active Personnel'],
    ];
    foreach ($badCat as $label => [$over, $msg]) {
        $r = $post('categories/create.php', $over + $goodCat);
        $t("$label is rejected", $r['status'] === 200 && str_contains($r['body'], $msg));
    }
    $t('rejected attempts created no category', (int) $one("SELECT COUNT(*) FROM categories WHERE category_name LIKE 'p4test_Road%'") === 0);
    $complaintBefore = $row('SELECT priority, status FROM complaints WHERE complaint_id = ?', [$cmpId]);
    $assignBefore = (int) $one('SELECT COUNT(*) FROM assignments WHERE complaint_id = ?', [$cmpId]);
    $r = $post('categories/create.php', $goodCat);
    $t('valid category is created', $goesTo($r, 'categories/index.php'));
    $cat = $row("SELECT * FROM categories WHERE category_name = 'p4test_Road Repair'");
    $t('category stores priority and assignee as suggestions, active by default', $cat && $cat['default_priority'] === 'High' && (int) $cat['default_assignee_id'] === $ids['p2'] && (int) $cat['is_active'] === 1);
    $t('suggestions do not change any complaint or create any assignment',
        $row('SELECT priority, status FROM complaints WHERE complaint_id = ?', [$cmpId]) === $complaintBefore
        && (int) $one('SELECT COUNT(*) FROM assignments WHERE complaint_id = ?', [$cmpId]) === $assignBefore);
    $r = $post('categories/create.php', ['category_name' => 'P4TEST_ROAD REPAIR'] + $goodCat);
    $t('duplicate name (any letter case) is rejected', str_contains($r['body'], 'already exists'));
    $roadId = (int) $cat['category_id'];
    $post('categories/create.php', ['category_name' => 'p4test_<b>bold</b>', 'description' => '<script>alert(1)</script>']);
    $body = $adm->get('categories/index.php?q=p4test_')['body'];
    $t('HTML in category fields is escaped in the list', str_contains($body, '&lt;b&gt;bold&lt;/b&gt;') && str_contains($body, '&lt;script&gt;') && !str_contains($body, '<script>alert(1)'));
    $r = $adm->get('categories/edit.php?id=' . $roadId);
    $t('edit page preselects the stored suggestions', $r['status'] === 200 && str_contains($r['body'], 'value="High" selected') && str_contains($r['body'], 'value="' . $ids['p2'] . '" selected'));
    $r = $post('categories/edit.php', ['category_id' => (string) $roadId, 'category_name' => 'p4test_Road Works', 'description' => '', 'default_priority' => '', 'default_assignee_id' => '']);
    $cat = $row('SELECT * FROM categories WHERE category_id = ?', [$roadId]);
    $t('category can be renamed and suggestions cleared (NULL)', $goesTo($r, 'categories/index.php') && $cat['category_name'] === 'p4test_Road Works' && $cat['default_priority'] === null && $cat['default_assignee_id'] === null && $cat['description'] === null);
    $r = $post('categories/edit.php', ['category_id' => (string) $roadId, 'category_name' => 'p4test_cat']);
    $t('renaming onto an existing name is rejected', str_contains($r['body'], 'already exists') && $one('SELECT category_name FROM categories WHERE category_id = ?', [$roadId]) === 'p4test_Road Works');
    $t('editing an unknown category is 404', $adm->get('categories/edit.php?id=999999999')['status'] === 404);

    echo "== Guards: categories and default assignees ==\n";
    $post('categories/edit.php', ['category_id' => (string) $roadId, 'category_name' => 'p4test_Road Works', 'default_assignee_id' => (string) $ids['p2'], 'default_priority' => 'Low']);
    $t('personnel who is a default assignee: confirmation names the category', str_contains($adm->get('personnel/status.php?id=' . $ids['p2'] . '&action=deactivate')['body'], 'p4test_Road Works'));
    $post('personnel/status.php', ['user_id' => (string) $ids['p2'], 'action' => 'deactivate']);
    $t('...and deactivation is refused', $userActive($ids['p2']) === 1);
    $r = $post('personnel/edit.php', ['user_id' => (string) $ids['p2'], 'full_name' => 'P4 Personnel Two', 'role' => ROLE_MANAGEMENT]);
    $t('...and moving them off Personnel is refused', str_contains($r['body'], 'default assignee') && $userRole($ids['p2']) === ROLE_PERSONNEL);
    $post('categories/edit.php', ['category_id' => (string) $roadId, 'category_name' => 'p4test_Road Works', 'default_assignee_id' => '', 'default_priority' => 'Low']);
    $post('personnel/status.php', ['user_id' => (string) $ids['p2'], 'action' => 'deactivate']);
    $t('after the category is changed, deactivation is allowed', $userActive($ids['p2']) === 0);
    $post('personnel/status.php', ['user_id' => (string) $ids['p2'], 'action' => 'activate']);

    $t('category with an open complaint: confirmation shows why', str_contains($adm->get('categories/status.php?id=' . $catId . '&action=deactivate')['body'], 'open complaint'));
    $post('categories/status.php', ['category_id' => (string) $catId, 'action' => 'deactivate']);
    $t('...and deactivation is refused', $catActive($catId) === 1 && str_contains($adm->get('categories/index.php')['body'], 'was not deactivated'));
    $r = $adm->post('categories/status.php', ['category_id' => (string) $catId, 'action' => 'deactivate']);
    $t('category status POST without CSRF token is rejected (403)', $r['status'] === 403);
    $setComplaint('Closed');
    $r = $post('categories/status.php', ['category_id' => (string) $catId, 'action' => 'deactivate']);
    $t('once the complaint is closed, the category can be deactivated', $goesTo($r, 'categories/index.php') && $catActive($catId) === 0);
    $t('the closed complaint keeps its category', (int) $one('SELECT category_id FROM complaints WHERE complaint_id = ?', [$cmpId]) === $catId);
    $post('categories/status.php', ['category_id' => (string) $catId, 'action' => 'activate']);
    $t('category can be reactivated', $catActive($catId) === 1);
    $t('unknown category / action are 404', $adm->get('categories/status.php?id=999999999&action=activate')['status'] === 404 && $post('categories/status.php', ['category_id' => (string) $catId, 'action' => 'delete'])['status'] === 404);

    echo "== Guards that need a special database state (run inside rolled-back transactions) ==\n";
    $acct = static fn(int $id) => staff_account_find($pdo, $id);
    $has  = static fn(array $list, string $needle): bool => (bool) array_filter($list, static fn($m) => str_contains($m, $needle));

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users u JOIN roles r ON r.role_id = u.role_id SET u.is_active = 0 WHERE r.role_name = 'Administrator' AND u.user_id <> ?")->execute([$ids['admin']]);
    $t('last active Administrator cannot be removed', $has(staff_access_loss_blockers($pdo, 0, $acct($ids['admin']), null), 'last active Administrator'));
    $t('...or moved to another role', $has(staff_access_loss_blockers($pdo, 0, $acct($ids['admin']), ROLE_MANAGEMENT), 'last active Administrator'));
    $pdo->rollBack();
    $t('with another active Administrator the rule does not block', !$has(staff_access_loss_blockers($pdo, 0, $acct($ids['admin']), null), 'last active Administrator'));

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users u JOIN roles r ON r.role_id = u.role_id SET u.is_active = 0 WHERE r.role_name = 'Complaint Officer' AND u.user_id <> ?")->execute([$ids['off']]);
    $pdo->prepare("UPDATE users SET is_active = 0 WHERE username = 'p4test_newofficer'")->execute();
    $setComplaint('In Progress');
    $t('last active Complaint Officer cannot be removed while open complaints exist', $has(staff_access_loss_blockers($pdo, 0, $acct($ids['off']), null), 'last active Complaint Officer'));
    $pdo->exec("UPDATE complaints SET status = 'Closed'"); // rolled back below, so real complaints are untouched
    $t('...but may be once no complaint is open', count_open_complaints($pdo) === 0 && !$has(staff_access_loss_blockers($pdo, 0, $acct($ids['off']), null), 'last active Complaint Officer'));
    $pdo->rollBack();

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE categories SET is_active = 0 WHERE category_id <> ?')->execute([$roadId]);
    $setComplaint('Closed');
    $t('the only active category cannot be deactivated', $has(category_deactivation_blockers($pdo, category_find($pdo, $roadId)), 'only active category'));
    $pdo->rollBack();
    $t('with other active categories that rule does not block', !$has(category_deactivation_blockers($pdo, category_find($pdo, $roadId)), 'only active category'));

    echo "== Existing protections still hold ==\n";
    $adminCount = (int) $one("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Administrator' AND u.is_active = 1");
    $t('at least one active Administrator remains', $adminCount >= 1);
    $t('no staff account is linked to a resident profile', (int) $one("SELECT COUNT(*) FROM complainants c JOIN users u ON u.user_id = c.user_id JOIN roles r ON r.role_id = u.role_id WHERE r.role_name <> 'Resident'") === 0);
    $t('the Phase 3 resident endpoint still cannot touch staff accounts',
        $adm->post('residents/status.php', ['csrf_token' => $T, 'user_id' => (string) $ids['off'], 'action' => 'deactivate'])['status'] === 404 && $userActive($ids['off']) === 1);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $cleanup();
    printf("(cleanup: %d synthetic users, %d categories remain)\n",
        (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'p4test\\_%'")->fetchColumn(),
        (int) $pdo->query("SELECT COUNT(*) FROM categories WHERE category_name LIKE 'p4test\\_%'")->fetchColumn());
}

echo $failures === 0 ? "\nPhase 4 checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
