<?php
/**
 * Complaint category management rules (Phase 4). DESIGN §5 (Category management).
 *
 * default_priority and default_assignee are SUGGESTIONS only: they never assign a complaint or
 * set its official priority (RULES §5, §6.3-6.4). Priority levels are the placeholders in
 * config/app.php. The default assignee must be an active Personnel account (placeholder rule).
 *
 * PLACEHOLDER guard rules pending client approval: a category cannot be deactivated while open
 * complaints use it, or when it is the only active category (residents would have nothing to choose).
 */
declare(strict_types=1);

function category_require_admin(array $actor): void
{
    if (($actor['role_name'] ?? '') !== ROLE_ADMIN) {
        throw new RuntimeException('Only an Administrator may manage categories.');
    }
}

/** @return array{0: array<string,string>, 1: array{category_name:string,description:?string,default_priority:?string,default_assignee_id:?int}} */
function category_validate(PDO $pdo, array $input): array
{
    $errors = [];

    $name = text_normalize(input_string($input, 'category_name'));
    $len  = str_char_length($name);
    if ($name === '' || $len === null) {
        $errors['category_name'] = 'Enter a category name.';
    } elseif ($len > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        $errors['category_name'] = 'Category name must be 100 characters or fewer.';
    }

    $description = text_normalize(input_string($input, 'description'));
    if ($description === '') {
        $description = null;
    } else {
        $dLen = str_char_length($description);
        if ($dLen === null || $dLen > 1000 || preg_match('/[\x00-\x1F\x7F]/', $description)) {
            $errors['description'] = 'Description must be 1000 characters or fewer.';
        }
    }

    $priority = input_string($input, 'default_priority');
    if ($priority === '') {
        $priority = null;
    } elseif (!in_array($priority, PRIORITY_LEVELS, true)) {
        $errors['default_priority'] = 'Choose one of the listed priority levels.';
    }

    $assigneeRaw = input_string($input, 'default_assignee_id');
    $assignee    = null;
    if ($assigneeRaw !== '') {
        if (!ctype_digit($assigneeRaw) || !category_assignee_is_eligible($pdo, (int) $assigneeRaw)) {
            $errors['default_assignee_id'] = 'Choose an active Personnel member from the list.';
        } else {
            $assignee = (int) $assigneeRaw;
        }
    }

    return [$errors, ['category_name' => $name, 'description' => $description, 'default_priority' => $priority, 'default_assignee_id' => $assignee]];
}

/** @return array{ok:bool, errors?:array<string,string>} */
function category_create(PDO $pdo, array $actor, array $clean): array
{
    category_require_admin($actor);
    try {
        $id = category_insert($pdo, $clean);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'errors' => ['category_name' => 'A category with that name already exists.']];
        }
        throw $e;
    }
    error_log(sprintf('AUDIT category action=created actor_user_id=%d category_id=%d', (int) $actor['user_id'], $id));
    return ['ok' => true];
}

/** @return array{ok:bool, errors?:array<string,string>} */
function category_edit(PDO $pdo, array $actor, int $categoryId, array $clean): array
{
    category_require_admin($actor);
    try {
        category_update($pdo, $categoryId, $clean);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'errors' => ['category_name' => 'A category with that name already exists.']];
        }
        throw $e;
    }
    error_log(sprintf('AUDIT category action=updated actor_user_id=%d category_id=%d', (int) $actor['user_id'], $categoryId));
    return ['ok' => true];
}

/** @return list<string> reasons the category cannot be deactivated (empty = allowed) */
function category_deactivation_blockers(PDO $pdo, array $category): array
{
    if ((int) $category['is_active'] !== 1) {
        return [];
    }
    $id       = (int) $category['category_id'];
    $blockers = [];
    $open     = count_open_complaints_in_category($pdo, $id);
    if ($open > 0) {
        $blockers[] = "$open open complaint(s) use this category. Resolve or close them first.";
    }
    if (count_active_categories($pdo, $id) === 0) {
        $blockers[] = 'This is the only active category; residents would have no category to choose.';
    }
    return $blockers;
}

/** @return array{result:'changed'|'unchanged'|'not_found'|'blocked', blockers:list<string>} */
function category_change_status(PDO $pdo, array $actor, int $categoryId, bool $active): array
{
    category_require_admin($actor);
    $category = category_find($pdo, $categoryId);
    if ($category === null) {
        return ['result' => 'not_found', 'blockers' => []];
    }
    if ((int) $category['is_active'] === ($active ? 1 : 0)) {
        return ['result' => 'unchanged', 'blockers' => []];
    }
    if (!$active) {
        $blockers = category_deactivation_blockers($pdo, $category);
        if ($blockers !== []) {
            return ['result' => 'blocked', 'blockers' => $blockers];
        }
    }
    category_set_active($pdo, $categoryId, $active);
    error_log(sprintf('AUDIT category action=%s actor_user_id=%d category_id=%d', $active ? 'activated' : 'deactivated', (int) $actor['user_id'], $categoryId));
    return ['result' => 'changed', 'blockers' => []];
}
