<?php
/**
 * Staff Dashboard (Phase 11: FR-015).
 *
 * Summary cards, priority breakdown, complaint volume by category,
 * recent complaints, and personnel workload.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$currentUser = require_role(staff_roles());
$pdo = db();

$summary    = dashboard_staff_summary($pdo);
$categories = dashboard_category_breakdown($pdo);
$recent     = dashboard_recent_complaints($pdo, 10);
$workload   = dashboard_personnel_workload($pdo);

$pageTitle  = 'Dashboard';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>

<!-- Header / Hero section -->
<div class="d-flex flex-wrap align-items-center justify-content-between mb-4 pb-2 border-bottom gap-2">
    <div>
        <h1 class="h3 fw-bold text-dark mb-1">
            <i class="bi bi-speedometer2 text-primary me-2"></i>Executive Dashboard
        </h1>
        <p class="text-secondary small mb-0">Overview of complaint volumes, handling priorities, and staff workloads.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-light text-secondary border px-3 py-2">
            <i class="bi bi-clock me-1"></i> <?= e((new DateTimeImmutable('now', new DateTimeZone(APP_DISPLAY_TIMEZONE)))->format('M d, Y h:i A T')) ?>
        </span>
        <?php if (in_array($currentUser['role_name'], [ROLE_ADMIN, ROLE_OFFICER], true)): ?>
            <a href="<?= e(app_url('complaints/encode.php')) ?>" class="btn btn-primary btn-sm shadow-sm">
                <i class="bi bi-plus-circle me-1"></i> Encode Complaint
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Key Stat Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card stat-card border-0 shadow-sm rounded-3 overflow-hidden h-100">
            <div class="card-body p-3 p-md-4 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem; letter-spacing: 0.05em;">Total Complaints</span>
                    <h2 class="display-6 fw-bold text-dark mb-0 mt-1"><?= e($summary['total']) ?></h2>
                </div>
                <div class="stat-icon-wrapper bg-primary-subtle text-primary rounded-3 p-3">
                    <i class="bi bi-folder2-open fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card border-0 shadow-sm rounded-3 overflow-hidden h-100 border-start border-4 border-warning">
            <div class="card-body p-3 p-md-4 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-warning text-uppercase fw-semibold" style="font-size: 0.75rem; letter-spacing: 0.05em;">Open &amp; Pending</span>
                    <h2 class="display-6 fw-bold text-dark mb-0 mt-1"><?= e($summary['open']) ?></h2>
                </div>
                <div class="stat-icon-wrapper bg-warning-subtle text-warning-emphasis rounded-3 p-3">
                    <i class="bi bi-hourglass-split fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card border-0 shadow-sm rounded-3 overflow-hidden h-100 border-start border-4 border-success">
            <div class="card-body p-3 p-md-4 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-success text-uppercase fw-semibold" style="font-size: 0.75rem; letter-spacing: 0.05em;">Resolved</span>
                    <h2 class="display-6 fw-bold text-dark mb-0 mt-1"><?= e($summary['resolved']) ?></h2>
                </div>
                <div class="stat-icon-wrapper bg-success-subtle text-success rounded-3 p-3">
                    <i class="bi bi-check2-circle fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card border-0 shadow-sm rounded-3 overflow-hidden h-100 border-start border-4 border-secondary">
            <div class="card-body p-3 p-md-4 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-secondary text-uppercase fw-semibold" style="font-size: 0.75rem; letter-spacing: 0.05em;">Closed</span>
                    <h2 class="display-6 fw-bold text-dark mb-0 mt-1"><?= e($summary['closed']) ?></h2>
                </div>
                <div class="stat-icon-wrapper bg-secondary-subtle text-secondary rounded-3 p-3">
                    <i class="bi bi-archive fs-3"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Priority Breakdown Card -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <span class="fw-bold text-dark"><i class="bi bi-bar-chart-fill text-primary me-2"></i>By Priority</span>
                <span class="badge bg-light text-muted border">4 Levels</span>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach (PRIORITY_LEVELS as $level): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-3 py-3 border-light-subtle">
                        <?= priority_badge($level) ?>
                        <span class="badge bg-light text-dark border rounded-pill px-3 py-2 fw-bold">
                            <?= e($summary['by_priority'][$level] ?? 0) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Status Breakdown Card -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <span class="fw-bold text-dark"><i class="bi bi-pie-chart-fill text-primary me-2"></i>By Lifecycle Status</span>
            </div>
            <ul class="list-group list-group-flush">
                <?php
                $allStatuses = ['Received', 'For Assignment', 'Assigned', 'In Progress', 'On Hold', 'Resolved', 'Closed', 'Rejected / Invalid'];
                foreach ($allStatuses as $st):
                    $cnt = $summary['by_status'][$st] ?? 0;
                    if ($cnt === 0 && in_array($st, ['Rejected / Invalid', 'On Hold'], true)) continue;
                ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-3 py-2 border-light-subtle">
                        <?= status_badge($st) ?>
                        <span class="fw-bold text-secondary"><?= e($cnt) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Category Breakdown Card -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <span class="fw-bold text-dark"><i class="bi bi-tag-fill text-primary me-2"></i>Volume by Category</span>
            </div>
            <ul class="list-group list-group-flush">
                <?php if (empty($categories)): ?>
                    <li class="list-group-item text-muted small py-4 text-center">No complaint categories recorded.</li>
                <?php else: ?>
                    <?php foreach (array_slice($categories, 0, 5) as $cat): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-3 py-2 border-light-subtle">
                            <span class="text-truncate me-2 small fw-medium" style="max-width: 220px;"><?= e($cat['category_name']) ?></span>
                            <span class="badge bg-primary-subtle text-primary rounded-pill px-2 py-1"><?= e($cat['cnt']) ?></span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<!-- Personnel Workload Table -->
<?php if (in_array($currentUser['role_name'], [ROLE_ADMIN, ROLE_OFFICER], true) && !empty($workload)): ?>
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <span class="fw-bold text-dark"><i class="bi bi-people-fill text-primary me-2"></i>Field Personnel Workload (Active Assignments)</span>
        <a href="<?= e(app_url('assignments/index.php')) ?>" class="btn btn-sm btn-outline-primary">Manage Assignments</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Personnel Officer</th>
                    <th>Username</th>
                    <th class="text-end pe-3">Active Complaints Assigned</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($workload as $w): ?>
                <tr>
                    <td class="ps-3 fw-semibold">
                        <i class="bi bi-person-badge text-secondary me-1"></i> <?= e($w['full_name']) ?>
                    </td>
                    <td><code><?= e($w['username']) ?></code></td>
                    <td class="text-end pe-3">
                        <?php if ((int)$w['active_count'] > 0): ?>
                            <span class="badge bg-primary text-white rounded-pill px-3 py-1"><?= e($w['active_count']) ?> active</span>
                        <?php else: ?>
                            <span class="badge bg-light text-muted border rounded-pill px-3 py-1">Available (0)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Recent Complaints Table -->
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <span class="fw-bold text-dark"><i class="bi bi-clock-history text-primary me-2"></i>Recent Complaints</span>
        <a href="<?= e(app_url('complaints/list.php')) ?>" class="btn btn-sm btn-outline-primary">View Full Register &rarr;</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Reference No</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Assigned To</th>
                    <th>Date Filed</th>
                    <th class="text-end pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent)): ?>
                    <tr><td colspan="7" class="text-body-secondary text-center py-4">No complaint records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($recent as $r): ?>
                    <tr>
                        <td class="ps-3 fw-bold">
                            <a href="<?= e(app_url('complaints/view.php?id=' . $r['complaint_id'])) ?>" class="text-decoration-none">
                                <?= e($r['reference_no']) ?>
                            </a>
                        </td>
                        <td><?= e($r['category_name'] ?? '—') ?></td>
                        <td><?= priority_badge($r['priority']) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td>
                            <?php if (!empty($r['assigned_to_name'])): ?>
                                <span class="text-dark small"><i class="bi bi-person-check text-success me-1"></i><?= e($r['assigned_to_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted small"><em>Unassigned</em></span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-secondary"><?= e(format_display_datetime($r['created_at'])) ?></td>
                        <td class="text-end pe-3">
                            <a href="<?= e(app_url('complaints/view.php?id=' . $r['complaint_id'])) ?>" class="btn btn-sm btn-light border py-1 px-2">
                                Review <i class="bi bi-chevron-right ms-1"></i>
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
