<?php
/**
 * Staff complaint encoding page (Phase 5). Allows non‑online sources.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$currentUser = require_role([ROLE_ADMIN, ROLE_OFFICER]);
$pdo = db();

$errors = [];
$values = [
    'category_id'      => '',
    'description'      => '',
    'incident_location'=> '',
    'incident_at'      => '',
    'severity'        => 'Moderate',
    'affected_residents' => '0',
    'immediate_danger' => false,
    'community_impact' => 'Low',
    'location_type' => 'Other',
    'priority'         => '',
    'priority_reason'  => '',
    'source'           => 'Walk-in',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    [$errors, $clean] = complaint_validate($pdo, $_POST, false);
    if ($errors === []) {
        $result = complaint_create($pdo, $currentUser, $clean, false);
        if ($result['ok']) {
            flash_set('success', 'Complaint encoded. Reference: ' . $result['reference']);
            redirect('complaints/encode.php');
        }
        $errors = $result['errors'];
    }
    // repopulate values on error
    $values = [
        'category_id'      => input_string($_POST, 'category_id'),
        'description'      => $_POST['description'] ?? '',
        'incident_location'=> $_POST['incident_location'] ?? '',
        'incident_at'      => $_POST['incident_at'] ?? '',
        'severity'         => $_POST['severity'] ?? 'Moderate',
        'affected_residents' => $_POST['affected_residents'] ?? '0',
        'immediate_danger' => !empty($_POST['immediate_danger']),
        'community_impact' => $_POST['community_impact'] ?? 'Low',
        'location_type' => $_POST['location_type'] ?? 'Other',
        'priority'         => $_POST['priority'] ?? '',
        'priority_reason'  => $_POST['priority_reason'] ?? '',
        'source'           => $_POST['source'] ?? 'Walk-in',
    ];
}

$categories = $pdo->query('SELECT category_id, category_name FROM categories WHERE is_active = 1 ORDER BY category_name')->fetchAll();
$selfAction = app_url('complaints/encode.php');
$pageTitle = 'Encode Complaint (Staff)';
$layoutArea = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<h1 class="h3 mb-3">Encode Complaint</h1>
<form method="post" action="<?= e($selfAction) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="category_id" class="form-label">Category</label>
        <select class="form-select<?= field_invalid($errors, 'category_id') ?>" id="category_id" name="category_id" <?= field_describe($errors, 'category_id') ?> required>
            <option value="">Select a category</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c['category_id']) ?>"<?= $values['category_id'] == $c['category_id'] ? ' selected' : '' ?>><?= e($c['category_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?= field_feedback($errors, 'category_id') ?>
    </div>
    <div class="mb-3">
        <label for="description" class="form-label">Description</label>
        <textarea class="form-control<?= field_invalid($errors, 'description') ?>" id="description" name="description" rows="4" <?= field_describe($errors, 'description') ?> required><?= e($values['description']) ?></textarea>
        <?= field_feedback($errors, 'description') ?>
    </div>
    <div class="mb-3">
        <label for="incident_location" class="form-label">Incident location (optional)</label>
        <input type="text" class="form-control<?= field_invalid($errors, 'incident_location') ?>" id="incident_location" name="incident_location" value="<?= e($values['incident_location']) ?>" <?= field_describe($errors, 'incident_location') ?> maxlength="255">
        <?= field_feedback($errors, 'incident_location') ?>
    </div>
    <div class="mb-3">
        <label for="incident_at" class="form-label">Incident date/time (optional)</label>
        <input type="datetime-local" class="form-control<?= field_invalid($errors, 'incident_at') ?>" id="incident_at" name="incident_at" value="<?= e($values['incident_at']) ?>" <?= field_describe($errors, 'incident_at') ?>>
        <?= field_feedback($errors, 'incident_at') ?>
    </div>
    <div class="mb-3">
        <label for="source" class="form-label">Source</label>
        <select class="form-select<?= field_invalid($errors, 'source') ?>" id="source" name="source" <?= field_describe($errors, 'source') ?> required>
            <?php foreach (['Walk-in','Phone','Referral','Other'] as $src): ?>
                <option value="<?= e($src) ?>"<?= $values['source'] === $src ? ' selected' : '' ?>><?= e($src) ?></option>
            <?php endforeach; ?>
        </select>
        <?= field_feedback($errors, 'source') ?>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label for="severity" class="form-label">Severity</label>
            <select class="form-select<?= field_invalid($errors, 'severity') ?>" id="severity" name="severity" required>
                <?php foreach (['Low', 'Moderate', 'Severe', 'Critical'] as $level): ?>
                    <option value="<?= e($level) ?>"<?= $values['severity'] === $level ? ' selected' : '' ?>><?= e($level) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_feedback($errors, 'severity') ?>
        </div>
        <div class="col-md-6">
            <label for="affected_residents" class="form-label">Affected residents</label>
            <input type="number" min="0" max="1000000" class="form-control<?= field_invalid($errors, 'affected_residents') ?>" id="affected_residents" name="affected_residents" value="<?= e($values['affected_residents']) ?>" required>
            <?= field_feedback($errors, 'affected_residents') ?>
        </div>
        <div class="col-md-6">
            <label for="community_impact" class="form-label">Community impact</label>
            <select class="form-select<?= field_invalid($errors, 'community_impact') ?>" id="community_impact" name="community_impact" required>
                <?php foreach (['Low', 'Medium', 'High'] as $level): ?>
                    <option value="<?= e($level) ?>"<?= $values['community_impact'] === $level ? ' selected' : '' ?>><?= e($level) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_feedback($errors, 'community_impact') ?>
        </div>
        <div class="col-md-6">
            <label for="location_type" class="form-label">Location type</label>
            <select class="form-select<?= field_invalid($errors, 'location_type') ?>" id="location_type" name="location_type" required>
                <?php foreach (['Residential', 'Public Facility', 'Roadway', 'Utility', 'Other'] as $type): ?>
                    <option value="<?= e($type) ?>"<?= $values['location_type'] === $type ? ' selected' : '' ?>><?= e($type) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_feedback($errors, 'location_type') ?>
        </div>
    </div>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="immediate_danger" name="immediate_danger" value="1"<?= $values['immediate_danger'] ? ' checked' : '' ?>>
        <label class="form-check-label" for="immediate_danger">Immediate danger or active safety threat</label>
    </div>
    <div class="mb-3">
        <label for="priority" class="form-label">Priority (placeholder)</label>
        <select class="form-select<?= field_invalid($errors, 'priority') ?>" id="priority" name="priority" <?= field_describe($errors, 'priority') ?> required>
            <option value="">Select priority</option>
            <?php foreach (PRIORITY_LEVELS as $lvl): ?>
                <option value="<?= e($lvl) ?>"<?= $values['priority'] === $lvl ? ' selected' : '' ?>><?= e($lvl) ?></option>
            <?php endforeach; ?>
        </select>
        <?= field_feedback($errors, 'priority') ?>
    </div>
    <div class="mb-3">
        <label for="priority_reason" class="form-label">Priority reason</label>
        <input type="text" class="form-control<?= field_invalid($errors, 'priority_reason') ?>" id="priority_reason" name="priority_reason" value="<?= e($values['priority_reason']) ?>" <?= field_describe($errors, 'priority_reason') ?> maxlength="500" required>
        <?= field_feedback($errors, 'priority_reason') ?>
    </div>
    <button type="submit" class="btn btn-primary">Encode</button>
</form>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
?>
