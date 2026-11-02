<?php
/** Administrator: staff accounts list (Phase 4). Resident accounts never appear here. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
$currentUser = require_role([ROLE_ADMIN]);

const STAFF_PER_PAGE = 20;

$q      = text_normalize(substr(input_string($_GET, 'q'), 0, 100));
$role   = in_array(input_string($_GET, 'role'), staff_roles(), true) ? input_string($_GET, 'role') : '';
$status = in_array(input_string($_GET, 'status'), ['active', 'inactive'], true) ? input_string($_GET, 'status') : '';
$page   = max(1, (int) input_string($_GET, 'page'));

[$rows, $total] = staff_accounts_search(db(), $q, $role, $status, STAFF_PER_PAGE, ($page - 1) * STAFF_PER_PAGE);
$pages = max(1, (int) ceil($total / STAFF_PER_PAGE));
$pageUrl = static function (int $p) use ($q, $role, $status): string {
    $query = array_filter(['q' => $q, 'role' => $role, 'status' => $status, 'page' => $p > 1 ? $p : ''], static fn($v) => $v !== '');
    return app_url('personnel/index.php') . ($query ? '?' . http_build_query($query) : '');
};

$pageTitle  = 'Staff accounts';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Staff accounts</h1>
    <a class="btn btn-primary" href="<?= e(app_url('personnel/create.php')) ?>">Add staff account</a>
</div>

<form method="get" action="<?= e(app_url('personnel/index.php')) ?>" class="row g-2 mb-3">
    <div class="col-12 col-md-5">
        <label for="q" class="visually-hidden">Search by name or username</label>
        <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Search by name or username">
    </div>
    <div class="col-6 col-md-3">
        <label for="role" class="visually-hidden">Role</label>
        <select class="form-select" id="role" name="role">
            <option value="">All roles</option>
            <?php foreach (staff_roles() as $r): ?>
                <option value="<?= e($r) ?>"<?= $role === $r ? ' selected' : '' ?>><?= e($r) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label for="status" class="visually-hidden">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">All statuses</option>
            <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-12 col-md-2"><button type="submit" class="btn btn-outline-primary w-100">Search</button></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr><th scope="col">Name</th><th scope="col">Username</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Actions</th></tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="5" class="text-body-secondary">No staff accounts match.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): $active = (int) $row['is_active'] === 1; ?>
                <tr>
                    <td><?= e($row['full_name']) ?></td>
                    <td><?= e($row['username']) ?></td>
                    <td><?= e($row['role_name']) ?></td>
                    <td><span class="badge <?= $active ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('personnel/edit.php') . '?' . http_build_query(['id' => $row['user_id']])) ?>"
                           aria-label="Edit <?= e($row['username']) ?>">Edit</a>
                        <a class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-primary' ?>"
                           href="<?= e(app_url('personnel/status.php') . '?' . http_build_query(['id' => $row['user_id'], 'action' => $active ? 'deactivate' : 'activate'])) ?>"
                           aria-label="<?= $active ? 'Deactivate' : 'Activate' ?> <?= e($row['username']) ?>"><?= $active ? 'Deactivate' : 'Activate' ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
    <span class="small text-body-secondary">
        <?php if ($total > 0): ?>Showing <?= (int) (($page - 1) * STAFF_PER_PAGE + 1) ?>&ndash;<?= (int) min($total, $page * STAFF_PER_PAGE) ?> of <?= (int) $total ?><?php else: ?>0 results<?php endif; ?>
    </span>
    <?php render_pagination('Staff accounts pages', $page, $pages, $pageUrl); ?>
</div>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
