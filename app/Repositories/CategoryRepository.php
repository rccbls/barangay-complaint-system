<?php
/**
 * Complaint category data access (Phase 4). PDO prepared statements only.
 * default_priority and default_assignee_id are SUGGESTIONS only (RULES §5, §6).
 */
declare(strict_types=1);

/** @return list<array> */
function categories_search(PDO $pdo, string $q, string $status): array
{
    $where  = '1 = 1';
    $params = [];
    if ($q !== '') {
        $like     = '%' . addcslashes($q, '\\%_') . '%';
        $where   .= ' AND (c.category_name LIKE ? OR c.description LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    if ($status === 'active') {
        $where .= ' AND c.is_active = 1';
    } elseif ($status === 'inactive') {
        $where .= ' AND c.is_active = 0';
    }
    $stmt = $pdo->prepare(
        'SELECT c.category_id, c.category_name, c.description, c.default_priority, c.default_assignee_id,
                c.is_active, c.created_at, u.full_name AS default_assignee_name
           FROM categories c LEFT JOIN users u ON u.user_id = c.default_assignee_id
          WHERE ' . $where . ' ORDER BY c.category_name ASC LIMIT 500'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function category_find(PDO $pdo, int $categoryId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.category_id, c.category_name, c.description, c.default_priority, c.default_assignee_id,
                c.is_active, u.full_name AS default_assignee_name
           FROM categories c LEFT JOIN users u ON u.user_id = c.default_assignee_id
          WHERE c.category_id = ? LIMIT 1'
    );
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** @throws PDOException (driver code 1062 when the name exists) */
function category_insert(PDO $pdo, array $c): int
{
    $pdo->prepare(
        'INSERT INTO categories (category_name, description, default_priority, default_assignee_id, is_active)
         VALUES (?, ?, ?, ?, 1)'
    )->execute([$c['category_name'], $c['description'], $c['default_priority'], $c['default_assignee_id']]);
    return (int) $pdo->lastInsertId();
}

/** @throws PDOException (driver code 1062 when the name exists) */
function category_update(PDO $pdo, int $categoryId, array $c): void
{
    $pdo->prepare(
        'UPDATE categories SET category_name = ?, description = ?, default_priority = ?, default_assignee_id = ?
          WHERE category_id = ?'
    )->execute([$c['category_name'], $c['description'], $c['default_priority'], $c['default_assignee_id'], $categoryId]);
}

function category_set_active(PDO $pdo, int $categoryId, bool $active): void
{
    $pdo->prepare('UPDATE categories SET is_active = ? WHERE category_id = ?')->execute([$active ? 1 : 0, $categoryId]);
}

/** Active Personnel accounts: the only users offered as a default assignee (placeholder rule). @return list<array> */
function category_eligible_assignees(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.full_name, u.username FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE r.role_name = ? AND u.is_active = 1 ORDER BY u.full_name, u.user_id'
    );
    $stmt->execute([ROLE_PERSONNEL]);
    return $stmt->fetchAll();
}

function category_assignee_is_eligible(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE u.user_id = ? AND r.role_name = ? AND u.is_active = 1 LIMIT 1'
    );
    $stmt->execute([$userId, ROLE_PERSONNEL]);
    return $stmt->fetchColumn() !== false;
}

function count_open_complaints_in_category(PDO $pdo, int $categoryId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM complaints c WHERE c.category_id = ? AND ' . OPEN_COMPLAINT_CONDITION);
    $stmt->execute([$categoryId]);
    return (int) $stmt->fetchColumn();
}

function count_active_categories(PDO $pdo, int $excludeCategoryId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE is_active = 1 AND category_id <> ?');
    $stmt->execute([$excludeCategoryId]);
    return (int) $stmt->fetchColumn();
}
