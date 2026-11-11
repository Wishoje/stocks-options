<?php

namespace App\Support\Swing;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;

/** Ordered, published price-range observations. No assumed fills or option P&L. */
final class SwingReactionRules
{
    public const VERSION = 'swing-wall-reaction.v2';

    public const HORIZONS = SwingIdeaRules::HORIZONS;

    private const TERMINAL = ['completed', 'cancelled', 'expired', 'ambiguous'];

    public function propose(array $input, string $horizon, CarbonImmutable $availableAt): array
    {
        if (! isset(self::HORIZONS[$horizon])) {
            throw new \InvalidArgumentException('Unknown swing holding period.');
        }
        $profile = self::HORIZONS[$horizon];
        $date = $this->date($input['data_date'] ?? null);
        $prices = $this->prices($input['prices'] ?? [], $date);
        if (! $date || ! $prices || ! preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/D', $input['symbol'] ?? '')) {
            return [];
        }
        if ($availableAt->lt($this->sessionClose($date))) {
            return [];
        }
        $close = $prices[array_key_last($prices)]['close'];
        $ranges = [];
        for ($i = count($prices) - 14; $i < count($prices); $i++) {
            $prior = $prices[$i - 1]['close'];
            $bar = $prices[$i];
            if (abs($bar['close'] / $prior - 1) > .3) {
                return [];
            }
            $ranges[] = max($bar['high'] - $bar['low'], abs($bar['high'] - $prior), abs($bar['low'] - $prior));
        }
        $atr = array_sum($ranges) / 14;
        if (! is_finite($atr) || $atr <= 0) {
            return [];
        }
        $start = SwingIdeaRules::sessionAfter($date, 1);
        $entryDate = SwingIdeaRules::sessionAfter($date, $profile['entry_sessions']);
        $entryDeadline = $this->sessionClose($entryDate);
        if ($availableAt->gte($entryDeadline)) {
            return [];
        }
        $scopeEnd = CarbonImmutable::parse($date, 'America/New_York')->addDays($profile['scope_days'])->toDateString();
        $strikes = $this->strikes($input['strikes'] ?? [], $date, $start, $scopeEnd);
        if (! $strikes) {
            return [];
        }
        $ranked = [];
        foreach (['put' => -1, 'call' => 1] as $side => $sign) {
            $ranked[$side] = array_values(array_filter($strikes, fn ($r) => $sign * $r['net_gex'] > 0));
            usort($ranked[$side], fn ($a, $b) => abs($b['net_gex']) <=> abs($a['net_gex']) ?: $a['strike'] <=> $b['strike']);
            $ranked[$side] = array_slice($ranked[$side], 0, 3);
        }
        $significant = array_values(array_filter(array_merge(...array_values($ranked)), fn ($r) => ($r['relative_magnitude'] ?? 0) >= 1.5));
        $closes = array_column($prices, 'close');
        $context = ['close' => $close, 'atr14' => $atr,
            'sma20' => count($closes) >= 20 ? array_sum(array_slice($closes, -20)) / 20 : null,
            'sma50' => count($closes) >= 50 ? array_sum(array_slice($closes, -50)) / 50 : null,
            'return5_pct' => 100 * ($close / $closes[count($closes) - 6] - 1),
            'net_gex' => array_sum(array_column($strikes, 'net_gex')),
            'dex_shares' => $this->dex($input['dex'] ?? [], $start, $scopeEnd),
            'volatility' => $input['volatility'] ?? null, 'benchmark' => $input['benchmark'] ?? null,
            'trend_role' => 'context_only', 'inventory_convention' => 'call_minus_put',
            'unit' => 'USD_per_1pct_move'];
        $plans = [];
        foreach ($ranked as $side => $walls) {
            $near = array_values(array_filter($walls, fn ($r) => ($r['relative_magnitude'] ?? 0) >= 1.5 && abs($r['strike'] - $close) <= 2 * $atr));
            usort($near, fn ($a, $b) => abs($a['strike'] - $close) <=> abs($b['strike'] - $close) ?: $a['strike'] <=> $b['strike']);
            $wall = $near[0] ?? null;
            if (! $wall) {
                continue;
            }
            foreach (['hold_reclaim', 'break_retest'] as $pattern) {
                $d = ($side === 'put' ? 1 : -1) * ($pattern === 'hold_reclaim' ? 1 : -1);
                $trigger = $wall['strike'] + $d * .15 * $atr;
                $entry = [$trigger, $wall['strike'] + $d * .5 * $atr];
                sort($entry);
                $far = $d === 1 ? $entry[1] : $entry[0];
                $invalid = $wall['strike'] - $d * .75 * $atr;
                if (min([...$entry, $invalid]) <= 0) {
                    continue;
                }
                // Select the first ranked obstacle, including one inside the proposed entry area.
                // Reject poor geometry rather than skipping that obstacle for a farther target.
                $targets = array_values(array_filter($significant, fn ($r) => $d * ($r['strike'] - $wall['strike']) > 0));
                usort($targets, fn ($a, $b) => abs($a['strike'] - $wall['strike']) <=> abs($b['strike'] - $wall['strike']));
                $target = $targets[0] ?? null;
                $risk = abs($far - $invalid);
                $room = $target ? $d * ($target['strike'] - $far) : 0;
                if (! $target || $risk <= 0 || $room / $risk < 1.5 || $room > ($horizon === 'short' ? 4 : 8) * $atr) {
                    continue;
                }
                $maxExit = SwingIdeaRules::sessionAfter($entryDate, $profile['max_sessions']);
                $absolute = array_sum(array_map(fn ($c) => abs($c['net_gex']), $target['cells']));
                $rolloff = array_sum(array_map(fn ($c) => $c['expiration'] < $maxExit ? abs($c['net_gex']) : 0, $target['cells']));
                if (! is_finite($absolute) || ! is_finite($rolloff) || $absolute <= 0 || $rolloff / $absolute > .5) {
                    continue;
                }
                $expiryAbsolute = array_sum(array_map(fn ($c) => abs($c['net_gex']), $wall['cells']));
                if (! is_finite($expiryAbsolute)) {
                    continue;
                }
                $expiryShares = array_map(fn ($c) => ['expiration' => $c['expiration'], 'net_gex' => $c['net_gex'],
                    'share_pct' => $expiryAbsolute > 0 ? 100 * abs($c['net_gex']) / $expiryAbsolute : null], $wall['cells']);
                $phase = $pattern === 'hold_reclaim' ? 'awaiting_touch' : 'awaiting_acceptance';
                $plans[] = ['rule_version' => self::VERSION, 'metric_basis' => 'price_touch', 'symbol' => $input['symbol'],
                    'horizon' => $horizon, 'holding_label' => $profile['label'], 'scope_label' => $profile['scope_label'],
                    'source_date' => $date, 'plan_session' => $start, 'pattern' => $pattern,
                    'source_version' => $input['source_version'] ?? null, 'volatility_version' => $input['volatility_version'] ?? null,
                    'direction' => $d === 1 ? 'bullish' : 'bearish', 'side' => $side,
                    'status' => 'watching', 'phase' => $phase, 'available_at' => $this->iso($availableAt),
                    'first_recorded_at' => $this->iso($availableAt), 'entry_deadline' => $this->iso($entryDeadline),
                    'max_holding_sessions' => $profile['max_sessions'], 'holding_deadline' => null,
                    'levels' => ['wall' => $wall['strike'], 'touch_low' => $wall['strike'] - .1 * $atr,
                        'touch_high' => $wall['strike'] + .1 * $atr, 'trigger' => $trigger,
                        'entry_low' => $entry[0], 'entry_high' => $entry[1], 'invalidation' => $invalid,
                        'invalidation_side' => $d === 1 ? 'below' : 'above', 'target' => $target['strike']],
                    'evidence' => [...$context, 'wall_net_gex' => $wall['net_gex'],
                        'wall_relative_magnitude' => $wall['relative_magnitude'],
                        'wall_relative_gex' => $wall['relative_magnitude'],
                        'neighbor_mean_abs_gex' => $wall['neighbor_mean_abs_gex'], 'wall_expiry_shares' => $expiryShares,
                        'target_net_gex' => $target['net_gex'], 'target_room_ratio' => $room / $risk,
                        'target_rolloff_pct' => 100 * $rolloff / $absolute],
                    'why' => 'A nearby ranked '.$side.' wall has '.round($wall['relative_magnitude'], 2).' times the average exposure of nearby strikes. The next significant level leaves '.round($room / $risk, 2).' times the room to the risk boundary.',
                    'reason' => 'Wait for the recorded wall reaction, then a separate observed touch of the entry area.',
                    'next_condition' => $pattern === 'hold_reclaim' ? 'Watch for a wall touch, then a separate price bar entirely beyond the reaction trigger.' : 'Watch for three consecutive price bars entirely beyond the wall, a separate retest, then a separate reaction away.',
                    'acceptance_bars' => 0, 'episode_started_at' => null, 'touched_at' => null, 'retest_at' => null,
                    'qualified_at' => null, 'qualified_price' => null, 'ready_recorded_at' => null,
                    'entry_at' => null, 'entry_price' => null, 'outcome' => null, 'outcome_at' => null,
                    'evidence_gap' => false, 'observations' => 0, 'last_at' => null, 'events' => [], 'metrics' => null];
            }
        }

        return $plans;
    }

    public function advance(array $plan, array $bar, CarbonImmutable $recordedAt): array
    {
        if (in_array($plan['status'] ?? null, self::TERMINAL, true)) {
            return $plan;
        }
        $normalized = $this->bar($bar, $recordedAt);
        $available = $this->instant($plan['available_at'] ?? null);
        if (! $normalized || ! $available || $normalized['start']->lt($available)
            || ($last = $this->instant($plan['last_bar_at'] ?? null)) && $normalized['end']->lte($last)) {
            return $plan;
        }
        if (($previousRecord = $this->instant($plan['last_recorded_at'] ?? null)) && $recordedAt->lt($previousRecord)) {
            return $plan;
        }
        $deadline = $this->instant(($plan['status'] ?? null) === 'entry_touched' ? ($plan['holding_deadline'] ?? null) : ($plan['entry_deadline'] ?? null));
        if ($deadline && $normalized['end']->gt($deadline)) {
            return $this->expire($plan, $normalized['end']);
        }
        $gap = $last && ! $normalized['start']->equalTo($this->nextStart($last));
        if ($gap) {
            if (in_array($plan['status'], ['ready', 'entry_touched'], true)) {
                $plan['evidence_gap'] = true;
            } else {
                $plan['phase'] = $plan['pattern'] === 'hold_reclaim' ? 'awaiting_touch' : 'awaiting_acceptance';
                $plan['acceptance_bars'] = 0;
                $plan['episode_started_at'] = $plan['touched_at'] = $plan['retest_at'] = null;
            }
            $plan = $this->event($plan, 'evidence_gap', $normalized, $recordedAt);
        }
        $plan['last_bar_at'] = $plan['last_at'] = $plan['reviewed_through'] = $this->iso($normalized['end']);
        $plan['observations'] = ($plan['observations'] ?? 0) + 1;
        $plan['last_recorded_at'] = $this->iso($recordedAt);
        $previousPrice = $this->number($plan['last_price'] ?? $plan['evidence']['close'] ?? null);
        $plan['last_price'] = $normalized['close'];
        if ($previousPrice !== null && $previousPrice > 0 && abs($normalized['open'] / $previousPrice - 1) > .3) {
            $plan['evidence_discontinuity'] = $plan['evidence_gap'] = true;
            $plan = $this->event($plan, 'price_discontinuity', $normalized, $recordedAt);
            $plan = $this->finish($plan, 'ambiguous', 'ambiguous', $normalized['end']);
            $plan['reason'] = 'A large price discontinuity requires review before the original price levels can be compared. No win or loss is assigned.';

            return $plan;
        }
        if ($gap && $plan['status'] === 'ready') {
            return $this->finish($plan, 'ambiguous', 'ambiguous', $normalized['end']);
        }
        $l = $plan['levels'];
        $d = $plan['direction'] === 'bullish' ? 1 : -1;
        $stop = $d === 1 ? $normalized['low'] <= $l['invalidation'] : $normalized['high'] >= $l['invalidation'];
        $target = $d === 1 ? $normalized['high'] >= $l['target'] : $normalized['low'] <= $l['target'];
        $overlap = $normalized['high'] >= $l['entry_low'] && $normalized['low'] <= $l['entry_high'];
        if ($plan['status'] === 'entry_touched') {
            if ($stop || $target) {
                $uncertain = ($stop && $target) || $plan['evidence_gap'];
                // An outcome bar may move farther after the boundary was touched.
                // Only the first known boundary contributes to excursion metrics.
                if (! $uncertain) {
                    $plan = $this->metrics($plan, $normalized, $stop ? 'stop' : 'target');
                }

                return $this->finish($plan, $uncertain ? 'ambiguous' : 'completed',
                    $uncertain ? 'ambiguous' : ($stop ? 'stop_reached' : 'target_reached'), $normalized['end']);
            }

            return $plan['evidence_gap'] ? $plan : $this->metrics($plan, $normalized);
        }
        if ($plan['status'] === 'ready') {
            $ready = $this->instant($plan['ready_recorded_at'] ?? null);
            // Historical bars that elapsed before the ready assessment was recorded cannot be entries.
            if (! $ready || $normalized['start']->lt($ready)) {
                if ($stop || $target) {
                    return $this->finish($plan, ($stop && $target) || $plan['evidence_gap'] ? 'ambiguous' : 'cancelled',
                        ($stop && $target) || $plan['evidence_gap'] ? 'ambiguous' : ($stop ? 'stop_before_entry' : 'target_before_entry'), $normalized['end']);
                }

                return $plan;
            }
            if ($overlap && ($stop || $target)) {
                return $this->finish($plan, 'ambiguous', 'ambiguous', $normalized['end']);
            }
            if ($stop || $target) {
                return $this->finish($plan, $plan['evidence_gap'] ? 'ambiguous' : 'cancelled',
                    $plan['evidence_gap'] ? 'ambiguous' : ($stop ? 'stop_before_entry' : 'target_before_entry'), $normalized['end']);
            }
            if ($overlap) {
                $plan['status'] = 'entry_touched';
                $plan['phase'] = 'monitoring';
                $plan['entry_at'] = $this->iso($normalized['end']);
                $plan['entry_recorded_at'] = $this->iso($recordedAt);
                $plan['entry_price'] = $d === 1 ? $l['entry_high'] : $l['entry_low'];
                $date = $normalized['end']->setTimezone('America/New_York')->toDateString();
                $plan['holding_deadline'] = $this->iso($this->sessionClose(SwingIdeaRules::sessionAfter($date, $plan['max_holding_sessions'])));
                $plan['metrics'] = ['mfe_pct' => 0., 'mae_pct' => 0., 'mfe_r' => 0., 'mae_r' => 0., 'reached_05r' => false, 'reached_1r' => false];
                $plan['next_condition'] = 'Follow observed price touches of the frozen target and risk boundary.';

                return $this->event($plan, 'entry_touched', $normalized, $recordedAt);
            }

            return $plan;
        }
        if ($plan['episode_started_at'] && ($stop || $target)) {
            return $this->finish($plan, ($stop && $target) ? 'ambiguous' : 'cancelled',
                ($stop && $target) ? 'ambiguous' : ($stop ? 'stop_before_entry' : 'target_before_entry'), $normalized['end']);
        }
        $touch = $normalized['high'] >= $l['touch_low'] && $normalized['low'] <= $l['touch_high'];
        $away = $d === 1 ? $normalized['low'] >= $l['trigger'] : $normalized['high'] <= $l['trigger'];
        if ($plan['pattern'] === 'hold_reclaim') {
            if ($plan['phase'] === 'awaiting_touch' && $touch) {
                if ($stop || $target) {
                    return $this->finish($plan, 'ambiguous', 'ambiguous', $normalized['end']);
                }
                $plan['episode_started_at'] = $plan['touched_at'] = $this->iso($normalized['end']);
                $plan['phase'] = 'awaiting_reclaim';
                $plan['next_condition'] = 'The wall was touched. Wait for a separate bar entirely beyond the reaction trigger.';

                return $this->event($plan, 'wall_touched', $normalized, $recordedAt);
            }
            if ($plan['phase'] === 'awaiting_reclaim' && $away) {
                return $this->ready($plan, $normalized, $recordedAt);
            }
        } else {
            if ($plan['phase'] === 'awaiting_acceptance') {
                $beyond = $d === 1 ? $normalized['low'] > $l['touch_high'] : $normalized['high'] < $l['touch_low'];
                $plan['acceptance_bars'] = $beyond ? $plan['acceptance_bars'] + 1 : 0;
                if ($beyond) {
                    $plan['episode_started_at'] ??= $this->iso($normalized['end']);
                    if ($stop || $target) {
                        return $this->finish($plan, 'cancelled', $stop ? 'stop_before_entry' : 'target_before_entry', $normalized['end']);
                    }
                }
                if ($plan['acceptance_bars'] >= 3) {
                    $plan['phase'] = 'awaiting_retest';
                    $plan['next_condition'] = 'Price accepted the break. Wait for a separate retest of the wall.';

                    return $this->event($plan, 'break_accepted', $normalized, $recordedAt);
                }
            } elseif ($plan['phase'] === 'awaiting_retest' && $touch) {
                $plan['retest_at'] = $this->iso($normalized['end']);
                $plan['phase'] = 'awaiting_retest_reaction';
                $plan['next_condition'] = 'The wall retest is recorded. Wait for a separate bar entirely away on the break side.';

                return $this->event($plan, 'wall_retested', $normalized, $recordedAt);
            } elseif ($plan['phase'] === 'awaiting_retest_reaction' && $away) {
                return $this->ready($plan, $normalized, $recordedAt);
            }
        }

        return $plan;
    }

    public function expire(array $plan, CarbonImmutable $now): array
    {
        if (in_array($plan['status'] ?? null, self::TERMINAL, true)) {
            return $plan;
        }
        $entered = ($plan['status'] ?? null) === 'entry_touched';
        $deadline = $this->instant($entered ? ($plan['holding_deadline'] ?? null) : ($plan['entry_deadline'] ?? null));
        if ($deadline && $now->gte($deadline)) {
            $last = $this->instant($plan['last_bar_at'] ?? null);
            if (in_array($plan['status'], ['ready', 'entry_touched'], true) && (! $last || $last->lt($deadline))) {
                $plan['evidence_gap'] = true;
            }

            return $this->finish($plan, ($plan['evidence_gap'] ?? false) ? 'ambiguous' : 'expired',
                ($plan['evidence_gap'] ?? false) ? 'ambiguous' : ($entered ? 'holding_ended' : 'no_entry'), $deadline);
        }

        return $plan;
    }

    private function ready(array $plan, array $bar, CarbonImmutable $recordedAt): array
    {
        $plan['status'] = 'ready';
        $plan['phase'] = 'awaiting_entry';
        $plan['qualified_at'] = $this->iso($bar['end']);
        $plan['qualified_price'] = $bar['close'];
        $plan['ready_recorded_at'] = $this->iso($recordedAt);
        $plan['next_condition'] = 'The wall reaction is recorded. Wait for a later observed price bar to reach the entry area.';

        return $this->event($plan, 'reaction_confirmed', $bar, $recordedAt);
    }

    private function metrics(array $plan, array $bar, ?string $boundary = null): array
    {
        $p = $plan['entry_price'];
        $risk = abs($p - $plan['levels']['invalidation']);
        $favorable = $plan['direction'] === 'bullish' ? max(0, $bar['high'] - $p) : max(0, $p - $bar['low']);
        $adverse = $plan['direction'] === 'bullish' ? max(0, $p - $bar['low']) : max(0, $bar['high'] - $p);
        if ($boundary === 'stop') {
            $favorable = 0.;
            $adverse = $risk;
        } elseif ($boundary === 'target') {
            $favorable = abs($plan['levels']['target'] - $p);
            $adverse = 0.;
        }
        $m = $plan['metrics'];
        foreach (['mfe_pct' => 100 * $favorable / $p, 'mae_pct' => 100 * $adverse / $p,
            'mfe_r' => $favorable / $risk, 'mae_r' => $adverse / $risk] as $key => $value) {
            if (! is_finite($value)) {
                $plan['evidence_gap'] = true;
                $plan['metrics_invalid'] = true;

                return $plan;
            }
            $m[$key] = round(max($m[$key], $value), 6);
        }
        $m['reached_05r'] = $m['mfe_r'] >= .5;
        $m['reached_1r'] = $m['mfe_r'] >= 1;
        $plan['metrics'] = $m;

        return $plan;
    }

    private function finish(array $plan, string $status, string $outcome, CarbonImmutable $at): array
    {
        return [...$plan, 'status' => $status, 'phase' => 'ended', 'outcome' => $outcome,
            'outcome_at' => $this->iso($at), 'next_condition' => null,
            'reason' => match ($outcome) {
                'target_reached' => 'A later observed price range reached the target after the entry area was touched.',
                'stop_reached' => 'A later observed price range reached the risk boundary after the entry area was touched.',
                'stop_before_entry' => 'The risk boundary was reached before a separate entry-area touch.',
                'target_before_entry' => 'The target was reached before a separate entry-area touch. No success is recorded.',
                'no_entry' => 'The entry window ended without a qualifying entry-area touch.',
                'holding_ended' => 'The holding review ended without an observed target or risk-boundary touch.',
                default => 'The observed price ranges do not establish the event order. No win or loss is assigned.',
            }];
    }

    private function event(array $plan, string $type, array $bar, CarbonImmutable $recordedAt): array
    {
        $plan['events'] = array_slice([...($plan['events'] ?? []), ['type' => $type,
            'at' => $this->iso($bar['end']), 'recorded_at' => $this->iso($recordedAt),
            'open' => $bar['open'], 'high' => $bar['high'], 'low' => $bar['low'], 'close' => $bar['close']]], -20);

        return $plan;
    }

    private function prices(array $prices, ?string $date): array
    {
        if (! $date || count($prices) > 1000) {
            return [];
        }
        $normalized = [];
        foreach ($prices as $bar) {
            $day = $this->date($bar['date'] ?? null);
            if (! $day || $day > $date) {
                continue;
            }
            if (isset($normalized[$day])) {
                return [];
            }
            foreach (['open', 'high', 'low', 'close'] as $field) {
                $value = $this->number($bar[$field] ?? null);
                if ($value === null || $value <= 0) {
                    return [];
                }
                $bar[$field] = $value;
            }
            if ($bar['high'] < max($bar['open'], $bar['close']) || $bar['low'] > min($bar['open'], $bar['close'])) {
                return [];
            }
            $normalized[$day] = $bar;
        }
        ksort($normalized);
        if (count($normalized) < 15 || array_key_last($normalized) !== $date) {
            return [];
        }
        $expected = CarbonImmutable::parse($date, 'America/New_York');
        for ($i = 0; $i < min(50, count($normalized)); $i++) {
            if (! isset($normalized[$expected->toDateString()])) {
                return [];
            }
            $expected = CarbonImmutable::parse(MarketSession::tradingDateOnOrBefore($expected->subDay()), 'America/New_York');
        }

        return array_values($normalized);
    }

    private function strikes(array $rows, string $date, string $start, string $end): array
    {
        if (count($rows) > 5000) {
            return [];
        }
        $strikes = [];
        $known = $covered = 0.;
        foreach ($rows as $row) {
            $strike = $this->number($row['strike'] ?? null);
            if ($strike === null || $strike <= 0 || isset($strikes[(string) $strike])) {
                return [];
            }
            $cells = [];
            $seen = [];
            foreach ($row['expirations'] ?? [] as $cell) {
                $expiry = $this->date($cell['expiration'] ?? null);
                if (! $expiry || $expiry < $start || $expiry > $end) {
                    continue;
                }
                if (isset($seen[$expiry]) || ($cell['data_date'] ?? null) !== $date) {
                    return [];
                }
                $seen[$expiry] = true;
                foreach (['net_gex', 'known_oi', 'covered_oi'] as $field) {
                    $cell[$field] = $this->number($cell[$field] ?? null);
                    if ($cell[$field] === null || ($field !== 'net_gex' && $cell[$field] < 0)) {
                        return [];
                    }
                }
                if ($cell['covered_oi'] > $cell['known_oi']) {
                    return [];
                }
                $known += $cell['known_oi'];
                $covered += $cell['covered_oi'];
                $cells[] = $cell;
            }
            if ($cells) {
                $net = array_sum(array_column($cells, 'net_gex'));
                if (! is_finite($net)) {
                    return [];
                }
                $strikes[(string) $strike] = ['strike' => $strike, 'net_gex' => $net, 'cells' => $cells];
            }
        }
        if (! is_finite($known) || ! is_finite($covered) || $known <= 0 || $covered / $known < .98) {
            return [];
        }
        $list = array_values($strikes);
        usort($list, fn ($a, $b) => $a['strike'] <=> $b['strike']);
        foreach ($list as $index => &$row) {
            $neighbors = array_merge(array_slice($list, max(0, $index - 2), min(2, $index)), array_slice($list, $index + 1, 2));
            $mean = count($neighbors) ? array_sum(array_map(fn ($r) => abs($r['net_gex']), $neighbors)) / count($neighbors) : 0;
            $row['neighbor_mean_abs_gex'] = is_finite($mean) && $mean > 0 ? $mean : null;
            $ratio = $row['neighbor_mean_abs_gex'] ? abs($row['net_gex']) / $mean : null;
            $row['relative_magnitude'] = $ratio !== null && is_finite($ratio) ? $ratio : null;
        }
        unset($row);

        return $list;
    }

    private function dex(array $rows, string $start, string $end): ?float
    {
        $values = [];
        foreach ($rows as $row) {
            if (($row['expiration'] ?? '') >= $start && ($row['expiration'] ?? '') <= $end && ($value = $this->number($row['value'] ?? null)) !== null) {
                $values[] = $value;
            }
        }
        $sum = array_sum($values);

        return $values && is_finite($sum) ? $sum : null;
    }

    private function bar(array $bar, CarbonImmutable $recordedAt): ?array
    {
        $end = $this->instant($bar['at'] ?? null);
        $start = $this->instant($bar['start_at'] ?? null);
        if (! $end || ! $start || $end->getTimestamp() - $start->getTimestamp() !== 300
            || $start->getTimestamp() % 300 !== 0 || $end->gt($recordedAt)) {
            return null;
        }
        $market = MarketSession::describe($start);
        if (! $market['is_rth'] || $end->gt(CarbonImmutable::parse($market['closes_at']))) {
            return null;
        }
        foreach (['open', 'high', 'low', 'close'] as $field) {
            $bar[$field] = $this->number($bar[$field] ?? null);
            if ($bar[$field] === null || $bar[$field] <= 0) {
                return null;
            }
        }
        if ($bar['high'] < max($bar['open'], $bar['close']) || $bar['low'] > min($bar['open'], $bar['close'])) {
            return null;
        }

        return [...$bar, 'start' => $start, 'end' => $end];
    }

    private function nextStart(CarbonImmutable $end): CarbonImmutable
    {
        $market = MarketSession::describe($end);

        return $market['is_rth'] ? $end : CarbonImmutable::parse($market['next_open_at']);
    }

    private function sessionClose(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(MarketSession::describe(CarbonImmutable::parse($date.' 12:00', 'America/New_York'))['closes_at']);
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'America/New_York');

            return $date && $date->toDateString() === $value && MarketSession::isTradingDay($date) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function iso(CarbonImmutable $at): string
    {
        return $at->utc()->toIso8601String();
    }
}
