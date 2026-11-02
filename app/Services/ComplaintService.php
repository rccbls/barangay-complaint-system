<?php
/**
 * Complaint service:
 * Registration, validation, search, assignments (Phase 8),
 * personnel workspace queries (Phase 9), status transitions (Phase 7),
 * priority overrides (Phase 10), dashboard aggregates (Phase 11),
 * report queries (Phase 12), and attachment management (Phase 13).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/app/Services/Authorization.php';
require_once APP_ROOT . '/app/Services/Validation.php';
require_once APP_ROOT . '/app/Services/PriorityScoringService.php';

/** Generate a date-based reference number: BC-YYYYMMDD-XXXXX */
function complaint_generate_reference(): string {
    $date = (new DateTimeImmutable('now', new DateTimeZone(APP_STORAGE_TIMEZONE)))->format('Ymd');
    $rand = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    return "BC-{$date}-{$rand}";
}

/** Validate complaint input. Returns [errors, cleanData]. */
function complaint_validate(PDO $pdo, array $input, bool $isOnline = true): array {
    $errors = [];

    $categoryIdRaw = input_string($input, 'category_id');
    if ($categoryIdRaw === '' || !ctype_digit($categoryIdRaw)) {
        $errors['category_id'] = 'Select a valid category.';
    } else {
        $categoryId = (int)$categoryIdRaw;
        $stmt = $pdo->prepare('SELECT is_active FROM categories WHERE category_id = ?');
        $stmt->execute([$categoryId]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['is_active'] !== 1) {
            $errors['category_id'] = 'Selected category is not active.';
        }
    }

    $description = text_normalize(input_string($input, 'description'));
    if ($description === '' || str_char_length($description) === null) {
        $errors['description'] = 'Enter a description.';
    } elseif (str_char_length($description) > 5000) {
        $errors['description'] = 'Description is too long (maximum 5,000 characters).';
    }

    $incidentLocation = text_normalize(input_string($input, 'incident_location'));
    if ($incidentLocation !== '' && (str_char_length($incidentLocation) ?? 0) > 255) {
        $errors['incident_location'] = 'Location too long (maximum 255 characters).';
    }

    $incidentAtRaw = input_string($input, 'incident_at');
    $incidentAt = null;
    if ($incidentAtRaw !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $incidentAtRaw, new DateTimeZone(APP_DISPLAY_TIMEZONE));
        if ($dt === false) {
            $errors['incident_at'] = 'Invalid incident date/time.';
        } else {
            $incidentAt = $dt->setTimezone(new DateTimeZone(APP_STORAGE_TIMEZONE))->format('Y-m-d H:i:s');
        }
    }

    $source = $isOnline ? 'Online' : input_string($input, 'source');
    $allowedSources = ['Walk-in', 'Phone', 'Online', 'Referral', 'Other'];
    if (!in_array($source, $allowedSources, true)) {
        $errors['source'] = 'Invalid source.';
    }

    $severity = input_string($input, 'severity');
    if (!in_array($severity, ['Low', 'Moderate', 'Severe', 'Critical'], true)) {
        $errors['severity'] = 'Choose a valid severity level.';
    }

    $affectedResidentsRaw = input_string($input, 'affected_residents');
    if ($affectedResidentsRaw === '' || !ctype_digit($affectedResidentsRaw) || (int) $affectedResidentsRaw > 1000000) {
        $errors['affected_residents'] = 'Enter the estimated number of affected residents (0 to 1,000,000).';
    }
    $affectedResidents = (int) $affectedResidentsRaw;

    $immediateDanger = !empty($input['immediate_danger']) ||
        !empty($input['threat_to_life']) ||
        !empty($input['public_hazard']);
    $communityImpact = input_string($input, 'community_impact');
    if (!in_array($communityImpact, ['Low', 'Medium', 'High'], true)) {
        $errors['community_impact'] = 'Choose a valid community impact level.';
    }
    $locationType = input_string($input, 'location_type');
    if (!in_array($locationType, ['Residential', 'Public Facility', 'Roadway', 'Utility', 'Other'], true)) {
        $errors['location_type'] = 'Choose a valid location type.';
    }

    $categoryStmt = $pdo->prepare('SELECT default_priority FROM categories WHERE category_id = ?');
    $categoryStmt->execute([$categoryId ?? 0]);
    $categoryPriority = $categoryStmt->fetchColumn() ?: 'Low';
    $similarStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM complaints WHERE category_id = ? AND incident_location = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 365 DAY)'
    );
    $similarStmt->execute([$categoryId ?? 0, $incidentLocation !== '' ? $incidentLocation : null]);
    $similarComplaints = (int) $similarStmt->fetchColumn();

    $score = complaint_score_priority([
        'category_priority' => $categoryPriority,
        'severity' => $severity,
        'affected_residents' => $affectedResidents,
        'immediate_danger' => $immediateDanger,
        'community_impact' => $communityImpact,
        'location_type' => $locationType,
        'similar_complaints' => $similarComplaints,
    ]);

    if ($isOnline) {
        $priority = $score['priority'];
        $priorityReason = $score['reason'];
    } else {
        // Staff can choose a different initial priority, but the model remains the suggested baseline.
        $priority = input_string($input, 'priority');
        if (!in_array($priority, PRIORITY_LEVELS, true)) {
            $errors['priority'] = 'Choose a priority level.';
        }

        $manualReason = text_normalize(input_string($input, 'priority_reason'));
        $priorityReason = $manualReason;
        if ($priorityReason === '' || (str_char_length($priorityReason) ?? 0) > 500) {
            $errors['priority_reason'] = 'Provide a priority reason (maximum 500 characters).';
        }
    }

    $clean = [];
    if ($errors === []) {
        $clean = [
            'category_id'       => $categoryId,
            'description'       => $description,
            'incident_location' => $incidentLocation !== '' ? $incidentLocation : null,
            'incident_at'       => $incidentAt,
            'priority'          => $priority,
            'priority_reason'   => $priorityReason,
            'severity'          => $severity,
            'affected_residents'=> $affectedResidents,
            'immediate_danger'  => $immediateDanger ? 1 : 0,
            'community_impact'  => $communityImpact,
            'location_type'     => $locationType,
            'priority_score'    => $score['score'],
            'predicted_priority'=> $score['priority'],
            'priority_confirmed'=> $isOnline ? 0 : 1,
            'source'            => $source,
        ];
    }
    return [$errors, $clean];
}

/** Check for duplicate complaint within a 5-minute window. */
function complaint_is_duplicate(PDO $pdo, int $userId, array $clean): bool {
    $window = 5 * 60;
    $sql = "SELECT COUNT(*) FROM complaints WHERE submitted_by_user_id = :uid AND category_id = :cat " .
           "AND description = :desc AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :win SECOND)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uid'  => $userId,
        ':cat'  => $clean['category_id'],
        ':desc' => $clean['description'],
        ':win'  => $window,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Insert complaint, initial status_history, and initial priority_history in one transaction. */
function complaint_create(PDO $pdo, array $currentUser, array $clean, bool $isOnline = true): array {
    $complainantId = null;
    $submittedByUserId = null;
    $receivedBy = null;

    if ($isOnline) {
        $submittedByUserId = (int)$currentUser['user_id'];
        $stmt = $pdo->prepare('SELECT complainant_id FROM complainants WHERE user_id = ?');
        $stmt->execute([$submittedByUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $complainantId = (int)$row['complainant_id'];
        } else {
            $ins = $pdo->prepare('INSERT INTO complainants (user_id, full_name) VALUES (?, ?)');
            $ins->execute([$submittedByUserId, $currentUser['full_name']]);
            $complainantId = (int)$pdo->lastInsertId();
        }

        if (complaint_is_duplicate($pdo, $submittedByUserId, $clean)) {
            return ['ok' => false, 'errors' => ['duplicate' => 'A similar complaint was submitted recently. Please check My Complaints.']];
        }
    } else {
        $receivedBy = (int)$currentUser['user_id'];
    }

    $reference = complaint_generate_reference();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO complaints (reference_no, complainant_id, submitted_by_user_id, category_id, received_by, description, incident_location, incident_at, source, priority, priority_reason, severity, affected_residents, immediate_danger, community_impact, location_type, priority_score, predicted_priority, priority_confirmed, priority_reviewed_by, priority_reviewed_at, status) ' .
            'VALUES (:ref, :cid, :uid, :cat, :rec, :desc, :loc, :inc, :src, :prio, :prr, :severity, :affected_residents, :immediate_danger, :community_impact, :location_type, :priority_score, :predicted_priority, :priority_confirmed, :reviewed_by, :reviewed_at, "Received")'
        );
        $stmt->execute([
            ':ref'  => $reference,
            ':cid'  => $complainantId,
            ':uid'  => $submittedByUserId,
            ':cat'  => $clean['category_id'],
            ':rec'  => $receivedBy,
            ':desc' => $clean['description'],
            ':loc'  => $clean['incident_location'],
            ':inc'  => $clean['incident_at'],
            ':src'  => $clean['source'],
            ':prio' => $clean['priority'],
            ':prr'  => $clean['priority_reason'],
            ':severity' => $clean['severity'],
            ':affected_residents' => $clean['affected_residents'],
            ':immediate_danger' => $clean['immediate_danger'],
            ':community_impact' => $clean['community_impact'],
            ':location_type' => $clean['location_type'],
            ':priority_score' => $clean['priority_score'],
            ':predicted_priority' => $clean['predicted_priority'],
            ':priority_confirmed' => $clean['priority_confirmed'],
            ':reviewed_by' => $clean['priority_confirmed'] ? $currentUser['user_id'] : null,
            ':reviewed_at' => $clean['priority_confirmed'] ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $complaintId = (int)$pdo->lastInsertId();

        // Initial status history
        $stmtHist = $pdo->prepare(
            'INSERT INTO status_history (complaint_id, changed_by, old_status, new_status, action_taken, remarks) ' .
            'VALUES (:cid, :uid, NULL, "Received", "Complaint registered", :rem)'
        );
        $stmtHist->execute([
            ':cid' => $complaintId,
            ':uid' => $currentUser['user_id'],
            ':rem' => $isOnline ? 'Submitted online by resident' : 'Encoded by staff',
        ]);

        // Initial priority history (Phase 10)
        $stmtPrio = $pdo->prepare(
            'INSERT INTO priority_history (complaint_id, changed_by, old_priority, new_priority, reason, changed_at) ' .
            'VALUES (:cid, :uid, NULL, :new_prio, :reason, UTC_TIMESTAMP())'
        );
        $stmtPrio->execute([
            ':cid'      => $complaintId,
            ':uid'      => $currentUser['user_id'],
            ':new_prio' => $clean['priority'],
            ':reason'   => $clean['priority_reason'],
        ]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'errors' => ['db' => $e->getMessage()]];
    }

    return ['ok' => true, 'reference' => $reference, 'complaint_id' => $complaintId];
}

/** Fetch a complaint with its category, complainant, submitter, and active assignment details. */
function complaint_fetch_by_id(PDO $pdo, int $complaintId): ?array {
    $sql = 'SELECT c.*,
                   cat.category_name,
                   cat.default_priority,
                   cat.default_assignee_id,
                   def_u.full_name AS default_assignee_name,
                   sub_u.username AS submitted_by_username,
                   sub_u.full_name AS submitted_by_fullname,
                   rec_u.username AS received_by_username,
                   rec_u.full_name AS received_by_fullname,
                   cp.full_name AS complainant_name,
                   cp.contact_number AS complainant_contact,
                   cp.address AS complainant_address,
                   a.assignment_id AS active_assignment_id,
                   a.assigned_to AS active_assigned_to_id,
                   a.assigned_at AS active_assigned_at,
                   a.assignment_notes AS active_assignment_notes,
                   asg_u.full_name AS active_assignee_name,
                   asg_u.username AS active_assignee_username
            FROM complaints c
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            LEFT JOIN users def_u ON cat.default_assignee_id = def_u.user_id
            LEFT JOIN users sub_u ON c.submitted_by_user_id = sub_u.user_id
            LEFT JOIN users rec_u ON c.received_by = rec_u.user_id
            LEFT JOIN complainants cp ON c.complainant_id = cp.complainant_id
            LEFT JOIN assignments a ON c.complaint_id = a.complaint_id AND a.unassigned_at IS NULL
            LEFT JOIN users asg_u ON a.assigned_to = asg_u.user_id
            WHERE c.complaint_id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$complaintId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Search/filter complaints for staff. */
function complaint_search(PDO $pdo, array $filters = []): array {
    $sql = 'SELECT c.*,
                   cat.category_name,
                   sub_u.username AS submitted_by_username,
                   sub_u.full_name AS submitted_by_fullname,
                   a.assigned_to,
                   asg_u.full_name AS assigned_to_name
            FROM complaints c
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            LEFT JOIN users sub_u ON c.submitted_by_user_id = sub_u.user_id
            LEFT JOIN assignments a ON c.complaint_id = a.complaint_id AND a.unassigned_at IS NULL
            LEFT JOIN users asg_u ON a.assigned_to = asg_u.user_id';
    $conditions = [];
    $params = [];

    if (!empty($filters['status'])) {
        $conditions[] = 'c.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['category_id'])) {
        $conditions[] = 'c.category_id = ?';
        $params[] = (int)$filters['category_id'];
    }
    if (!empty($filters['priority'])) {
        $conditions[] = 'c.priority = ?';
        $params[] = $filters['priority'];
    }
    if (!empty($filters['assigned_to'])) {
        $conditions[] = 'a.assigned_to = ?';
        $params[] = (int)$filters['assigned_to'];
    }
    if (!empty($filters['search'])) {
        $conditions[] = '(c.reference_no LIKE ? OR c.description LIKE ?)';
        $search = "%{$filters['search']}%";
        $params[] = $search;
        $params[] = $search;
    }
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY c.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Fetch complaints belonging to a specific resident. */
function complaint_fetch_for_user(PDO $pdo, int $userId): array {
    $sql = 'SELECT c.*, cat.category_name
            FROM complaints c
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            LEFT JOIN complainants cp ON c.complainant_id = cp.complainant_id
            WHERE c.submitted_by_user_id = :uid1 OR cp.user_id = :uid2
            ORDER BY c.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid1' => $userId, ':uid2' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Load status timeline for a complaint. Ordered chronologically. */
function complaint_load_timeline(PDO $pdo, int $complaintId): array {
    $stmt = $pdo->prepare(
        'SELECT sh.*, u.username AS changed_by_username, u.full_name AS changed_by_name
         FROM status_history sh
         JOIN users u ON sh.changed_by = u.user_id
         WHERE sh.complaint_id = ?
         ORDER BY sh.created_at ASC, sh.status_history_id ASC'
    );
    $stmt->execute([$complaintId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Load priority history for a complaint (Phase 10). Ordered chronologically. */
function priority_get_history(PDO $pdo, int $complaintId): array {
    $stmt = $pdo->prepare(
        'SELECT ph.*, u.username AS changed_by_username, u.full_name AS changed_by_name
         FROM priority_history ph
         JOIN users u ON ph.changed_by = u.user_id
         WHERE ph.complaint_id = ?
         ORDER BY ph.changed_at ASC, ph.priority_history_id ASC'
    );
    $stmt->execute([$complaintId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Load assignment history for a complaint (Phase 8). Ordered chronologically. */
function assignment_get_history(PDO $pdo, int $complaintId): array {
    $stmt = $pdo->prepare(
        'SELECT a.*,
                to_u.full_name AS assigned_to_name,
                to_u.username AS assigned_to_username,
                by_u.full_name AS assigned_by_name,
                by_u.username AS assigned_by_username
         FROM assignments a
         JOIN users to_u ON a.assigned_to = to_u.user_id
         JOIN users by_u ON a.assigned_by = by_u.user_id
         WHERE a.complaint_id = ?
         ORDER BY a.assigned_at ASC, a.assignment_id ASC'
    );
    $stmt->execute([$complaintId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Fetch currently active assignment for a complaint, or null if unassigned. */
function assignment_get_active(PDO $pdo, int $complaintId): ?array {
    $stmt = $pdo->prepare(
        'SELECT a.*,
                to_u.full_name AS assigned_to_name,
                to_u.username AS assigned_to_username,
                by_u.full_name AS assigned_by_name,
                by_u.username AS assigned_by_username
         FROM assignments a
         JOIN users to_u ON a.assigned_to = to_u.user_id
         JOIN users by_u ON a.assigned_by = by_u.user_id
         WHERE a.complaint_id = ? AND a.unassigned_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$complaintId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Check if a complaint is actively assigned to a specific personnel user. */
function assignment_is_assigned_to_user(PDO $pdo, int $complaintId, int $userId): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM assignments WHERE complaint_id = ? AND assigned_to = ? AND unassigned_at IS NULL LIMIT 1');
    $stmt->execute([$complaintId, $userId]);
    return $stmt->fetchColumn() !== false;
}

/** Fetch complaints assigned to a personnel user (Phase 9: My Assignments). */
function complaint_get_assigned_for_personnel(PDO $pdo, int $personnelUserId, bool $activeOnly = true): array {
    $sql = 'SELECT c.*,
                   cat.category_name,
                   a.assignment_id,
                   a.assigned_at,
                   a.assignment_notes,
                   a.unassigned_at
            FROM assignments a
            JOIN complaints c ON a.complaint_id = c.complaint_id
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            WHERE a.assigned_to = ?';
    if ($activeOnly) {
        $sql .= ' AND a.unassigned_at IS NULL';
    }
    $sql .= ' ORDER BY a.assigned_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$personnelUserId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Fetch active personnel for assignment selection (Phase 8). */
function staff_get_active_personnel(PDO $pdo): array {
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.full_name, u.username
         FROM users u
         JOIN roles r ON u.role_id = r.role_id
         WHERE r.role_name = ? AND u.is_active = 1
         ORDER BY u.full_name ASC'
    );
    $stmt->execute([ROLE_PERSONNEL]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Assign or reassign a complaint to active personnel (Phase 8: FR-009, FR-010).
 *
 * Rules:
 *  - Only Administrator and Complaint Officer can assign/reassign.
 *  - Target must be an active Personnel account.
 *  - Reassignment closes previous assignment (unassigned_at = UTC_TIMESTAMP()).
 *  - At most one active assignment per complaint.
 *  - Assignment and status change ('Received' or 'For Assignment' -> 'Assigned') committed in one transaction.
 */
function complaint_assign(PDO $pdo, array $currentUser, int $complaintId, int $assignedToUserId, ?string $notes = null): array {
    $role = $currentUser['role_name'] ?? '';
    if (!in_array($role, [ROLE_ADMIN, ROLE_OFFICER], true)) {
        return ['ok' => false, 'errors' => ['auth' => 'Only Complaint Officers and Administrators can assign complaints.']];
    }

    $complaint = complaint_fetch_by_id($pdo, $complaintId);
    if (!$complaint) {
        return ['ok' => false, 'errors' => ['not_found' => 'Complaint not found.']];
    }
    if (in_array($complaint['status'], ['Closed', 'Rejected / Invalid'], true)) {
        return ['ok' => false, 'errors' => ['status' => 'Cannot assign a closed or rejected complaint.']];
    }

    // Verify target user is active personnel
    $stmt = $pdo->prepare('SELECT u.user_id, u.full_name, u.is_active, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?');
    $stmt->execute([$assignedToUserId]);
    $assignee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignee || $assignee['role_name'] !== ROLE_PERSONNEL || (int)$assignee['is_active'] !== 1) {
        return ['ok' => false, 'errors' => ['assigned_to' => 'Assignee must be an active Personnel account.']];
    }

    $notesClean = text_normalize($notes ?? '');
    if ((str_char_length($notesClean) ?? 0) > 1000) {
        return ['ok' => false, 'errors' => ['notes' => 'Assignment notes are too long (max 1000 characters).']];
    }

    try {
        $pdo->beginTransaction();

        // 1. Close any currently active assignment
        $stmtClose = $pdo->prepare('UPDATE assignments SET unassigned_at = UTC_TIMESTAMP() WHERE complaint_id = ? AND unassigned_at IS NULL');
        $stmtClose->execute([$complaintId]);

        // 2. Insert new assignment
        $stmtIns = $pdo->prepare(
            'INSERT INTO assignments (complaint_id, assigned_to, assigned_by, assigned_at, assignment_notes) ' .
            'VALUES (?, ?, ?, UTC_TIMESTAMP(), ?)'
        );
        $stmtIns->execute([$complaintId, $assignedToUserId, $currentUser['user_id'], $notesClean !== '' ? $notesClean : null]);

        // 3. Status change if currently 'Received' or 'For Assignment'
        $oldStatus = $complaint['status'];
        if (in_array($oldStatus, ['Received', 'For Assignment'], true)) {
            $stmtStatus = $pdo->prepare('UPDATE complaints SET status = "Assigned", updated_at = UTC_TIMESTAMP() WHERE complaint_id = ?');
            $stmtStatus->execute([$complaintId]);

            $stmtHist = $pdo->prepare(
                'INSERT INTO status_history (complaint_id, changed_by, old_status, new_status, action_taken, remarks) ' .
                'VALUES (?, ?, ?, "Assigned", ?, ?)'
            );
            $actionText = 'Assigned to ' . $assignee['full_name'];
            $stmtHist->execute([$complaintId, $currentUser['user_id'], $oldStatus, $actionText, $notesClean !== '' ? $notesClean : null]);
        } else {
            // Record reassignment note in status history if already Assigned / In Progress
            $stmtHist = $pdo->prepare(
                'INSERT INTO status_history (complaint_id, changed_by, old_status, new_status, action_taken, remarks) ' .
                'VALUES (?, ?, ?, ?, ?, ?)'
            );
            $actionText = 'Reassigned to ' . $assignee['full_name'];
            $stmtHist->execute([$complaintId, $currentUser['user_id'], $oldStatus, $oldStatus, $actionText, $notesClean !== '' ? $notesClean : null]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'errors' => ['db' => $e->getMessage()]];
    }

    return ['ok' => true];
}

/**
 * Update complaint status with validation against central transition rules (Phase 7, 9).
 */
function complaint_update_status(
    PDO $pdo,
    array $currentUser,
    int $complaintId,
    string $newStatus,
    ?string $remarks = null,
    ?string $resolutionDetails = null,
    ?string $rejectionReason = null
): array {
    $complaint = complaint_fetch_by_id($pdo, $complaintId);
    if (!$complaint) {
        return ['ok' => false, 'errors' => ['not_found' => 'Complaint not found.']];
    }

    $currentStatus = $complaint['status'];
    $role = $currentUser['role_name'] ?? '';

    // If current role is Personnel, enforce that complaint is actively assigned to them
    if ($role === ROLE_PERSONNEL) {
        if (!assignment_is_assigned_to_user($pdo, $complaintId, (int)$currentUser['user_id'])) {
            return ['ok' => false, 'errors' => ['auth' => 'You can only update complaints assigned to you.']];
        }
    }

    // Check transition rules
    $rules = require APP_ROOT . '/app/Config/status_rules.php';
    $allowed = $rules[$currentStatus][$role] ?? [];
    if (!in_array($newStatus, $allowed, true)) {
        return ['ok' => false, 'errors' => ['invalid_transition' => "Transition from {$currentStatus} to {$newStatus} is not allowed for your role."]];
    }

    $cleanRemarks = text_normalize($remarks ?? '');
    $cleanResolution = text_normalize($resolutionDetails ?? '');
    $cleanRejection = text_normalize($rejectionReason ?? '');

    // Rejection reason required for 'Rejected / Invalid'
    if ($newStatus === 'Rejected / Invalid') {
        if ($cleanRejection === '' || (str_char_length($cleanRejection) ?? 0) < 5) {
            return ['ok' => false, 'errors' => ['rejection_reason' => 'A specific rejection reason is required (minimum 5 characters).']];
        }
        if ((str_char_length($cleanRejection) ?? 0) > 500) {
            return ['ok' => false, 'errors' => ['rejection_reason' => 'Rejection reason is too long (max 500 characters).']];
        }
    }

    // Resolution details required for 'Resolved'
    if ($newStatus === 'Resolved') {
        if ($cleanResolution === '' || (str_char_length($cleanResolution) ?? 0) < 5) {
            return ['ok' => false, 'errors' => ['resolution_details' => 'Resolution details are required before resolving (minimum 5 characters).']];
        }
    }

    // On Hold requires a reason/remark
    if ($newStatus === 'On Hold') {
        if ($cleanRemarks === '' || (str_char_length($cleanRemarks) ?? 0) < 5) {
            return ['ok' => false, 'errors' => ['remarks' => 'Reason for putting complaint on hold is required (minimum 5 characters).']];
        }
    }

    // Reopening requires a reason/remark
    if (in_array($currentStatus, ['Resolved', 'Closed'], true) && $newStatus === 'In Progress') {
        if ($cleanRemarks === '' || (str_char_length($cleanRemarks) ?? 0) < 5) {
            return ['ok' => false, 'errors' => ['remarks' => 'Reason for reopening the complaint is required (minimum 5 characters).']];
        }
    }

    try {
        $pdo->beginTransaction();

        $sql = 'UPDATE complaints SET status = :new_status, updated_at = UTC_TIMESTAMP()';
        $params = [':new_status' => $newStatus, ':cid' => $complaintId];

        if ($newStatus === 'Rejected / Invalid') {
            $sql .= ', rejection_reason = :rej';
            $params[':rej'] = $cleanRejection;
        } elseif ($newStatus === 'Resolved') {
            $sql .= ', resolution_details = :res, resolved_at = UTC_TIMESTAMP()';
            $params[':res'] = $cleanResolution;
        } elseif ($newStatus === 'Closed') {
            $sql .= ', closed_at = UTC_TIMESTAMP()';
        } elseif ($newStatus === 'In Progress' && in_array($currentStatus, ['Resolved', 'Closed'], true)) {
            // Reopened
            $sql .= ', closed_at = NULL, resolved_at = NULL';
        }

        $sql .= ' WHERE complaint_id = :cid';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Action taken label
        $actionTaken = "Status changed to {$newStatus}";
        if ($newStatus === 'In Progress' && in_array($currentStatus, ['Resolved', 'Closed'], true)) {
            $actionTaken = "Reopened by {$role}";
        } elseif ($newStatus === 'Closed') {
            $actionTaken = "Case closed by {$role}";
        }

        $historyRemarks = $cleanRemarks;
        if ($newStatus === 'Rejected / Invalid') {
            $historyRemarks = $cleanRejection;
        } elseif ($newStatus === 'Resolved') {
            $historyRemarks = $cleanResolution;
        }

        $stmtHist = $pdo->prepare(
            'INSERT INTO status_history (complaint_id, changed_by, old_status, new_status, action_taken, remarks, created_at) ' .
            'VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmtHist->execute([
            $complaintId,
            $currentUser['user_id'],
            $currentStatus,
            $newStatus,
            $actionTaken,
            $historyRemarks !== '' ? $historyRemarks : null,
        ]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'errors' => ['db' => $e->getMessage()]];
    }

    return ['ok' => true];
}

/**
 * Priority override by authorized officer with required reason (Phase 10: FR-008).
 */
function complaint_override_priority(PDO $pdo, array $currentUser, int $complaintId, string $newPriority, string $reason): array {
    $role = $currentUser['role_name'] ?? '';
    if (!in_array($role, [ROLE_ADMIN, ROLE_OFFICER], true)) {
        return ['ok' => false, 'errors' => ['auth' => 'Only Complaint Officers and Administrators can override priority.']];
    }

    if (!in_array($newPriority, PRIORITY_LEVELS, true)) {
        return ['ok' => false, 'errors' => ['priority' => 'Invalid priority level.']];
    }

    $cleanReason = text_normalize($reason);
    if ($cleanReason === '' || (str_char_length($cleanReason) ?? 0) < 5) {
        return ['ok' => false, 'errors' => ['reason' => 'An override reason is required (minimum 5 characters).']];
    }
    if ((str_char_length($cleanReason) ?? 0) > 500) {
        return ['ok' => false, 'errors' => ['reason' => 'Reason too long (max 500 characters).']];
    }

    $complaint = complaint_fetch_by_id($pdo, $complaintId);
    if (!$complaint) {
        return ['ok' => false, 'errors' => ['not_found' => 'Complaint not found.']];
    }

    $oldPriority = $complaint['priority'];
    if ($oldPriority === $newPriority) {
        return ['ok' => false, 'errors' => ['priority' => 'New priority must be different from current priority.']];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('UPDATE complaints SET priority = ?, priority_reason = ?, priority_confirmed = TRUE, priority_reviewed_by = ?, priority_reviewed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE complaint_id = ?');
        $stmt->execute([$newPriority, $cleanReason, $currentUser['user_id'], $complaintId]);

        $stmtHist = $pdo->prepare(
            'INSERT INTO priority_history (complaint_id, changed_by, old_priority, new_priority, reason, changed_at) ' .
            'VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmtHist->execute([
            $complaintId,
            $currentUser['user_id'],
            $oldPriority,
            $newPriority,
            $cleanReason,
        ]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'errors' => ['db' => $e->getMessage()]];
    }

    return ['ok' => true];
}

/**
 * Urgency assessment helper using deterministic placeholder criteria (Phase 10: FR-006, FR-007).
 */
function complaint_evaluate_urgency(array $answers): array {
    // answers keys: 'threat_to_life', 'public_hazard', 'dispute_or_damage'
    if (!empty($answers['threat_to_life'])) {
        return [
            'priority' => 'Critical',
            'reason'   => 'Urgency assessment: Threat to life or physical safety reported (placeholder criteria).',
            'is_emergency' => true,
        ];
    }
    if (!empty($answers['public_hazard'])) {
        return [
            'priority' => 'High',
            'reason'   => 'Urgency assessment: Significant public hazard or major community disruption reported.',
            'is_emergency' => false,
        ];
    }
    if (!empty($answers['dispute_or_damage'])) {
        return [
            'priority' => 'Medium',
            'reason'   => 'Urgency assessment: Standard property damage, noise nuisance, or neighborhood concern.',
            'is_emergency' => false,
        ];
    }
    return [
        'priority' => 'Low',
        'reason'   => 'Urgency assessment: Minor inquiry or low-impact feedback.',
        'is_emergency' => false,
    ];
}

// ───────────────────────────────────────────────────────────────────────────────
// Phase 11: Dashboard aggregate queries
// ───────────────────────────────────────────────────────────────────────────────

/**
 * Dashboard summary counts for staff.
 * Returns associative array: total, open, resolved, closed, rejected,
 * by_priority (Critical/High/Medium/Low), by_status (each status => count).
 */
function dashboard_staff_summary(PDO $pdo): array {
    // Total
    $total = (int) $pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn();

    // By status
    $stmt = $pdo->query('SELECT status, COUNT(*) AS cnt FROM complaints GROUP BY status');
    $byStatus = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byStatus[$row['status']] = (int) $row['cnt'];
    }

    // By priority
    $stmt = $pdo->query('SELECT priority, COUNT(*) AS cnt FROM complaints GROUP BY priority');
    $byPriority = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byPriority[$row['priority']] = (int) $row['cnt'];
    }

    $open     = $total - ($byStatus['Closed'] ?? 0) - ($byStatus['Rejected / Invalid'] ?? 0);
    $resolved = $byStatus['Resolved'] ?? 0;
    $closed   = $byStatus['Closed'] ?? 0;
    $rejected = $byStatus['Rejected / Invalid'] ?? 0;

    return [
        'total'       => $total,
        'open'        => $open,
        'resolved'    => $resolved,
        'closed'      => $closed,
        'rejected'    => $rejected,
        'by_status'   => $byStatus,
        'by_priority' => $byPriority,
    ];
}

/**
 * Complaint volume by category for the staff dashboard.
 */
function dashboard_category_breakdown(PDO $pdo): array {
    $sql = 'SELECT cat.category_name, COUNT(*) AS cnt
            FROM complaints c
            JOIN categories cat ON c.category_id = cat.category_id
            GROUP BY c.category_id, cat.category_name
            ORDER BY cnt DESC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Recent complaints for the staff dashboard (most recent N).
 */
function dashboard_recent_complaints(PDO $pdo, int $limit = 10): array {
    $stmt = $pdo->prepare(
        'SELECT c.complaint_id, c.reference_no, c.status, c.priority, c.created_at,
                cat.category_name,
                sub_u.full_name AS submitted_by_fullname,
                a.assigned_to,
                asg_u.full_name AS assigned_to_name
         FROM complaints c
         LEFT JOIN categories cat ON c.category_id = cat.category_id
         LEFT JOIN users sub_u ON c.submitted_by_user_id = sub_u.user_id
         LEFT JOIN assignments a ON c.complaint_id = a.complaint_id AND a.unassigned_at IS NULL
         LEFT JOIN users asg_u ON a.assigned_to = asg_u.user_id
         ORDER BY c.created_at DESC
         LIMIT ?'
    );
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Active assignment workload per personnel for the staff dashboard.
 */
function dashboard_personnel_workload(PDO $pdo): array {
    $sql = 'SELECT u.user_id, u.full_name, u.username,
                   COUNT(a.assignment_id) AS active_count
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN assignments a ON u.user_id = a.assigned_to AND a.unassigned_at IS NULL
            WHERE r.role_name = ? AND u.is_active = 1
            GROUP BY u.user_id, u.full_name, u.username
            ORDER BY active_count DESC, u.full_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([ROLE_PERSONNEL]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Resident dashboard summary (scoped to the resident's own complaints only).
 */
function dashboard_resident_summary(PDO $pdo, int $userId): array {
    $sql = 'SELECT c.status, COUNT(*) AS cnt
            FROM complaints c
            LEFT JOIN complainants cp ON c.complainant_id = cp.complainant_id
            WHERE c.submitted_by_user_id = :uid1 OR cp.user_id = :uid2
            GROUP BY c.status';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid1' => $userId, ':uid2' => $userId]);

    $byStatus = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byStatus[$row['status']] = (int) $row['cnt'];
    }

    $total  = array_sum($byStatus);
    $open   = $total - ($byStatus['Closed'] ?? 0) - ($byStatus['Rejected / Invalid'] ?? 0);
    $closed = $byStatus['Closed'] ?? 0;

    return [
        'total'     => $total,
        'open'      => $open,
        'closed'    => $closed,
        'by_status' => $byStatus,
    ];
}

/**
 * Recent complaints for a resident dashboard (scoped, most recent N).
 */
function dashboard_resident_recent(PDO $pdo, int $userId, int $limit = 5): array {
    $stmt = $pdo->prepare(
        'SELECT c.complaint_id, c.reference_no, c.status, c.priority, c.created_at,
                cat.category_name
         FROM complaints c
         LEFT JOIN categories cat ON c.category_id = cat.category_id
         LEFT JOIN complainants cp ON c.complainant_id = cp.complainant_id
         WHERE c.submitted_by_user_id = :uid1 OR cp.user_id = :uid2
         ORDER BY c.created_at DESC
         LIMIT ' . $limit
    );
    $stmt->execute([':uid1' => $userId, ':uid2' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ───────────────────────────────────────────────────────────────────────────────
// Phase 12: Report queries with server-side filters
// ───────────────────────────────────────────────────────────────────────────────

/**
 * Report query: filtered complaint list for staff reports.
 *
 * Filters: status, category_id, priority, assigned_to, date_from, date_to, search.
 * Returns [rows, filters_applied, generated_at].
 */
function report_complaint_query(PDO $pdo, array $filters = []): array {
    $sql = 'SELECT c.*,
                   cat.category_name,
                   sub_u.full_name AS submitted_by_fullname,
                   rec_u.full_name AS received_by_fullname,
                   a.assigned_to,
                   asg_u.full_name AS assigned_to_name
            FROM complaints c
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            LEFT JOIN users sub_u ON c.submitted_by_user_id = sub_u.user_id
            LEFT JOIN users rec_u ON c.received_by = rec_u.user_id
            LEFT JOIN assignments a ON c.complaint_id = a.complaint_id AND a.unassigned_at IS NULL
            LEFT JOIN users asg_u ON a.assigned_to = asg_u.user_id';
    $conditions = [];
    $params = [];
    $appliedFilters = [];

    if (!empty($filters['status'])) {
        $conditions[] = 'c.status = ?';
        $params[] = $filters['status'];
        $appliedFilters['Status'] = $filters['status'];
    }
    if (!empty($filters['category_id'])) {
        $conditions[] = 'c.category_id = ?';
        $params[] = (int) $filters['category_id'];
    }
    if (!empty($filters['priority'])) {
        $conditions[] = 'c.priority = ?';
        $params[] = $filters['priority'];
        $appliedFilters['Priority'] = $filters['priority'];
    }
    if (!empty($filters['assigned_to'])) {
        $conditions[] = 'a.assigned_to = ?';
        $params[] = (int) $filters['assigned_to'];
    }
    if (!empty($filters['date_from'])) {
        $conditions[] = 'c.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
        $appliedFilters['From'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $conditions[] = 'c.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
        $appliedFilters['To'] = $filters['date_to'];
    }
    if (!empty($filters['search'])) {
        $conditions[] = '(c.reference_no LIKE ? OR c.description LIKE ?)';
        $search = "%{$filters['search']}%";
        $params[] = $search;
        $params[] = $search;
        $appliedFilters['Search'] = $filters['search'];
    }

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY c.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Look up category name for the filter label
    if (!empty($filters['category_id'])) {
        $catStmt = $pdo->prepare('SELECT category_name FROM categories WHERE category_id = ?');
        $catStmt->execute([(int) $filters['category_id']]);
        $catName = $catStmt->fetchColumn();
        $appliedFilters['Category'] = $catName ?: ('ID ' . $filters['category_id']);
    }
    if (!empty($filters['assigned_to'])) {
        $userStmt = $pdo->prepare('SELECT full_name FROM users WHERE user_id = ?');
        $userStmt->execute([(int) $filters['assigned_to']]);
        $userName = $userStmt->fetchColumn();
        $appliedFilters['Assignee'] = $userName ?: ('ID ' . $filters['assigned_to']);
    }

    return [
        'rows'            => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'filters_applied' => $appliedFilters,
        'generated_at'    => (new DateTimeImmutable('now', new DateTimeZone(APP_DISPLAY_TIMEZONE)))->format('Y-m-d H:i:s T'),
    ];
}

/**
 * Report summary counts matching the same filters.
 * Returns: total, by_status, by_priority arrays.
 */
function report_summary_counts(PDO $pdo, array $filters = []): array {
    $baseConditions = [];
    $params = [];

    if (!empty($filters['status'])) {
        $baseConditions[] = 'c.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['category_id'])) {
        $baseConditions[] = 'c.category_id = ?';
        $params[] = (int) $filters['category_id'];
    }
    if (!empty($filters['priority'])) {
        $baseConditions[] = 'c.priority = ?';
        $params[] = $filters['priority'];
    }
    if (!empty($filters['date_from'])) {
        $baseConditions[] = 'c.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $baseConditions[] = 'c.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['search'])) {
        $baseConditions[] = '(c.reference_no LIKE ? OR c.description LIKE ?)';
        $search = "%{$filters['search']}%";
        $params[] = $search;
        $params[] = $search;
    }
    if (!empty($filters['assigned_to'])) {
        // Need assignments join for assignee filter
        $joinSql = ' LEFT JOIN assignments a ON c.complaint_id = a.complaint_id AND a.unassigned_at IS NULL';
        $baseConditions[] = 'a.assigned_to = ?';
        $params[] = (int) $filters['assigned_to'];
    } else {
        $joinSql = '';
    }

    $where = $baseConditions ? ' WHERE ' . implode(' AND ', $baseConditions) : '';

    $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM complaints c' . $joinSql . $where);
    $totalStmt->execute($params);
    $total = (int) $totalStmt->fetchColumn();

    $statusStmt = $pdo->prepare('SELECT c.status, COUNT(*) AS cnt FROM complaints c' . $joinSql . $where . ' GROUP BY c.status');
    $statusStmt->execute($params);
    $byStatus = [];
    while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        $byStatus[$row['status']] = (int) $row['cnt'];
    }

    $prioStmt = $pdo->prepare('SELECT c.priority, COUNT(*) AS cnt FROM complaints c' . $joinSql . $where . ' GROUP BY c.priority');
    $prioStmt->execute($params);
    $byPriority = [];
    while ($row = $prioStmt->fetch(PDO::FETCH_ASSOC)) {
        $byPriority[$row['priority']] = (int) $row['cnt'];
    }

    return [
        'total'       => $total,
        'by_status'   => $byStatus,
        'by_priority' => $byPriority,
    ];
}

// ───────────────────────────────────────────────────────────────────────────────
// Phase 13: Overdue calculations (FR-019, RULES §8)
// ───────────────────────────────────────────────────────────────────────────────

/**
 * Service targets (in hours) per priority for response-time milestone (placeholder targets).
 * The client must confirm official SLA targets.
 */
function complaint_sla_hours_for_priority(string $priority): int {
    return match ($priority) {
        'Critical' => 24,    // 1 day
        'High'     => 72,    // 3 days
        'Medium'   => 120,   // 5 days
        'Low'      => 168,   // 7 days
        default    => 168,
    };
}

/**
 * Check if a complaint is overdue based on elapsed hours vs priority target.
 * Open complaints that exceed the target hours from creation are flagged overdue.
 * Resolved or Closed complaints evaluate elapsed time up to resolution/closure.
 */
function complaint_is_overdue(array $complaint): bool {
    if (in_array($complaint['status'], ['Rejected / Invalid'], true)) {
        return false;
    }
    $targetHours = complaint_sla_hours_for_priority($complaint['priority'] ?? 'Low');
    $created = new DateTimeImmutable($complaint['created_at'], new DateTimeZone(APP_STORAGE_TIMEZONE));
    
    // For open cases, check against current time
    if (!in_array($complaint['status'], ['Resolved', 'Closed'], true)) {
        $now = new DateTimeImmutable('now', new DateTimeZone(APP_STORAGE_TIMEZONE));
        $diffHours = ($now->getTimestamp() - $created->getTimestamp()) / 3600;
        return $diffHours > $targetHours;
    }

    // For closed/resolved cases, check if completed past SLA
    $resolvedTime = $complaint['resolved_at'] ?? $complaint['closed_at'] ?? null;
    if ($resolvedTime !== null) {
        $completed = new DateTimeImmutable($resolvedTime, new DateTimeZone(APP_STORAGE_TIMEZONE));
        $diffHours = ($completed->getTimestamp() - $created->getTimestamp()) / 3600;
        return $diffHours > $targetHours;
    }

    return false;
}

/**
 * Sanitize a cell value for CSV export to prevent spreadsheet formula injection (RULES §12.7).
 * Prefixes a tab character when the value starts with =, +, -, @, 0x09, 0x0D.
 */
function csv_safe(string $value): string {
    if ($value === '') {
        return $value;
    }
    $dangerChars = ['=', '+', '-', '@', "\t", "\r"];
    if (in_array($value[0], $dangerChars, true)) {
        return "\t" . $value;
    }
    return $value;
}

/** Allowed attachment MIME types and their extensions (RULES §11). */
function attachment_allowed_types(): array {
    return [
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/gif'       => ['gif'],
        'application/pdf' => ['pdf'],
        'text/plain'      => ['txt'],
    ];
}

/** Maximum attachment file size in bytes (5 MB). */
function attachment_max_size(): int {
    return 5 * 1024 * 1024;
}

/**
 * Validate and store an attachment file (Phase 13: FR-018, RULES §11).
 *
 * Returns ['ok' => true, 'attachment_id' => int] or ['ok' => false, 'errors' => [...]].
 */
function attachment_upload(PDO $pdo, array $currentUser, int $complaintId, array $file): array {
    // Check complaint exists
    $complaint = complaint_fetch_by_id($pdo, $complaintId);
    if (!$complaint) {
        return ['ok' => false, 'errors' => ['file' => 'Complaint not found.']];
    }

    // Check upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'Upload error occurred.',
        };
        return ['ok' => false, 'errors' => ['file' => $msg]];
    }

    // File size check
    $maxSize = attachment_max_size();
    if ($file['size'] > $maxSize) {
        return ['ok' => false, 'errors' => ['file' => 'File exceeds maximum size of ' . ($maxSize / 1024 / 1024) . ' MB.']];
    }

    // MIME type verification (server-side, not trusting browser)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($file['tmp_name']);
    $allowedTypes = attachment_allowed_types();

    if (!isset($allowedTypes[$detectedMime])) {
        return ['ok' => false, 'errors' => ['file' => 'File type not allowed. Accepted: JPEG, PNG, GIF, PDF, TXT.']];
    }

    // Verify extension matches detected MIME
    $originalName = basename($file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes[$detectedMime], true)) {
        return ['ok' => false, 'errors' => ['file' => 'File extension does not match detected file type.']];
    }

    // Block executable content patterns
    $content = file_get_contents($file['tmp_name']);
    if ($content !== false) {
        // Check for PHP tags, script tags, or ELF/MZ headers
        if (preg_match('/<\?php|<script|<\?=/i', $content) || str_starts_with($content, 'MZ') || str_starts_with($content, "\x7FELF")) {
            return ['ok' => false, 'errors' => ['file' => 'File contains disallowed content.']];
        }
    }

    // Generate random stored filename
    $storedFilename = bin2hex(random_bytes(16)) . '.' . $ext;
    $uploadDir = APP_ROOT . '/storage/uploads';
    $destPath = $uploadDir . '/' . $storedFilename;

    // Move uploaded file (supports move_uploaded_file in HTTP or rename/copy in test harness)
    $moved = false;
    if (is_uploaded_file($file['tmp_name'])) {
        $moved = move_uploaded_file($file['tmp_name'], $destPath);
    } else {
        $moved = copy($file['tmp_name'], $destPath);
    }

    if (!$moved) {
        return ['ok' => false, 'errors' => ['file' => 'Failed to save uploaded file.']];
    }

    // Record in database
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO complaint_attachments (complaint_id, uploaded_by, original_filename, stored_filename, mime_type, file_size_bytes) ' .
            'VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $complaintId,
            $currentUser['user_id'],
            $originalName,
            $storedFilename,
            $detectedMime,
            $file['size'],
        ]);
        $attachmentId = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        @unlink($destPath);
        return ['ok' => false, 'errors' => ['file' => 'Database error saving attachment.']];
    }

    return ['ok' => true, 'attachment_id' => $attachmentId];
}

/**
 * Fetch all attachments for a complaint.
 */
function attachment_list(PDO $pdo, int $complaintId): array {
    $stmt = $pdo->prepare(
        'SELECT ca.*, u.full_name AS uploaded_by_name, u.username AS uploaded_by_username
         FROM complaint_attachments ca
         JOIN users u ON ca.uploaded_by = u.user_id
         WHERE ca.complaint_id = ?
         ORDER BY ca.uploaded_at ASC'
    );
    $stmt->execute([$complaintId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch a single attachment by ID.
 */
function attachment_fetch(PDO $pdo, int $attachmentId): ?array {
    $stmt = $pdo->prepare(
        'SELECT ca.*, u.full_name AS uploaded_by_name
         FROM complaint_attachments ca
         JOIN users u ON ca.uploaded_by = u.user_id
         WHERE ca.attachment_id = ?'
    );
    $stmt->execute([$attachmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
