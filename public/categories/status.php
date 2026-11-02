<?php
/**
 * Administrator: activate/deactivate a category (Phase 4). Categories are never deleted.
 * GET = confirmation (shows blocking reasons, changes nothing). POST + CSRF applies the change.
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
$id     = (int) input_string($source, $isPost ? 'category_id' : 'id');
$action = input_string($source, 'action');
if (!in_array($action, ['activate', 'deactivate'], true) || $id < 1) {
    abort_page(404, 'Not found', 'That request could not be understood.', $currentUser);
}
$category = category_find($pdo, $id);
if ($category === null) {
    abort_page(404, 'Not found', 'That category could not be found.', $currentUser);
}
$activate = $action === 'activate';

if ($isPost) {
    $result = category_change_status($pdo, $currentUser, $id, $activate);
    if ($result['result'] === 'changed') {
        flash_set('success', 'Category "' . $category['category_name'] . ($activate ? '" was activated.' : '" was deactivated.'));
    } elseif ($result['result'] === 'blocked') {
        flash_set('danger', 'Category "' . $category['category_name'] . '" was not deactivated. ' . implode(' ', $result['blockers']));
    } else {
        flash_set('info', 'Category "' . $category['category_name'] . '" was already ' . ($activate ? 'active.' : 'inactive.'));
    }
    redirect('categories/index.php');
}

$blockers   = $activate ? [] : category_deactivation_blockers($pdo, $category);
$pageTitle  = $activate ? 'Activate category' : 'Deactivate category';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<h1 class="h3 mb-3"><?= e($pageTitle) ?></h1>
<div class="card" style="max-width: 36rem;">
    <div class="card-body">
        <p><?= $activate ? 'Activate' : 'Deactivate' ?> the category <strong><?= e($category['category_name']) ?></strong>?</p>
        <?php if ($blockers !== []): ?>
            <div class="alert alert-warning" role="alert">
                <p class="mb-1 fw-semibold">This category cannot be deactivated yet:</p>
                <ul class="mb-0"><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
            </div>
            <a class="btn btn-outline-secondary" href="<?= e(app_url('categories/index.php')) ?>">Back to categories</a>
        <?php else: ?>
            <p class="text-body-secondary">
                <?= $activate ? 'Residents and staff will be able to choose this category again.' : 'It will no longer be offered for new complaints. Existing complaints keep their category.' ?>
            </p>
            <form method="post" action="<?= e(app_url('categories/status.php')) ?>" class="d-flex gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="category_id" value="<?= (int) $category['category_id'] ?>">
                <input type="hidden" name="action" value="<?= e($action) ?>">
                <button type="submit" class="btn <?= $activate ? 'btn-primary' : 'btn-danger' ?>"><?= $activate ? 'Activate category' : 'Deactivate category' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(app_url('categories/index.php')) ?>">Cancel</a>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
