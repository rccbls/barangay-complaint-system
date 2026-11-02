<?php
/**
 * Administrator: resident accounts list (Phase 3).
 * Shows account information only (name, username, status, registration date). It deliberately
 * does not show resident contact numbers or addresses (RULES §10.2).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
$currentUser = require_role([ROLE_ADMIN]);

const RESIDENTS_PER_PAGE = 20;

$q      = text_normalize(substr(input_string($_GET, 'q'), 0, 100));
$status = in_array(input_string($_GET, 'status'), ['active', 'inactive'], true) ? input_string($_GET, 'status') : '';
$page   = max(1, (int) input_string($_GET, 'page'));

[$rows, $total] = resident_accounts_search(db(), $q, $status, RESIDENTS_PER_PAGE, ($page - 1) * RESIDENTS_PER_PAGE);
$pages = max(1, (int) ceil($total / RESIDENTS_PER_PAGE));

$pageUrl = static function (int $p) use ($q, $status): string {
    $query = array_filter(['q' => $q, 'status' => $status, 'page' => $p > 1 ? $p : ''], static fn($v) => $v !== '');
    return app_url('residents/index.php') . ($query ? '?' . http_build_query($query) : '');
};

$pageTitle  = 'Resident accounts';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<h1 class="h3 mb-3">Resident accounts</h1>

<form method="get" action="<?= e(app_url('residents/index.php')) ?>" class="row g-2 mb-3">
    <div class="col-12 col-md-6">
        <label for="q" class="visually-hidden">Search by name or username</label>
        <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Search by name or username">
    </div>
    <div class="col-8 col-md-3">
        <label for="status" class="visually-hidden">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">All statuses</option>
            <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-4 col-md-3"><button type="submit" class="btn btn-primary w-100">Search</button></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr><th scope="col">Name</th><th scope="col">Username</th><th scope="col">Status</th><th scope="col">Registered</th><th scope="col">Action</th></tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="5" class="text-body-secondary">No resident accounts match.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): $active = (int) $row['is_active'] === 1; ?>
                <tr>
                    <td><?= e($row['full_name']) ?></td>
                    <td><?= e($row['username']) ?></td>
                    <td><span class="badge <?= $active ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                    <td><?= e(format_display_datetime($row['created_at'], 'Y-m-d')) ?></td>
                    <td>
                        <a class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-primary' ?>"
                           href="<?= e(app_url('residents/status.php') . '?' . http_build_query(['id' => $row['user_id'], 'action' => $active ? 'deactivate' : 'activate'])) ?>"
                           aria-label="<?= $active ? 'Deactivate' : 'Activate' ?> account <?= e($row['username']) ?>"><?= $active ? 'Deactivate' : 'Activate' ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
    <span class="small text-body-secondary">
        <?php if ($total > 0): ?>
            Showing <?= (int) (($page - 1) * RESIDENTS_PER_PAGE + 1) ?>&ndash;<?= (int) min($total, $page * RESIDENTS_PER_PAGE) ?> of <?= (int) $total ?>
        <?php else: ?>0 results<?php endif; ?>
    </span>
    <nav aria-label="Resident accounts pages">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= e($pageUrl(max(1, $page - 1))) ?>">Previous</a>
            </li>
            <li class="page-item disabled"><span class="page-link">Page <?= (int) $page ?> of <?= (int) $pages ?></span></li>
            <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= e($pageUrl(min($pages, $page + 1))) ?>">Next</a>
            </li>
        </ul>
    </nav>
</div>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
