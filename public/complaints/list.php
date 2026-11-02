<?php
/**
 * Staff complaint list page (Phase 6: FR-005).
 * Search and filter complaints by approved fields.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$currentUser = require_role([ROLE_ADMIN, ROLE_OFFICER, ROLE_MANAGEMENT]);
$pdo = db();

$filters = [
    'search'      => trim(input_string($_GET, 'search')),
    'status'      => input_string($_GET, 'status'),
    'category_id' => input_string($_GET, 'category_id'),
    'priority'    => input_string($_GET, 'priority'),
    'assigned_to' => input_string($_GET, 'assigned_to'),
];

$complaints = complaint_search($pdo, array_filter($filters, static fn($v) => $v !== ''));
$categories = $pdo->query('SELECT category_id, category_name FROM categories ORDER BY category_name')->fetchAll(PDO::FETCH_ASSOC);
$personnelList = staff_get_active_personnel($pdo);

$pageTitle = 'Complaints';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Complaints</h1>
    <?php if (in_array($currentUser['role_name'], [ROLE_ADMIN, ROLE_OFFICER], true)): ?>
        <a href="<?= e(app_url('complaints/encode.php')) ?>" class="btn btn-primary">Encode Complaint</a>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(app_url('complaints/list.php')) ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label small mb-1">Search Keyword</label>
                <input type="text" class="form-control form-control-sm" id="search" name="search"
                       placeholder="Reference or description..." value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label small mb-1">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (['Received','For Assignment','Assigned','In Progress','On Hold','Resolved','Closed','Rejected / Invalid'] as $st): ?>
                        <option value="<?= e($st) ?>"<?= $filters['status'] === $st ? ' selected' : '' ?>><?= e($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="category_id" class="form-label small mb-1">Category</label>
                <select class="form-select form-select-sm" id="category_id" name="category_id">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['category_id']) ?>"<?= $filters['category_id'] === (string)$cat['category_id'] ? ' selected' : '' ?>>
                            <?= e($cat['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="priority" class="form-label small mb-1">Priority</label>
                <select class="form-select form-select-sm" id="priority" name="priority">
                    <option value="">All priorities</option>
                    <?php foreach (PRIORITY_LEVELS as $pr): ?>
                        <option value="<?= e($pr) ?>"<?= $filters['priority'] === $pr ? ' selected' : '' ?>><?= e($pr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="assigned_to" class="form-label small mb-1">Assignee</label>
                <select class="form-select form-select-sm" id="assigned_to" name="assigned_to">
                    <option value="">All assignees</option>
                    <?php foreach ($personnelList as $p): ?>
                        <option value="<?= e($p['user_id']) ?>"<?= $filters['assigned_to'] === (string)$p['user_id'] ? ' selected' : '' ?>>
                            <?= e($p['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-secondary btn-sm w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Category</th>
                    <th scope="col">Priority</th>
                    <th scope="col">Status</th>
                    <th scope="col">Assignee</th>
                    <th scope="col">Submitted By</th>
                    <th scope="col">Date Submitted</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($complaints)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-body-secondary">No complaints found matching the criteria.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($complaints as $c): ?>
                        <tr>
                            <td>
                                <a href="<?= e(app_url('complaints/view.php?id=' . $c['complaint_id'])) ?>" class="fw-semibold text-decoration-none">
                                    <?= e($c['reference_no']) ?>
                                </a>
                            </td>
                            <td><?= e($c['category_name'] ?? 'Uncategorized') ?></td>
                            <td><?= priority_badge($c['priority']) ?></td>
                            <td><?= status_badge($c['status']) ?></td>
                            <td>
                                <?php if (!empty($c['assigned_to_name'])): ?>
                                    <?= e($c['assigned_to_name']) ?>
                                <?php else: ?>
                                    <span class="text-body-secondary fst-italic">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['submitted_by_username'])): ?>
                                    <?= e($c['submitted_by_fullname'] ?? $c['submitted_by_username']) ?>
                                    <small class="text-body-secondary d-block">(Resident)</small>
                                <?php else: ?>
                                    <span class="text-body-secondary"><?= e($c['source']) ?> (Staff-encoded)</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(format_display_datetime($c['created_at'])) ?></td>
                            <td class="text-end">
                                <a href="<?= e(app_url('complaints/view.php?id=' . $c['complaint_id'])) ?>" class="btn btn-outline-secondary btn-sm">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
