<?php

namespace App\Support\WallIntelligence;

/** Explains the existing evaluator state without changing confirmation or eligibility. */
final class WallSetupNextCondition
{
    public static function describe(array $setup, array $row = [], ?array $candidate = null): array
    {
        $status = $setup['status'];
        $base = ['version' => 'wall-next-condition.v1', 'rule_version' => WallSetupRules::VERSION,
            'setup_status' => $status, 'code' => 'price_sequence', 'text' => 'Wait for a completed price sequence at this wall.',
            'completed_closes' => null, 'required_closes' => null, 'threshold' => null, 'close_side' => null,
            'threshold_inclusive' => false];
        $ended = match ($status) {
            'entry_missed' => 'Price moved beyond the entry area. Wait for a new price sequence.',
            'invalidated' => 'This setup ended. Wait for a new price sequence at the current wall.',
            'expired' => 'The setup window ended. Wait for a new price sequence.',
            'target_reached' => 'A later close reached the reference target. Wait for a new price sequence.',
            'no_setup' => $setup['reason'],
            'awaiting_update' => 'Wait for the next completed five-minute assessment. The last setup is not active.',
            'conditions_met' => 'The conditions passed at this recorded close. Check current price against the entry area.',
            default => null,
        };
        if ($ended !== null) {
            return [...$base, 'code' => $status, 'text' => $ended];
        }
        $strike = $setup['strike'] ?? null;
        if (! $setup['pattern'] || ! isset($row['tolerance']) || ! is_numeric($strike)) {
            return [...$base, 'code' => 'wall_reaction', 'text' => ! empty($row['first_touch_time'])
                ? 'Wait for a completed five-minute close away from '.self::price($strike).'.'
                : 'Watch for price to reach the wall, then assess the completed five-minute closes.'];
        }
        $direction = $setup['direction'] === 'bullish' ? 1 : -1;
        $side = $direction === 1 ? 'above' : 'below';
        $threshold = $strike + $direction * $row['tolerance'];
        $boundary = $side.' '.self::price($threshold);
        $base = [...$base, 'threshold' => $threshold, 'close_side' => $side];
        if ($setup['confirmed_at']) {
            return [...$base, 'code' => 'entry_close', 'text' => 'The price sequence passed. Wait for a five-minute close inside '
                .self::price($setup['entry_zone']['low']).'–'.self::price($setup['entry_zone']['high']).' before the entry window ends.'];
        }
        if ($setup['pattern'] === 'continuation') {
            $accepted = $direction === 1 ? ($row['accepted_above'] ?? false) : ($row['accepted_below'] ?? false);
            if (! $accepted) {
                $count = min(1, max(0, (int) ($row['consecutive_closes_beyond'] ?? 0)));

                return [...$base, 'code' => 'acceptance_close', 'completed_closes' => $count, 'required_closes' => 2,
                    'text' => 'Wait for '.($count ? 'a second consecutive' : 'two consecutive').' five-minute close'.($count ? '' : 's').' '.$boundary.'.'];
            }
            if (empty($row['retest_time'])) {
                return [...$base, 'code' => 'retest', 'text' => 'Two closes accepted the break. Wait for a later completed bar to retest '
                    .self::price($strike - $row['tolerance']).'–'.self::price($strike + $row['tolerance']).'.'];
            }
            if (! is_numeric($row['continuation_confirmation_price'] ?? null)) {
                return [...$base, 'code' => 'retest_failure', 'text' => 'The retest is recorded. Wait for a separate five-minute close '.$boundary.'.'];
            }
            $threshold = $row['continuation_confirmation_price'];

            return [...$base, 'code' => 'continuation_close', 'threshold' => $threshold, 'threshold_inclusive' => true,
                'text' => 'The retest closed back on the break side. Wait for a later five-minute close at or '
                    .$side.' '.self::price($threshold).'.'];
        }
        if ($setup['pattern'] === 'reclaim') {
            return empty($candidate['retest_at'])
                ? [...$base, 'code' => 'retest', 'text' => 'Price reclaimed the wall. Wait for a later completed bar to retest '
                    .self::price($strike - $row['tolerance']).'–'.self::price($strike + $row['tolerance']).'.']
                : [...$base, 'code' => 'reclaim_close', 'text' => 'The retest is recorded. Wait for a separate five-minute close '.$boundary.'.'];
        }
        $count = in_array($row['status'] ?? null, ['bounce', 'rejection'], true) ? 1 : 0;

        return [...$base, 'code' => 'reversal_close', 'completed_closes' => $count, 'required_closes' => 2,
            'text' => 'Wait for '.($count ? 'a second consecutive' : 'two consecutive').' five-minute close'.($count ? '' : 's').' '.$boundary.'.'];
    }

    private static function price(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ','), '0'), '.');
    }
}
