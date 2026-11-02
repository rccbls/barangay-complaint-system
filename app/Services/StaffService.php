<?php
/**
 * Staff account management rules (Phase 4). RULES §2.3, DESIGN §5 (Personnel management).
 *
 * Only an Administrator may call the mutating functions (checked here as well as on the pages),
 * and every query is limited to staff roles, so Resident accounts cannot be changed.
 *
 * PLACEHOLDER guard rules pending client approval ("valid handling path", ROADMAP Phase 4):
 *  - nobody may deactivate their own account or change their own role;
 *  - the last active Administrator cannot be removed;
 *  - the last active Complaint Officer cannot be removed while open complaints exist;
 *  - Personnel with open assignments cannot be deactivated or moved to another role;
 *  - a user who is the default assignee of an active category cannot be deactivated or moved
 *    off the Personnel role until the category is changed.
 */
declare(strict_types=1);

function staff_require_admin(array $actor): void
{
    if (($actor['role_name'] ?? '') !== ROLE_ADMIN) {
        throw new RuntimeException('Only an Administrator may manage staff accounts.');
    }
}

/** @return array{0: array<string,string>, 1: array{full_name:string,username:string,role:string,password:string}} */
function staff_validate_new(array $input): array
{
    $errors = [];

    $fullName = text_normalize(input_string($input, 'full_name'));
    if (($err = person_name_error($fullName)) !== null) {
        $errors['full_name'] = str_replace('your', 'the', $err);
    }

    $username = trim(input_string($input, 'username'));
    if (!username_is_valid($username)) {
        $errors['username'] = 'Use 3 to 60 letters, numbers, dots, underscores, or hyphens.';
    }

    $role = input_string($input, 'role');
    if (!in_array($role, staff_roles(), true)) {
        $errors['role'] = 'Choose a staff role.';
    }

    $password = input_string($input, 'password');
    if (($err = password_policy_error($password, $username)) !== null) {
        $errors['password'] = $err;
    } elseif (input_string($input, 'password_confirm') !== $password) {
        $errors['password_confirm'] = 'Passwords do not match.';
    }

    return [$errors, ['full_name' => $fullName, 'username' => $username, 'role' => $role, 'password' => $password]];
}

/**
 * Edit input: full name, role, and an OPTIONAL replacement password (blank = keep the current one).
 * @return array{0: array<string,string>, 1: array{full_name:string,role:string,password:?string}}
 */
function staff_validate_edit(array $input, string $username): array
{
    $errors = [];

    $fullName = text_normalize(input_string($input, 'full_name'));
    if (($err = person_name_error($fullName)) !== null) {
        $errors['full_name'] = str_replace('your', 'the', $err);
    }

    $role = input_string($input, 'role');
    if (!in_array($role, staff_roles(), true)) {
        $errors['role'] = 'Choose a staff role.';
    }

    $password = input_string($input, 'password');
    if ($password === '' && input_string($input, 'password_confirm') === '') {
        $password = null;
    } elseif (($err = password_policy_error((string) $password, $username)) !== null) {
        $errors['password'] = $err;
    } elseif (input_string($input, 'password_confirm') !== $password) {
        $errors['password_confirm'] = 'Passwords do not match.';
    }

    return [$errors, ['full_name' => $fullName, 'role' => $role, 'password' => $password]];
}

/**
 * Reasons why this account may not lose its current access right now (empty list = allowed).
 *
 * @param ?string $newRole null = the account is being deactivated; otherwise the role it would get.
 * @return list<string>
 */
function staff_access_loss_blockers(PDO $pdo, int $actorId, array $account, ?string $newRole): array
{
    $role = $account['role_name'];
    $uid  = (int) $account['user_id'];
    if ($newRole !== null && $newRole === $role) {
        return []; // role unchanged: nothing is lost
    }

    $blockers = [];
    if ($uid === $actorId) {
        $blockers[] = $newRole === null
            ? 'You cannot deactivate your own account.'
            : 'You cannot change your own role.';
    }

    if ((int) $account['is_active'] === 1) {
        if ($role === ROLE_ADMIN && count_active_users_with_role($pdo, ROLE_ADMIN, $uid) === 0) {
            $blockers[] = 'This is the last active Administrator.';
        }
        if ($role === ROLE_OFFICER && count_active_users_with_role($pdo, ROLE_OFFICER, $uid) === 0 && count_open_complaints($pdo) > 0) {
            $blockers[] = 'This is the last active Complaint Officer and open complaints still need an officer.';
        }
        if ($role === ROLE_PERSONNEL) {
            $open = count_open_assignments_for_user($pdo, $uid);
            if ($open > 0) {
                $blockers[] = "This person still has $open open assignment(s). Reassign them first.";
            }
            $cats = active_categories_defaulting_to($pdo, $uid);
            if ($cats !== []) {
                $blockers[] = 'This person is the default assignee of: ' . implode(', ', $cats) . '. Change those categories first.';
            }
        }
    }
    return $blockers;
}

/** @return array{ok:bool, errors?:array<string,string>} */
function staff_create_account(PDO $pdo, array $actor, array $clean): array
{
    staff_require_admin($actor);
    try {
        $id = staff_account_insert($pdo, $clean['full_name'], $clean['username'], password_hash($clean['password'], PASSWORD_DEFAULT), $clean['role']);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'errors' => ['username' => 'That username is already taken.']];
        }
        throw $e;
    }
    // INTERIM AUDIT TRAIL (no audit table in SCHEMA.md): IDs and role only, never passwords.
    error_log(sprintf('AUDIT staff_account action=created actor_user_id=%d target_user_id=%d role="%s"', (int) $actor['user_id'], $id, $clean['role']));
    return ['ok' => true];
}

/** @return array{result:'ok'|'not_found'|'blocked', blockers:list<string>} */
function staff_update_account(PDO $pdo, array $actor, int $userId, array $clean): array
{
    staff_require_admin($actor);
    $account = staff_account_find($pdo, $userId);
    if ($account === null) {
        return ['result' => 'not_found', 'blockers' => []];
    }
    $roleChanged = $clean['role'] !== $account['role_name'];
    if ($roleChanged) {
        $blockers = staff_access_loss_blockers($pdo, (int) $actor['user_id'], $account, $clean['role']);
        if ($blockers !== []) {
            return ['result' => 'blocked', 'blockers' => $blockers];
        }
    }
    $hash = $clean['password'] !== null ? password_hash($clean['password'], PASSWORD_DEFAULT) : null;
    staff_account_update($pdo, $userId, $clean['full_name'], $clean['role'], $hash);
    error_log(sprintf(
        'AUDIT staff_account action=updated actor_user_id=%d target_user_id=%d role_changed=%d password_reset=%d',
        (int) $actor['user_id'], $userId, $roleChanged ? 1 : 0, $hash !== null ? 1 : 0
    ));
    return ['result' => 'ok', 'blockers' => []];
}

/** @return array{result:'changed'|'unchanged'|'not_found'|'blocked', blockers:list<string>} */
function staff_change_account_status(PDO $pdo, array $actor, int $userId, bool $active): array
{
    staff_require_admin($actor);
    $account = staff_account_find($pdo, $userId);
    if ($account === null) {
        return ['result' => 'not_found', 'blockers' => []];
    }
    if ((int) $account['is_active'] === ($active ? 1 : 0)) {
        return ['result' => 'unchanged', 'blockers' => []];
    }
    if (!$active) {
        $blockers = staff_access_loss_blockers($pdo, (int) $actor['user_id'], $account, null);
        if ($blockers !== []) {
            return ['result' => 'blocked', 'blockers' => $blockers];
        }
    }
    staff_account_set_active($pdo, $userId, $active);
    error_log(sprintf('AUDIT staff_account action=%s actor_user_id=%d target_user_id=%d', $active ? 'activated' : 'deactivated', (int) $actor['user_id'], $userId));
    return ['result' => 'changed', 'blockers' => []];
}
