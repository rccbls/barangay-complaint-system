<?php
/**
 * Staff complaint assignments page (Phase 8: FR-009, FR-010).
 * Accessible by Complaint Officers and Administrators.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$currentUser = require_role([ROLE_ADMIN, ROLE_OFFICER]);
$pdo = db();

$activePersonnel = staff_get_active_personnel($pdo);

// Handle assignment submission from this page if submitted
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $complaintId = (int)input_string($_POST, 'complaint_id');
    $assignedTo  = (int)input_string($_POST, 'assigned_to');
    $notes       = input_string($_POST, 'assignment_notes');

    $res = complaint_assign($pdo, $currentUser, $complaintId, $assignedTo, $notes);
    if ($res['ok']) {
        flash_set('success', 'Complaint successfully assigned.');
        redirect('assignments/index.php');
    }
    $errors = $res['errors'];
}

$scope = input_string($_GET, 'scope');
if (!in_array($scope, ['all', 'assigned', 'pending'], true)) {
    $scope = 'pending';
}

$sql = 'SELECT c.*,
               cat.category_name,
               cat.default_assignee_id,
               def_u.full_name AS default_assignee_name,
               a.assignment_id,
               a.assigned_to,
               a.assigned_at,
               a.assignment_notes,
               asg_u.full_name AS assigned_to_name
        FROM complaints c
        LEFT JOIN categories cat ON c.category_id = cat.category_id
        LEFT JOIN users def_u ON cat.default_assignee_id = def_u.user_id
        LEFT JOIN assignments a ON c.complaint_id = a.complaint_id AND a.unassigned_at IS NULL
        LEFT JOIN users asg_u ON a.assigned_to = asg_u.user_id
        WHERE c.status NOT IN ("Closed", "Rejected / Invalid")';

if ($scope === 'pending') {
    $sql .= ' AND a.assignment_id IS NULL';
} elseif ($scope === 'assigned') {
    $sql .= ' AND a.assignment_id IS NOT NULL';
}

$sql .= ' ORDER BY c.created_at DESC';
$complaints = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Assignments';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Complaint Routing &amp; Assignments</h1>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body py-2">
        <ul class="nav nav-pills">
            <li class="nav-item">
                <a class="nav-link<?= $scope === 'pending' ? ' active' : '' ?>" href="<?= e(app_url('assignments/index.php?scope=pending')) ?>">
                    Awaiting Assignment
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link<?= $scope === 'assigned' ? ' active' : '' ?>" href="<?= e(app_url('assignments/index.php?scope=assigned')) ?>">
                    Currently Assigned
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link<?= $scope === 'all' ? ' active' : '' ?>" href="<?= e(app_url('assignments/index.php?scope=all')) ?>">
                    All Active Complaints
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
                    <th scope="col">Current Assignee</th>
                    <th scope="col">Suggested Assignee</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($complaints)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-body-secondary">
                            No complaints in this view.
                        </td>
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
                                    <span class="fw-medium"><?= e($c['assigned_to_name']) ?></span>
                                    <small class="text-body-secondary d-block"><?= e(format_display_datetime($c['assigned_at'])) ?></small>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['default_assignee_name'])): ?>
                                    <span class="text-body-secondary"><?= e($c['default_assignee_name']) ?></span>
                                    <small class="text-body-secondary d-block">(Category suggestion)</small>
                                <?php else: ?>
                                    <span class="text-body-secondary">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= e(app_url('complaints/view.php?id=' . $c['complaint_id'])) ?>#assign-section" class="btn btn-outline-primary btn-sm">
                                    <?= empty($c['assigned_to']) ? 'Assign' : 'Reassign' ?>
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

