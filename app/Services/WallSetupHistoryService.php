<?php

namespace App\Services;

use App\Models\WallObservation;
use App\Support\WallIntelligence\WallSetupOutcomeEvaluator;
use App\Support\WallIntelligence\WallSetupRules;
use Carbon\CarbonImmutable;

final class WallSetupHistoryService
{
    public function read(array $interaction): array
    {
        $evaluator = new WallSetupOutcomeEvaluator;
        $seeds = [];
        // Compact seed arrays avoid reloading full price and interaction histories.
        $records = $interaction['session'] ? WallObservation::where('symbol', $interaction['symbol'])
            ->where('dataset', 'wall_interactions')->where('analysis_session', $interaction['session'])
            ->where('schema_version', 'wall-interactions.v1')
            ->where('payload_json->timeframe', $interaction['timeframe'])
            ->where('payload_json->setups->rule_version', WallSetupRules::VERSION)
            ->whereNotNull('payload_json->setups->review_seeds')
            ->orderBy('id')->limit(161)
            ->get(['content_hash', 'recorded_at', 'payload_json->setups->review_seeds as seeds', 'payload_json->price_evidence_hash as price_hash']) : collect();
        $truncated = $records->count() > 160;
        foreach ($records->take(160) as $record) {
            $rows = json_decode($record->seeds ?? '[]', true);
            foreach (is_array($rows) ? $rows : [] as $seed) {
                if (! is_array($seed) || empty($seed['id'])) {
                    continue;
                }
                $id = $seed['id'];
                if (($seeds[$id]['status'] ?? null) === 'conditions_met') {
                    continue;
                }
                $seeds[$id] = [...$seed, 'review_basis' => 'saved_assessment',
                    'first_recorded_at' => CarbonImmutable::parse($record->recorded_at, 'UTC')->toIso8601String(), 'assessment_hash' => $record->content_hash,
                    'source_price_evidence_hash' => $record->price_hash];
            }
        }
        foreach ($evaluator->seeds($interaction['setups']) as $seed) {
            // Saved scenarios are never replaced by a later retrospective replay.
            if (! isset($seeds[$seed['id']])) {
                $seeds[$seed['id']] = [...$seed, 'review_basis' => 'session_replay'];
            }
        }
        $result = $evaluator->analyze($interaction, array_values($seeds));
        $result['capture_limit_reached'] = $truncated;

        return $result;
    }
}
