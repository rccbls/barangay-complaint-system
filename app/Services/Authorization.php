<?php
/**
 * Roles and authorization rules (Phase 2). RULES.md §2.
 * Pure functions: no session or HTML here, so they are easy to test and reuse.
 */
declare(strict_types=1);

const ROLE_RESIDENT   = 'Resident';
const ROLE_ADMIN      = 'Administrator';
const ROLE_OFFICER    = 'Complaint Officer';
const ROLE_COMPLAINT_OFFICER = ROLE_OFFICER;
const ROLE_PERSONNEL  = 'Personnel';
const ROLE_MANAGEMENT = 'Management';

/** @return list<string> */
function staff_roles(): array
{
    return [ROLE_ADMIN, ROLE_OFFICER, ROLE_PERSONNEL, ROLE_MANAGEMENT];
}

/** Layout area for a role: 'resident' or 'staff'. */
function layout_area_for_role(string $role): string
{
    return $role === ROLE_RESIDENT ? 'resident' : 'staff';
}

/** Where a role lands after sign-in (placeholder pages until later phases). */
function home_path_for_role(string $role): string
{
    return $role === ROLE_RESIDENT ? 'resident/index.php' : 'dashboard.php';
}

/**
 * RECORD-LEVEL RULE for residents (RULES §2.7–2.8, §3.3, §10.3):
 * a resident may access a complaint only if it was submitted through that resident's own
 * account. The check runs in SQL on the server and takes the complaint ID from the request
 * but the user ID ONLY from the authenticated session, so changing a URL, hidden field,
 * request parameter, or reference number cannot reach another resident's complaint.
 *
 * Later phases must apply this (or the same `submitted_by_user_id = :session_user_id`
 * condition in list queries) for every resident-facing complaint read.
 */
function resident_owns_complaint(PDO $pdo, int $residentUserId, int $complaintId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM complaints WHERE complaint_id = ? AND submitted_by_user_id = ? LIMIT 1'
    );
    $stmt->execute([$complaintId, $residentUserId]);
    return $stmt->fetchColumn() !== false;
}
