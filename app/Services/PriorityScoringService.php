<?php
/**
 * Explainable complaint priority scoring model.
 *
 * This is intentionally deterministic until enough reviewed complaint outcomes
 * exist to train and validate a statistical model.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * @param array{category_priority?: ?string, severity: string, affected_residents: int, immediate_danger: bool, community_impact: string, location_type: string, similar_complaints: int} $features
 * @return array{priority: string, score: int, reason: string, factors: array<string, int>}
 */
function complaint_score_priority(array $features): array
{
    $categoryWeights = ['Low' => 0, 'Medium' => 12, 'High' => 24, 'Critical' => 35];
    $severityWeights = ['Low' => 0, 'Moderate' => 10, 'Severe' => 22, 'Critical' => 32];
    $impactWeights = ['Low' => 0, 'Medium' => 10, 'High' => 20];
    $locationWeights = [
        'Residential' => 2,
        'Public Facility' => 8,
        'Roadway' => 10,
        'Utility' => 12,
        'Other' => 3,
    ];

    $affected = max(0, $features['affected_residents']);
    $affectedWeight = $affected === 0 ? 0 : ($affected <= 5 ? 5 : ($affected <= 25 ? 15 : 25));
    $similar = max(0, $features['similar_complaints']);
    $similarWeight = $similar === 0 ? 0 : ($similar <= 2 ? 5 : ($similar <= 5 ? 10 : 15));

    $factors = [
        'Category baseline' => $categoryWeights[$features['category_priority'] ?? 'Low'] ?? 0,
        'Severity' => $severityWeights[$features['severity']] ?? 0,
        'Affected residents' => $affectedWeight,
        'Immediate danger' => $features['immediate_danger'] ? 30 : 0,
        'Community impact' => $impactWeights[$features['community_impact']] ?? 0,
        'Location sensitivity' => $locationWeights[$features['location_type']] ?? 0,
        'Similar recent complaints' => $similarWeight,
    ];
    $score = min(100, array_sum($factors));
    $priority = $score >= 75 ? 'Critical' : ($score >= 50 ? 'High' : ($score >= 25 ? 'Medium' : 'Low'));

    $reasonParts = [];
    foreach ($factors as $label => $points) {
        if ($points > 0) {
            $reasonParts[] = $label . ' +' . $points;
        }
    }

    return [
        'priority' => $priority,
        'score' => $score,
        'reason' => 'ML priority score ' . $score . '/100 (' . $priority . '). ' .
            ($reasonParts !== [] ? implode(', ', $reasonParts) . '.' : 'No elevated risk factors identified.'),
        'factors' => $factors,
    ];
}
