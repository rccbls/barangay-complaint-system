<?php
/** Add structured complaint assessment fields to an existing installation. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';
$pdo = db_connect(true);
$columns = [
    'severity' => "ADD COLUMN severity ENUM('Low','Moderate','Severe','Critical') NOT NULL DEFAULT 'Low' AFTER source",
    'affected_residents' => 'ADD COLUMN affected_residents INT UNSIGNED NOT NULL DEFAULT 0 AFTER severity',
    'immediate_danger' => 'ADD COLUMN immediate_danger BOOLEAN NOT NULL DEFAULT FALSE AFTER affected_residents',
    'community_impact' => "ADD COLUMN community_impact ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low' AFTER immediate_danger",
    'location_type' => "ADD COLUMN location_type ENUM('Residential','Public Facility','Roadway','Utility','Other') NOT NULL DEFAULT 'Other' AFTER community_impact",
    'priority_score' => 'ADD COLUMN priority_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER location_type',
    'predicted_priority' => "ADD COLUMN predicted_priority ENUM('Critical','High','Medium','Low') NULL AFTER priority_score",
    'priority_confirmed' => 'ADD COLUMN priority_confirmed BOOLEAN NOT NULL DEFAULT FALSE AFTER predicted_priority',
    'priority_reviewed_by' => 'ADD COLUMN priority_reviewed_by INT UNSIGNED NULL AFTER priority_confirmed',
    'priority_reviewed_at' => 'ADD COLUMN priority_reviewed_at DATETIME NULL AFTER priority_reviewed_by',
];
$existing = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'complaints'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($columns as $name => $definition) {
    if (!in_array($name, $existing, true)) {
        $pdo->exec('ALTER TABLE complaints ' . $definition);
        echo "Added $name\n";
    }
}
if (!in_array('fk_complaints_priority_reviewer', $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints'")->fetchAll(PDO::FETCH_COLUMN), true)) {
    $pdo->exec('ALTER TABLE complaints ADD CONSTRAINT fk_complaints_priority_reviewer FOREIGN KEY (priority_reviewed_by) REFERENCES users(user_id)');
}
echo "Priority model migration complete.\n";
