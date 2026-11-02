<?php
/**
 * Phase 2 check: authentication and role enforcement.
 *
 *   php tests/phase2_check.php [base-url]
 *   php tests/phase2_check.php http://localhost/barangay-complaint-system/public/
 *
 * Needs: the web server running, the database installed (Phase 1), and APP_ENV=local
 * (it creates and then deletes SYNTHETIC users whose usernames start with "p2test_").
 * It drives the real pages over HTTP, so it tests exactly what a browser would hit.
 * Exit code 0 = all checks passed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';
require_once APP_ROOT . '/app/Services/Authorization.php';

if (!app_is_local()) {
    fwrite(STDERR, "Refusing to run: this test creates synthetic users. Set APP_ENV=local in .env.\n");
    exit(1);
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/', '/') . '/';
require_once __DIR__ . '/support/http.php';

$failures = 0;
$t = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' && !$ok ? " - $detail" : '');
};
$goesTo = static fn(array $r, string $suffix): bool => $r['status'] === 302 && $r['location'] !== null && str_ends_with($r['location'], $suffix);

$pdo = db_connect(true);

$cleanup = static function () use ($pdo): void {
    $pdo->exec("DELETE FROM complaints WHERE reference_no LIKE 'P2TEST-%'");
    $pdo->exec("DELETE FROM categories WHERE category_name = 'p2test_category'");
    $pdo->exec("DELETE c FROM complainants c JOIN users u ON u.user_id = c.user_id WHERE u.username LIKE 'p2test\\_%'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'p2test\\_%'");
};

$makeUser = static function (string $username, string $full, string $role, int $active = 1) use ($pdo): int {
    $stmt = $pdo->prepare('INSERT INTO users (role_id, full_name, username, password_hash, is_active)
                           SELECT role_id, ?, ?, ?, ? FROM roles WHERE role_name = ?');
    $stmt->execute([$full, $username, password_hash(TEST_PASSWORD, PASSWORD_DEFAULT), $active, $role]);
    return (int) $pdo->lastInsertId();
};

$cleanup();
try {
    $ids = [
        'resident'  => $makeUser('p2test_resident', 'P2 Resident One', ROLE_RESIDENT),
        'resident2' => $makeUser('p2test_resident2', 'P2 Resident Two', ROLE_RESIDENT),
        'admin'     => $makeUser('p2test_admin', 'P2 Admin', ROLE_ADMIN),
        'officer'   => $makeUser('p2test_officer', 'P2 Officer', ROLE_OFFICER),
        'personnel' => $makeUser('p2test_personnel', 'P2 Personnel', ROLE_PERSONNEL),
        'mgmt'      => $makeUser('p2test_mgmt', 'P2 Management', ROLE_MANAGEMENT),
        'inactive'  => $makeUser('p2test_inactive', 'P2 Inactive', ROLE_RESIDENT, 0),
        'midsess'   => $makeUser('p2test_midsess', 'P2 Mid Session', ROLE_RESIDENT),
    ];

    echo "== Static: every page is protected or on the public allow-list ==\n";
    $publicAllow = ['index.php', 'login.php', 'register.php', 'logout.php', 'setup-check.php'];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/public', FilesystemIterator::SKIP_DOTS));
    $unprotected = [];
    $count = 0;
    foreach ($it as $file) {
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(APP_ROOT . '/public/')));
        if (!str_ends_with($rel, '.php') || str_starts_with($rel, 'assets/')) {
            continue;
        }
        $count++;
        $src = (string) file_get_contents($file->getPathname());
        if (!in_array($rel, $publicAllow, true) && !preg_match('/\brequire_(role|login)\(/', $src)) {
            $unprotected[] = $rel;
        }
    }
    $t("$count public pages checked; none unprotected", $unprotected === [], implode(', ', $unprotected));

    echo "== Record-level rule: resident_owns_complaint ==\n";
    $pdo->exec("INSERT INTO categories (category_name) VALUES ('p2test_category')");
    $catId = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO complaints (reference_no, submitted_by_user_id, category_id, description, priority, priority_reason)
                          VALUES (?, ?, ?, 'synthetic', 'Low', 'synthetic test')");
    $ins->execute(['P2TEST-A', $ids['resident'], $catId]);
    $cA = (int) $pdo->lastInsertId();
    $ins->execute(['P2TEST-B', $ids['resident2'], $catId]);
    $cB = (int) $pdo->lastInsertId();
    $t('resident owns own complaint', resident_owns_complaint($pdo, $ids['resident'], $cA));
    $t('resident cannot access another resident\'s complaint', !resident_owns_complaint($pdo, $ids['resident'], $cB));
    $t('second resident cannot access the first one\'s complaint', !resident_owns_complaint($pdo, $ids['resident2'], $cA));
    $t('nonexistent complaint id is not accessible', !resident_owns_complaint($pdo, $ids['resident'], 999999999));
    $t('staff user id does not own a resident\'s complaint', !resident_owns_complaint($pdo, $ids['admin'], $cA));

    echo "== Signed-out visitors ==\n";
    $c = new Client($base);
    foreach (['dashboard.php', 'resident/index.php'] as $page) {
        $r = $c->get($page);
        $t("signed out: $page redirects to login", $goesTo($r, 'login.php') && !str_contains($r['body'], 'Welcome'));
    }
    $fresh = new Client($base);
    $r = $fresh->get('login.php');
    $t('login page loads with CSRF token', $r['status'] === 200 && csrf_from($r['body']) !== '');
    $cookieHeader = implode(' | ', $r['cookies']);
    $t('session cookie is HttpOnly and SameSite=Lax', stripos($cookieHeader, 'HttpOnly') !== false && stripos($cookieHeader, 'SameSite=Lax') !== false, $cookieHeader);
    $hdrs = implode("\n", $r['headers']);
    $t('anti-framing and nosniff headers present', stripos($hdrs, 'X-Frame-Options: DENY') !== false && stripos($hdrs, 'X-Content-Type-Options: nosniff') !== false);

    echo "== CSRF ==\n";
    $r = $c->post('login.php', ['username' => 'p2test_resident', 'password' => TEST_PASSWORD]);
    $t('login POST without CSRF token is rejected (403)', $r['status'] === 403);
    $r = $c->post('login.php', ['csrf_token' => str_repeat('a', 64), 'username' => 'p2test_resident', 'password' => TEST_PASSWORD]);
    $t('login POST with wrong CSRF token is rejected (403)', $r['status'] === 403);
    $other = new Client($base);
    $foreignToken = csrf_from($other->get('login.php')['body']);
    $r = $c->post('login.php', ['csrf_token' => $foreignToken, 'username' => 'p2test_resident', 'password' => TEST_PASSWORD]);
    $t('CSRF token from a different session is rejected', $r['status'] === 403);
    $r = $c->post('register.php', ['username' => 'p2test_csrf', 'full_name' => 'X', 'password' => TEST_PASSWORD, 'password_confirm' => TEST_PASSWORD]);
    $t('registration POST without CSRF token is rejected (403)', $r['status'] === 403);

    echo "== Invalid credentials ==\n";
    $c = new Client($base);
    $r1 = sign_in($c, 'p2test_resident', 'wrong-password-123');
    $r2 = sign_in($c, 'p2test_nobody', 'wrong-password-123');
    $msg = 'Incorrect username or password.';
    $t('wrong password rejected with generic message', $r1['status'] === 200 && str_contains($r1['body'], $msg));
    $t('unknown username gets the same generic message', $r2['status'] === 200 && str_contains($r2['body'], $msg));
    $t('still signed out after failed attempts', $goesTo($c->get('dashboard.php'), 'login.php'));
    $r = sign_in($c, 'p2test_resident', '');
    $t('empty password rejected', $r['status'] === 200 && str_contains($r['body'], $msg));

    echo "== Resident sign-in, session regeneration, role boundary ==\n";
    $c = new Client($base);
    $c->get('login.php');
    $before = $c->session();
    $r = sign_in($c, 'p2test_resident');
    $after = $c->session();
    $t('resident login redirects to resident home', $goesTo($r, 'resident/index.php'));
    $t('session ID regenerated after login', $before !== null && $after !== null && $before !== $after);
    $r = $c->req('GET', 'resident/index.php', [], ['BCS_SESSION' => (string) $before]);
    $t('pre-login session ID cannot be reused', $goesTo($r, 'login.php'));
    $r = $c->get('resident/index.php');
    $t('resident can open resident home', $r['status'] === 200 && str_contains($r['body'], 'P2 Resident One'));
    $t('protected pages send Cache-Control: no-store', stripos(implode("\n", $r['headers']), 'Cache-Control: no-store') !== false);
    $r = $c->get('dashboard.php');
    $t('resident typing the staff URL gets 403', $r['status'] === 403 && !str_contains($r['body'], 'Welcome,'));
    $t('login page redirects a signed-in resident home', $goesTo($c->get('login.php'), 'resident/index.php'));
    $t('no staff-only navigation shown to a resident', !str_contains($c->get('resident/index.php')['body'], 'Settings'));

    echo "== Sign-out ==\n";
    $oldSession = $c->session();
    $r = $c->get('logout.php');
    $t('GET logout does not sign out', $goesTo($r, 'login.php') && $c->get('resident/index.php')['status'] === 200);
    $token = csrf_from($c->get('resident/index.php')['body']);
    $r = $c->post('logout.php', []);
    $t('logout POST without CSRF token is rejected (403)', $r['status'] === 403 && $c->get('resident/index.php')['status'] === 200);
    $token = csrf_from($c->get('resident/index.php')['body']);
    $r = $c->post('logout.php', ['csrf_token' => $token]);
    $t('logout POST with CSRF token redirects to login', $goesTo($r, 'login.php'));
    $t('after logout the protected page redirects to login', $goesTo($c->get('resident/index.php'), 'login.php'));
    $r = $c->req('GET', 'resident/index.php', [], ['BCS_SESSION' => (string) $oldSession]);
    $t('old session ID is invalid after logout (server-side)', $goesTo($r, 'login.php'));

    echo "== Session fixation ==\n";
    $c = new Client($base);
    $fixed = 'attackerchosen1234567890abcdef0123';
    $c->cookies['BCS_SESSION'] = $fixed;
    sign_in($c, 'p2test_resident');
    $t('attacker-chosen session ID is not kept after login', $c->session() !== null && $c->session() !== $fixed);

    echo "== Staff roles ==\n";
    foreach (['admin' => 'Administrator', 'officer' => 'Complaint Officer', 'personnel' => 'Personnel', 'mgmt' => 'Management'] as $key => $role) {
        $c = new Client($base);
        $r = sign_in($c, "p2test_$key");
        $t("$role login redirects to staff home", $goesTo($r, 'dashboard.php'));
        $r = $c->get('dashboard.php');
        $t("$role can open staff home", $r['status'] === 200 && str_contains($r['body'], $role));
        $r2 = $c->get('resident/index.php');
        $t("$role typing the resident URL gets 403", $r2['status'] === 403);
        if ($key === 'admin') {
            $t('Administrator navigation includes Categories', str_contains($r['body'], 'Categories'));
        }
        if ($key === 'personnel' || $key === 'mgmt') {
            $t("$role navigation hides administrator items", !str_contains($r['body'], 'Categories') && !str_contains($r['body'], 'Personnel'));
        }
    }

    echo "== Inactive accounts ==\n";
    $c = new Client($base);
    $r = sign_in($c, 'p2test_inactive');
    $t('inactive user cannot sign in', $r['status'] === 200 && str_contains($r['body'], 'This account is inactive') && $goesTo($c->get('resident/index.php'), 'login.php'));
    $r = sign_in($c, 'p2test_inactive', 'wrong-password-123');
    $t('inactive status is not revealed without the correct password', str_contains($r['body'], $msg) && !str_contains($r['body'], 'This account is inactive'));
    $c = new Client($base);
    sign_in($c, 'p2test_midsess');
    $t('user signed in before deactivation can open page', $c->get('resident/index.php')['status'] === 200);
    $pdo->prepare('UPDATE users SET is_active = 0 WHERE user_id = ?')->execute([$ids['midsess']]);
    $t('deactivated user is cut off on the next request', $goesTo($c->get('resident/index.php'), 'login.php'));

    echo "== Resident registration ==\n";
    $c = new Client($base);
    $reg = static function (Client $c, array $f) {
        $f['csrf_token'] = csrf_from($c->get('register.php')['body']);
        return $c->post('register.php', $f);
    };
    $good = ['full_name' => 'P2 Registered', 'username' => 'p2test_reg1', 'password' => TEST_PASSWORD, 'password_confirm' => TEST_PASSWORD];
    $count = static fn(string $u): int => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = " . $pdo->quote($u))->fetchColumn();

    $r = $reg($c, ['password' => 'short', 'password_confirm' => 'short'] + $good);
    $t('short password rejected, no account created', $r['status'] === 200 && str_contains($r['body'], 'at least') && $count('p2test_reg1') === 0);
    $r = $reg($c, ['password_confirm' => 'Different-Pass-12345'] + $good);
    $t('password mismatch rejected', str_contains($r['body'], 'do not match') && $count('p2test_reg1') === 0);
    $r = $reg($c, ['username' => 'bad name!'] + $good);
    $t('invalid username rejected', str_contains($r['body'], 'letters, numbers') && $count('bad name!') === 0);
    $r = $reg($c, ['full_name' => '   '] + $good);
    $t('blank full name rejected', str_contains($r['body'], 'Enter your full name'));
    $r = $reg($c, ['username' => 'p2test_resident'] + $good);
    $t('duplicate username rejected', str_contains($r['body'], 'already taken'));
    $r = $reg($c, ['password' => str_repeat('a', 73), 'password_confirm' => str_repeat('a', 73)] + $good);
    $t('password over 72 bytes rejected (bcrypt limit)', str_contains($r['body'], 'too long') && $count('p2test_reg1') === 0);

    $r = $reg($c, $good + ['role_id' => '2', 'role' => 'Administrator', 'role_name' => 'Administrator', 'is_active' => '0']);
    $t('valid registration redirects to login', $goesTo($r, 'login.php'));
    $row = $pdo->query("SELECT r.role_name, u.is_active, u.password_hash FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.username = 'p2test_reg1'")->fetch();
    $t('registered account is a Resident even when role fields are tampered', $row && $row['role_name'] === 'Resident' && (int) $row['is_active'] === 1);
    $t('registered password is stored hashed', $row && $row['password_hash'] !== TEST_PASSWORD && password_verify(TEST_PASSWORD, $row['password_hash']));
    $r = sign_in($c, 'p2test_reg1');
    $t('newly registered resident can sign in', $goesTo($r, 'resident/index.php'));
    $t('registration page redirects a signed-in user', $goesTo($c->get('register.php'), 'resident/index.php'));

    echo "== Output escaping ==\n";
    $c = new Client($base);
    $xss = '<script>alert(1)</script>';
    $reg($c, ['full_name' => $xss, 'username' => 'p2test_xss'] + $good);
    sign_in($c, 'p2test_xss');
    $body = $c->get('resident/index.php')['body'];
    $t('HTML in a name is escaped in the page', str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($body, '<script>alert(1)'));
    $c2 = new Client($base);
    $r = sign_in($c2, '"><img src=x onerror=alert(1)>');
    $t('reflected username is escaped on the login page', !str_contains($r['body'], '<img src=x'));
} finally {
    $cleanup();
    $left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'p2test\\_%'")->fetchColumn();
    printf("(cleanup: %d synthetic test users remain)\n", $left);
}

echo $failures === 0 ? "\nPhase 2 checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
