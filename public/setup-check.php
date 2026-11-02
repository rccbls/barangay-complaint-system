<?php
/**
 * Phase 0 setup check page (developer tool).
 *
 * Proves Apache + PHP + MySQL work and the shared layout renders. It shows server
 * details, so it answers 404 unless APP_ENV=local. Not part of the real application.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

if (!app_is_local()) {
    http_response_code(404);
    exit;
}

// Developer-only preview of the planned resident/staff shells (APP_ENV=local).
$layoutArea = 'public';
$requested  = $_GET['layout'] ?? '';
if (app_is_local() && in_array($requested, ['resident', 'staff'], true)) {
    $layoutArea = $requested;
}

$pageTitle = 'Setup check';
$health    = db_health_check();
$now       = new DateTimeImmutable('now', new DateTimeZone(APP_STORAGE_TIMEZONE));

if ($health['error'] !== null) {
    error_log('Phase 0 DB check failed: ' . $health['error']);
}

require APP_ROOT . '/app/Views/layouts/header.php';
?>
<h1 class="h3 mb-1">Setup check</h1>
<p class="text-body-secondary">Phase 0: confirms the web server, PHP, and MySQL are working.</p>

<div class="card mb-3">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <tbody>
                <tr>
                    <th scope="row">Web server and PHP</th>
                    <td>Running &mdash; PHP <?= e(PHP_VERSION) ?></td>
                </tr>
                <tr>
                    <th scope="row">MySQL server</th>
                    <td>
                        <?php if ($health['server']): ?>
                            Connected &mdash; <?= e($health['version']) ?>
                        <?php else: ?>
                            <strong>Not connected.</strong>
                            Start MySQL and check the DB_* values in <code>.env</code>.
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Database <code><?= e(db_config()['name']) ?></code></th>
                    <td>
                        <?php if (!$health['server']): ?>
                            Not checked
                        <?php elseif ($health['database']): ?>
                            Exists
                        <?php else: ?>
                            Not created yet &mdash; expected until Phase 1
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Database session time zone</th>
                    <td><?= $health['session_tz'] !== null ? e($health['session_tz']) . ' (stored as UTC)' : 'Not checked' ?></td>
                </tr>
                <tr>
                    <th scope="row">Time now</th>
                    <td>
                        <?= e(format_display_datetime($now->format('Y-m-d H:i:s'), 'Y-m-d H:i')) ?> <?= e(APP_DISPLAY_TIMEZONE) ?>
                        <span class="text-body-secondary">(<?= e($now->format('Y-m-d H:i')) ?> UTC)</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php if (app_is_local()): ?>
    <?php if ($health['error'] !== null): ?>
        <div class="alert alert-warning" role="alert">
            <strong>Connection detail (local only):</strong> <?= e($health['error']) ?>
        </div>
    <?php endif; ?>
    <p class="small text-body-secondary mb-0">
        Layout preview (local only):
        <a href="?">public</a> &middot; <a href="?layout=resident">resident</a> &middot; <a href="?layout=staff">staff</a>
    </p>
<?php endif; ?>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
