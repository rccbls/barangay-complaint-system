<?php
/**
 * User data access (Phase 2). PDO prepared statements only.
 */
declare(strict_types=1);

/** Includes password_hash: for login verification only. Never pass this array to views. */
function user_find_for_login(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.full_name, u.username, u.password_hash, u.is_active, r.role_name
           FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE u.username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Safe profile for the current request (no password hash). */
function user_find_by_id(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.full_name, u.username, u.is_active, r.role_name
           FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE u.user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Create a Resident-role account. The role is fixed here, never taken from request input.
 * @return int new user_id
 * @throws PDOException (code 23000 / driver 1062 when the username already exists)
 */
function user_create_resident(PDO $pdo, string $fullName, string $username, string $passwordHash): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (role_id, full_name, username, password_hash, is_active)
         SELECT role_id, ?, ?, ?, 1 FROM roles WHERE role_name = ?'
    );
    $stmt->execute([$fullName, $username, $passwordHash, ROLE_RESIDENT]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Resident role is missing; run database/install.php.');
    }
    return (int) $pdo->lastInsertId();
}

function user_update_password_hash(PDO $pdo, int $userId, string $hash): void
{
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
    $stmt->execute([$hash, $userId]);
}
