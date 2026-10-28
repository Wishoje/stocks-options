<?php

namespace App\Support\WallIntelligence;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;

/** Recover session alert identities from saved assessments, independently of browser visits. */
final class WallAlertHistory
{
    public const LIMIT = 2000;

    private const EVENT_LIMIT = 500;

    public function read(string $symbol, string $session, array $lifecycle, array $events, CarbonImmutable $cutoff,
        ?CarbonImmutable $sourceThrough = null): array
    {
        $sourceThrough ??= $cutoff;
        $rows = [];
        $truncated = count($events) > self::EVENT_LIMIT;
        if (($lifecycle['schema_version'] ?? null) === WallSetupLifecycle::VERSION
            && ($lifecycle['rule_version'] ?? null) === WallSetupRules::VERSION
            && ($lifecycle['review_rule_version'] ?? null) === WallSetupOutcomeEvaluator::VERSION
            && ($lifecycle['symbol'] ?? null) === $symbol && ($lifecycle['session'] ?? null) === $session
            && ($lifecycle['timeframe'] ?? null) === '7d') {
            $truncated = $truncated || ($lifecycle['truncated'] ?? false) || count($lifecycle['items'] ?? []) > 100;
            foreach (array_slice($lifecycle['items'] ?? [], 0, 100) as $row) {
                if (! is_array($row) || ($row['qualified'] ?? null) !== true
                    || ($row['symbol'] ?? null) !== $symbol || ($row['session'] ?? null) !== $session
                    || ($row['timeframe'] ?? null) !== '7d' || ! $this->key($row['id'] ?? null)
                    || ! in_array($row['side'] ?? null, ['put', 'call'], true)
                    || ! in_array($row['status_group'] ?? null, ['conditions_met', 'in_progress', 'finished', 'needs_update'], true)
                    || ! $this->price($row['strike'] ?? null) || ! $this->price($row['close'] ?? null)) {
                    continue;
                }
                $id = hash('sha256', json_encode([$symbol, $session, 'setup', $row['id']]));
                $at = $this->sessionTime($row['qualified_at'] ?? null, $session, $cutoff);
                $observed = $this->sessionTime($row['observed_at'] ?? null, $session, $cutoff);
                $through = $this->instant($row['evaluated_through'] ?? null);
                if (($row['alert_id'] ?? null) !== $id || ! $at || ! $observed || ! $at->equalTo($observed)
                    || $at->greaterThan($sourceThrough)
                    || ! $through || $through->lessThan($at) || $through->greaterThan($cutoff)
                    || $through->setTimezone('America/New_York')->toDateString() !== $session
                    || ! $this->setup($row['setup'] ?? null, (float) $row['close'])) {
                    continue;
                }
                if (! empty($row['outcome_at'])) {
                    $outcome = $this->sessionTime($row['outcome_at'], $session, $cutoff);
                    if (! $outcome || $outcome->lessThan($at) || $outcome->greaterThan($sourceThrough)) {
                        continue;
                    }
                }
                $state = array_intersect_key($row, array_flip(['id', 'symbol', 'side', 'strike', 'alert_id', 'assessment_id',
                    'session', 'timeframe', 'status_group', 'outcome', 'qualified', 'observed_at', 'qualified_at',
                    'entry_expires_at', 'evaluated_through', 'outcome_at', 'review_until', 'reviewed_through', 'close', 'reason', 'setup']));
                $setup = ['direction' => $row['setup']['direction'], 'pattern' => $row['setup']['pattern'],
                    'entry_zone' => ['low' => (float) $row['setup']['entry_zone']['low'], 'high' => (float) $row['setup']['entry_zone']['high']],
                    'invalidation' => ['price' => (float) $row['setup']['invalidation']['price'], 'close_side' => $row['setup']['invalidation']['close_side']],
                    'target' => ['strike' => (float) $row['setup']['target']['strike']]];
                $state['setup'] = $setup;
                $rows[$id] = ['alert_id' => $id, 'assessment_id' => $this->key($row['assessment_id'] ?? null) ? $row['assessment_id'] : null,
                    'symbol' => $symbol, 'side' => $row['side'], 'strike' => (float) $row['strike'], 'close' => (float) $row['close'],
                    'status' => 'conditions_met', 'observed_at' => $at->toIso8601String(), 'session' => $session, 'timeframe' => '7d',
                    'setup' => $setup,
                    'lifecycle' => $state];
            }
        }
        foreach (array_slice($events, 0, self::EVENT_LIMIT) as $event) {
            if (! is_array($event) || ($event['status'] ?? null) !== 'testing'
                || ($event['rule_version'] ?? null) !== WallInteractionRules::VERSION
                || ($event['bar_interval_minutes'] ?? null) !== 5
                || ! in_array($event['side'] ?? null, ['put', 'call'], true)
                || ! $this->price($event['strike'] ?? null) || ! $this->price($event['close'] ?? null)
                || ! $this->key($event['wall_identity'] ?? null)) {
                continue;
            }
            $at = $this->sessionTime($event['observed_at'] ?? null, $session, $cutoff);
            $episode = $this->sessionTime($event['episode_started_at'] ?? null, $session, $cutoff);
            $start = $this->sessionTime($event['bar_start'] ?? null, $session, $cutoff);
            $assessment = WallInteractionDetector::assessmentId($event);
            if (! $at || ! $episode || ! $start || $at->greaterThan($sourceThrough) || $episode->greaterThan($start)
                || $start->timestamp % 300 !== 0 || $episode->timestamp % 300 !== 0
                || $start->addMinutes(5)->notEqualTo($at) || ! $assessment || ($event['id'] ?? null) !== $assessment) {
                continue;
            }
            $id = hash('sha256', json_encode([$symbol, $session, 'testing', $event['wall_identity'],
                $event['episode_started_at'], WallInteractionRules::VERSION]));
            if (isset($rows[$id]) && strtotime($rows[$id]['observed_at']) <= $at->timestamp) {
                continue;
            }
            $rows[$id] = ['alert_id' => $id, 'assessment_id' => $assessment, 'symbol' => $symbol,
                'side' => $event['side'], 'strike' => (float) $event['strike'], 'close' => (float) $event['close'],
                'status' => 'testing', 'observed_at' => $at->toIso8601String(), 'session' => $session, 'timeframe' => '7d',
                'setup' => null, 'lifecycle' => null];
        }

        return ['items' => array_values($rows), 'truncated' => $truncated];
    }

    private function setup(mixed $setup, float $close): bool
    {
        if (! is_array($setup) || ! in_array($setup['direction'] ?? null, ['bullish', 'bearish'], true)
            || ! in_array($setup['pattern'] ?? null, ['reversal', 'continuation', 'reclaim'], true)) {
            return false;
        }
        $low = $setup['entry_zone']['low'] ?? null;
        $high = $setup['entry_zone']['high'] ?? null;
        $invalid = $setup['invalidation']['price'] ?? null;
        $target = $setup['target']['strike'] ?? null;
        if (! $this->price($low) || ! $this->price($high) || ! $this->price($invalid) || ! $this->price($target)
            || $low >= $high || $close < $low || $close > $high) {
            return false;
        }

        return $setup['direction'] === 'bullish'
            ? ($setup['invalidation']['close_side'] ?? null) === 'below' && $close > $low && $invalid < $low && $target > $high
            : ($setup['invalidation']['close_side'] ?? null) === 'above' && $close < $high && $invalid > $high && $target < $low;
    }

    private function sessionTime(mixed $value, string $session, CarbonImmutable $cutoff): ?CarbonImmutable
    {
        $at = $this->instant($value);
        if (! $at || $at->greaterThan($cutoff) || $at->setTimezone('America/New_York')->toDateString() !== $session) {
            return null;
        }
        $market = MarketSession::describe($at);

        return $market['is_trading_day'] && $at->greaterThanOrEqualTo(CarbonImmutable::parse($market['opens_at']))
            && $at->lessThanOrEqualTo(CarbonImmutable::parse($market['closes_at'])) ? $at : null;
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function key(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value);
    }

    private function price(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value) && $value > 0;
    }
}
