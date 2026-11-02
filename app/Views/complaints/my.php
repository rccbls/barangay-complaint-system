<?php
/**
 * View template for resident's own complaints (Phase 6).
 * Expects variable: $complaints (array).
 */
?>
<h2>My Complaints</h2>
<table class="table table-hover">
    <thead>
        <tr>
            <th>#</th>
            <th>Reference</th>
            <th>Status</th>
            <th>Created At</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($complaints as $c): ?>
            <tr>
                <td><?= $c['complaint_id'] ?></td>
                <td><?= htmlspecialchars($c['reference_no']) ?></td>
                <td><?= htmlspecialchars($c['status'] ?? 'Received') ?></td>
                <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($c['created_at']))) ?></td>
                <td><a href="/complaints/view.php?id=<?= $c['complaint_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

