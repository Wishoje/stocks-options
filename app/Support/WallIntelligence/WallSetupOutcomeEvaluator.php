<?php

namespace App\Support\WallIntelligence;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;

/** Descriptive close-based reviews. No simulated fills or calibrated success rates. */
final class WallSetupOutcomeEvaluator
{
    public const SCHEMA = 'wall-setup-reviews.v1';

    public const VERSION = 'frozen-levels-60m-close.v1';

    public const HORIZON_MINUTES = 60;

    /** Keep the first eligible reading, otherwise the most recent scenario state. */
    public function seeds(array $setups): array
    {
        if (($setups['rule_version'] ?? null) !== WallSetupRules::VERSION) {
            return [];
        }
        $seeds = [];
        foreach ($setups['readings'] ?? [] as $row) {
            if (! is_array($row) || ! $this->validSeed($row, $setups)) {
                continue;
            }
            $id = $row['id'];
            if (($seeds[$id]['status'] ?? null) !== 'conditions_met') {
                $seeds[$id] = $row;
            }
        }

        return array_values($seeds);
    }

    public function analyze(array $interaction, array $seeds): array
    {
        $session = $interaction['session'];
        $calendar = $session ? MarketSession::describe(CarbonImmutable::parse($session.' 12:00', 'America/New_York')) : [];
        $close = strtotime($calendar['closes_at'] ?? '') ?: 0;
        $open = strtotime($calendar['opens_at'] ?? '') ?: 0;
        $cutoff = strtotime($interaction['evaluated_through'] ?? '') ?: 0;
        $bars = $interaction['bars'] ?? [];
        usort($bars, fn ($a, $b) => $a['t'] <=> $b['t']);
        $contexts = [];
        foreach ($interaction['readings'] ?? [] as $reading) {
            $contexts[$reading['side']][strtotime($reading['observed_at'])] = $reading['wall_identity'];
        }
        $cases = [];
        foreach ($seeds as $seed) {
            if (! $this->validSeed($seed, $interaction) || strtotime($seed['observed_at']) < $open
                || strtotime($seed['observed_at']) > min($close, $cutoff)) {
                continue;
            }
            $qualified = $seed['status'] === 'conditions_met';
            if ($qualified && (! $this->validLevels($seed) || strtotime($seed['confirmed_at']) < $open)) {
                continue;
            }
            $case = ['id' => $seed['id'], 'side' => $seed['side'], 'strike' => $seed['strike'],
                'pattern' => $seed['pattern'], 'direction' => $seed['direction'],
                'evidence_grade' => $seed['evidence_grade'] ?? null, 'observed_at' => $seed['observed_at'],
                'qualified' => $qualified, 'qualified_at' => $qualified ? $seed['observed_at'] : null,
                'reference_close' => $seed['close'], 'entry_zone' => $seed['entry_zone'],
                'invalidation' => $seed['invalidation'], 'target' => $seed['target'],
                'confirmation_scope' => $seed['confirmation_scope'] ?? $seed['scope'],
                'confirmation_wall_hash' => $seed['confirmation_wall_hash'] ?? null,
                'review_basis' => $seed['review_basis'] ?? 'session_replay',
                'first_recorded_at' => $seed['first_recorded_at'] ?? null,
                'assessment_hash' => $seed['assessment_hash'] ?? null,
                'source_price_evidence_hash' => $seed['source_price_evidence_hash'] ?? null,
                'outcome' => $qualified ? 'in_progress' : $this->unqualifiedStatus($seed['status'], $close, $cutoff, $seed['expires_at'] ?? null),
                'reason' => $seed['reason'] ?? 'Watching for the scenario to complete.',
                'outcome_at' => null, 'outcome_close' => null, 'review_until' => null,
                'evidence_bar_ids' => [], 'follow_up_bars' => [], 'overlaps_another_review' => false,
                'historical_outcome_eligible' => false, 'executed_trade' => false];
            if ($qualified) {
                $this->follow($case, $seed, $bars, $contexts[$seed['side']] ?? [], $close, $cutoff);
            }
            $cases[$seed['id']] = $case;
        }
        $cases = array_values($cases);
        usort($cases, fn ($a, $b) => strcmp($a['observed_at'], $b['observed_at']) ?: strcmp($a['id'], $b['id']));
        $until = 0;
        foreach ($cases as &$case) {
            if (! $case['qualified']) {
                continue;
            }
            $case['overlaps_another_review'] = strtotime($case['qualified_at']) < $until;
            $until = max($until, strtotime($case['outcome_at'] ?? $case['review_until']));
        }
        unset($case);
        $counts = array_fill_keys(['target_reached', 'invalidated', 'expired', 'unresolved', 'in_progress', 'entry_missed', 'no_setup', 'watching'], 0);
        foreach ($cases as $case) {
            $counts[$case['outcome']]++;
        }
        $qualifiedCounts = array_fill_keys(array_keys($counts), 0);
        foreach ($cases as $case) {
            if ($case['qualified']) {
                $qualifiedCounts[$case['outcome']]++;
            }
        }

        return ['schema_version' => self::SCHEMA, 'rule_version' => self::VERSION,
            'setup_rule_version' => WallSetupRules::VERSION, 'symbol' => $interaction['symbol'],
            'session' => $session, 'timeframe' => $interaction['timeframe'], 'as_of' => $interaction['as_of'],
            'horizon_minutes' => self::HORIZON_MINUTES, 'outcome_basis' => 'completed_five_minute_close',
            'summary' => ['scenarios' => count($cases), 'qualified' => count(array_filter($cases, fn ($c) => $c['qualified'])),
                'saved_assessments' => count(array_filter($cases, fn ($c) => $c['review_basis'] === 'saved_assessment')),
                'overlapping_reviews' => count(array_filter($cases, fn ($c) => $c['overlaps_another_review'])), 'qualified_outcomes' => $qualifiedCounts, ...$counts],
            'cases' => array_reverse($cases), 'historical_outcome_eligible' => false,
            'win_rate' => null, 'win_probability' => null, 'actionable' => false,
            'interpretation' => 'Recorded price reviews, not executed trades. Counts include ended and unresolved scenarios; overlapping reviews are not independent samples.'];
    }

    private function follow(array &$case, array $seed, array $bars, array $contexts, int $sessionClose, int $cutoff): void
    {
        $start = strtotime($seed['observed_at']);
        $deadline = min($sessionClose, $start + self::HORIZON_MINUTES * 60);
        $case['review_until'] = gmdate('c', $deadline);
        $previous = $start;
        $direction = $seed['direction'] === 'bullish' ? 1 : -1;
        foreach ($bars as $bar) {
            $barStart = (int) ($bar['t'] / 1000);
            $end = $barStart + 300;
            if ($barStart < $start || $end > min($deadline, $cutoff)) {
                continue;
            }
            if ($barStart !== $previous || ! isset($contexts[$end])) {
                $case['outcome'] = 'unresolved';
                $case['reason'] = 'The continuous comparison ended before a target or invalidation close.';
                $case['outcome_at'] = gmdate('c', $previous);

                return;
            }
            if ($contexts[$end] !== $seed['wall_identity']) {
                $case['outcome'] = 'expired';
                $case['reason'] = 'The wall comparison changed before a target or invalidation close.';
                $case['outcome_at'] = gmdate('c', $end);

                return;
            }
            $case['follow_up_bars'][] = ['id' => $bar['id'], 'observed_at' => gmdate('c', $end), 'close' => (float) $bar['c']];
            $case['evidence_bar_ids'][] = $bar['id'];
            $previous = $end;
            $target = $direction * ($bar['c'] - $seed['target']['strike']) >= 0;
            $invalidated = $direction * ($bar['c'] - $seed['invalidation']['price']) < -1e-9;
            if ($target || $invalidated) {
                $case['outcome'] = $target ? 'target_reached' : 'invalidated';
                $case['reason'] = $target ? 'A later five-minute close reached the frozen reference target.' : 'A later five-minute close crossed the frozen invalidation boundary.';
                $case['outcome_at'] = gmdate('c', $end);
                $case['outcome_close'] = (float) $bar['c'];

                return;
            }
        }
        if ($previous >= $deadline) {
            $case['outcome'] = 'expired';
            $case['reason'] = $deadline === $sessionClose ? 'The market session ended before a target or invalidation close.' : 'The one-hour review ended before a target or invalidation close.';
            $case['outcome_at'] = gmdate('c', $deadline);
        } elseif ($cutoff >= $deadline || $cutoff - $previous > WallInteractionRules::profile()['maximum_bar_age_seconds']) {
            $case['outcome'] = 'unresolved';
            $case['reason'] = 'The continuous comparison ended before a target or invalidation close.';
            $case['outcome_at'] = gmdate('c', $previous);
        } else {
            $case['reason'] = 'Following completed closes within the one-hour review window.';
        }
    }

    private function validSeed(array $row, array $context): bool
    {
        return ! empty($row['id']) && is_string($row['id']) && is_string($row['observed_at'] ?? null) && strtotime($row['observed_at']) !== false
            && is_numeric($row['strike'] ?? null) && is_finite((float) $row['strike']) && $row['strike'] > 0
            && is_numeric($row['close'] ?? null) && is_finite((float) $row['close']) && $row['close'] > 0
            && isset($row['status'], $row['entry_zone'], $row['invalidation'])
            && in_array($row['side'] ?? null, ['put', 'call'], true)
            && in_array($row['pattern'] ?? null, ['reversal', 'reclaim', 'continuation'], true)
            && in_array($row['direction'] ?? null, ['bullish', 'bearish'], true)
            && ($row['scope']['symbol'] ?? null) === $context['symbol']
            && ($row['scope']['session'] ?? null) === $context['session']
            && ($row['scope']['timeframe'] ?? null) === $context['timeframe'];
    }

    private function validLevels(array $seed): bool
    {
        foreach ([$seed['close'] ?? null, $seed['entry_zone']['low'] ?? null, $seed['entry_zone']['high'] ?? null,
            $seed['invalidation']['price'] ?? null, $seed['target']['strike'] ?? null] as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value <= 0) {
                return false;
            }
        }
        $bull = $seed['direction'] === 'bullish';
        $low = $seed['entry_zone']['low'];
        $high = $seed['entry_zone']['high'];

        $far = $bull ? $high : $low;
        $risk = abs($far - $seed['invalidation']['price']);
        $room = ($bull ? 1 : -1) * ($seed['target']['strike'] - $far);

        return $low <= $high && $seed['close'] >= $low && $seed['close'] <= $high
            && ($bull ? $seed['invalidation']['price'] < $low && $seed['target']['strike'] > $high
                : $seed['invalidation']['price'] > $high && $seed['target']['strike'] < $low)
            && $risk > 0 && $room / $risk >= WallSetupRules::profile()['minimum_target_to_invalidation_ratio']
            && ($seed['scope']['units'] ?? null) === 'USD_per_1pct_move'
            && ! empty($seed['confirmed_at']) && strtotime($seed['confirmed_at']) <= strtotime($seed['observed_at'])
            && ! empty($seed['wall_identity']);
    }

    private function unqualifiedStatus(string $status, int $close, int $cutoff, ?string $expires): string
    {
        return match ($status) {
            'entry_missed', 'no_setup', 'expired', 'invalidated' => $status,
            default => $cutoff >= $close || ($expires && $cutoff > strtotime($expires)) ? 'expired' : 'watching',
        };
    }
}
