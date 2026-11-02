<?php
/**
 * Category form fields (Phase 4). Expects: $errors, $values, $assignees, $selfAction (form action URL),
 * $categoryId (int|null), $submitLabel.
 */
declare(strict_types=1);
?>
<form method="post" action="<?= e($selfAction) ?>" novalidate style="max-width: 36rem;">
    <?= csrf_field() ?>
    <?php if ($categoryId !== null): ?><input type="hidden" name="category_id" value="<?= (int) $categoryId ?>"><?php endif; ?>
    <div class="mb-3">
        <label for="category_name" class="form-label">Category name</label>
        <input type="text" class="form-control<?= field_invalid($errors, 'category_name') ?>" id="category_name" name="category_name"
               value="<?= e($values['category_name']) ?>" maxlength="100"<?= field_describe($errors, 'category_name') ?> required>
        <?= field_feedback($errors, 'category_name') ?>
    </div>
    <div class="mb-3">
        <label for="description" class="form-label">Description <span class="text-body-secondary">(optional)</span></label>
        <input type="text" class="form-control<?= field_invalid($errors, 'description') ?>" id="description" name="description"
               value="<?= e($values['description']) ?>" maxlength="1000"<?= field_describe($errors, 'description') ?>>
        <?= field_feedback($errors, 'description') ?>
    </div>
    <div class="mb-3">
        <label for="default_priority" class="form-label">Suggested priority <span class="text-body-secondary">(optional)</span></label>
        <select class="form-select<?= field_invalid($errors, 'default_priority') ?>" id="default_priority" name="default_priority"<?= field_describe($errors, 'default_priority') ?>>
            <option value="">None</option>
            <?php foreach (PRIORITY_LEVELS as $level): ?>
                <option value="<?= e($level) ?>"<?= $values['default_priority'] === $level ? ' selected' : '' ?>><?= e($level) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">A suggestion only. A Complaint Officer reviews the official priority of every complaint.</div>
        <?= field_feedback($errors, 'default_priority') ?>
    </div>
    <div class="mb-3">
        <label for="default_assignee_id" class="form-label">Suggested assignee <span class="text-body-secondary">(optional)</span></label>
        <select class="form-select<?= field_invalid($errors, 'default_assignee_id') ?>" id="default_assignee_id" name="default_assignee_id"<?= field_describe($errors, 'default_assignee_id') ?>>
            <option value="">None</option>
            <?php foreach ($assignees as $a): ?>
                <option value="<?= (int) $a['user_id'] ?>"<?= (string) $values['default_assignee_id'] === (string) $a['user_id'] ? ' selected' : '' ?>><?= e($a['full_name']) ?> (<?= e($a['username']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">A suggestion only. Complaints are never assigned automatically; an officer confirms every assignment.</div>
        <?= field_feedback($errors, 'default_assignee_id') ?>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><?= e($submitLabel) ?></button>
        <a class="btn btn-outline-secondary" href="<?= e(app_url('categories/index.php')) ?>">Cancel</a>
    </div>
</form>
