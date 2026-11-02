<?php
/**
 * Resident profile and resident-account rules (Phase 3).
 *
 * PLACEHOLDER pending client approval: the profile fields are the three columns the schema
 * already has (full name, contact number, address); contact number and address are optional.
 */
declare(strict_types=1);

/**
 * @return array{0: array<string,string>, 1: array{full_name:string,contact_number:?string,address:?string}}
 *         [errors keyed by field, cleaned values]
 */
function resident_validate_profile(array $input): array
{
    $errors = [];

    $fullName = text_normalize(input_string($input, 'full_name'));
    if (($err = person_name_error($fullName)) !== null) {
        $errors['full_name'] = $err;
    }

    $contact = text_normalize(input_string($input, 'contact_number'));
    if ($contact === '') {
        $contact = null;
    } elseif (
        strlen($contact) > 30
        || !preg_match('/^[0-9+()\-. ]+$/', $contact)
        || !preg_match('/\d/', $contact)
    ) {
        $errors['contact_number'] = 'Enter a valid contact number (digits, spaces, + - . ( ) only, up to 30 characters).';
    }

    $address = text_normalize(input_string($input, 'address'));
    if ($address === '') {
        $address = null;
    } else {
        $len = str_char_length($address);
        if ($len === null || $len > 255 || preg_match('/[\x00-\x1F\x7F]/', $address)) {
            $errors['address'] = 'Address must be 255 characters or fewer.';
        }
    }

    return [$errors, ['full_name' => $fullName, 'contact_number' => $contact, 'address' => $address]];
}

/**
 * Activate or deactivate a RESIDENT account. Only an Administrator may do this, and only to
 * Resident-role accounts (staff accounts are managed in Phase 4). Nothing is deleted:
 * the profile and any complaints stay on record.
 *
 * @return 'changed'|'unchanged'|'not_found'
 */
function resident_change_account_status(PDO $pdo, array $actor, int $targetUserId, bool $active): string
{
    if (($actor['role_name'] ?? '') !== ROLE_ADMIN) {
        throw new RuntimeException('Only an Administrator may change account status.');
    }
    $account = resident_account_find($pdo, $targetUserId);
    if ($account === null) {
        return 'not_found';
    }
    if ((int) $account['is_active'] === ($active ? 1 : 0)) {
        return 'unchanged';
    }
    resident_account_set_active($pdo, $targetUserId, $active);

    // INTERIM AUDIT TRAIL: RULES §13 requires consequential account actions to be recorded with
    // actor and timestamp, but SCHEMA.md has no table for this. Until the client approves a
    // schema addition, write IDs only (no names, no passwords) to the server error log.
    error_log(sprintf(
        'AUDIT account_status actor_user_id=%d target_user_id=%d is_active=%d',
        (int) $actor['user_id'], $targetUserId, $active ? 1 : 0
    ));
    return 'changed';
}
