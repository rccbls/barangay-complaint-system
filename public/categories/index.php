<?php
/** Administrator: complaint categories list (Phase 4). */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
$currentUser = require_role([ROLE_ADMIN]);

$q      = text_normalize(substr(input_string($_GET, 'q'), 0, 100));
$status = in_array(input_string($_GET, 'status'), ['active', 'inactive'], true) ? input_string($_GET, 'status') : '';
$rows   = categories_search(db(), $q, $status);

$pageTitle  = 'Categories';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Complaint categories</h1>
    <a class="btn btn-primary" href="<?= e(app_url('categories/create.php')) ?>">Add category</a>
</div>
<p class="text-body-secondary">Default priority and default assignee are suggestions only. A Complaint Officer confirms priority and assignment.</p>

<form method="get" action="<?= e(app_url('categories/index.php')) ?>" class="row g-2 mb-3">
    <div class="col-12 col-md-6">
        <label for="q" class="visually-hidden">Search categories</label>
        <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Search by name or description">
    </div>
    <div class="col-8 col-md-3">
        <label for="status" class="visually-hidden">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">All statuses</option>
            <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-4 col-md-3"><button type="submit" class="btn btn-outline-primary w-100">Search</button></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr><th scope="col">Category</th><th scope="col">Suggested priority</th><th scope="col">Suggested assignee</th><th scope="col">Status</th><th scope="col">Actions</th></tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="5" class="text-body-secondary">No categories match.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): $active = (int) $row['is_active'] === 1; ?>
                <tr>
                    <td>
                        <?= e($row['category_name']) ?>
                        <?php if ($row['description'] !== null): ?><div class="small text-body-secondary"><?= e($row['description']) ?></div><?php endif; ?>
                    </td>
                    <td><?= e($row['default_priority'] ?? 'None') ?></td>
                    <td><?= e($row['default_assignee_name'] ?? 'None') ?></td>
                    <td><span class="badge <?= $active ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('categories/edit.php') . '?' . http_build_query(['id' => $row['category_id']])) ?>"
                           aria-label="Edit <?= e($row['category_name']) ?>">Edit</a>
                        <a class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-primary' ?>"
                           href="<?= e(app_url('categories/status.php') . '?' . http_build_query(['id' => $row['category_id'], 'action' => $active ? 'deactivate' : 'activate'])) ?>"
                           aria-label="<?= $active ? 'Deactivate' : 'Activate' ?> <?= e($row['category_name']) ?>"><?= $active ? 'Deactivate' : 'Activate' ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
