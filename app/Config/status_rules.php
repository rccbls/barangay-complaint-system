<?php
/**
 * Status transition rules for complaints (Phase 7, RULES.md §7).
 * Each entry defines allowed next statuses per current status and user role.
 * Keyed by role_name (Administrator, Complaint Officer, Personnel, Management, Resident).
 */
declare(strict_types=1);

require_once APP_ROOT . '/app/Services/Authorization.php';

return [
    'Received' => [
        ROLE_ADMIN      => ['For Assignment', 'Rejected / Invalid'],
        ROLE_OFFICER    => ['For Assignment', 'Rejected / Invalid'],
        ROLE_PERSONNEL  => [],
        ROLE_MANAGEMENT => [],
        ROLE_RESIDENT   => [],
    ],
    'For Assignment' => [
        ROLE_ADMIN      => ['Assigned', 'Rejected / Invalid'],
        ROLE_OFFICER    => ['Assigned', 'Rejected / Invalid'],
        ROLE_PERSONNEL  => [],
        ROLE_MANAGEMENT => [],
        ROLE_RESIDENT   => [],
    ],
    'Assigned' => [
        ROLE_ADMIN      => ['For Assignment'],
        ROLE_OFFICER    => ['For Assignment'],
        ROLE_PERSONNEL  => ['In Progress'],
        ROLE_MANAGEMENT => [],
        ROLE_RESIDENT   => [],
    ],
    'In Progress' => [
        ROLE_ADMIN      => ['On Hold', 'Resolved'],
        ROLE_OFFICER    => ['On Hold', 'Resolved'],
        ROLE_PERSONNEL  => ['On Hold', 'Resolved'],
        ROLE_MANAGEMENT => [],
        ROLE_RESIDENT   => [],
    ],
    'On Hold' => [
        ROLE_ADMIN      => ['In Progress'],
        ROLE_OFFICER    => ['In Progress'],
        ROLE_PERSONNEL  => ['In Progress'],
        ROLE_MANAGEMENT => [],
        ROLE_RESIDENT   => [],
    ],
    'Resolved' => [
        ROLE_ADMIN      => ['Closed', 'In Progress'],
        ROLE_OFFICER    => ['Closed', 'In Progress'],
        ROLE_PERSONNEL  => [],
        ROLE_MANAGEMENT => [],
        ROLE_RESIDENT   => [],
    ],
    'Closed' => [
        ROLE_ADMIN      => ['In Progress'],
        ROLE_OFFICER    => ['In Progress'],
        ROLE_PERSONNEL  => [],
        ROLE_MANAGEMENT => [],
        ROLE_RESIDENT   => [],
    ],
    'Rejected / Invalid' => [
        ROLE_ADMIN      => [],
        ROLE_OFFICER    => [],
        ROLE_PERSONNEL  => [],
        ROLE_MANAGEMENT => [],
        ROLE_RESIDENT   => [],
    ],
];
