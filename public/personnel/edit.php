<?php
/**
 * Administrator: edit a STAFF account (Phase 4): name, role, and an optional replacement password.
 * Resident accounts and unknown IDs answer 404. Role changes that would leave records without a
 * valid handling path are refused with the reason shown.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
$currentUser = require_role([ROLE_ADMIN]);
$pdo         = db();

$isPost  = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost) {
    require_csrf();
}
$id      = (int) input_string($isPost ? $_POST : $_GET, $isPost ? 'user_id' : 'id');
$account = $id > 0 ? staff_account_find($pdo, $id) : null;
if ($account === null) {
    abort_page(404, 'Not found', 'That staff account could not be found.', $currentUser);
}

$errors = [];
$values = ['full_name' => $account['full_name'], 'role' => $account['role_name']];

if ($isPost) {
    [$errors, $clean] = staff_validate_edit($_POST, $account['username']);
    $values = ['full_name' => $clean['full_name'], 'role' => $clean['role']];
    if ($errors === []) {
        $result = staff_update_account($pdo, $currentUser, $id, $clean);
        if ($result['result'] === 'ok') {
            flash_set('success', 'Staff account ' . $account['username'] . ' was updated.');
            redirect('personnel/index.php');
        }
        if ($result['result'] === 'not_found') {
            abort_page(404, 'Not found', 'That staff account could not be found.', $currentUser);
        }
        $errors['role'] = implode(' ', $result['blockers']);
    }
}

$pageTitle  = 'Edit staff account';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<h1 class="h3 mb-3">Edit staff account</h1>
<form method="post" action="<?= e(app_url('personnel/edit.php')) ?>" novalidate style="max-width: 32rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="user_id" value="<?= (int) $account['user_id'] ?>">
    <div class="mb-3">
        <label for="username_display" class="form-label">Username</label>
        <input type="text" class="form-control" id="username_display" value="<?= e($account['username']) ?>" readonly>
    </div>
    <div class="mb-3">
        <label for="full_name" class="form-label">Full name</label>
        <input type="text" class="form-control<?= field_invalid($errors, 'full_name') ?>" id="full_name" name="full_name"
               value="<?= e($values['full_name']) ?>" maxlength="150"<?= field_describe($errors, 'full_name') ?> required>
        <?= field_feedback($errors, 'full_name') ?>
    </div>
    <div class="mb-3">
        <label for="role" class="form-label">Role</label>
        <select class="form-select<?= field_invalid($errors, 'role') ?>" id="role" name="role"<?= field_describe($errors, 'role') ?> required>
            <?php foreach (staff_roles() as $r): ?>
                <option value="<?= e($r) ?>"<?= $values['role'] === $r ? ' selected' : '' ?>><?= e($r) ?></option>
            <?php endforeach; ?>
        </select>
        <?= field_feedback($errors, 'role') ?>
    </div>
    <fieldset class="mb-3">
        <legend class="h6">Reset password <span class="text-body-secondary fw-normal">(optional)</span></legend>
        <div class="mb-2">
            <label for="password" class="form-label">New password</label>
            <input type="password" class="form-control<?= field_invalid($errors, 'password') ?>" id="password" name="password"
                   autocomplete="new-password"<?= field_describe($errors, 'password') ?>>
            <div class="form-text">Leave blank to keep the current password.</div>
            <?= field_feedback($errors, 'password') ?>
        </div>
        <div>
            <label for="password_confirm" class="form-label">Confirm new password</label>
            <input type="password" class="form-control<?= field_invalid($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm"
                   autocomplete="new-password"<?= field_describe($errors, 'password_confirm') ?>>
            <?= field_feedback($errors, 'password_confirm') ?>
        </div>
    </fieldset>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a class="btn btn-outline-secondary" href="<?= e(app_url('personnel/index.php')) ?>">Cancel</a>
    </div>
</form>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
