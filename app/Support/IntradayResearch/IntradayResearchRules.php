<?php

namespace App\Support\IntradayResearch;

use App\Support\MarketSession;
use App\Support\SetupStudy\SetupStudyMetrics;
use App\Support\WallIntelligence\WallSetupRules;
use Carbon\CarbonImmutable;

/** Private recorded-close comparisons. Qualification never establishes a fill. */
final class IntradayResearchRules
{
    public const VERSION = 'intraday-research.v1';

    public const DELAY_SECONDS = 900;

    public static function variants(): array
    {
        return [
            'baseline' => ['label' => 'Signal-close baseline', 'description' => 'Track the original recorded qualifying close and fixed levels. Study reference only; no experimental alerts.'],
            'post_publication_retest' => ['label' => 'Retest after publication', 'description' => 'After the private watch is recorded, allow 15 market minutes for a whole five-minute bar to close inside the original entry area. Target and invalidation stay fixed.'],
        ];
    }

    public static function create(array $sample, CarbonImmutable $createdAt, string $variant): array
    {
        if (! isset(self::variants()[$variant])) {
            throw new \InvalidArgumentException('Unknown intraday research variant.');
        }
        $createdAt = $createdAt->utc();
        $signal = self::instant($sample['signal_at'] ?? null);
        $available = self::instant($sample['available_at'] ?? null);
        $end = self::instant($sample['review_until'] ?? null);
        $anchor = $available ? max($available, $createdAt) : $createdAt;
        $plan = ['research_version' => self::VERSION, 'variant' => $variant, 'mode' => 'intraday',
            'symbol' => $sample['symbol'] ?? null, 'session' => $sample['session'] ?? null, 'timeframe' => $sample['timeframe'] ?? null,
            'direction' => $sample['direction'] ?? null, 'side' => $sample['side'] ?? null, 'pattern' => $sample['pattern'] ?? null,
            'source_rule_version' => $sample['rule_version'] ?? null, 'source_sample_id' => $sample['id'] ?? null,
            'status' => 'watching', 'reason' => 'Waiting for a complete five-minute retest close after the research watch was recorded.',
            'entry_low' => $sample['entry_low'] ?? null, 'entry_high' => $sample['entry_high'] ?? null,
            'invalidation' => $sample['invalidation'] ?? null, 'target' => $sample['target'] ?? null,
            'invalidation_side' => ($sample['direction'] ?? null) === 'bullish' ? 'below' : 'above',
            'original_signal_at' => $signal?->toIso8601String(), 'original_available_at' => $available?->toIso8601String(),
            'original_entry_expires_at' => $sample['entry_expires_at'] ?? null, 'created_at' => $createdAt->toIso8601String(),
            'entry_not_before' => $anchor->toIso8601String(), 'entry_deadline' => $end ? min($end, $anchor->addMinutes(15))->toIso8601String() : null,
            'signal_at' => $variant === 'baseline' ? $signal?->toIso8601String() : null,
            'signal_price' => $variant === 'baseline' ? ($sample['signal_price'] ?? null) : null,
            'qualified_recorded_at' => null, 'signal_available_at' => null, 'review_until' => $end?->toIso8601String(),
            'outcome' => null, 'outcome_at' => null, 'notification_eligible' => false,
            'quote_delay_seconds' => self::DELAY_SECONDS, 'metrics' => self::emptyMetrics()];
        $valid = $signal && $available && $end && $available >= $signal && $available <= $createdAt
            && ($sample['mode'] ?? null) === 'intraday' && ($sample['timeframe'] ?? null) === '7d'
            && ($sample['rule_version'] ?? null) === WallSetupRules::VERSION
            && in_array($plan['direction'], ['bullish', 'bearish'], true)
            && in_array($plan['side'], ['put', 'call'], true)
            && in_array($plan['pattern'], ['reversal', 'reclaim', 'continuation'], true)
            && is_string($plan['session']) && $signal->setTimezone('America/New_York')->toDateString() === $plan['session']
            && $signal->timestamp % 300 === 0 && self::geometry($plan)
            && self::positive($sample['signal_price'] ?? null) && self::inside($plan, (float) $sample['signal_price'])
            && ! ($sample['demonstration'] ?? false) && ! ($sample['source']['demonstration'] ?? false);
        if ($valid) {
            $market = MarketSession::describe($signal);
            $valid = $market['is_trading_day'] && $signal >= CarbonImmutable::parse($market['opens_at'])
                && $end->equalTo(CarbonImmutable::parse($market['closes_at'])) && $signal <= $end;
        }
        if (! $valid) {
            return self::needsReview($plan, 'The saved qualification or fixed price levels need review.');
        }
        if ($variant !== 'baseline' && $createdAt >= $end) {
            return [...$plan, 'status' => 'not_qualified', 'reason' => 'The session ended before this private watch was recorded.'];
        }

        return $plan;
    }

    public static function evaluate(array $plan, array $bars, CarbonImmutable $now): array
    {
        $now = $now->utc();
        $state = [...$plan, 'notification_eligible' => false, 'metrics' => self::emptyMetrics()];
        if (($plan['research_version'] ?? null) !== self::VERSION || ! isset(self::variants()[$plan['variant'] ?? ''])
            || ! self::geometry($plan) || ($plan['status'] ?? '') === 'needs_update') {
            return self::needsReview($state, $plan['reason'] ?? 'Research evidence needs review.');
        }
        $origin = self::instant($plan['original_signal_at']);
        $end = self::instant($plan['review_until']);
        $anchor = self::instant($plan['entry_not_before']);
        $deadline = self::instant($plan['entry_deadline']);
        if (! $origin || ! $end || ! $anchor || ! $deadline) {
            return self::needsReview($state, 'The recorded comparison times need review.');
        }
        $cutoff = min($now->subSeconds(self::DELAY_SECONDS), $end);
        $normalized = [];
        foreach ($bars as $bar) {
            $at = self::instant($bar['at'] ?? null);
            if (! $at || $at <= $origin || $at > $cutoff) {
                continue;
            }
            if ($at->timestamp % 300 !== 0 || isset($normalized[$at->timestamp]) || ! self::barValid($bar)) {
                return self::needsReview($state, 'A completed five-minute price bar needs review.');
            }
            $normalized[$at->timestamp] = [...$bar, 'at' => $at->toIso8601String()];
        }
        ksort($normalized, SORT_NUMERIC);
        $known = array_values($normalized);
        $last = $known ? CarbonImmutable::parse($known[array_key_last($known)]['at']) : null;
        $state['last_observed_at'] = $last?->toIso8601String();
        $state['evaluated_through'] = $last?->toIso8601String();
        if ($plan['variant'] !== 'baseline' && ($plan['status'] ?? null) === 'not_qualified') {
            return $state;
        }
        if ($plan['variant'] === 'post_publication_retest') {
            $state['signal_at'] = $state['signal_price'] = null;
            $expected = $origin->addMinutes(5);
            foreach ($known as $bar) {
                $at = CarbonImmutable::parse($bar['at']);
                if ($at > $deadline) {
                    break;
                }
                if (! $at->equalTo($expected)) {
                    return self::needsReview($state, 'A gap prevents a continuous retest comparison.');
                }
                $expected = $at->addMinutes(5);
                $close = (float) $bar['close'];
                // A completed close that already ended the original scenario cannot be reused for a later entry.
                if (self::invalidated($plan, $close) || self::targetReached($plan, $close)) {
                    return [...$state, 'status' => 'not_qualified', 'reason' => 'The original target or invalidation was reached before the experimental entry.',
                        'outcome' => 'resolved_before_entry', 'outcome_at' => $at->toIso8601String()];
                }
                if ($at->subMinutes(5) < $anchor) {
                    continue;
                }
                if (self::inside($plan, $close)) {
                    $state['signal_at'] = $at->toIso8601String();
                    $state['signal_price'] = $close;
                    break;
                }
            }
            if (! $state['signal_at']) {
                if ($cutoff >= $expected && $expected <= $deadline) {
                    return self::needsReview($state, 'A completed retest bar is not available for this comparison.');
                }
                if ($cutoff >= $deadline) {
                    return [...$state, 'status' => 'not_qualified', 'reason' => 'No completed close met the experimental entry conditions within its recorded window.',
                        'outcome' => 'entry_window_ended', 'outcome_at' => $deadline->toIso8601String()];
                }

                return $state;
            }
        }
        $qualifiedAt = CarbonImmutable::parse($state['signal_at']);
        $recorded = self::instant($plan['qualified_recorded_at'] ?? null) ?? $now;
        $state['qualified_recorded_at'] = $recorded->toIso8601String();
        $state['signal_available_at'] = $plan['variant'] === 'baseline' ? $plan['original_available_at'] : $state['qualified_recorded_at'];
        $sample = [...$state, 'native_status' => 'conditions_met', 'available_at' => $state['signal_available_at'], 'bars' => $known];
        $measurement = (new SetupStudyMetrics)->measure($sample, $cutoff);
        $state['metrics'] = [...$measurement['metrics'], 'qualified' => true,
            'followup_complete' => $measurement['followup_complete'], 'needs_update' => $measurement['needs_update'], 'outcome_move_pct' => null];
        $state['outcome'] = $measurement['outcome'];
        $state['outcome_at'] = $measurement['metrics']['strict_at'];
        if ($measurement['needs_update']) {
            return self::needsReview($state, 'A continuous recorded follow-up is required before counting this result.');
        }
        if ($measurement['followup_complete']) {
            $state['status'] = 'completed';
            $state['reason'] = 'The recorded close comparison reached its first target, invalidation or session end.';
            foreach ($known as $bar) {
                if (CarbonImmutable::parse($bar['at'])->equalTo(CarbonImmutable::parse($state['outcome_at']))) {
                    $state['metrics']['outcome_move_pct'] = round(100 * ($state['direction'] === 'bullish' ? 1 : -1)
                        * ((float) $bar['close'] - $state['signal_price']) / $state['signal_price'], 4);
                    break;
                }
            }

            return $state;
        }
        $state['status'] = $last && $last > $qualifiedAt ? 'in_progress' : 'conditions_met';
        $state['reason'] = 'A recorded five-minute close met the entry conditions. Follow the fixed target and invalidation in this paper comparison.';
        $market = MarketSession::describe($now);
        $lastEvidence = max($qualifiedAt, $last ?? $qualifiedAt);
        $age = $now->timestamp - $lastEvidence->timestamp;
        $qualificationAge = $now->timestamp - $qualifiedAt->timestamp;
        $state['notification_eligible'] = $plan['variant'] === 'post_publication_retest' && $market['is_rth']
            && $now->setTimezone('America/New_York')->toDateString() === $state['session']
            && $qualificationAge >= self::DELAY_SECONDS && $qualificationAge <= self::DELAY_SECONDS + 420
            && $age >= self::DELAY_SECONDS && $age <= self::DELAY_SECONDS + 420;

        return $state;
    }

    private static function emptyMetrics(): array
    {
        return ['qualified' => false, 'followup_complete' => false, 'needs_update' => false,
            'mfe_pct' => null, 'mae_pct' => null, 'outcome_move_pct' => null, 'observations' => 0, 'evidence_gap' => false];
    }

    private static function needsReview(array $state, string $reason): array
    {
        return [...$state, 'status' => 'needs_update', 'reason' => $reason, 'notification_eligible' => false,
            'metrics' => [...($state['metrics'] ?? self::emptyMetrics()), 'needs_update' => true, 'followup_complete' => false]];
    }

    private static function geometry(array $plan): bool
    {
        foreach (['entry_low', 'entry_high', 'invalidation', 'target'] as $key) {
            if (! self::positive($plan[$key] ?? null)) {
                return false;
            }
        }
        if ($plan['entry_low'] >= $plan['entry_high']) {
            return false;
        }

        return $plan['direction'] === 'bullish'
            ? $plan['invalidation'] < $plan['entry_low'] && $plan['target'] > $plan['entry_high']
            : ($plan['direction'] === 'bearish' && $plan['invalidation'] > $plan['entry_high'] && $plan['target'] < $plan['entry_low']);
    }

    private static function inside(array $plan, float $close): bool
    {
        return $plan['direction'] === 'bullish' ? $close > $plan['entry_low'] && $close <= $plan['entry_high']
            : $close >= $plan['entry_low'] && $close < $plan['entry_high'];
    }

    private static function invalidated(array $plan, float $close): bool
    {
        return ($plan['direction'] === 'bullish' ? 1 : -1) * ($close - $plan['invalidation']) < -1e-9;
    }

    private static function targetReached(array $plan, float $close): bool
    {
        return ($plan['direction'] === 'bullish' ? 1 : -1) * ($close - $plan['target']) >= -1e-9;
    }

    private static function barValid(array $bar): bool
    {
        foreach (['open', 'high', 'low', 'close'] as $key) {
            if (! self::positive($bar[$key] ?? null)) {
                return false;
            }
        }

        return $bar['high'] >= max($bar['open'], $bar['close']) && $bar['low'] <= min($bar['open'], $bar['close']);
    }

    private static function positive(mixed $number): bool
    {
        return is_numeric($number) && is_finite((float) $number) && $number > 0;
    }

    private static function instant(mixed $value): ?CarbonImmutable
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
}
