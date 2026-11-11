<?php

namespace App\Support\Swing;

use Carbon\CarbonImmutable;

/** A frozen-plan comparison rubric. Tiers do not estimate success probabilities. */
final class SwingIdeaTier
{
    public const VERSION = 'swing-tier.v1';

    private const COMPARISON_EPSILON = 1e-9;

    public function assess(array $plan): array
    {
        $evidence = is_array($plan['evidence'] ?? null) ? $plan['evidence'] : [];
        $levels = is_array($plan['levels'] ?? null) ? $plan['levels'] : [];
        $direction = match ($plan['direction'] ?? null) {
            'bullish' => 1,
            'bearish' => -1,
            default => null,
        };
        $close = $this->positive($evidence['close'] ?? null);
        $geometry = $this->geometry($levels, $direction, $close);
        $maxExit = $this->maxExit($plan);
        $wallRolloff = $maxExit ? $this->wallRolloff($evidence['wall_expiry_shares'] ?? null, $maxExit) : null;
        $targetRolloff = $this->percentage($evidence['target_rolloff_pct'] ?? null);
        $relative = $this->positive($evidence['wall_relative_gex'] ?? $evidence['wall_relative_magnitude'] ?? null);
        $sma20 = $this->positive($evidence['sma20'] ?? null);
        $trend = $direction !== null && $close !== null && $sma20 !== null ? $direction * ($close - $sma20) >= 0 : null;
        $metrics = [...$geometry, 'wall_rolloff_pct' => $wallRolloff, 'target_rolloff_pct' => $targetRolloff,
            'wall_relative_gex' => $relative, 'trend_aligned' => $trend, 'max_exit_date' => $maxExit];

        $gold = $this->meets($metrics, ['reward_risk' => ['min', 2.5], 'risk_pct' => ['max', 3.5],
            'entry_distance_pct' => ['max', 1.5], 'wall_rolloff_pct' => ['max', 40.],
            'target_rolloff_pct' => ['max', 25.], 'wall_relative_gex' => ['min', 2.]]) && $trend === true;
        $silver = $this->meets($metrics, ['reward_risk' => ['min', 1.8], 'risk_pct' => ['max', 6.],
            'entry_distance_pct' => ['max', 5.], 'wall_rolloff_pct' => ['max', 80.],
            'target_rolloff_pct' => ['max', 50.], 'wall_relative_gex' => ['min', 1.5]]);
        $key = $gold ? 'gold' : ($silver ? 'silver' : 'bronze');

        return ['version' => self::VERSION, 'key' => $key, 'label' => ucfirst($key),
            'rank' => $gold ? 1 : ($silver ? 2 : 3),
            'headline' => match ($key) {
                'gold' => 'Nearby entry, defined risk and more durable wall exposure.',
                'silver' => 'Useful planned geometry with tradeoffs to review.',
                default => 'Review the entry distance, risk and exposure timing.',
            },
            'reasons' => $this->reasons($metrics), 'tradeoffs' => $this->tradeoffs($metrics), 'metrics' => $metrics];
    }

    private function geometry(array $levels, ?int $direction, ?float $close): array
    {
        $empty = ['reward_risk' => null, 'risk_pct' => null, 'target_pct' => null, 'entry_distance_pct' => null];
        $low = $this->positive($levels['entry_low'] ?? null);
        $high = $this->positive($levels['entry_high'] ?? null);
        $stop = $this->positive($levels['invalidation'] ?? null);
        $target = $this->positive($levels['target'] ?? null);
        if ($direction === null || $low === null || $high === null || $stop === null || $target === null || $low > $high
            || ($direction === 1 ? $stop >= $low || $target <= $high : $stop <= $high || $target >= $low)) {
            return $empty;
        }
        $entry = $direction === 1 ? $high : $low;
        $risk = $direction * ($entry - $stop);
        $reward = $direction * ($target - $entry);
        $values = ['reward_risk' => $reward / $risk, 'risk_pct' => ($risk / $entry) * 100,
            'target_pct' => ($reward / $entry) * 100,
            'entry_distance_pct' => $close !== null ? (max($low - $close, $close - $high, 0) / $close) * 100 : null];
        foreach ($values as $value) {
            if ($value !== null && ! is_finite($value)) {
                return $empty;
            }
        }

        return $values;
    }

    private function maxExit(array $plan): ?string
    {
        $deadline = $plan['entry_deadline'] ?? null;
        $sessions = $this->positive($plan['max_holding_sessions'] ?? null);
        if (! is_string($deadline) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/D', $deadline)
            || ! $this->date(substr($deadline, 0, 10)) || $sessions === null || floor($sessions) !== $sessions || $sessions > 30) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($deadline)->setTimezone('America/New_York')->toDateString();

            return SwingIdeaRules::sessionAfter($date, (int) $sessions);
        } catch (\Throwable) {
            return null;
        }
    }

    private function wallRolloff(mixed $shares, string $maxExit): ?float
    {
        if (! is_array($shares) || ! $shares || count($shares) > 1000) {
            return null;
        }
        $weights = [];
        foreach ($shares as $row) {
            if (! is_array($row)) {
                return null;
            }
            $date = $this->date($row['expiration'] ?? null);
            $weight = $this->number($row['share_pct'] ?? null);
            if ($date === null || $weight === null || $weight < 0) {
                return null;
            }
            // Aggregate repeated expirations, then normalize the observed weights.
            $weights[$date] = ($weights[$date] ?? 0.) + $weight;
        }
        $sum = array_sum($weights);
        if (! is_finite($sum) || $sum <= 0) {
            return null;
        }
        $rolling = 0.;
        foreach ($weights as $date => $weight) {
            if ($date < $maxExit) {
                $rolling += $weight;
            }
        }

        return is_finite($rolling) ? min(100., max(0., ($rolling / $sum) * 100)) : null;
    }

    private function meets(array $metrics, array $rules): bool
    {
        foreach ($rules as $field => [$comparison, $threshold]) {
            $value = $metrics[$field];
            if ($value === null || ($comparison === 'min'
                ? $value + self::COMPARISON_EPSILON < $threshold
                : $value - self::COMPARISON_EPSILON > $threshold)) {
                return false;
            }
        }

        return true;
    }

    private function reasons(array $metrics): array
    {
        $reasons = [];
        if ($metrics['reward_risk'] !== null && $metrics['reward_risk'] >= 1.8) {
            $reasons[] = 'The planned target offers '.$this->format($metrics['reward_risk']).'× the distance to the risk boundary.';
        }
        if ($metrics['risk_pct'] !== null && $metrics['risk_pct'] <= 6) {
            $reasons[] = 'The risk boundary is '.$this->format($metrics['risk_pct']).'% from the conservative entry reference.';
        }
        if ($metrics['wall_rolloff_pct'] !== null && $metrics['wall_rolloff_pct'] <= 40) {
            $reasons[] = $this->format(100 - $metrics['wall_rolloff_pct']).'% of the watched-wall exposure remains through the maximum review window.';
        } elseif ($metrics['entry_distance_pct'] !== null && $metrics['entry_distance_pct'] <= 5) {
            $reasons[] = $metrics['entry_distance_pct'] == 0
                ? 'The original EOD price is inside the planned entry area; the price sequence still needs confirmation.'
                : 'The entry area is '.$this->format($metrics['entry_distance_pct']).'% from the original EOD price.';
        }

        return array_slice($reasons, 0, 3);
    }

    private function tradeoffs(array $metrics): array
    {
        $tradeoffs = [];
        if ($metrics['wall_rolloff_pct'] === null) {
            $tradeoffs[] = 'Watched-wall exposure timing cannot be established from the recorded expiry shares.';
        } elseif ($metrics['wall_rolloff_pct'] - self::COMPARISON_EPSILON > 40) {
            $tradeoffs[] = $this->format($metrics['wall_rolloff_pct']).'% of the watched-wall exposure expires before the maximum review ends.';
        }
        if ($metrics['trend_aligned'] === false) {
            $tradeoffs[] = 'The idea opposes the original price position relative to its 20-day average.';
        } elseif ($metrics['trend_aligned'] === null) {
            $tradeoffs[] = 'The original 20-day trend comparison is not established.';
        }
        foreach (['entry_distance_pct' => [1.5, 'The entry area is %s%% from the original EOD price.'],
            'risk_pct' => [3.5, 'The planned risk-boundary distance is wider at %s%%.'],
            'target_rolloff_pct' => [25., '%s%% of the target exposure expires before the maximum review ends.']] as $field => [$threshold, $text]) {
            if ($metrics[$field] !== null && $threshold < $metrics[$field] - self::COMPARISON_EPSILON) {
                $tradeoffs[] = sprintf($text, $this->format($metrics[$field]));
            }
        }
        if ($metrics['reward_risk'] !== null && $metrics['reward_risk'] + self::COMPARISON_EPSILON < 2.5) {
            $tradeoffs[] = 'Planned target room is '.$this->format($metrics['reward_risk']).'× the risk distance.';
        }
        if ($metrics['wall_relative_gex'] !== null && $metrics['wall_relative_gex'] + self::COMPARISON_EPSILON < 2) {
            $tradeoffs[] = 'Watched-wall exposure is '.$this->format($metrics['wall_relative_gex']).'× the average of nearby strikes.';
        }
        if (in_array(null, array_intersect_key($metrics, array_flip(['reward_risk', 'risk_pct', 'entry_distance_pct', 'target_rolloff_pct', 'wall_relative_gex'])), true)) {
            $tradeoffs[] = 'Some recorded price or exposure inputs do not establish the stronger comparison tiers.';
        }

        return array_slice($tradeoffs, 0, 3);
    }

    private function percentage(mixed $value): ?float
    {
        $number = $this->number($value);

        return $number !== null && $number >= 0 && $number <= 100 ? $number : null;
    }

    private function positive(mixed $value): ?float
    {
        $number = $this->number($value);

        return $number !== null && $number > 0 ? $number : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'America/New_York');

            return $date && $date->toDateString() === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
