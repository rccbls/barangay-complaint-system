<?php
/**
 * View template for a single complaint detail (Phase 6 & 7).
 * Expects variables:
 *   $complaint (array) – complaint data
 *   $timeline (array) – status history rows
 *   $allowedNext (array) – list of statuses the current user can transition to
 *   $errors (array) – optional validation errors from status update
 *   $flash (string) – optional success message
 */
?>
<h2>Complaint Detail</h2>
<?php if (!empty($flash)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($errors as $field => $msg): ?>
                <li><?= htmlspecialchars($msg) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<table class="table table-bordered">
    <tr><th>Reference</th><td><?= htmlspecialchars($complaint['reference_no']) ?></td></tr>
    <tr><th>Status</th><td><?= htmlspecialchars($complaint['status'] ?? 'Received') ?></td></tr>
    <tr><th>Category</th><td><?= htmlspecialchars($complaint['category_id']) ?></td></tr>
    <tr><th>Description</th><td><?= nl2br(htmlspecialchars($complaint['description'])) ?></td></tr>
    <tr><th>Incident Location</th><td><?= htmlspecialchars($complaint['incident_location'] ?? '-') ?></td></tr>
    <tr><th>Incident At</th><td><?= htmlspecialchars($complaint['incident_at'] ?? '-') ?></td></tr>
    <tr><th>Priority</th><td><?= htmlspecialchars($complaint['priority']) ?></td></tr>
    <tr><th>Priority Reason</th><td><?= nl2br(htmlspecialchars($complaint['priority_reason'])) ?></td></tr>
    <tr><th>Source</th><td><?= htmlspecialchars($complaint['source']) ?></td></tr>
    <tr><th>Created At</th><td><?= htmlspecialchars($complaint['created_at']) ?></td></tr>
</table>

<h3>Status Timeline</h3>
<?php include APP_ROOT . '/app/Views/partials/timeline.php'; ?>

<?php if (!empty($allowedNext)): ?>
    <h3>Change Status</h3>
    <form method="post" class="mb-3">
        <div class="mb-2">
            <label for="new_status" class="form-label">New Status</label>
            <select name="new_status" id="new_status" class="form-select" required>
                <option value="" selected disabled>Select status</option>
                <?php foreach ($allowedNext as $ns): ?>
                    <option value="<?= $ns ?>"><?= $ns ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-2">
            <label for="reason" class="form-label">Reason (required for Rejected/Invalid)</label>
            <textarea name="reason" id="reason" class="form-control" rows="3"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Update Status</button>
    </form>
<?php endif; ?>

