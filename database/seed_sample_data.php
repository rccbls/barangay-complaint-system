<?php
/**
 * Database seeder with comprehensive sample data for manual and UAT testing (Phase 14).
 * Run: php database/seed_sample_data.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$pdo = db_connect(true);
$defaultPassword = 'Password123!';
$hash = password_hash($defaultPassword, PASSWORD_DEFAULT);

echo "Seeding sample accounts and complaints...\n";

// 1. Roles mapping
$roles = $pdo->query('SELECT role_name, role_id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);

// Helper to create user if not exists
$ensureUser = function (string $username, string $fullName, string $roleName) use ($pdo, $roles, $hash): int {
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }
    $roleId = $roles[$roleName] ?? null;
    if (!$roleId) {
        throw new RuntimeException("Unknown role: $roleName");
    }
    $ins = $pdo->prepare('INSERT INTO users (role_id, full_name, username, password_hash, is_active) VALUES (?, ?, ?, ?, 1)');
    $ins->execute([$roleId, $fullName, $username, $hash]);
    $userId = (int)$pdo->lastInsertId();

    if ($roleName === 'Resident') {
        $pStmt = $pdo->prepare('INSERT INTO complainants (user_id, full_name, contact_number, address) VALUES (?, ?, "0917-123-4567", "Zone 4, Barangay Santolan")');
        $pStmt->execute([$userId, $fullName]);
    }
    return $userId;
};

// Seed core testing accounts
$adminId   = $ensureUser('admin', 'Barangay Administrator', 'Administrator');
$officer1  = $ensureUser('officer1', 'Maria Santos (Officer)', 'Complaint Officer');
$officer2  = $ensureUser('officer2', 'Juan Reyes (Officer)', 'Complaint Officer');
$pers1     = $ensureUser('personnel1', 'Ricardo Dalisay (Field Team)', 'Personnel');
$pers2     = $ensureUser('personnel2', 'Elena Bautista (Desk Team)', 'Personnel');
$mgmt1     = $ensureUser('mgmt1', 'Captain Roberto Tan (Management)', 'Management');
$resident1 = $ensureUser('resident1', 'Pedro Penduko', 'Resident');
$resident2 = $ensureUser('resident2', 'Gabriela Silang', 'Resident');

echo "Accounts ready (Password for all: Password123!):\n";
echo " - Administrator:       admin\n";
echo " - Complaint Officers:  officer1, officer2\n";
echo " - Personnel:           personnel1, personnel2\n";
echo " - Management:          mgmt1\n";
echo " - Residents:           resident1, resident2\n\n";

// 2. Seed realistic Categories
$categories = [
    ['Noise Disturbance', 'Videoke, loud construction, barking dogs during quiet hours', 'Low', $pers1],
    ['Road & Traffic Obstruction', 'Illegal parking, blocked driveways, construction debris on street', 'Medium', $pers1],
    ['Sanitation & Garbage', 'Uncollected garbage, illegal dumping, clogged drainage canals', 'Medium', $pers2],
    ['Public Safety & Streetlights', 'Broken streetlights, suspicious loitering, exposed electrical wires', 'High', $pers1],
    ['Disputes & Altercations', 'Neighborhood boundary disputes, verbal altercations, minor harassment', 'Medium', $pers2],
    ['Emergency & Structural Hazard', 'Imminent wall collapse, fire hazard, flooding risk', 'Critical', $pers1],
];

$catIds = [];
foreach ($categories as $cat) {
    $stmt = $pdo->prepare('SELECT category_id FROM categories WHERE category_name = ?');
    $stmt->execute([$cat[0]]);
    $cId = $stmt->fetchColumn();
    if (!$cId) {
        $ins = $pdo->prepare('INSERT INTO categories (category_name, description, default_priority, default_assignee_id, is_active) VALUES (?, ?, ?, ?, 1)');
        $ins->execute([$cat[0], $cat[1], $cat[2], $cat[3]]);
        $cId = $pdo->lastInsertId();
    }
    $catIds[$cat[0]] = (int)$cId;
}

// 3. Seed Complaints spanning various statuses and priorities
$sampleComplaints = [
    [
        'ref'         => 'BC-20261025-00101',
        'sub_user'    => $resident1,
        'cat'         => $catIds['Emergency & Structural Hazard'],
        'desc'        => 'Exposed electrical wiring hanging dangerously low across Alley 3 after yesterday\'s high wind.',
        'location'    => 'Alley 3 near Santolan Chapel',
        'priority'    => 'Critical',
        'reason'      => 'Imminent electrocution hazard to pedestrians and children.',
        'status'      => 'In Progress',
        'assigned_to' => $pers1,
        'source'      => 'Online',
    ],
    [
        'ref'         => 'BC-20261026-00102',
        'sub_user'    => $resident1,
        'cat'         => $catIds['Road & Traffic Obstruction'],
        'desc'        => 'Commercial delivery truck routinely parking across pedestrian sidewalk and blocking vehicle driveway.',
        'location'    => 'Corner Acacia St. and Mabini Ave.',
        'priority'    => 'Medium',
        'reason'      => 'Dispute and pedestrian blockage.',
        'status'      => 'Assigned',
        'assigned_to' => $pers1,
        'source'      => 'Online',
    ],
    [
        'ref'         => 'BC-20261027-00103',
        'sub_user'    => $resident1,
        'cat'         => $catIds['Noise Disturbance'],
        'desc'        => 'Loud outdoor videoke celebration continuing past 1:00 AM on weeknights.',
        'location'    => '14 Sampaguita Extension',
        'priority'    => 'Low',
        'reason'      => 'Neighborhood peace and quiet disturbance.',
        'status'      => 'Resolved',
        'assigned_to' => $pers1,
        'source'      => 'Online',
        'resolution'  => 'Barangay tanod dispatched. Talked to homeowner, volume lowered and event concluded at 1:30 AM.',
    ],
    [
        'ref'         => 'BC-20261028-00104',
        'sub_user'    => $resident2,
        'cat'         => $catIds['Sanitation & Garbage'],
        'desc'        => 'Accumulated construction rubble and uncollected bagged waste attracting pests in the vacant lot.',
        'location'    => 'Lot 12 Blk 4, Riverside Subd.',
        'priority'    => 'Medium',
        'reason'      => 'Community public health and sanitation concern.',
        'status'      => 'For Assignment',
        'source'      => 'Online',
    ],
    [
        'ref'         => 'BC-20261029-00105',
        'sub_user'    => null, // Staff-encoded walk-in
        'rec_user'    => $officer1,
        'cat'         => $catIds['Public Safety & Streetlights'],
        'desc'        => 'Three consecutive streetlights out along the main access road creating dark spots at night.',
        'location'    => 'F. Manalo Street between Posts 14 and 17',
        'priority'    => 'High',
        'reason'      => 'Public hazard and nighttime security concern.',
        'status'      => 'In Progress',
        'assigned_to' => $pers2,
        'source'      => 'Walk-in',
    ],
    [
        'ref'         => 'BC-20261030-00106',
        'sub_user'    => null,
        'rec_user'    => $officer2,
        'cat'         => $catIds['Disputes & Altercations'],
        'desc'        => 'Boundary wall runoff dispute between two neighboring properties during heavy rain.',
        'location'    => 'Purok 2, Interior St.',
        'priority'    => 'Low',
        'reason'      => 'Civil neighborhood dispute for mediation.',
        'status'      => 'Closed',
        'assigned_to' => $pers2,
        'source'      => 'Walk-in',
        'resolution'  => 'Both parties attended conciliation at the Barangay Hall. Drainage agreement signed.',
    ],
];

foreach ($sampleComplaints as $sc) {
    $stmt = $pdo->prepare('SELECT complaint_id FROM complaints WHERE reference_no = ?');
    $stmt->execute([$sc['ref']]);
    if ($stmt->fetchColumn()) {
        continue; // Already seeded
    }

    $cStmt = $pdo->prepare(
        'INSERT INTO complaints (reference_no, submitted_by_user_id, received_by, category_id, description, incident_location, priority, priority_reason, status, resolution_details, source, created_at, resolved_at, closed_at)
         VALUES (:ref, :sub, :rec, :cat, :desc, :loc, :prio, :prr, :st, :res, :src, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY), :rat, :cat_time)'
    );
    $resolvedAt = in_array($sc['status'], ['Resolved', 'Closed'], true) ? date('Y-m-d H:i:s') : null;
    $closedAt   = ($sc['status'] === 'Closed') ? date('Y-m-d H:i:s') : null;

    $cStmt->execute([
        ':ref'      => $sc['ref'],
        ':sub'      => $sc['sub_user'] ?? null,
        ':rec'      => $sc['rec_user'] ?? null,
        ':cat'      => $sc['cat'],
        ':desc'     => $sc['desc'],
        ':loc'      => $sc['location'],
        ':prio'     => $sc['priority'],
        ':prr'      => $sc['reason'],
        ':st'       => $sc['status'],
        ':res'      => $sc['resolution'] ?? null,
        ':src'      => $sc['source'],
        ':rat'      => $resolvedAt,
        ':cat_time' => $closedAt,
    ]);
    $compId = (int)$pdo->lastInsertId();

    // Initial status history
    $pdo->prepare('INSERT INTO status_history (complaint_id, changed_by, old_status, new_status, action_taken, remarks, created_at) VALUES (?, ?, NULL, "Received", "Complaint registered", "Sample record created", UTC_TIMESTAMP())')
        ->execute([$compId, $sc['sub_user'] ?? $sc['rec_user'] ?? $adminId]);

    // Initial priority history
    $pdo->prepare('INSERT INTO priority_history (complaint_id, changed_by, old_priority, new_priority, reason, changed_at) VALUES (?, ?, NULL, ?, ?, UTC_TIMESTAMP())')
        ->execute([$compId, $sc['sub_user'] ?? $sc['rec_user'] ?? $adminId, $sc['priority'], $sc['reason']]);

    // Assignment if specified
    if (!empty($sc['assigned_to'])) {
        $pdo->prepare('INSERT INTO assignments (complaint_id, assigned_to, assigned_by, assigned_at, assignment_notes) VALUES (?, ?, ?, UTC_TIMESTAMP(), "Initial task assignment")')
            ->execute([$compId, $sc['assigned_to'], $officer1]);

        $pdo->prepare('INSERT INTO status_history (complaint_id, changed_by, old_status, new_status, action_taken, remarks, created_at) VALUES (?, ?, "Received", "Assigned", "Assigned to personnel", "Assigned by officer", UTC_TIMESTAMP())')
            ->execute([$compId, $officer1]);
    }
}

echo "Successfully seeded sample categories and realistic complaints.\n";

