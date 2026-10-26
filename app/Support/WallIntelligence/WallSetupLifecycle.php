<?php

namespace App\Support\WallIntelligence;

/** Compact scenario states for the shared feed; never represents an executed position. */
final class WallSetupLifecycle
{
    public const VERSION = 'wall-lifecycle.v1';

    public function build(array $analysis, array $reviews): array
    {
        $rows = [];
        $current = $analysis['setups']['current'] ?? [];
        foreach ($reviews['cases'] ?? [] as $case) {
            $outcome = $case['outcome'];
            $group = match ($outcome) {
                'in_progress' => (($current[$case['side']]['id'] ?? null) === $case['id']
                    && ($current[$case['side']]['status'] ?? null) === 'conditions_met') ? 'conditions_met' : 'in_progress',
                'watching' => 'watching',
                'target_reached', 'invalidated', 'expired', 'entry_missed', 'no_setup' => 'finished',
                default => 'needs_update',
            };
            $rows[$case['id']] = [
                'id' => $case['id'], 'symbol' => $analysis['symbol'], 'side' => $case['side'], 'strike' => $case['strike'],
                'alert_id' => hash('sha256', json_encode([$analysis['symbol'], $analysis['session'], 'setup', $case['id']])),
                'assessment_id' => $case['assessment_id'] ?? null,
                'session' => $analysis['session'], 'timeframe' => $analysis['timeframe'],
                'status_group' => $group, 'outcome' => $outcome, 'qualified' => $case['qualified'],
                'observed_at' => $case['observed_at'], 'qualified_at' => $case['qualified_at'],
                'entry_expires_at' => $case['entry_expires_at'] ?? null,
                'evaluated_through' => $analysis['evaluated_through'], 'outcome_at' => $case['outcome_at'],
                'close' => $case['reference_close'], 'reason' => $case['reason'],
                'setup' => array_intersect_key($case, array_flip(['pattern', 'direction', 'entry_zone', 'invalidation', 'target'])),
            ];
        }
        foreach ($current as $side => $setup) {
            if (! in_array($side, ['put', 'call'], true) || isset($rows[$setup['id'] ?? ''])) {
                continue;
            }
            $reaction = $analysis['current'][$side] ?? [];
            if (empty($reaction['observed_at']) || ! is_numeric($reaction['strike'] ?? null)) {
                continue;
            }
            $status = $setup['status'] ?? 'watch';
            // Qualification is accepted only from the validated outcome evaluator.
            $group = in_array($status, ['expired', 'invalidated', 'entry_missed', 'no_setup', 'target_reached'], true)
                ? 'finished' : ($status === 'watch' ? 'watching' : 'needs_update');
            $id = $setup['id'] ?? WallInteractionDetector::assessmentId($reaction);
            if (! $id) {
                continue;
            }
            $rows[$id] = ['id' => $id, 'symbol' => $analysis['symbol'], 'side' => $side,
                'strike' => $reaction['strike'], 'alert_id' => null, 'assessment_id' => WallInteractionDetector::assessmentId($reaction),
                'session' => $analysis['session'], 'timeframe' => $analysis['timeframe'],
                'status_group' => $group, 'outcome' => $status === 'watch' ? 'watching' : $status, 'qualified' => false,
                'observed_at' => $reaction['observed_at'], 'qualified_at' => null,
                'evaluated_through' => $analysis['evaluated_through'], 'outcome_at' => null,
                'close' => $reaction['close'], 'reason' => $setup['next_condition']['text'] ?? $setup['reason'] ?? 'Watch for a completed price sequence.',
                'setup' => null];
        }
        usort($rows, fn ($a, $b) => strcmp($b['observed_at'], $a['observed_at']) ?: strcmp($a['id'], $b['id']));

        return ['schema_version' => self::VERSION, 'rule_version' => WallSetupRules::VERSION,
            'review_rule_version' => WallSetupOutcomeEvaluator::VERSION, 'symbol' => $analysis['symbol'],
            'session' => $analysis['session'], 'timeframe' => $analysis['timeframe'],
            'evaluated_through' => $analysis['evaluated_through'], 'truncated' => count($rows) > 100 || ($reviews['capture_limit_reached'] ?? false),
            'items' => array_slice($rows, 0, 100)];
    }
}
