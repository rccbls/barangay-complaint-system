<?php
/**
 * Resident registration (FR-020).
 * Fields are a PLACEHOLDER minimum (full name, username, password) until the client approves
 * the registration fields. The role is always Resident; it is never read from the request.
 * Linking to a resident profile record is Phase 3.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/init.php';
require_guest();

$errors = [];
$values = ['full_name' => '', 'username' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    [$errors, $clean] = auth_validate_registration($_POST);
    $values = ['full_name' => $clean['full_name'], 'username' => $clean['username']];

    if ($errors === []) {
        $result = auth_register_resident(db(), $clean);
        if ($result['ok']) {
            flash_set('success', 'Your account was created. You can now sign in.');
            redirect('login.php');
        }
        $errors = $result['errors'];
    }
}

/** Bootstrap validation classes + message for a field. */
$invalid  = static fn(string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
$feedback = static fn(string $f): string => isset($errors[$f])
    ? '<div class="invalid-feedback" id="' . e($f) . '-error">' . e($errors[$f]) . '</div>' : '';
$describe = static fn(string $f): string => isset($errors[$f]) ? ' aria-describedby="' . e($f) . '-error"' : '';

$pageTitle   = 'Create an account';
$layoutArea  = 'public';
$currentUser = null;
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<div class="mx-auto" style="max-width: 28rem;">
    <h1 class="h3 mb-1">Create a resident account</h1>
    <p class="text-body-secondary">Staff accounts are created by the barangay administrator.</p>

    <form method="post" action="<?= e(app_url('register.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="full_name" class="form-label">Full name</label>
            <input type="text" class="form-control<?= $invalid('full_name') ?>" id="full_name" name="full_name"
                   value="<?= e($values['full_name']) ?>" maxlength="150" autocomplete="name"<?= $describe('full_name') ?> required>
            <?= $feedback('full_name') ?>
        </div>
        <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input type="text" class="form-control<?= $invalid('username') ?>" id="username" name="username"
                   value="<?= e($values['username']) ?>" maxlength="60" autocomplete="username"
                   autocapitalize="none" spellcheck="false"<?= $describe('username') ?> required>
            <?= $feedback('username') ?>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control<?= $invalid('password') ?>" id="password" name="password"
                   autocomplete="new-password"<?= $describe('password') ?> required>
            <div class="form-text">At least <?= (int) AUTH_PASSWORD_MIN_LENGTH ?> characters.</div>
            <?= $feedback('password') ?>
        </div>
        <div class="mb-3">
            <label for="password_confirm" class="form-label">Confirm password</label>
            <input type="password" class="form-control<?= $invalid('password_confirm') ?>" id="password_confirm"
                   name="password_confirm" autocomplete="new-password"<?= $describe('password_confirm') ?> required>
            <?= $feedback('password_confirm') ?>
        </div>
        <button type="submit" class="btn btn-primary w-100">Create account</button>
    </form>

    <p class="mt-3 mb-0">Already registered? <a href="<?= e(app_url('login.php')) ?>">Sign in</a></p>
</div>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
