<?php
/**
 * Navigation per area and role (DESIGN.md §3–§4).
 *
 * 'roles' limits an item to those roles (omitted = every role in that area). The role lists
 * are a PLACEHOLDER reading of DESIGN §4 pending the client's approved role/permission matrix.
 * Items without an 'href' are disabled placeholders for pages built in the stated phase.
 * Hiding navigation is a usability aid only; every page enforces access on the server.
 */
declare(strict_types=1);

return [
    'resident' => [
        ['label' => 'Dashboard',        'icon' => 'bi-grid-1x2-fill', 'phase' => 11, 'href' => 'resident/index.php'],
        ['label' => 'My Complaints',    'icon' => 'bi-inbox-fill', 'phase' => 6,  'href' => 'complaints/my.php'],
        ['label' => 'Submit Complaint', 'icon' => 'bi-plus-circle-fill', 'phase' => 5,  'href' => 'complaints/create.php'],
        ['label' => 'Profile',          'icon' => 'bi-person-fill', 'phase' => 3,  'href' => 'resident/profile/index.php'],
    ],
    'staff' => [
        ['label' => 'Dashboard',      'icon' => 'bi-grid-1x2-fill', 'phase' => 11, 'href' => 'dashboard.php', 'roles' => ['Administrator', 'Complaint Officer', 'Management']],
        ['label' => 'Complaints',     'icon' => 'bi-inbox-fill', 'phase' => 6,  'href' => 'complaints/list.php', 'roles' => ['Administrator', 'Complaint Officer', 'Management']],
        ['label' => 'Assignments',    'icon' => 'bi-diagram-3-fill', 'phase' => 8,  'href' => 'assignments/index.php', 'roles' => ['Administrator', 'Complaint Officer']],
        ['label' => 'My Assignments', 'icon' => 'bi-clipboard-check-fill', 'phase' => 9,  'href' => 'assignments/my.php', 'roles' => ['Personnel']],
        ['label' => 'Residents',      'icon' => 'bi-people-fill', 'phase' => 3,  'href' => 'residents/index.php', 'roles' => ['Administrator']],
        ['label' => 'Personnel',      'icon' => 'bi-person-badge-fill', 'phase' => 4,  'href' => 'personnel/index.php', 'roles' => ['Administrator']],
        ['label' => 'Categories',     'icon' => 'bi-tags-fill', 'phase' => 4,  'href' => 'categories/index.php', 'roles' => ['Administrator']],
        ['label' => 'Reports',        'icon' => 'bi-bar-chart-fill', 'phase' => 12, 'href' => 'reports/index.php', 'roles' => ['Administrator', 'Complaint Officer', 'Management']],
    ],
];
