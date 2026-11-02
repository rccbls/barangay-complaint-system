<?php
/**
 * Resident online complaint submission page (Phase 5, 10, 13).
 *
 * Implements:
 *  - Resident factual input & category selection.
 *  - Factual urgency assessment indicators (instead of residents choosing official priority).
 *  - Proof of complaint attachment upload (photos, documents, PDF, txt).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$currentUser = require_role([ROLE_RESIDENT]);
$pdo = db();

$errors = [];
$values = [
    'category_id'       => '',
    'description'       => '',
    'incident_location' => '',
    'incident_at'       => '',
    'severity'          => 'Moderate',
    'affected_residents'=> '0',
    'immediate_danger'  => false,
    'public_hazard'     => false,
    'community_impact'  => 'Low',
    'location_type'     => 'Other',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();

    $payload = $_POST;
    $payload['threat_to_life'] = !empty($_POST['threat_to_life']);
    $payload['public_hazard']  = !empty($_POST['public_hazard']);

    [$errors, $clean] = complaint_validate($pdo, $payload, true);

    // Optional proof of complaint attachment validation
    $hasFile = isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hasFile && $errors === []) {
        $file = $_FILES['attachment'];
        $maxSize = attachment_max_size();
        if ($file['size'] > $maxSize) {
            $errors['attachment'] = 'Attachment exceeds maximum size of 5 MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowed = attachment_allowed_types();
            if (!isset($allowed[$mime])) {
                $errors['attachment'] = 'File type not allowed. Accepted: JPG, PNG, GIF, PDF, TXT.';
            }
        }
    }

    if ($errors === []) {
        $result = complaint_create($pdo, $currentUser, $clean, true);
        if ($result['ok']) {
            $complaintId = $result['complaint_id'];
            
            // Upload attachment proof if provided
            if ($hasFile) {
                attachment_upload($pdo, $currentUser, $complaintId, $_FILES['attachment']);
            }

            flash_set('success', 'Your complaint has been submitted successfully! Reference Number: ' . $result['reference']);
            redirect('complaints/my.php');
        }
        $errors = $result['errors'];
    }

    // Repopulate values on error
    $values = [
        'category_id'       => input_string($_POST, 'category_id'),
        'description'       => $_POST['description'] ?? '',
        'incident_location' => $_POST['incident_location'] ?? '',
        'incident_at'       => $_POST['incident_at'] ?? '',
        'severity'          => $_POST['severity'] ?? 'Moderate',
        'affected_residents'=> $_POST['affected_residents'] ?? '0',
        'immediate_danger'  => !empty($_POST['immediate_danger']) || !empty($_POST['threat_to_life']),
        'public_hazard'     => !empty($_POST['public_hazard']),
        'community_impact'  => $_POST['community_impact'] ?? 'Low',
        'location_type'     => $_POST['location_type'] ?? 'Other',
    ];
}

$categories = $pdo->query('SELECT category_id, category_name FROM categories WHERE is_active = 1 ORDER BY category_name')->fetchAll();
$selfAction = app_url('complaints/create.php');
$pageTitle = 'Submit a Complaint';
$layoutArea = 'resident';
require APP_ROOT . '/app/Views/layouts/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h1 class="h3 fw-bold text-dark mb-1">Submit a Complaint</h1>
                <p class="text-secondary small mb-0">Provide details and evidence about your barangay concern.</p>
            </div>
            <a href="<?= e(app_url('complaints/my.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> My Complaints
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger rounded-3 shadow-sm py-2 px-3 small" role="alert">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Please check the form errors:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <span class="fw-semibold text-dark"><i class="bi bi-card-checklist text-primary me-2"></i>Incident Details</span>
            </div>
            <div class="card-body p-4">
                <form method="post" action="<?= e($selfAction) ?>" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>

                    <!-- Category -->
                    <div class="mb-3">
                        <label for="category_id" class="form-label small fw-bold text-secondary">
                            Complaint Category <span class="text-danger">*</span>
                        </label>
                        <select class="form-select<?= field_invalid($errors, 'category_id') ?>" id="category_id" name="category_id" required>
                            <option value="">-- Choose what this is about --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= e($c['category_id']) ?>"<?= $values['category_id'] == $c['category_id'] ? ' selected' : '' ?>>
                                    <?= e($c['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_feedback($errors, 'category_id') ?>
                    </div>

                    <!-- Description -->
                    <div class="mb-3">
                        <label for="description" class="form-label small fw-bold text-secondary">
                            Description of Concern <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control<?= field_invalid($errors, 'description') ?>" id="description" name="description" rows="4"
                                  placeholder="Please describe what happened, who is involved, and what assistance you are requesting..." required><?= e($values['description']) ?></textarea>
                        <?= field_feedback($errors, 'description') ?>
                    </div>

                    <!-- Location and Date -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="incident_location" class="form-label small fw-bold text-secondary">Incident Location</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-secondary"><i class="bi bi-geo-alt"></i></span>
                                <input type="text" class="form-control<?= field_invalid($errors, 'incident_location') ?>" id="incident_location" name="incident_location"
                                       value="<?= e($values['incident_location']) ?>" placeholder="e.g. Purok 3, Alley 2, near post 12" maxlength="255">
                            </div>
                            <?= field_feedback($errors, 'incident_location') ?>
                        </div>
                        <div class="col-md-6">
                            <label for="incident_at" class="form-label small fw-bold text-secondary">Incident Date &amp; Time (Optional)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-secondary"><i class="bi bi-calendar-event"></i></span>
                                <input type="datetime-local" class="form-control<?= field_invalid($errors, 'incident_at') ?>" id="incident_at" name="incident_at" value="<?= e($values['incident_at']) ?>">
                            </div>
                            <?= field_feedback($errors, 'incident_at') ?>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="severity" class="form-label small fw-bold text-secondary">How severe is the situation? <span class="text-danger">*</span></label>
                            <select class="form-select<?= field_invalid($errors, 'severity') ?>" id="severity" name="severity" required>
                                <?php foreach (['Low', 'Moderate', 'Severe', 'Critical'] as $level): ?>
                                    <option value="<?= e($level) ?>"<?= $values['severity'] === $level ? ' selected' : '' ?>><?= e($level) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_feedback($errors, 'severity') ?>
                        </div>
                        <div class="col-md-6">
                            <label for="affected_residents" class="form-label small fw-bold text-secondary">Estimated residents affected <span class="text-danger">*</span></label>
                            <input type="number" min="0" max="1000000" class="form-control<?= field_invalid($errors, 'affected_residents') ?>" id="affected_residents" name="affected_residents" value="<?= e($values['affected_residents']) ?>" required>
                            <?= field_feedback($errors, 'affected_residents') ?>
                        </div>
                        <div class="col-md-6">
                            <label for="community_impact" class="form-label small fw-bold text-secondary">Potential community impact <span class="text-danger">*</span></label>
                            <select class="form-select<?= field_invalid($errors, 'community_impact') ?>" id="community_impact" name="community_impact" required>
                                <?php foreach (['Low', 'Medium', 'High'] as $level): ?>
                                    <option value="<?= e($level) ?>"<?= $values['community_impact'] === $level ? ' selected' : '' ?>><?= e($level) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_feedback($errors, 'community_impact') ?>
                        </div>
                        <div class="col-md-6">
                            <label for="location_type" class="form-label small fw-bold text-secondary">Location type <span class="text-danger">*</span></label>
                            <select class="form-select<?= field_invalid($errors, 'location_type') ?>" id="location_type" name="location_type" required>
                                <?php foreach (['Residential', 'Public Facility', 'Roadway', 'Utility', 'Other'] as $type): ?>
                                    <option value="<?= e($type) ?>"<?= $values['location_type'] === $type ? ' selected' : '' ?>><?= e($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_feedback($errors, 'location_type') ?>
                        </div>
                    </div>

                    <!-- Proof / Evidence Attachment Upload -->
                    <div class="card bg-light border p-3 rounded-3 mb-4">
                        <label for="attachment" class="form-label small fw-bold text-dark mb-1">
                            <i class="bi bi-paperclip text-primary me-1"></i> Attach Proof / Evidence (Optional)
                        </label>
                        <p class="text-muted small mb-2" style="font-size: 0.8rem;">
                            Upload a photo, video capture, document, or written complaint statement supporting your report. (Max 5MB. Formats: JPG, PNG, GIF, PDF, TXT).
                        </p>
                        <input type="file" class="form-control form-control-sm<?= field_invalid($errors, 'attachment') ?>" id="attachment" name="attachment">
                        <?= field_feedback($errors, 'attachment') ?>
                    </div>

                    <!-- Urgency Assessment Checkboxes (Factual Indicators) -->
                    <div class="card border-0 bg-primary-subtle text-dark p-3 rounded-3 mb-4">
                        <div class="fw-bold small text-primary mb-2">
                            <i class="bi bi-shield-exclamation me-1"></i> Urgency &amp; Safety Assessment
                        </div>
                        <p class="small text-secondary mb-2" style="font-size: 0.825rem;">
                            Official priority is evaluated and confirmed by the Barangay Complaint Officer. Please check if any of the following apply:
                        </p>
                        
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="immediate_danger" name="immediate_danger" value="1" <?= $values['immediate_danger'] ? 'checked' : '' ?>>
                            <input type="hidden" name="threat_to_life" value="0">
                            <label class="form-check-label small" for="immediate_danger">
                                <strong>Immediate Danger:</strong> Involves ongoing violence, threat to life, or structural emergency.
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="public_hazard" name="public_hazard" value="1" <?= $values['public_hazard'] ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="public_hazard">
                                <strong>Major Public Hazard:</strong> Involves exposed wires, severe flooding, blocked thoroughfares, or active contamination.
                            </label>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= e(app_url('resident/index.php')) ?>" class="btn btn-light border px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4 shadow-sm">
                            Submit Complaint <i class="bi bi-send ms-1"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
