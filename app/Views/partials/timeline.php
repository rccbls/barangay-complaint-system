<?php
/**
 * Partial view to render the status timeline for a complaint.
 * Expects $timeline array where each row has: changed_at, old_status, new_status, reason, username (changed_by).
 */
?>
<ul class="list-group">
    <?php foreach ($timeline as $event): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <strong><?= htmlspecialchars($event['new_status']) ?></strong>
                <?php if (!empty($event['reason'])): ?>
                    <small class="text-muted">– <?= htmlspecialchars($event['reason']) ?></small>
                <?php endif; ?>
                <br>
                <small class="text-muted">
                    by <?= htmlspecialchars($event['changed_by']) ?>
                    on <?= htmlspecialchars(date('Y-m-d H:i', strtotime($event['changed_at']))) ?>
                </small>
            </div>
            <?php
                // Display a badge with the old status (if any) for context
                $badgeClass = 'secondary';
                if ($event['old_status'] === 'Received') $badgeClass = 'info';
                elseif ($event['old_status'] === 'In Review') $badgeClass = 'primary';
                elseif ($event['old_status'] === 'Rejected' || $event['old_status'] === 'Invalid') $badgeClass = 'danger';
                elseif ($event['old_status'] === 'Resolved') $badgeClass = 'success';
                elseif ($event['old_status'] === 'Closed') $badgeClass = 'dark';
            ?>
            <?php if ($event['old_status'] !== null): ?>
                <span class="badge bg-<?= $badgeClass ?>"><?= htmlspecialchars($event['old_status']) ?></span>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>

