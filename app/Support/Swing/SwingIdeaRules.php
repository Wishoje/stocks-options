<?php

namespace App\Support\Swing;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;

/** Initial research rules. No estimated fills, option returns or success probabilities. */
final class SwingIdeaRules
{
    public const VERSION = 'swing-wall-reclaim.v1.1';

    public const HORIZONS = [
        'short' => ['label' => '2–5 trading days', 'max_sessions' => 5, 'entry_sessions' => 3, 'scope_days' => 30, 'scope_label' => '1M'],
        'position' => ['label' => '1–3 weeks', 'max_sessions' => 15, 'entry_sessions' => 5, 'scope_days' => 90, 'scope_label' => '3M'],
    ];

    public static function sessionAfter(string $date, int $count): string
    {
        $day = CarbonImmutable::parse($date, 'America/New_York');
        while ($count > 0) {
            $day = $day->addDay();
            if (MarketSession::isTradingDay($day)) {
                $count--;
            }
        }

        return $day->toDateString();
    }

    public function propose(array $input, string $horizon): array
    {
        $profile = self::HORIZONS[$horizon];
        $date = $input['data_date'];
        $base = ['rule_version' => self::VERSION, 'symbol' => $input['symbol'], 'horizon' => $horizon,
            'holding_label' => $profile['label'], 'scope_label' => $profile['scope_label'], 'source_date' => $date,
            'plan_session' => self::sessionAfter($date, 1), 'direction' => null, 'status' => 'no_idea',
            'entry_deadline' => self::sessionAfter($date, $profile['entry_sessions']),
            'max_holding_sessions' => $profile['max_sessions'], 'pattern' => 'wall_pullback_reclaim',
            'event_review' => 'Check upcoming earnings and major events before holding overnight.',
            'evidence' => [], 'levels' => null, 'reason' => 'No qualifying swing plan for this reading.'];
        $prices = array_values(array_filter($input['prices'] ?? [], fn ($p) => $p['date'] <= $date));
        usort($prices, fn ($a, $b) => strcmp($a['date'], $b['date']));
        if (count($prices) < 50 || end($prices)['date'] !== $date) {
            return [...$base, 'reason' => 'A current daily trend comparison is not available.'];
        }
        $dates = array_column($prices, 'date');
        if (count(array_unique($dates)) !== count($dates)) {
            return [...$base, 'reason' => 'The daily price comparison needs review.'];
        }
        for ($i = 0; $i < 50; $i++) {
            $expected = $i === 0 ? $date : MarketSession::tradingDateOnOrBefore(CarbonImmutable::parse($expected, 'America/New_York')->subDay());
            if (! in_array($expected, $dates, true)) {
                return [...$base, 'reason' => 'A continuous daily trend comparison is not available.'];
            }
        }
        foreach ($prices as $p) {
            foreach (['open', 'high', 'low', 'close'] as $field) {
                if (! is_numeric($p[$field] ?? null) || ! is_finite((float) $p[$field]) || $p[$field] <= 0) {
                    return [...$base, 'reason' => 'The daily price comparison needs review.'];
                }
            }
            if ($p['high'] < max($p['open'], $p['close']) || $p['low'] > min($p['open'], $p['close'])) {
                return [...$base, 'reason' => 'The daily price comparison needs review.'];
            }
        }
        $closes = array_column($prices, 'close');
        $close = (float) end($closes);
        $sma20 = array_sum(array_slice($closes, -20)) / 20;
        $sma50 = array_sum(array_slice($closes, -50)) / 50;
        $return5 = $close / $closes[count($closes) - 6] - 1;
        $ranges = [];
        for ($i = count($prices) - 14; $i < count($prices); $i++) {
            $bar = $prices[$i];
            $prior = $prices[$i - 1]['close'];
            // Do not bridge a likely adjustment discontinuity as an ordinary swing.
            if (abs($bar['close'] / $prior - 1) > .3) {
                return [...$base, 'reason' => 'A large price discontinuity needs review before a new plan.'];
            }
            $ranges[] = max($bar['high'] - $bar['low'], abs($bar['high'] - $prior), abs($bar['low'] - $prior));
        }
        $atr = array_sum($ranges) / 14;
        $d = $close > $sma20 && $sma20 > $sma50 && $return5 > 0 ? 1
            : ($close < $sma20 && $sma20 < $sma50 && $return5 < 0 ? -1 : 0);
        $evidence = ['close' => $close, 'sma20' => $sma20, 'sma50' => $sma50, 'atr14' => $atr,
            'return5_pct' => $return5 * 100, 'volatility' => $input['volatility'] ?? null,
            'benchmark' => $input['benchmark'] ?? null];
        $base['evidence'] = $evidence;
        if (! $d || $atr <= 0) {
            return [...$base, 'reason' => 'The daily trend and recent price movement do not align.'];
        }
        $base['direction'] = $d === 1 ? 'bullish' : 'bearish';
        $scopeEnd = CarbonImmutable::parse($date, 'America/New_York')->addDays($profile['scope_days'])->toDateString();
        $strikes = [];
        $expiryTotals = [];
        $known = $covered = 0.;
        foreach ($input['strikes'] ?? [] as $row) {
            $cells = array_values(array_filter($row['expirations'], fn ($c) => $c['expiration'] >= $base['plan_session'] && $c['expiration'] <= $scopeEnd));
            if (! $cells) {
                continue;
            }
            foreach ($cells as $cell) {
                if ($cell['data_date'] !== $date) {
                    return [...$base, 'reason' => 'The options comparison is not aligned with this EOD reading.'];
                }
                $known += $cell['known_oi'];
                $covered += $cell['covered_oi'];
                $expiryTotals[$cell['expiration']] = ($expiryTotals[$cell['expiration']] ?? 0) + abs($cell['net_gex']);
            }
            $strikes[] = ['strike' => (float) $row['strike'], 'net_gex' => array_sum(array_column($cells, 'net_gex')), 'cells' => $cells];
        }
        if ($known <= 0 || $covered / $known < .98) {
            return [...$base, 'reason' => 'A qualifying options comparison is not available for this plan.'];
        }
        $base['evidence']['net_gex'] = array_sum(array_column($strikes, 'net_gex'));
        $dexRows = array_values(array_filter($input['dex'] ?? [], fn ($r) => $r['expiration'] >= $base['plan_session'] && $r['expiration'] <= $scopeEnd));
        $base['evidence']['dex_shares'] = $dexRows ? array_sum(array_column($dexRows, 'value')) : null;
        $base['evidence']['expiry_totals'] = $expiryTotals;
        $walls = array_values(array_filter($strikes, fn ($r) => $d * $r['net_gex'] < 0));
        usort($walls, fn ($a, $b) => abs($b['net_gex']) <=> abs($a['net_gex']) ?: $a['strike'] <=> $b['strike']);
        $near = array_values(array_filter(array_slice($walls, 0, 3), fn ($r) => $d * ($close - $r['strike']) >= 0 && abs($close - $r['strike']) <= 2 * $atr));
        usort($near, fn ($a, $b) => abs($a['strike'] - $close) <=> abs($b['strike'] - $close));
        if (! $near) {
            return [...$base, 'reason' => 'Price is not near a ranked wall that fits the daily trend.'];
        }
        $wall = $near[0];
        $trigger = $wall['strike'] + $d * .1 * $atr;
        $entry = [$trigger, $trigger + $d * .35 * $atr];
        sort($entry);
        $invalid = $wall['strike'] - $d * .75 * $atr;
        $far = $d === 1 ? $entry[1] : $entry[0];
        $opposing = array_values(array_filter($strikes, fn ($r) => $d * $r['net_gex'] > 0));
        usort($opposing, fn ($a, $b) => abs($b['net_gex']) <=> abs($a['net_gex']) ?: $a['strike'] <=> $b['strike']);
        $targets = array_values(array_filter(array_slice($opposing, 0, 3), fn ($r) => $d * ($r['strike'] - $far) > 0));
        usort($targets, fn ($a, $b) => abs($a['strike'] - $far) <=> abs($b['strike'] - $far));
        if (! $targets) {
            return [...$base, 'reason' => 'No recorded target supports this direction.'];
        }
        $target = $targets[0];
        $room = $d * ($target['strike'] - $far);
        $risk = abs($far - $invalid);
        if ($risk <= 0 || $room / $risk < 1.5) {
            return [...$base, 'reason' => 'The nearest opposing level leaves too little room after entry.'];
        }
        if ($room > ($horizon === 'short' ? 4 : 8) * $atr) {
            return [...$base, 'reason' => 'The nearest supported target is too distant for this holding window.'];
        }
        $maxExit = self::sessionAfter($base['entry_deadline'], $profile['max_sessions']);
        $absolute = array_sum(array_map(fn ($c) => abs($c['net_gex']), $target['cells']));
        $rolloff = array_sum(array_map(fn ($c) => $c['expiration'] < $maxExit ? abs($c['net_gex']) : 0, $target['cells']));
        if ($absolute <= 0 || $rolloff / $absolute > .5) {
            return [...$base, 'reason' => 'Most exposure at the nearest target expires before this plan could finish.'];
        }
        $oi = $input['prior_oi'] ?? [];
        $currentOi = $priorOi = 0.;
        $comparable = true;
        foreach ($wall['cells'] as $cell) {
            $prior = $oi[$cell['expiration'].':'.$wall['strike']] ?? null;
            if (! $prior || $prior['count'] !== $cell['contract_rows'] || $prior['invalid'] || $cell['excluded_rows']) {
                $comparable = false;
                break;
            }
            $currentOi += $cell['known_oi'];
            $priorOi += $prior['oi'];
        }
        $base['evidence']['oi_change'] = $comparable ? ['contracts' => $currentOi - $priorOi, 'pct' => $priorOi > 0 ? 100 * ($currentOi - $priorOi) / $priorOi : null] : null;
        $base['evidence']['target_rolloff_pct'] = 100 * $rolloff / $absolute;
        $base['evidence']['target_room_ratio'] = $room / $risk;

        return [...$base, 'status' => 'watching', 'reason' => 'Wait for a wall test and a daily close back inside the entry area.',
            'levels' => ['wall' => $wall['strike'], 'trigger' => $trigger, 'touch_low' => $wall['strike'] - .1 * $atr,
                'touch_high' => $wall['strike'] + .1 * $atr, 'entry_low' => $entry[0], 'entry_high' => $entry[1],
                'invalidation' => $invalid, 'target' => $target['strike'], 'invalidation_side' => $d === 1 ? 'below' : 'above'],
            'touched_at' => null, 'qualified_at' => null, 'qualified_price' => null, 'outcome' => null, 'deadline' => null];
    }

    public function advance(array $plan, array $bar): array
    {
        if (! in_array($plan['status'], ['watching', 'conditions_met', 'in_progress'], true) || $bar['date'] <= ($plan['reviewed_through'] ?? $plan['source_date'])) {
            return $plan;
        }
        $expected = self::sessionAfter($plan['reviewed_through'] ?? $plan['source_date'], 1);
        if ($bar['date'] !== $expected || ! is_numeric($bar['close'] ?? null) || $bar['close'] <= 0) {
            return [...$plan, 'status' => 'needs_update', 'reason' => 'The next daily comparison needs review.'];
        }
        if (! is_numeric($bar['high'] ?? null) || ! is_numeric($bar['low'] ?? null) || ! is_finite((float) $bar['high'])
            || ! is_finite((float) $bar['low']) || $bar['low'] <= 0 || $bar['high'] < $bar['close'] || $bar['low'] > $bar['close']) {
            return [...$plan, 'status' => 'needs_update', 'reason' => 'The next daily comparison needs review.'];
        }
        $plan['reviewed_through'] = $bar['date'];
        $d = $plan['direction'] === 'bullish' ? 1 : -1;
        $l = $plan['levels'];
        if ($plan['status'] === 'watching' && $bar['date'] > $plan['entry_deadline']) {
            return $this->finish($plan, 'expired', 'The entry window ended before the conditions passed.', $bar);
        }
        if ($bar['date'] < ($plan['activation_session'] ?? $plan['plan_session'])) {
            return $plan;
        }
        if ($d * ($bar['close'] - $l['invalidation']) < 0) {
            return $this->finish($plan, 'invalidated', 'A daily close crossed the frozen invalidation level.', $bar);
        }
        if ($plan['status'] === 'watching') {
            if ($bar['date'] > $plan['entry_deadline']) {
                return $this->finish($plan, 'expired', 'The entry window ended before the conditions passed.', $bar);
            }
            if ($bar['low'] <= $l['touch_high'] && $bar['high'] >= $l['touch_low']) {
                $plan['touched_at'] ??= $bar['date'];
            }
            if ($plan['touched_at'] && $bar['close'] >= $l['entry_low'] && $bar['close'] <= $l['entry_high']) {
                return [...$plan, 'status' => 'conditions_met', 'qualified_at' => $bar['date'], 'qualified_price' => $bar['close'],
                    'deadline' => self::sessionAfter($bar['date'], $plan['max_holding_sessions']),
                    'reason' => 'A wall test and daily reclaim close met the conditions. Check current price against the recorded entry area.'];
            }
            $far = $d === 1 ? $l['entry_high'] : $l['entry_low'];
            if ($plan['touched_at'] && $d * ($bar['close'] - $far) > 0) {
                return $this->finish($plan, 'entry_missed', 'The reclaim close moved beyond the entry area.', $bar);
            }

            return $plan;
        }
        if ($d * ($bar['close'] - $l['target']) >= 0) {
            return $this->finish($plan, 'target_reached', 'A later daily close reached the frozen reference target.', $bar);
        }
        if ($bar['date'] >= $plan['deadline']) {
            return $this->finish($plan, 'expired', 'The holding window ended before a target or invalidation close.', $bar);
        }

        return [...$plan, 'status' => 'in_progress', 'reason' => 'Following daily closes between the frozen target and invalidation. No position is assumed.'];
    }

    private function finish(array $plan, string $outcome, string $reason, array $bar): array
    {
        return [...$plan, 'status' => 'finished', 'outcome' => $outcome, 'reason' => $reason, 'outcome_at' => $bar['date'], 'outcome_close' => $bar['close']];
    }
}
