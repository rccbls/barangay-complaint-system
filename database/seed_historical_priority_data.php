<?php
/**
 * Seed synthetic historical complaint labels for local model development.
 *
 * Run only in a local development database:
 *   C:\xampp\php\php.exe database\seed_historical_priority_data.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';
require_once APP_ROOT . '/app/Services/PriorityScoringService.php';

if (!app_is_local()) {
    fwrite(STDERR, "Refusing to seed synthetic historical data outside APP_ENV=local.\n");
    exit(1);
}

$pdo = db_connect(true);
$requiredColumns = ['severity', 'affected_residents', 'immediate_danger', 'community_impact', 'location_type', 'priority_score', 'predicted_priority', 'priority_confirmed', 'priority_reviewed_by', 'priority_reviewed_at'];
$columnStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'complaints'");
$availableColumns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);
$missingColumns = array_diff($requiredColumns, $availableColumns);
if ($missingColumns !== []) {
    fwrite(STDERR, "Missing priority model columns. Run database\\migrate_priority_model.php first.\n");
    exit(1);
}

$officerStmt = $pdo->prepare(
    'SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
     WHERE r.role_name = "Complaint Officer" AND u.is_active = 1 ORDER BY u.user_id LIMIT 1'
);
$officerStmt->execute();
$reviewerId = (int) $officerStmt->fetchColumn();
if ($reviewerId <= 0) {
    fwrite(STDERR, "Create an active Complaint Officer before seeding historical data.\n");
    exit(1);
}

$categoryDefinitions = [
    ['Road & Traffic Obstruction', 'Medium'],
    ['Sanitation & Garbage', 'Medium'],
    ['Public Safety & Streetlights', 'High'],
    ['Disputes & Altercations', 'Medium'],
    ['Emergency & Structural Hazard', 'Critical'],
    ['Noise Disturbance', 'Low'],
];
$categoryIds = [];
$categoryLookup = $pdo->prepare('SELECT category_id FROM categories WHERE category_name = ?');
$categoryInsert = $pdo->prepare(
    'INSERT INTO categories (category_name, description, default_priority, is_active) VALUES (?, ?, ?, 1)'
);
foreach ($categoryDefinitions as [$name, $defaultPriority]) {
    $categoryLookup->execute([$name]);
    $categoryId = (int) $categoryLookup->fetchColumn();
    if ($categoryId <= 0) {
        $categoryInsert->execute([$name, 'Synthetic historical training category', $defaultPriority]);
        $categoryId = (int) $pdo->lastInsertId();
    }
    $categoryIds[$name] = $categoryId;
}

$records = [
    ['Emergency & Structural Hazard', 'Critical', 120, true, 'High', 'Utility', 8, 'Critical'],
    ['Emergency & Structural Hazard', 'Severe', 65, true, 'High', 'Public Facility', 6, 'Critical'],
    ['Public Safety & Streetlights', 'Severe', 40, true, 'High', 'Roadway', 7, 'Critical'],
    ['Sanitation & Garbage', 'Severe', 80, false, 'High', 'Public Facility', 9, 'High'],
    ['Road & Traffic Obstruction', 'Severe', 55, false, 'High', 'Roadway', 6, 'High'],
    ['Public Safety & Streetlights', 'Severe', 30, false, 'High', 'Utility', 4, 'High'],
    ['Emergency & Structural Hazard', 'Moderate', 20, false, 'High', 'Residential', 3, 'High'],
    ['Sanitation & Garbage', 'Moderate', 35, false, 'High', 'Public Facility', 5, 'High'],
    ['Road & Traffic Obstruction', 'Moderate', 28, false, 'Medium', 'Roadway', 4, 'Medium'],
    ['Disputes & Altercations', 'Severe', 8, false, 'Medium', 'Residential', 2, 'Medium'],
    ['Public Safety & Streetlights', 'Moderate', 12, false, 'Medium', 'Roadway', 3, 'Medium'],
    ['Sanitation & Garbage', 'Moderate', 15, false, 'Medium', 'Residential', 3, 'Medium'],
    ['Noise Disturbance', 'Moderate', 5, false, 'Medium', 'Residential', 4, 'Medium'],
    ['Road & Traffic Obstruction', 'Low', 4, false, 'Medium', 'Roadway', 1, 'Medium'],
    ['Disputes & Altercations', 'Moderate', 6, false, 'Low', 'Residential', 1, 'Low'],
    ['Noise Disturbance', 'Low', 2, false, 'Low', 'Residential', 0, 'Low'],
    ['Sanitation & Garbage', 'Low', 3, false, 'Low', 'Residential', 0, 'Low'],
    ['Road & Traffic Obstruction', 'Low', 2, false, 'Low', 'Roadway', 0, 'Low'],
    ['Noise Disturbance', 'Low', 1, false, 'Low', 'Residential', 2, 'Low'],
    ['Disputes & Altercations', 'Low', 4, false, 'Low', 'Residential', 0, 'Low'],
];

$exists = $pdo->prepare('SELECT complaint_id FROM complaints WHERE reference_no = ?');
$insert = $pdo->prepare(
    'INSERT INTO complaints (
        reference_no, received_by, category_id, description, incident_location, source,
        severity, affected_residents, immediate_danger, community_impact, location_type,
        priority_score, predicted_priority, priority_confirmed, priority_reviewed_by,
        priority_reviewed_at, priority, priority_reason, status, created_at, updated_at,
        resolved_at, closed_at
    ) VALUES (
        ?, ?, ?, ?, ?, "Other", ?, ?, ?, ?, ?, ?, ?, TRUE, ?, ?, ?, ?, "Closed", ?, ?, ?, ?
    )'
);
$history = $pdo->prepare(
    'INSERT INTO priority_history (complaint_id, changed_by, old_priority, new_priority, reason, changed_at)
     VALUES (?, ?, NULL, ?, ?, ?)'
);

$inserted = 0;
$skipped = 0;
$pdo->beginTransaction();
try {
    foreach ($records as $index => [$category, $severity, $affected, $danger, $impact, $locationType, $similar, $officialPriority]) {
        $reference = 'MNG-HIST-' . (string) (2025 + intdiv($index, 10)) . '-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
        $exists->execute([$reference]);
        if ($exists->fetchColumn()) {
            $skipped++;
            continue;
        }

        $categoryPriorityStmt = $pdo->prepare('SELECT default_priority FROM categories WHERE category_id = ?');
        $categoryPriorityStmt->execute([$categoryIds[$category]]);
        $prediction = complaint_score_priority([
            'category_priority' => $categoryPriorityStmt->fetchColumn() ?: 'Low',
            'severity' => $severity,
            'affected_residents' => $affected,
            'immediate_danger' => $danger,
            'community_impact' => $impact,
            'location_type' => $locationType,
            'similar_complaints' => $similar,
        ]);
        $createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . (360 - ($index * 15)) . ' days')->format('Y-m-d H:i:s');
        $location = 'Barangay Manggahan - ' . $locationType . ' Zone ' . (($index % 6) + 1);
        $description = '[SYNTHETIC TRAINING RECORD] Historical ' . strtolower($category) . ' report for model development.';
        $reason = 'Barangay official historical label: ' . $officialPriority . '. Synthetic development record.';

        $insert->execute([
            $reference, $reviewerId, $categoryIds[$category], $description, $location,
            $severity, $affected, $danger ? 1 : 0, $impact, $locationType,
            $prediction['score'], $prediction['priority'], $reviewerId, $createdAt,
            $officialPriority, $reason, $createdAt, $createdAt, $createdAt, $createdAt,
        ]);
        $complaintId = (int) $pdo->lastInsertId();
        $history->execute([$complaintId, $reviewerId, $officialPriority, $reason, $createdAt]);
        $inserted++;
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo "Synthetic historical priority records inserted: {$inserted}; skipped existing: {$skipped}.\n";
echo "These records are labeled development data and should be replaced with validated Barangay Manggahan records.\n";
