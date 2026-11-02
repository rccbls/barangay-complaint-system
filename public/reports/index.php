<?php
/**
 * Reports and Analytics (Phase 12: FR-016 & Phase 13: FR-017 Export).
 *
 * Server-side filtering by date range, category, priority, status, and assignee.
 * Report labels include date range, generation time, and applied filters.
 * Authorized for Administrator, Complaint Officer, and Management.
 * Supports CSV export with spreadsheet formula-injection protection (Phase 13: RULES §12.7).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$currentUser = require_role([ROLE_ADMIN, ROLE_OFFICER, ROLE_MANAGEMENT]);
$pdo = db();

// Fetch active categories and personnel for filters
$categories = $pdo->query('SELECT category_id, category_name FROM categories ORDER BY category_name ASC')->fetchAll(PDO::FETCH_ASSOC);
$personnel  = staff_get_active_personnel($pdo);

// Extract filters from GET
$filters = [
    'status'      => input_string($_GET, 'status'),
    'category_id' => input_string($_GET, 'category_id'),
    'priority'    => input_string($_GET, 'priority'),
    'assigned_to' => input_string($_GET, 'assigned_to'),
    'date_from'   => input_string($_GET, 'date_from'),
    'date_to'     => input_string($_GET, 'date_to'),
    'search'      => input_string($_GET, 'search'),
];

$queryResult   = report_complaint_query($pdo, $filters);
$rows          = $queryResult['rows'];
$appliedLabels = $queryResult['filters_applied'];
$generatedAt   = $queryResult['generated_at'];
$summaryCounts = report_summary_counts($pdo, $filters);

// Handle CSV Export (Phase 13: FR-017)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'complaints_report_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // Add UTF-8 BOM for Excel compatibility
    fwrite($out, "\xEF\xBB\xBF");

    // Header row
    fputcsv($out, [
        'Reference No',
        'Category',
        'Priority',
        'Status',
        'Complainant / Submitter',
        'Assigned To',
        'Source',
        'Incident Location',
        'Date Filed (UTC)',
        'Resolved At (UTC)',
        'Closed At (UTC)'
    ]);

    foreach ($rows as $row) {
        $submitter = $row['submitted_by_fullname'] ?? 'Anonymous / Walk-in';
        fputcsv($out, [
            csv_safe($row['reference_no']),
            csv_safe($row['category_name'] ?? 'Uncategorized'),
            csv_safe($row['priority']),
            csv_safe($row['status']),
            csv_safe($submitter),
            csv_safe($row['assigned_to_name'] ?? 'Unassigned'),
            csv_safe($row['source']),
            csv_safe($row['incident_location'] ?? ''),
            csv_safe($row['created_at']),
            csv_safe($row['resolved_at'] ?? ''),
            csv_safe($row['closed_at'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle  = 'Reports';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h3 mb-0">Complaints Report</h1>
        <div class="text-body-secondary small">
            Generated on: <strong><?= e($generatedAt) ?></strong>
            &bull; User: <strong><?= e($currentUser['full_name']) ?> (<?= e($currentUser['role_name']) ?>)</strong>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            Print Report
        </button>
        <?php
        $exportQuery = http_build_query(array_merge($_GET, ['export' => 'csv']));
        ?>
        <a href="?<?= e($exportQuery) ?>" class="btn btn-outline-primary btn-sm">
            Export to CSV
        </a>
    </div>
</div>

<!-- Filter Card -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" action="<?= e(app_url('reports/index.php')) ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="status" class="form-label small">Status</label>
                <select name="status" id="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php
                    $allStatuses = ['Received', 'For Assignment', 'Assigned', 'In Progress', 'On Hold', 'Resolved', 'Closed', 'Rejected / Invalid'];
                    foreach ($allStatuses as $st):
                    ?>
                        <option value="<?= e($st) ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label for="category_id" class="form-label small">Category</label>
                <select name="category_id" id="category_id" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e((string)$cat['category_id']) ?>" <?= $filters['category_id'] === (string)$cat['category_id'] ? 'selected' : '' ?>>
                            <?= e($cat['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label for="priority" class="form-label small">Priority</label>
                <select name="priority" id="priority" class="form-select form-select-sm">
                    <option value="">All Priorities</option>
                    <?php foreach (PRIORITY_LEVELS as $pr): ?>
                        <option value="<?= e($pr) ?>" <?= $filters['priority'] === $pr ? 'selected' : '' ?>><?= e($pr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label for="assigned_to" class="form-label small">Assignee</label>
                <select name="assigned_to" id="assigned_to" class="form-select form-select-sm">
                    <option value="">All Personnel</option>
                    <?php foreach ($personnel as $p): ?>
                        <option value="<?= e((string)$p['user_id']) ?>" <?= $filters['assigned_to'] === (string)$p['user_id'] ? 'selected' : '' ?>>
                            <?= e($p['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label for="date_from" class="form-label small">Date From</label>
                <input type="date" name="date_from" id="date_from" class="form-control form-control-sm" value="<?= e($filters['date_from']) ?>">
            </div>

            <div class="col-md-2">
                <label for="date_to" class="form-label small">Date To</label>
                <input type="date" name="date_to" id="date_to" class="form-control form-control-sm" value="<?= e($filters['date_to']) ?>">
            </div>

            <div class="col-md-4">
                <label for="search" class="form-label small">Keyword Search</label>
                <input type="text" name="search" id="search" class="form-control form-control-sm" placeholder="Ref no or description..." value="<?= e($filters['search']) ?>">
            </div>

            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">Apply Filters</button>
            </div>
            <div class="col-md-2">
                <a href="<?= e(app_url('reports/index.php')) ?>" class="btn btn-outline-secondary btn-sm w-100">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Applied Filters Badges / Labels (RULES.md §12.4) -->
<div class="mb-3">
    <span class="text-body-secondary small fw-semibold">Active Filter Parameters:</span>
    <?php if (empty($appliedLabels)): ?>
        <span class="badge bg-light text-dark border">None (All Records)</span>
    <?php else: ?>
        <?php foreach ($appliedLabels as $fKey => $fVal): ?>
            <span class="badge bg-secondary me-1">
                <?= e($fKey) ?>: <?= e((string)$fVal) ?>
            </span>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Metric Definitions Note (RULES.md §12.5) -->
<div class="alert alert-light border small py-2 mb-4">
    <strong>Report Metric Definitions:</strong>
    "Total" counts all matching complaints. "Open/Pending" refers to any complaint not yet Closed or Rejected.
    Timestamps are stored in UTC and rendered in Philippine Standard Time (PST/Asia:Manila).
</div>

<!-- Report Summary Counts -->
<div class="row g-2 mb-4">
    <div class="col-md-3">
        <div class="card p-2 text-center">
            <span class="small text-muted">Matching Records</span>
            <span class="fs-4 fw-bold"><?= e($summaryCounts['total']) ?></span>
        </div>
    </div>
    <div class="col-md-9">
        <div class="card p-2">
            <span class="small text-muted mb-1">Status Breakdown:</span>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($summaryCounts['by_status'] as $st => $cnt): ?>
                    <span class="small"><?= status_badge($st) ?> <strong><?= e($cnt) ?></strong></span>
                <?php endforeach; ?>
                <?php if (empty($summaryCounts['by_status'])): ?>
                    <span class="small text-muted">No records matching filter</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Results Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover mb-0">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Date Filed</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Submitter</th>
                    <th>Assignee</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            No complaint records found matching the specified criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <a href="<?= e(app_url('complaints/view.php?id=' . $r['complaint_id'])) ?>">
                                <?= e($r['reference_no']) ?>
                            </a>
                        </td>
                        <td><?= e(format_display_datetime($r['created_at'])) ?></td>
                        <td><?= e($r['category_name'] ?? '—') ?></td>
                        <td><?= priority_badge($r['priority']) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td><?= e($r['submitted_by_fullname'] ?? 'Walk-in / Staff') ?></td>
                        <td><?= e($r['assigned_to_name'] ?? 'Unassigned') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>

