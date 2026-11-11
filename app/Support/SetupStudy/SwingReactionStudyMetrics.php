<?php

namespace App\Support\SetupStudy;

use Carbon\CarbonImmutable;

/** Maps the retained ordered-touch lifecycle. Does not replay bars or assume option fills. */
final class SwingReactionStudyMetrics
{
    public const VERSION = 'price-touch-study.v2';

    public function measure(array $sample, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $native = $sample['native_status'] ?? 'watching';
        $terminal = in_array($native, ['completed', 'cancelled', 'expired', 'ambiguous'], true);
        $price = $this->number($sample['signal_price'] ?? null);
        $invalid = $this->number($sample['invalidation'] ?? null);
        $target = $this->number($sample['target'] ?? null);
        $entered = ! empty($sample['signal_at']) && $price !== null && $price > 0;
        $d = ($sample['direction'] ?? null) === 'bullish' ? 1 : -1;
        $risk = $entered && $invalid !== null ? $d * ($price - $invalid) : null;
        $gap = (bool) ($sample['evidence_gap'] ?? false);
        $ambiguous = (bool) ($sample['ambiguous'] ?? false) || $native === 'ambiguous';
        $badGeometry = $entered && ($risk <= 0 || $target === null || $d * ($target - $price) <= 0);
        $outcomeAt = ! empty($sample['outcome_at']) && strtotime($sample['outcome_at']) !== false
            ? CarbonImmutable::parse($sample['outcome_at'])->utc() : null;
        $entryAt = $entered && strtotime($sample['signal_at']) !== false ? CarbonImmutable::parse($sample['signal_at'])->utc() : null;
        $badOrder = $entered && $terminal && (! $outcomeAt || ! $entryAt || $outcomeAt <= $entryAt || $outcomeAt > $now);
        $needsReview = $gap || $ambiguous || $badGeometry || $badOrder;
        $source = $sample['native_metrics'] ?? [];
        unset($sample['native_metrics']);
        $metrics = [
            'mfe_r' => null, 'mae_r' => null, 'mfe_pct' => null, 'mae_pct' => null,
            'close_mfe_r' => null, 'close_mae_r' => null,
            'reached_05r' => null, 'reached_1r' => null,
            'entry_revisited' => $entered ? true : null, 'entry_observable' => $entered,
            'strict_outcome' => null, 'strict_at' => null,
            'observations' => (int) ($sample['observations'] ?? 0), 'last_at' => $sample['last_at'] ?? null,
            'risk_per_share' => $entered ? $risk : null, 'evidence_gap' => $gap,
            'ambiguous_order' => $ambiguous, 'first_05r_at' => $source['first_05r_at'] ?? null,
            'first_1r_at' => $source['first_1r_at'] ?? null,
            'publication_timing' => $entered ? 'open_at_publication' : 'timing_unknown',
        ];
        if ($entered && ! $badGeometry) {
            foreach (['mfe_r', 'mae_r', 'mfe_pct', 'mae_pct'] as $field) {
                $value = $this->number($source[$field] ?? null);
                $metrics[$field] = $value !== null && $value >= 0 ? round($value, 4) : null;
            }
            $metrics['reached_05r'] = $metrics['mfe_r'] === null ? null : $metrics['mfe_r'] >= .5;
            $metrics['reached_1r'] = $metrics['mfe_r'] === null ? null : $metrics['mfe_r'] >= 1;
            if ($terminal && ! $needsReview) {
                $metrics['strict_outcome'] = match ($sample['native_outcome'] ?? null) {
                    'target_reached' => 'target_reached',
                    'stop_reached' => 'invalidated',
                    'holding_ended' => 'holding_ended',
                    default => null,
                };
                $metrics['strict_at'] = $metrics['strict_outcome'] ? ($sample['outcome_at'] ?? null) : null;
                if (! $metrics['strict_outcome'] || ! $metrics['strict_at']) {
                    $needsReview = true;
                }
            }
        }

        return [...$sample, 'metric_version' => self::VERSION, 'metric_basis' => 'price_touch',
            'qualified' => $entered, 'confirmation_count' => count(array_unique($sample['confirmations'] ?? [])),
            'followup_complete' => $terminal && ! $needsReview,
            'needs_update' => $needsReview,
            'status' => $needsReview ? 'needs_update' : ($entered ? ($terminal ? 'completed' : 'in_progress') : ($terminal ? 'not_qualified' : 'watching')),
            'outcome' => $entered ? $metrics['strict_outcome'] : ($sample['native_outcome'] ?? null),
            'metrics' => $metrics,
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
