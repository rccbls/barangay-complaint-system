<?php
/**
 * Resident profile (Phase 3). Residents see and edit ONLY their own profile.
 *
 * There is no ID in the URL or the form: the profile is always looked up with the user ID
 * from the authenticated session, so another resident's data cannot be requested or changed.
 * PLACEHOLDER fields (pending client approval): full name, contact number, address.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/init.php';
$currentUser = require_role([ROLE_RESIDENT]);
$pdo         = db();
$userId      = (int) $currentUser['user_id'];

$profile = resident_profile_ensure($pdo, $userId);
$errors  = [];
$values  = [
    'full_name'      => (string) $profile['full_name'],
    'contact_number' => (string) $profile['contact_number'],
    'address'        => (string) $profile['address'],
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    [$errors, $clean] = resident_validate_profile($_POST);
    $values = [
        'full_name'      => $clean['full_name'],
        'contact_number' => (string) $clean['contact_number'],
        'address'        => (string) $clean['address'],
    ];
    if ($errors === []) {
        resident_profile_update($pdo, $userId, $clean);
        flash_set('success', 'Your profile was updated.');
        redirect('resident/profile/index.php');
    }
}

$invalid  = static fn(string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
$feedback = static fn(string $f): string => isset($errors[$f])
    ? '<div class="invalid-feedback" id="' . e($f) . '-error">' . e($errors[$f]) . '</div>' : '';
$describe = static fn(string $f): string => isset($errors[$f]) ? ' aria-describedby="' . e($f) . '-error"' : '';

$pageTitle  = 'My profile';
$layoutArea = 'resident';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<h1 class="h3 mb-1">My profile</h1>
<p class="text-body-secondary">Contact number and address are optional.</p>

<form method="post" action="<?= e(app_url('resident/profile/index.php')) ?>" novalidate style="max-width: 32rem;">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="username_display">Username</label>
        <input type="text" class="form-control" id="username_display" value="<?= e($currentUser['username']) ?>" readonly>
    </div>
    <div class="mb-3">
        <label for="full_name" class="form-label">Full name</label>
        <input type="text" class="form-control<?= $invalid('full_name') ?>" id="full_name" name="full_name"
               value="<?= e($values['full_name']) ?>" maxlength="150" autocomplete="name"<?= $describe('full_name') ?> required>
        <?= $feedback('full_name') ?>
    </div>
    <div class="mb-3">
        <label for="contact_number" class="form-label">Contact number <span class="text-body-secondary">(optional)</span></label>
        <input type="tel" class="form-control<?= $invalid('contact_number') ?>" id="contact_number" name="contact_number"
               value="<?= e($values['contact_number']) ?>" maxlength="30" autocomplete="tel"<?= $describe('contact_number') ?>>
        <?= $feedback('contact_number') ?>
    </div>
    <div class="mb-3">
        <label for="address" class="form-label">Address <span class="text-body-secondary">(optional)</span></label>
        <input type="text" class="form-control<?= $invalid('address') ?>" id="address" name="address"
               value="<?= e($values['address']) ?>" maxlength="255" autocomplete="street-address"<?= $describe('address') ?>>
        <?= $feedback('address') ?>
    </div>
    <button type="submit" class="btn btn-primary">Save changes</button>
</form>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
