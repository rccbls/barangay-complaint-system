<?php
/**
 * Staff account data access (Phase 4). Every query is restricted to STAFF roles, so Resident
 * accounts can never be read or changed through these functions. PDO prepared statements only.
 */
declare(strict_types=1);

/** SQL fragment + params that restrict a query on roles alias "r" to staff roles. */
function staff_role_filter(): array
{
    $roles = staff_roles();
    return ['r.role_name IN (' . implode(',', array_fill(0, count($roles), '?')) . ')', $roles];
}

/**
 * @param string $role   '' or one staff role name
 * @param string $status '' | 'active' | 'inactive'
 * @return array{0: list<array>, 1: int} [rows, total]
 */
function staff_accounts_search(PDO $pdo, string $q, string $role, string $status, int $limit, int $offset): array
{
    [$where, $params] = staff_role_filter();
    if ($role !== '') {
        $where   .= ' AND r.role_name = ?';
        $params[] = $role;
    }
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
        'SELECT u.user_id, u.full_name, u.username, u.is_active, u.created_at, r.role_name ' . $from .
        ' ORDER BY u.full_name ASC, u.user_id ASC LIMIT ? OFFSET ?'
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

/** A staff-role account by id, or null (Resident accounts are never returned). */
function staff_account_find(PDO $pdo, int $userId): ?array
{
    [$where, $params] = staff_role_filter();
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.full_name, u.username, u.is_active, r.role_name
           FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE u.user_id = ? AND ' . $where . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$userId], $params));
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** @throws PDOException (driver code 1062 when the username exists) */
function staff_account_insert(PDO $pdo, string $fullName, string $username, string $hash, string $roleName): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (role_id, full_name, username, password_hash, is_active)
         SELECT role_id, ?, ?, ?, 1 FROM roles WHERE role_name = ?'
    );
    $stmt->execute([$fullName, $username, $hash, $roleName]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Role is missing; run database/install.php.');
    }
    return (int) $pdo->lastInsertId();
}

/** Update name and role (and the password hash when given) of a STAFF account only. */
function staff_account_update(PDO $pdo, int $userId, string $fullName, string $roleName, ?string $newHash): void
{
    [$where, $params] = staff_role_filter();
    $sql = 'UPDATE users u JOIN roles r ON r.role_id = u.role_id
               SET u.full_name = ?, u.role_id = (SELECT role_id FROM roles WHERE role_name = ?)';
    $bind = [$fullName, $roleName];
    if ($newHash !== null) {
        $sql   .= ', u.password_hash = ?';
        $bind[] = $newHash;
    }
    $sql .= ' WHERE u.user_id = ? AND ' . $where;
    $pdo->prepare($sql)->execute(array_merge($bind, [$userId], $params));
}

function staff_account_set_active(PDO $pdo, int $userId, bool $active): void
{
    [$where, $params] = staff_role_filter();
    $pdo->prepare('UPDATE users u JOIN roles r ON r.role_id = u.role_id SET u.is_active = ? WHERE u.user_id = ? AND ' . $where)
        ->execute(array_merge([$active ? 1 : 0, $userId], $params));
}

/* ---- queries used by the "valid handling path" guards ---- */

function count_active_users_with_role(PDO $pdo, string $roleName, int $excludeUserId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE r.role_name = ? AND u.is_active = 1 AND u.user_id <> ?'
    );
    $stmt->execute([$roleName, $excludeUserId]);
    return (int) $stmt->fetchColumn();
}

function count_open_complaints(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM complaints c WHERE ' . OPEN_COMPLAINT_CONDITION)->fetchColumn();
}

/** Active assignments (not yet unassigned) on open complaints. */
function count_open_assignments_for_user(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM assignments a JOIN complaints c ON c.complaint_id = a.complaint_id
          WHERE a.assigned_to = ? AND a.unassigned_at IS NULL AND ' . OPEN_COMPLAINT_CONDITION
    );
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/** Names of ACTIVE categories that use this user as their default assignee. @return list<string> */
function active_categories_defaulting_to(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT category_name FROM categories WHERE default_assignee_id = ? AND is_active = 1 ORDER BY category_name');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
