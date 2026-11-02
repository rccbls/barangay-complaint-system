<?php
/**
 * View template for staff list of complaints (Phase 6).
 * Expects variables: $complaints (array), $filters (array).
 */
?>
<h2>Complaints List</h2>
<form method="get" class="mb-3">
    <div class="row g-2 align-items-center">
        <div class="col-auto">
            <input type="text" name="search" class="form-control" placeholder="Search..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
        </div>
        <div class="col-auto">
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                <?php foreach (['Received','In Review','Rejected','Invalid','Resolved','Closed'] as $st): ?>
                    <option value="<?= $st ?>" <?= (isset($filters['status']) && $filters['status']===$st) ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="category_id" class="form-select">
                <option value="">All categories</option>
                <?php
                // Load categories for filter dropdown
                $catStmt = $pdo->query('SELECT category_id, name FROM categories WHERE is_active = 1 ORDER BY name');
                foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $cat): ?>
                    <option value="<?= $cat['category_id'] ?>" <?= (isset($filters['category_id']) && $filters['category_id']==$cat['category_id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary">Filter</button>
        </div>
    </div>
</form>
<table class="table table-hover">
    <thead>
        <tr>
            <th>#</th>
            <th>Reference</th>
            <th>Category</th>
            <th>Status</th>
            <th>Submitted By</th>
            <th>Created At</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($complaints as $c): ?>
            <tr>
                <td><?= $c['complaint_id'] ?></td>
                <td><?= htmlspecialchars($c['reference_no']) ?></td>
                <td><?= htmlspecialchars($c['category_id']) ?></td>
                <td><?= htmlspecialchars($c['status'] ?? 'Received') ?></td>
                <td><?= htmlspecialchars($c['submitted_by']) ?></td>
                <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($c['created_at']))) ?></td>
                <td><a href="/complaints/view.php?id=<?= $c['complaint_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

