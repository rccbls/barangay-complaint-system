<?php
/**
 * Complaint details page (Phase 6, 7, 8, 9, 10).
 * Role-aware:
 *  - Residents see only their own complaints with resident-safe fields.
 *  - Personnel see only their assigned complaints with progress/resolution actions.
 *  - Complaint Officers & Administrators have full review, assignment, status, and priority controls.
 *  - Management has read-only oversight access.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$currentUser = require_login();
$pdo = db();

$complaintId = (int)input_string($_GET, 'id');
if ($complaintId <= 0) {
    abort_page(404, 'Not found', 'Invalid complaint identifier.', $currentUser);
}

$complaint = complaint_fetch_by_id($pdo, $complaintId);
if (!$complaint) {
    abort_page(404, 'Not found', 'That complaint could not be found.', $currentUser);
}

$userRole = $currentUser['role_name'];

// 1. Authorization check
if ($userRole === ROLE_RESIDENT) {
    if (!resident_owns_complaint($pdo, (int)$currentUser['user_id'], $complaintId)) {
        abort_page(404, 'Not found', 'That complaint could not be found.', $currentUser);
    }
} elseif ($userRole === ROLE_PERSONNEL) {
    if (!assignment_is_assigned_to_user($pdo, $complaintId, (int)$currentUser['user_id'])) {
        abort_page(403, 'Access denied', 'You can only view complaints assigned to you.', $currentUser);
    }
} elseif (!in_array($userRole, [ROLE_ADMIN, ROLE_OFFICER, ROLE_MANAGEMENT], true)) {
    abort_page(403, 'Access denied', 'You do not have permission to view this complaint.', $currentUser);
}

// 2. Handle POST actions
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = input_string($_POST, 'form_action');

    if ($action === 'status') {
        $newStatus   = input_string($_POST, 'new_status');
        $remarks     = input_string($_POST, 'remarks');
        $resolution  = input_string($_POST, 'resolution_details');
        $rejection   = input_string($_POST, 'rejection_reason');

        $res = complaint_update_status($pdo, $currentUser, $complaintId, $newStatus, $remarks, $resolution, $rejection);
        if ($res['ok']) {
            flash_set('success', "Complaint status updated to {$newStatus}.");
            redirect("complaints/view.php?id={$complaintId}");
        }
        $errors = $res['errors'];
    } elseif ($action === 'assign') {
        if (!in_array($userRole, [ROLE_ADMIN, ROLE_OFFICER], true)) {
            abort_page(403, 'Access denied', 'Only officers can assign complaints.', $currentUser);
        }
        $assignedTo = (int)input_string($_POST, 'assigned_to');
        $notes      = input_string($_POST, 'assignment_notes');

        $res = complaint_assign($pdo, $currentUser, $complaintId, $assignedTo, $notes);
        if ($res['ok']) {
            flash_set('success', 'Complaint successfully assigned.');
            redirect("complaints/view.php?id={$complaintId}");
        }
        $errors = $res['errors'];
    } elseif ($action === 'priority_override') {
        if (!in_array($userRole, [ROLE_ADMIN, ROLE_OFFICER], true)) {
            abort_page(403, 'Access denied', 'Only officers can override priority.', $currentUser);
        }
        $newPriority = input_string($_POST, 'new_priority');
        $reason      = input_string($_POST, 'priority_reason');

        $res = complaint_override_priority($pdo, $currentUser, $complaintId, $newPriority, $reason);
        if ($res['ok']) {
            flash_set('success', "Priority updated to {$newPriority}.");
            redirect("complaints/view.php?id={$complaintId}");
        }
        $errors = $res['errors'];
    } elseif ($action === 'attachment_upload') {
        if (!isset($_FILES['attachment'])) {
            $errors['attachment'] = 'No attachment file provided.';
        } else {
            $upRes = attachment_upload($pdo, $currentUser, $complaintId, $_FILES['attachment']);
            if ($upRes['ok']) {
                flash_set('success', 'Attachment uploaded successfully.');
                redirect("complaints/view.php?id={$complaintId}");
            }
            $errors = $upRes['errors'];
        }
    }
}

// Fetch history records
$statusHistory     = complaint_load_timeline($pdo, $complaintId);
$assignmentHistory = ($userRole !== ROLE_RESIDENT) ? assignment_get_history($pdo, $complaintId) : [];
$priorityHistory   = ($userRole !== ROLE_RESIDENT) ? priority_get_history($pdo, $complaintId) : [];
$activePersonnel   = in_array($userRole, [ROLE_ADMIN, ROLE_OFFICER], true) ? staff_get_active_personnel($pdo) : [];
$attachments       = attachment_list($pdo, $complaintId);

// Allowed next status transitions
$statusRules = require APP_ROOT . '/app/Config/status_rules.php';
$allowedNextStatuses = $statusRules[$complaint['status']][$userRole] ?? [];

$pageTitle = 'Complaint ' . $complaint['reference_no'];
$layoutArea = layout_area_for_role($userRole);
require APP_ROOT . '/app/Views/layouts/header.php';
?>

<div class="complaint-page">
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <div class="small text-body-secondary mb-1">
            Reference No: <strong><?= e($complaint['reference_no']) ?></strong>
        </div>
        <h1 class="h3 mb-0">Complaint Details</h1>
    </div>
    <div>
        <?php if ($userRole === ROLE_RESIDENT): ?>
            <a href="<?= e(app_url('complaints/my.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; My Complaints</a>
        <?php elseif ($userRole === ROLE_PERSONNEL): ?>
            <a href="<?= e(app_url('assignments/my.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; My Assignments</a>
        <?php else: ?>
            <a href="<?= e(app_url('complaints/list.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Complaints List</a>
            <a href="<?= e(app_url('assignments/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Assignments</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($complaint['priority'] === 'Critical'): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
        <div>
            <strong>Emergency Advisory:</strong> If this situation involves immediate threat to life, serious physical danger, or structural emergency, contact emergency responders immediately (Pasig Emergency / PNP: 911). This portal is not an emergency dispatch service.
        </div>
    </div>
<?php endif; ?>
<?php if (!empty($complaint['immediate_danger'])): ?>
    <div class="alert alert-warning d-flex align-items-center" role="alert">
        <i class="bi bi-person-check-fill fs-5 me-2"></i>
        <div><strong>Urgent human review required:</strong> Immediate danger was reported. An authorized officer must review this complaint regardless of the model prediction.</div>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row complaint-detail-layout">
    <!-- Main Details Column -->
    <div class="col-lg-8">
        <div class="card mb-3 complaint-card-general">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <span class="fw-semibold">General Information</span>
                <div class="d-flex gap-2 align-items-center">
                    <?= status_badge($complaint['status']) ?>
                    <?php if ($userRole !== ROLE_RESIDENT): ?>
                        <?= priority_badge($complaint['priority']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="detail-grid mb-3">
                    <div>Category:</div>
                    <div class="fw-medium"><?= e($complaint['category_name'] ?? 'Uncategorized') ?></div>
                    <div>Date Submitted:</div>
                    <div><?= e(format_display_datetime($complaint['created_at'])) ?></div>
                    <div>Incident Location:</div>
                    <div><?= e($complaint['incident_location'] ?? 'Not specified') ?></div>
                    <div>Incident Date &amp; Time:</div>
                    <div><?= !empty($complaint['incident_at']) ? e(format_display_datetime($complaint['incident_at'])) : 'Not specified' ?></div>
                </div>

                <hr class="my-3">

                <div class="mb-3">
                    <div class="section-label mb-2">Description</div>
                    <div class="description-panel text-break" style="white-space: pre-wrap;"><?= e($complaint['description']) ?></div>
                </div>

                <?php if ($userRole !== ROLE_RESIDENT): ?>
                    <div class="detail-grid mb-2">
                        <div>ML Priority Score:</div>
                        <div>
                            <span class="badge text-bg-primary"><?= e($complaint['priority_score'] ?? 0) ?>/100</span>
                            <?php if (empty($complaint['priority_confirmed'])): ?>
                                <span class="badge text-bg-warning ms-1">Human review required</span>
                            <?php else: ?>
                                <span class="badge text-bg-success ms-1">Confirmed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="detail-grid mb-2">
                        <div>Assessment:</div>
                        <div><?= e($complaint['severity'] ?? 'Not recorded') ?> severity, <?= e($complaint['affected_residents'] ?? 0) ?> affected, <?= e($complaint['community_impact'] ?? 'Not recorded') ?> community impact</div>
                    </div>
                    <div class="detail-grid mb-2">
                        <div>Immediate Danger:</div>
                        <div><?= !empty($complaint['immediate_danger']) ? 'Yes' : 'No' ?> · <?= e($complaint['location_type'] ?? 'Other') ?></div>
                    </div>
                    <div class="detail-grid mb-2">
                        <div>Priority Reason:</div>
                        <div><?= e($complaint['priority_reason']) ?></div>
                    </div>
                    <div class="detail-grid mb-2">
                        <div>Submission Channel:</div>
                        <div><?= e($complaint['source']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($complaint['resolution_details'])): ?>
                    <div class="mt-3 p-3 bg-success-subtle border border-success rounded">
                        <div class="fw-semibold text-success-emphasis mb-1">Resolution Summary:</div>
                        <div class="text-break" style="white-space: pre-wrap;"><?= e($complaint['resolution_details']) ?></div>
                        <?php if (!empty($complaint['resolved_at'])): ?>
                            <div class="small text-muted mt-1">Resolved on <?= e(format_display_datetime($complaint['resolved_at'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($complaint['rejection_reason'])): ?>
                    <div class="mt-3 p-3 bg-danger-subtle border border-danger rounded">
                        <div class="fw-semibold text-danger-emphasis mb-1">Rejection / Invalid Reason:</div>
                        <div class="text-break"><?= e($complaint['rejection_reason']) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status Timeline -->
        <div class="card mb-3 complaint-card-timeline">
            <div class="card-header bg-light fw-semibold">Status Timeline</div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (empty($statusHistory)): ?>
                        <li class="list-group-item text-body-secondary py-3">No status events recorded.</li>
                    <?php else: ?>
                        <?php foreach ($statusHistory as $sh): ?>
                            <li class="list-group-item py-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div class="d-flex gap-2 align-items-center">
                                        <?= status_badge($sh['new_status']) ?>
                                        <span class="fw-medium small"><?= e($sh['action_taken'] ?? 'Status updated') ?></span>
                                    </div>
                                    <small class="text-body-secondary"><?= e(format_display_datetime($sh['created_at'])) ?></small>
                                </div>
                                <?php if (!empty($sh['remarks'])): ?>
                                    <div class="small text-body-secondary ps-1 mt-1 border-start border-2 ps-2">
                                        <?= e($sh['remarks']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($userRole !== ROLE_RESIDENT && !empty($sh['changed_by_name'])): ?>
                                    <div class="small text-body-tertiary mt-1">
                                        Updated by: <?= e($sh['changed_by_name']) ?> (<?= e($sh['changed_by_username']) ?>)
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <?php if ($userRole !== ROLE_RESIDENT): ?>
            <!-- Assignment History (Phase 8) -->
            <div class="card mb-3 complaint-card-assignment-history">
                <div class="card-header bg-light fw-semibold">Assignment History</div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php if (empty($assignmentHistory)): ?>
                            <li class="list-group-item text-body-secondary py-3">No assignments recorded yet.</li>
                        <?php else: ?>
                            <?php foreach ($assignmentHistory as $ah): ?>
                                <li class="list-group-item py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            Assigned to: <strong><?= e($ah['assigned_to_name']) ?></strong>
                                            <?php if ($ah['unassigned_at'] === null): ?>
                                                <span class="badge bg-success-subtle text-success border border-success ms-1">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary ms-1">Completed / Reassigned</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-body-secondary"><?= e(format_display_datetime($ah['assigned_at'])) ?></small>
                                    </div>
                                    <div class="small text-body-secondary">
                                        Assigned by <?= e($ah['assigned_by_name']) ?>
                                        <?php if (!empty($ah['assignment_notes'])): ?>
                                            &mdash; <em><?= e($ah['assignment_notes']) ?></em>
                                        <?php endif; ?>
                                        <?php if (!empty($ah['unassigned_at'])): ?>
                                            <span class="d-block text-muted">Closed on: <?= e(format_display_datetime($ah['unassigned_at'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Priority History (Phase 10) -->
            <div class="card mb-3 complaint-card-priority-history">
                <div class="card-header bg-light fw-semibold">Priority History</div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php if (empty($priorityHistory)): ?>
                            <li class="list-group-item text-body-secondary py-3">No priority changes recorded.</li>
                        <?php else: ?>
                            <?php foreach ($priorityHistory as $ph): ?>
                                <li class="list-group-item py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <?= priority_badge($ph['new_priority']) ?>
                                            <?php if ($ph['old_priority'] !== null): ?>
                                                <small class="text-body-secondary">(from <?= e($ph['old_priority']) ?>)</small>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-body-secondary"><?= e(format_display_datetime($ph['changed_at'])) ?></small>
                                    </div>
                                    <div class="small mt-1">
                                        Reason: <?= e($ph['reason']) ?>
                                    </div>
                                    <div class="small text-body-tertiary">
                                        Changed by: <?= e($ph['changed_by_name']) ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar Column: Role-appropriate Actions & Information -->
    <div class="col-lg-4 complaint-sidebar">
        <div class="complaint-sidebar-stack">
        <!-- Attachments Card (Phase 13: FR-018) -->
        <div class="card mb-3 complaint-card-attachments">
            <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                <span>Attachments</span>
                <span class="badge bg-secondary"><?= count($attachments) ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($attachments)): ?>
                    <div class="text-muted small mb-3">No attachments uploaded yet.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush mb-3">
                        <?php foreach ($attachments as $att): ?>
                            <li class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                                <div class="text-truncate me-2" style="max-width: 180px;">
                                    <a href="<?= e(app_url('complaints/attachment.php?id=' . $att['attachment_id'])) ?>" target="_blank" title="<?= e($att['original_filename']) ?>">
                                        <?= e($att['original_filename']) ?>
                                    </a>
                                    <div class="text-muted" style="font-size: 0.75rem;">
                                        <?= round($att['file_size_bytes'] / 1024, 1) ?> KB &bull; <?= e(format_display_datetime($att['uploaded_at'])) ?>
                                    </div>
                                </div>
                                <a href="<?= e(app_url('complaints/attachment.php?id=' . $att['attachment_id'])) ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                    View
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!in_array($complaint['status'], ['Closed', 'Rejected / Invalid'], true)): ?>
                    <form method="post" action="<?= e(app_url('complaints/view.php?id=' . $complaintId)) ?>" enctype="multipart/form-data" class="mt-2 pt-2 border-top">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="attachment_upload">
                        <label for="attachment" class="form-label small fw-semibold">Add Attachment</label>
                        <input type="file" class="form-control form-control-sm mb-2" id="attachment" name="attachment" required>
                        <div class="form-text small text-muted mb-2">Max 5MB. Formats: JPG, PNG, GIF, PDF, TXT.</div>
                        <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Upload File</button>
                    </form>
                <?php endif; ?>
                </div>
            </div>
            <?php if ($userRole !== ROLE_RESIDENT): ?>
            <!-- Complainant & Source Info -->
            <div class="card mb-3 complaint-card-complainant">
                <div class="card-header bg-light fw-semibold">Complainant Information</div>
                <div class="card-body">
                    <?php if (!empty($complaint['complainant_name'])): ?>
                        <div class="mb-2"><strong>Name:</strong> <?= e($complaint['complainant_name']) ?></div>
                    <?php elseif (!empty($complaint['submitted_by_fullname'])): ?>
                        <div class="mb-2"><strong>Name:</strong> <?= e($complaint['submitted_by_fullname']) ?></div>
                    <?php else: ?>
                        <div class="mb-2 text-body-secondary"><em>Anonymous or not recorded</em></div>
                    <?php endif; ?>

                    <?php if (!empty($complaint['complainant_contact'])): ?>
                        <div class="mb-2"><strong>Contact:</strong> <?= e($complaint['complainant_contact']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($complaint['complainant_address'])): ?>
                        <div class="mb-2"><strong>Address:</strong> <?= e($complaint['complainant_address']) ?></div>
                    <?php endif; ?>
                    <div class="small text-body-secondary mt-2">
                        Submitted via <strong><?= e($complaint['source']) ?></strong>
                        <?php if (!empty($complaint['received_by_fullname'])): ?>
                            <br>Encoded by: <?= e($complaint['received_by_fullname']) ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Assignment Summary -->
            <div class="card mb-3 complaint-card-current-assignee">
                <div class="card-header bg-light fw-semibold">Current Assignee</div>
                <div class="card-body">
                    <?php if (!empty($complaint['active_assignee_name'])): ?>
                        <div class="fw-semibold text-primary"><?= e($complaint['active_assignee_name']) ?></div>
                        <small class="text-body-secondary d-block">
                            Assigned on: <?= e(format_display_datetime($complaint['active_assigned_at'])) ?>
                        </small>
                        <?php if (!empty($complaint['active_assignment_notes'])): ?>
                            <div class="small mt-2 p-2 bg-light rounded border">
                                <strong>Notes:</strong> <?= e($complaint['active_assignment_notes']) ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-body-secondary fst-italic mb-2">Not currently assigned.</div>
                        <?php if (!empty($complaint['default_assignee_name'])): ?>
                            <div class="small text-body-secondary">
                                Suggested category handler: <strong><?= e($complaint['default_assignee_name']) ?></strong>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Officer Routing & Assignment Form (Phase 8: FR-009) -->
            <?php if (in_array($userRole, [ROLE_ADMIN, ROLE_OFFICER], true) && !in_array($complaint['status'], ['Closed', 'Rejected / Invalid'], true)): ?>
                <div class="card mb-3 complaint-card-assign" id="assign-section">
                    <div class="card-header bg-light fw-semibold">
                        <?= empty($complaint['active_assignment_id']) ? 'Assign Complaint' : 'Reassign Complaint' ?>
                    </div>
                    <div class="card-body">
                        <form method="post" action="<?= e(app_url('complaints/view.php?id=' . $complaintId)) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="assign">

                            <div class="mb-3">
                                <label for="assigned_to" class="form-label small">Select Active Personnel</label>
                                <select class="form-select form-select-sm" id="assigned_to" name="assigned_to" required>
                                    <option value="">Choose personnel...</option>
                                    <?php foreach ($activePersonnel as $ap): ?>
                                        <?php
                                            $isSuggested = (!empty($complaint['default_assignee_id']) && (int)$complaint['default_assignee_id'] === (int)$ap['user_id']);
                                            $isCurrent   = (!empty($complaint['active_assigned_to_id']) && (int)$complaint['active_assigned_to_id'] === (int)$ap['user_id']);
                                        ?>
                                        <option value="<?= e($ap['user_id']) ?>"<?= $isCurrent ? ' selected' : '' ?>>
                                            <?= e($ap['full_name']) ?>
                                            <?= $isSuggested ? ' (Category Suggestion)' : '' ?>
                                            <?= $isCurrent ? ' [Current]' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="assignment_notes" class="form-label small">Assignment Notes (optional)</label>
                                <textarea class="form-control form-control-sm" id="assignment_notes" name="assignment_notes" rows="2"
                                          placeholder="Instructions or handling guidelines..."><?= e($complaint['active_assignment_notes'] ?? '') ?></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <?= empty($complaint['active_assignment_id']) ? 'Confirm Assignment' : 'Confirm Reassignment' ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Status Transition Controls (Phase 7, 9) -->
            <?php if (!empty($allowedNextStatuses)): ?>
                <div class="card mb-3 complaint-card-status">
                    <div class="card-header bg-light fw-semibold">Update Status</div>
                    <div class="card-body">
                        <form method="post" action="<?= e(app_url('complaints/view.php?id=' . $complaintId)) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="status">

                            <div class="mb-3">
                                <label for="new_status" class="form-label small">Next Status</label>
                                <select class="form-select form-select-sm" id="new_status" name="new_status" required onchange="toggleStatusFields(this.value)">
                                    <option value="">Select transition...</option>
                                    <?php foreach ($allowedNextStatuses as $st): ?>
                                        <option value="<?= e($st) ?>"><?= e($st) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div id="field-rejection" class="mb-3 d-none">
                                <label for="rejection_reason" class="form-label small text-danger">Rejection / Invalid Reason (required)</label>
                                <textarea class="form-control form-control-sm" id="rejection_reason" name="rejection_reason" rows="2"
                                          placeholder="Explain why this complaint is rejected or invalid..."></textarea>
                            </div>

                            <div id="field-resolution" class="mb-3 d-none">
                                <label for="resolution_details" class="form-label small text-success">Resolution Details (required)</label>
                                <textarea class="form-control form-control-sm" id="resolution_details" name="resolution_details" rows="3"
                                          placeholder="Describe actions taken and outcome achieved..."></textarea>
                            </div>

                            <div id="field-remarks" class="mb-3">
                                <label for="remarks" class="form-label small">Remarks / Notes</label>
                                <textarea class="form-control form-control-sm" id="remarks" name="remarks" rows="2"
                                          placeholder="Notes regarding this status update..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm w-100">Update Status</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Officer Priority Override (Phase 10: FR-008) -->
            <?php if (in_array($userRole, [ROLE_ADMIN, ROLE_OFFICER], true) && !in_array($complaint['status'], ['Closed', 'Rejected / Invalid'], true)): ?>
                <div class="card mb-3 complaint-card-override">
                    <div class="card-header bg-light fw-semibold">Override Priority</div>
                    <div class="card-body">
                        <form method="post" action="<?= e(app_url('complaints/view.php?id=' . $complaintId)) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="priority_override">

                            <div class="mb-2">
                                <label for="new_priority" class="form-label small">New Priority</label>
                                <select class="form-select form-select-sm" id="new_priority" name="new_priority" required>
                                    <?php foreach (PRIORITY_LEVELS as $pr): ?>
                                        <option value="<?= e($pr) ?>"<?= $complaint['priority'] === $pr ? ' selected' : '' ?>><?= e($pr) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="priority_reason" class="form-label small">Override Reason (required)</label>
                                <textarea class="form-control form-control-sm" id="priority_reason" name="priority_reason" rows="2" required
                                          placeholder="Justify priority adjustment..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-outline-warning btn-sm w-100 text-dark">
                                Save Priority Override
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
</div>
</div>

<script>
function toggleStatusFields(status) {
    const rejDiv = document.getElementById('field-rejection');
    const resDiv = document.getElementById('field-resolution');
    if (rejDiv) {
        rejDiv.classList.toggle('d-none', status !== 'Rejected / Invalid');
    }
    if (resDiv) {
        resDiv.classList.toggle('d-none', status !== 'Resolved');
    }
}
</script>

<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
