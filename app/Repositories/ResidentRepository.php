<?php
/**
 * Resident profile and resident-account data access (Phase 3). PDO prepared statements only.
 *
 * The resident profile is the `complainants` row whose user_id points at the resident's account.
 * complainants.user_id is UNIQUE (SCHEMA §3), so the database itself guarantees at most one
 * profile per account.
 */
declare(strict_types=1);

/** Create the profile for a resident account. Safe to call twice (never creates a duplicate). */
function resident_profile_create(PDO $pdo, int $userId, string $fullName): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO complainants (user_id, full_name) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE user_id = user_id'
    );
    $stmt->execute([$userId, $fullName]);
}

/** @return array{complainant_id:int,user_id:int,full_name:?string,contact_number:?string,address:?string,created_at:string}|null */
function resident_profile_find_by_user(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT complainant_id, user_id, full_name, contact_number, address, created_at
           FROM complainants WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * The profile used by complaints for this resident account; created on first use for accounts
 * that pre-date Phase 3. The user ID must come from the session.
 */
function resident_profile_ensure(PDO $pdo, int $userId): array
{
    $profile = resident_profile_find_by_user($pdo, $userId);
    if ($profile !== null) {
        return $profile;
    }
    $user = user_find_by_id($pdo, $userId);
    if ($user === null || $user['role_name'] !== ROLE_RESIDENT) {
        throw new RuntimeException('Only Resident accounts have a resident profile.');
    }
    resident_profile_create($pdo, $userId, $user['full_name']);
    $profile = resident_profile_find_by_user($pdo, $userId);
    if ($profile === null) {
        throw new RuntimeException('Could not create the resident profile.');
    }
    return $profile;
}

/**
 * Update the resident's own profile. The account display name (users.full_name) is kept in
 * step with the profile name in the same transaction.
 *
 * @param array{full_name:string,contact_number:?string,address:?string} $clean validated values
 */
function resident_profile_update(PDO $pdo, int $userId, array $clean): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE complainants SET full_name = ?, contact_number = ?, address = ? WHERE user_id = ?')
            ->execute([$clean['full_name'], $clean['contact_number'], $clean['address'], $userId]);
        $pdo->prepare('UPDATE users SET full_name = ? WHERE user_id = ?')
            ->execute([$clean['full_name'], $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Administrator list of Resident accounts. Deliberately returns NO contact number or address.
 *
 * @param string $status '' (all) | 'active' | 'inactive'
 * @return array{0: list<array>, 1: int} [rows, total matching]
 */
function resident_accounts_search(PDO $pdo, string $q, string $status, int $limit, int $offset): array
{
    $where  = "r.role_name = 'Resident'";
    $params = [];
    if ($q !== '') {
        $like     = '%' . addcslashes($q, '\\%_') . '%';
        $where   .= ' AND (u.username LIKE ? OR u.full_name LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    if ($status === 'active') {
        $where .= ' AND u.is_active = 1';
    } elseif ($status === 'inactive') {
        $where .= ' AND u.is_active = 0';
    }

    $from  = 'FROM users u JOIN roles r ON r.role_id = u.role_id WHERE ' . $where;
    $count = $pdo->prepare('SELECT COUNT(*) ' . $from);
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.full_name, u.username, u.is_active, u.created_at ' . $from .
        ' ORDER BY u.created_at DESC, u.user_id DESC LIMIT ? OFFSET ?'
    );
    $i = 1;
    foreach ($params as $p) {
        $stmt->bindValue($i++, $p);
    }
    $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($i, $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [$stmt->fetchAll(), $total];
}

/** A Resident-role account by id, or null (staff accounts are never returned here). */
function resident_account_find(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.full_name, u.username, u.is_active
           FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE u.user_id = ? AND r.role_name = 'Resident' LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Set is_active on a Resident-role account only. */
function resident_account_set_active(PDO $pdo, int $userId, bool $active): void
{
    $stmt = $pdo->prepare(
        "UPDATE users u JOIN roles r ON r.role_id = u.role_id
            SET u.is_active = ? WHERE u.user_id = ? AND r.role_name = 'Resident'"
    );
    $stmt->execute([$active ? 1 : 0, $userId]);
}
