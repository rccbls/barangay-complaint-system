<?php
/**
 * Administrator: activate/deactivate a STAFF account (Phase 4).
 * GET = confirmation page (shows any blocking reasons, changes nothing). POST + CSRF applies it.
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
$account = staff_account_find($pdo, $id);
if ($account === null) {
    abort_page(404, 'Not found', 'That staff account could not be found.', $currentUser);
}
$activate = $action === 'activate';

if ($isPost) {
    $result = staff_change_account_status($pdo, $currentUser, $id, $activate);
    if ($result['result'] === 'changed') {
        flash_set('success', 'Account ' . $account['username'] . ($activate ? ' was activated.' : ' was deactivated.'));
    } elseif ($result['result'] === 'blocked') {
        flash_set('danger', 'Account ' . $account['username'] . ' was not deactivated. ' . implode(' ', $result['blockers']));
    } else {
        flash_set('info', 'Account ' . $account['username'] . ' was already ' . ($activate ? 'active.' : 'inactive.'));
    }
    redirect('personnel/index.php');
}

$blockers   = $activate ? [] : staff_access_loss_blockers($pdo, (int) $currentUser['user_id'], $account, null);
$pageTitle  = $activate ? 'Activate account' : 'Deactivate account';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<h1 class="h3 mb-3"><?= e($pageTitle) ?></h1>
<div class="card" style="max-width: 36rem;">
    <div class="card-body">
        <p>
            <?= $activate ? 'Activate' : 'Deactivate' ?> the account of <strong><?= e($account['full_name']) ?></strong>
            (<?= e($account['username']) ?>, <?= e($account['role_name']) ?>)?
        </p>
        <?php if ($blockers !== []): ?>
            <div class="alert alert-warning" role="alert">
                <p class="mb-1 fw-semibold">This account cannot be deactivated yet:</p>
                <ul class="mb-0"><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
            </div>
            <a class="btn btn-outline-secondary" href="<?= e(app_url('personnel/index.php')) ?>">Back to staff accounts</a>
        <?php else: ?>
            <p class="text-body-secondary">
                <?= $activate ? 'The person will be able to sign in again.' : 'The person will be signed out and cannot sign in until the account is activated again. Records they handled are kept.' ?>
            </p>
            <form method="post" action="<?= e(app_url('personnel/status.php')) ?>" class="d-flex gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int) $account['user_id'] ?>">
                <input type="hidden" name="action" value="<?= e($action) ?>">
                <button type="submit" class="btn <?= $activate ? 'btn-primary' : 'btn-danger' ?>"><?= $activate ? 'Activate account' : 'Deactivate account' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(app_url('personnel/index.php')) ?>">Cancel</a>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
