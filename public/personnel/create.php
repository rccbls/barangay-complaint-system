<?php
/**
 * Administrator: create a STAFF account (Phase 4).
 * The role must be one of the staff roles; Resident accounts are created only by self-registration.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
$currentUser = require_role([ROLE_ADMIN]);

$errors = [];
$values = ['full_name' => '', 'username' => '', 'role' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    [$errors, $clean] = staff_validate_new($_POST);
    $values = ['full_name' => $clean['full_name'], 'username' => $clean['username'], 'role' => $clean['role']];
    if ($errors === []) {
        $result = staff_create_account(db(), $currentUser, $clean);
        if ($result['ok']) {
            flash_set('success', 'Staff account ' . $clean['username'] . ' was created.');
            redirect('personnel/index.php');
        }
        $errors = $result['errors'];
    }
}

$pageTitle  = 'Add staff account';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<h1 class="h3 mb-3">Add staff account</h1>
<form method="post" action="<?= e(app_url('personnel/create.php')) ?>" novalidate style="max-width: 32rem;">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="full_name" class="form-label">Full name</label>
        <input type="text" class="form-control<?= field_invalid($errors, 'full_name') ?>" id="full_name" name="full_name"
               value="<?= e($values['full_name']) ?>" maxlength="150"<?= field_describe($errors, 'full_name') ?> required>
        <?= field_feedback($errors, 'full_name') ?>
    </div>
    <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <input type="text" class="form-control<?= field_invalid($errors, 'username') ?>" id="username" name="username"
               value="<?= e($values['username']) ?>" maxlength="60" autocomplete="off" autocapitalize="none" spellcheck="false"<?= field_describe($errors, 'username') ?> required>
        <?= field_feedback($errors, 'username') ?>
    </div>
    <div class="mb-3">
        <label for="role" class="form-label">Role</label>
        <select class="form-select<?= field_invalid($errors, 'role') ?>" id="role" name="role"<?= field_describe($errors, 'role') ?> required>
            <option value="">Choose a role</option>
            <?php foreach (staff_roles() as $r): ?>
                <option value="<?= e($r) ?>"<?= $values['role'] === $r ? ' selected' : '' ?>><?= e($r) ?></option>
            <?php endforeach; ?>
        </select>
        <?= field_feedback($errors, 'role') ?>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" class="form-control<?= field_invalid($errors, 'password') ?>" id="password" name="password"
               autocomplete="new-password"<?= field_describe($errors, 'password') ?> required>
        <div class="form-text">At least <?= (int) AUTH_PASSWORD_MIN_LENGTH ?> characters. Give it to the staff member privately; accounts must not be shared.</div>
        <?= field_feedback($errors, 'password') ?>
    </div>
    <div class="mb-3">
        <label for="password_confirm" class="form-label">Confirm password</label>
        <input type="password" class="form-control<?= field_invalid($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm"
               autocomplete="new-password"<?= field_describe($errors, 'password_confirm') ?> required>
        <?= field_feedback($errors, 'password_confirm') ?>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Create account</button>
        <a class="btn btn-outline-secondary" href="<?= e(app_url('personnel/index.php')) ?>">Cancel</a>
    </div>
</form>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
