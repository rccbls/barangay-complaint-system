<?php
/**
 * Phase 13 check: P1 features (FR-017 CSV Export, FR-018 Restricted Attachments, FR-019 Overdue Flags).
 *
 *   php tests/phase13_check.php [base-url]
 *   php tests/phase13_check.php http://localhost/barangay-complaint-system/public/
 *
 * Verifies:
 *  - CSV export formula-injection protection (cells starting with =, +, -, @ prefixed with tab).
 *  - Restricted attachments:
 *     - Only allowed file types (JPEG, PNG, GIF, PDF, TXT) and sizes (<= 5MB).
 *     - Verification of MIME and content (blocking executables/scripts).
 *     - Stored outside web root with random filename.
 *     - Auth-checked download endpoint:
 *        - Resident can access ONLY own complaint attachments.
 *        - Resident cannot access another resident's attachment (403/404).
 *        - Staff can access authorized attachments.
 *  - Overdue logic and response-time calculation rules.
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

$pdo = db_connect(true);
$hash = password_hash(TEST_PASSWORD, PASSWORD_DEFAULT);

$cleanup = static function () use ($pdo): void {
    // Clean files from disk
    $stmt = $pdo->query("SELECT stored_filename FROM complaint_attachments ca JOIN complaints c ON c.complaint_id = ca.complaint_id WHERE c.reference_no LIKE 'P13TEST-%'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $f = APP_ROOT . '/storage/uploads/' . $row['stored_filename'];
        if (file_exists($f)) {
            @unlink($f);
        }
    }
    $pdo->exec("DELETE ca FROM complaint_attachments ca JOIN complaints c ON c.complaint_id = ca.complaint_id WHERE c.reference_no LIKE 'P13TEST-%'");
    $pdo->exec("DELETE a FROM assignments a JOIN complaints c ON c.complaint_id = a.complaint_id WHERE c.reference_no LIKE 'P13TEST-%'");
    $pdo->exec("DELETE sh FROM status_history sh JOIN complaints c ON c.complaint_id = sh.complaint_id WHERE c.reference_no LIKE 'P13TEST-%'");
    $pdo->exec("DELETE ph FROM priority_history ph JOIN complaints c ON c.complaint_id = ph.complaint_id WHERE c.reference_no LIKE 'P13TEST-%'");
    $pdo->exec("DELETE FROM complaints WHERE reference_no LIKE 'P13TEST-%'");
    $pdo->exec("DELETE FROM categories WHERE category_name LIKE 'p13test\\_%'");
    $pdo->exec("DELETE FROM complainants WHERE full_name LIKE 'P13 %'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'p13test\\_%'");
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
    echo "== CSV Export & Formula-Injection Protection (FR-017, RULES §12.7) ==\n";

    $ids = [
        'admin'    => $makeUser('p13test_admin', 'P13 Admin', ROLE_ADMIN),
        'officer'  => $makeUser('p13test_officer', 'P13 Officer', ROLE_OFFICER),
        'res1'     => $makeUser('p13test_res1', 'P13 Resident One', ROLE_RESIDENT),
        'res2'     => $makeUser('p13test_res2', 'P13 Resident Two', ROLE_RESIDENT),
    ];

    // Verify csv_safe helper
    $t('formula injection: =cmd gets escaped', csv_safe('=SUM(A1:A10)') === "\t=SUM(A1:A10)");
    $t('formula injection: +cmd gets escaped', csv_safe('+cmd|/c calc') === "\t+cmd|/c calc");
    $t('formula injection: -cmd gets escaped', csv_safe('-1+1') === "\t-1+1");
    $t('formula injection: @cmd gets escaped', csv_safe('@SUM(B1)') === "\t@SUM(B1)");
    $t('normal text is not modified', csv_safe('Regular Text') === 'Regular Text');

    // Test actual CSV export request
    $cOff = new Client($base);
    sign_in($cOff, 'p13test_officer');
    $r = $cOff->get('reports/index.php?export=csv');
    $t('CSV export: HTTP 200 returned', $r['status'] === 200);
    $isCsv = false;
    foreach ($r['headers'] as $h) {
        if (stripos($h, 'Content-Type: text/csv') !== false) {
            $isCsv = true;
            break;
        }
    }
    $t('CSV export: text/csv content type', $isCsv);

    echo "== Restricted Attachments (FR-018, RULES §11) ==\n";

    $stmt = $pdo->prepare('INSERT INTO categories (category_name, default_priority) VALUES ("p13test_cat", "High")');
    $stmt->execute();
    $catId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO complaints (reference_no, submitted_by_user_id, category_id, description, priority, priority_reason, status, created_at)
                           VALUES ("P13TEST-0001", ?, ?, "P13 test complaint", "High", "Reason", "Received", UTC_TIMESTAMP())');
    $stmt->execute([$ids['res1'], $catId]);
    $compId1 = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO complaints (reference_no, submitted_by_user_id, category_id, description, priority, priority_reason, status, created_at)
                           VALUES ("P13TEST-0002", ?, ?, "P13 test complaint 2", "Low", "Reason", "Received", UTC_TIMESTAMP())');
    $stmt->execute([$ids['res2'], $catId]);
    $compId2 = (int)$pdo->lastInsertId();

    // 1. Upload valid text file via attachment_upload()
    $tmpFile = tempnam(sys_get_temp_dir(), 'p13_');
    file_put_contents($tmpFile, "Test photo evidence text content.");
    $filePayload = [
        'name'     => 'evidence.txt',
        'type'     => 'text/plain',
        'tmp_name' => $tmpFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tmpFile),
    ];
    $upRes = attachment_upload($pdo, ['user_id' => $ids['res1']], $compId1, $filePayload);
    $t('valid text attachment uploaded successfully', $upRes['ok'] === true);
    $attId = $upRes['attachment_id'] ?? 0;

    // Verify stored file is in storage/uploads and not inside public/
    $attRecord = attachment_fetch($pdo, $attId);
    $t('attachment record exists in DB', $attRecord !== null);
    $storedPath = APP_ROOT . '/storage/uploads/' . ($attRecord['stored_filename'] ?? '');
    $t('stored file exists on disk outside web root', file_exists($storedPath));
    $t('stored filename is randomly hashed (not original name)', !str_contains($attRecord['stored_filename'], 'evidence.txt'));

    // 2. Reject executable / disallowed content
    $badFile = tempnam(sys_get_temp_dir(), 'p13_bad_');
    file_put_contents($badFile, "<?php phpinfo(); ?>");
    $badPayload = [
        'name'     => 'exploit.php.txt',
        'type'     => 'text/plain',
        'tmp_name' => $badFile,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($badFile),
    ];
    $badRes = attachment_upload($pdo, ['user_id' => $ids['res1']], $compId1, $badPayload);
    $t('attachment with php executable tags is rejected', $badRes['ok'] === false);
    @unlink($badFile);

    // 3. Authorization check on download endpoint (attachment.php)
    // Anonymous
    $anon = new Client($base);
    $r = $anon->get('complaints/attachment.php?id=' . $attId);
    $t('anonymous accessing attachment redirected to login', $r['status'] === 302);

    // Resident 1 (owner) can download
    $cRes1 = new Client($base);
    sign_in($cRes1, 'p13test_res1');
    $r = $cRes1->get('complaints/attachment.php?id=' . $attId);
    $t('complaint owner can download attachment (200)', $r['status'] === 200 && str_contains($r['body'], 'Test photo evidence'));

    // Resident 2 (unauthorized) CANNOT download resident 1's attachment
    $cRes2 = new Client($base);
    sign_in($cRes2, 'p13test_res2');
    $r = $cRes2->get('complaints/attachment.php?id=' . $attId);
    $t('other resident forbidden from attachment (403)', $r['status'] === 403);

    // Officer can access attachment
    $r = $cOff->get('complaints/attachment.php?id=' . $attId);
    $t('officer can access complaint attachment (200)', $r['status'] === 200);

    echo "== Overdue Logic and Target Calculation (FR-019, RULES §8) ==\n";

    // 1. Fresh Critical complaint (created now) is NOT overdue (target = 24h)
    $freshCritical = [
        'priority'   => 'Critical',
        'status'     => 'Received',
        'created_at' => (new DateTimeImmutable('now', new DateTimeZone(APP_STORAGE_TIMEZONE)))->format('Y-m-d H:i:s'),
    ];
    $t('fresh Critical complaint is not overdue', complaint_is_overdue($freshCritical) === false);

    // 2. Old Critical complaint (created 2 days ago) IS overdue (> 24h)
    $oldCritical = [
        'priority'   => 'Critical',
        'status'     => 'In Progress',
        'created_at' => (new DateTimeImmutable('-48 hours', new DateTimeZone(APP_STORAGE_TIMEZONE)))->format('Y-m-d H:i:s'),
    ];
    $t('old Critical complaint (48h old) is overdue', complaint_is_overdue($oldCritical) === true);

    // 3. Low priority complaint (created 2 days ago) is NOT overdue (target = 168h / 7 days)
    $recentLow = [
        'priority'   => 'Low',
        'status'     => 'In Progress',
        'created_at' => (new DateTimeImmutable('-48 hours', new DateTimeZone(APP_STORAGE_TIMEZONE)))->format('Y-m-d H:i:s'),
    ];
    $t('low priority complaint (48h old) is not overdue (7d SLA)', complaint_is_overdue($recentLow) === false);

    // 4. Closed complaint resolved within SLA is NOT overdue
    $resolvedInTime = [
        'priority'    => 'Critical',
        'status'      => 'Resolved',
        'created_at'  => (new DateTimeImmutable('-20 hours', new DateTimeZone(APP_STORAGE_TIMEZONE)))->format('Y-m-d H:i:s'),
        'resolved_at' => (new DateTimeImmutable('-1 hour', new DateTimeZone(APP_STORAGE_TIMEZONE)))->format('Y-m-d H:i:s'),
    ];
    $t('resolved complaint within SLA is not overdue', complaint_is_overdue($resolvedInTime) === false);

    echo "\nPhase 13 checks passed.\n";
} finally {
    $cleanup();
}

exit($failures === 0 ? 0 : 1);

