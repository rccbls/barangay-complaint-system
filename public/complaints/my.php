<?php
/**
 * Resident "My Complaints" page (Phase 6: FR-005, FR-022).
 * Shows only complaints submitted by the authenticated resident.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$currentUser = require_role([ROLE_RESIDENT]);
$pdo = db();

$complaints = complaint_fetch_for_user($pdo, (int)$currentUser['user_id']);

$pageTitle = 'My Complaints';
$layoutArea = 'resident';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">My Complaints</h1>
    <a href="<?= e(app_url('complaints/create.php')) ?>" class="btn btn-primary">Submit New Complaint</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">Reference No</th>
                    <th scope="col">Category</th>
                    <th scope="col">Status</th>
                    <th scope="col">Date Submitted</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($complaints)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4 text-body-secondary">
                            You have not submitted any complaints yet.
                            <div class="mt-2">
                                <a href="<?= e(app_url('complaints/create.php')) ?>" class="btn btn-sm btn-outline-primary">Submit a complaint</a>
                            </div>
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
                            <td><?= status_badge($c['status']) ?></td>
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
