<?php
/**
 * Administrator: activate/deactivate a RESIDENT account (Phase 3).
 * GET shows a confirmation page and changes nothing. POST (with CSRF token) applies the change.
 * Staff accounts cannot be targeted here; they are managed in Phase 4.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
$currentUser = require_role([ROLE_ADMIN]);
$pdo         = db();

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost) {
    require_csrf();
}
$source = $isPost ? $_POST : $_GET;
$id     = (int) input_string($source, $isPost ? 'user_id' : 'id');
$action = input_string($source, 'action');

if (!in_array($action, ['activate', 'deactivate'], true) || $id < 1) {
    abort_page(404, 'Not found', 'That request could not be understood.', $currentUser);
}
$account = resident_account_find($pdo, $id);
if ($account === null) {
    abort_page(404, 'Not found', 'That resident account could not be found.', $currentUser);
}
$activate = $action === 'activate';

if ($isPost) {
    $result = resident_change_account_status($pdo, $currentUser, $id, $activate);
    if ($result === 'changed') {
        flash_set('success', 'Account ' . $account['username'] . ($activate ? ' was activated.' : ' was deactivated.'));
    } else {
        flash_set('info', 'Account ' . $account['username'] . ' was already ' . ($activate ? 'active.' : 'inactive.'));
    }
    redirect('residents/index.php');
}

$pageTitle  = $activate ? 'Activate account' : 'Deactivate account';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<h1 class="h3 mb-3"><?= e($pageTitle) ?></h1>
<div class="card" style="max-width: 36rem;">
    <div class="card-body">
        <p>
            <?= $activate ? 'Activate' : 'Deactivate' ?> the account of
            <strong><?= e($account['full_name']) ?></strong> (<?= e($account['username']) ?>)?
        </p>
        <p class="text-body-secondary">
            <?php if ($activate): ?>
                The resident will be able to sign in again.
            <?php else: ?>
                The resident will be signed out and will not be able to sign in until the account is activated again.
                Their profile and any complaints they submitted are kept.
            <?php endif; ?>
        </p>
        <form method="post" action="<?= e(app_url('residents/status.php')) ?>" class="d-flex gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int) $account['user_id'] ?>">
            <input type="hidden" name="action" value="<?= e($action) ?>">
            <button type="submit" class="btn <?= $activate ? 'btn-primary' : 'btn-danger' ?>"><?= $activate ? 'Activate account' : 'Deactivate account' ?></button>
            <a class="btn btn-outline-secondary" href="<?= e(app_url('residents/index.php')) ?>">Cancel</a>
        </form>
    </div>
</div>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
