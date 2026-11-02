<?php
/**
 * Personnel workspace: "My Assignments" (Phase 9: FR-011).
 * Shows only complaints assigned to the logged-in personnel user.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$currentUser = require_role([ROLE_PERSONNEL]);
$pdo = db();

$tab = input_string($_GET, 'tab');
$activeOnly = ($tab !== 'all');

$assignedComplaints = complaint_get_assigned_for_personnel($pdo, (int)$currentUser['user_id'], $activeOnly);

$pageTitle = 'My Assignments';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">My Assigned Complaints</h1>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <ul class="nav nav-pills">
            <li class="nav-item">
                <a class="nav-link<?= $activeOnly ? ' active' : '' ?>" href="<?= e(app_url('assignments/my.php?tab=active')) ?>">
                    Active Assignments
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link<?= !$activeOnly ? ' active' : '' ?>" href="<?= e(app_url('assignments/my.php?tab=all')) ?>">
                    All / Past Assignments
                </a>
            </li>
        </ul>
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
                    <th scope="col">Assigned On</th>
                    <th scope="col">Assignment Notes</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assignedComplaints)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-body-secondary">
                            <?= $activeOnly ? 'You have no active complaint assignments.' : 'No assignment records found.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assignedComplaints as $c): ?>
                        <tr>
                            <td>
                                <a href="<?= e(app_url('complaints/view.php?id=' . $c['complaint_id'])) ?>" class="fw-semibold text-decoration-none">
                                    <?= e($c['reference_no']) ?>
                                </a>
                            </td>
                            <td><?= e($c['category_name'] ?? 'Uncategorized') ?></td>
                            <td><?= priority_badge($c['priority']) ?></td>
                            <td><?= status_badge($c['status']) ?></td>
                            <td><?= e(format_display_datetime($c['assigned_at'])) ?></td>
                            <td>
                                <?php if (!empty($c['assignment_notes'])): ?>
                                    <span class="small"><?= e($c['assignment_notes']) ?></span>
                                <?php else: ?>
                                    <span class="text-body-secondary">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= e(app_url('complaints/view.php?id=' . $c['complaint_id'])) ?>" class="btn btn-outline-primary btn-sm">
                                    View / Update
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

