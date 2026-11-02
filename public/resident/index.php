<?php
/**
 * Resident Dashboard (Phase 11: FR-015).
 *
 * Scoped strictly to the resident's own complaints.
 * Clean, welcoming, and modern civic interface.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$currentUser = require_role([ROLE_RESIDENT]);
$pdo = db();

$userId = (int) $currentUser['user_id'];
$summary = dashboard_resident_summary($pdo, $userId);
$recent  = dashboard_resident_recent($pdo, $userId, 5);

$pageTitle  = 'My Dashboard';
$layoutArea = 'resident';
require APP_ROOT . '/app/Views/layouts/header.php';
?>

<!-- Resident Welcome Banner -->
<div class="card border-0 bg-primary bg-gradient text-white shadow-sm rounded-4 mb-4 overflow-hidden position-relative p-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 position-relative" style="z-index: 2;">
        <div>
            <span class="badge bg-white text-primary px-3 py-1 mb-2 fw-bold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">Resident Portal</span>
            <h1 class="h3 fw-bold mb-1">Mabuhay, <?= e($currentUser['full_name']) ?>!</h1>
            <p class="text-white-50 mb-0 small">Track the status of your concerns, follow progress notes, or submit new feedback.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(app_url('complaints/create.php')) ?>" class="btn btn-light text-primary fw-bold shadow-sm px-3">
                <i class="bi bi-file-earmark-plus-fill me-1"></i> File a Complaint
            </a>
            <a href="<?= e(app_url('resident/profile/index.php')) ?>" class="btn btn-outline-light px-3">
                <i class="bi bi-person-circle me-1"></i> My Profile
            </a>
        </div>
    </div>
</div>

<!-- Scoped Summary Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="card border-0 shadow-sm rounded-3 text-center p-3 h-100">
            <div class="stat-icon text-secondary mb-1">
                <i class="bi bi-folder2 fs-2"></i>
            </div>
            <div class="display-6 fw-bold text-dark"><?= e($summary['total']) ?></div>
            <div class="text-muted small fw-medium mt-1">My Total Complaints</div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm rounded-3 text-center p-3 h-100 border-start border-4 border-primary">
            <div class="stat-icon text-primary mb-1">
                <i class="bi bi-arrow-repeat fs-2"></i>
            </div>
            <div class="display-6 fw-bold text-primary"><?= e($summary['open']) ?></div>
            <div class="text-muted small fw-medium mt-1">Under Action / Open</div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm rounded-3 text-center p-3 h-100 border-start border-4 border-success">
            <div class="stat-icon text-success mb-1">
                <i class="bi bi-check-circle-fill fs-2"></i>
            </div>
            <div class="display-6 fw-bold text-success"><?= e($summary['closed']) ?></div>
            <div class="text-muted small fw-medium mt-1">Resolved &amp; Closed</div>
        </div>
    </div>
</div>

<!-- Recent Complaints Section -->
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <span class="fw-bold text-dark"><i class="bi bi-list-task text-primary me-2"></i>My Recent Submissions</span>
        <a href="<?= e(app_url('complaints/my.php')) ?>" class="btn btn-sm btn-outline-primary">View All Complaints &rarr;</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Reference No</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Date Filed</th>
                    <th class="text-end pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent)): ?>
                    <tr>
                        <td colspan="5" class="text-body-secondary text-center py-5">
                            <i class="bi bi-inbox fs-1 text-muted d-block mb-2"></i>
                            You have not submitted any complaints yet.
                            <div class="mt-3">
                                <a href="<?= e(app_url('complaints/create.php')) ?>" class="btn btn-sm btn-primary">File Your First Complaint</a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent as $r): ?>
                    <tr>
                        <td class="ps-3 fw-bold">
                            <a href="<?= e(app_url('complaints/view.php?id=' . $r['complaint_id'])) ?>" class="text-decoration-none">
                                <?= e($r['reference_no']) ?>
                            </a>
                        </td>
                        <td><?= e($r['category_name'] ?? '—') ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="text-secondary small"><?= e(format_display_datetime($r['created_at'])) ?></td>
                        <td class="text-end pe-3">
                            <a href="<?= e(app_url('complaints/view.php?id=' . $r['complaint_id'])) ?>" class="btn btn-sm btn-light border py-1 px-3">
                                View Details &rarr;
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
