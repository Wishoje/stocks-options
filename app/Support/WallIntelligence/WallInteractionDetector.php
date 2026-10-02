<?php

namespace App\Support\WallIntelligence;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;

/** Deterministic, causal replay. OHLC never supplies an assumed intrabar order. */
final class WallInteractionDetector
{
    public const SCHEMA = 'wall-interactions.v1';

    public function analyze(string $symbol, ?string $session, string $timeframe, array $observations, array $bars, CarbonImmutable $cutoff): array
    {
        $rules = WallInteractionRules::profile();
        $calendar = $session ? MarketSession::describe(CarbonImmutable::parse($session.' 12:00', 'America/New_York')) : null;
        $open = strtotime($calendar['opens_at'] ?? '') ?: PHP_INT_MAX;
        $close = strtotime($calendar['closes_at'] ?? '') ?: 0;
        $observations = array_values(array_filter($observations, fn ($o) => ($o['scope']['session'] ?? $o['scope']['analysis_session'] ?? $session) === $session
            && ($o['scope']['symbol'] ?? $symbol) === $symbol
            && ($o['scope']['timeframe'] ?? $timeframe) === $timeframe
            && strtotime($o['observed_at'] ?? '') >= $open && strtotime($o['observed_at'] ?? '') <= min($close, $cutoff->timestamp)));
        usort($observations, fn ($a, $b) => strtotime($a['observed_at']) <=> strtotime($b['observed_at']));
        // A provider must return one unambiguous bar per interval.
        $counts = array_count_values(array_map(fn ($bar) => (string) ($bar['t'] ?? ''), $bars));
        $bars = array_values(array_filter($bars, fn ($bar) => $this->validBar($bar, $open, $close, $cutoff->timestamp)
            && ($counts[(string) $bar['t']] ?? 0) === 1));
        usort($bars, fn ($a, $b) => $a['t'] <=> $b['t']);
        $events = [];
        $readings = [];
        $current = [];
        foreach (['put', 'call'] as $side) {
            $state = null;
            $lastEnd = null;
            $lastIdentity = null;
            $anchorIndex = -1;
            $current[$side] = $this->unknown($side);
            foreach ($bars as $bar) {
                $start = (int) ($bar['t'] / 1000);
                $end = $start + 300;
                while (isset($observations[$anchorIndex + 1]) && strtotime($observations[$anchorIndex + 1]['observed_at']) <= $start) {
                    $anchorIndex++;
                }
                $anchor = $observations[$anchorIndex] ?? null;
                $identity = $anchor ? $this->identity($anchor, $side) : null;
                $stable = $identity !== null && $end - strtotime($anchor['observed_at']) <= $rules['maximum_wall_age_seconds'];
                // A wall that moved within the price bar cannot explain that whole bar.
                for ($i = $anchorIndex + 1; isset($observations[$i]) && strtotime($observations[$i]['observed_at']) < $end; $i++) {
                    $stable = $stable && $this->identity($observations[$i], $side) === $identity;
                }
                if (! $stable) {
                    $state = null;
                    $lastIdentity = null;
                    $current[$side] = $this->unknown($side, $anchor['walls'][$side][0]['strike'] ?? null);

                    continue;
                }
                $strike = (float) $anchor['walls'][$side][0]['strike'];
                if ($state === null || $identity !== $lastIdentity || $lastEnd !== $start) {
                    $state = ['origin' => null, 'previous_close' => null, 'status' => 'unknown',
                        'touch' => null, 'break' => null, 'beyond' => 0, 'back' => 0,
                        'accepted' => false, 'retest' => null, 'failed' => null, 'confirmed' => false,
                        'episode_started_at' => gmdate('c', $start)];
                }
                $lastIdentity = $identity;
                $lastEnd = $end;
                $before = $state['status'];
                $tolerance = max($rules['minimum_tolerance_usd'], $strike * $rules['touch_tolerance_bps'] / 10000);
                $this->advance($state, $bar, $strike, $tolerance, $end, $rules);
                $barId = hash('sha256', json_encode([$symbol, $session, $bar], JSON_PRESERVE_ZERO_FRACTION));
                $reading = ['side' => $side, 'strike' => $strike, 'status' => $state['status'],
                    'rule_version' => WallInteractionRules::VERSION, 'bar_interval_minutes' => 5,
                    'observed_at' => gmdate('c', $end), 'bar_start' => gmdate('c', $start), 'close' => (float) $bar['c'],
                    'tolerance' => $tolerance, 'episode_started_at' => $state['episode_started_at'],
                    'wall_observed_at' => $anchor['observed_at'], 'wall_identity' => $identity,
                    'wall_evidence_hash' => $anchor['evidence_hash'] ?? null,
                    'basis_key' => $anchor['basis_key'] ?? null,
                    'first_touch_time' => $state['touch']['at'] ?? null, 'break_time' => $state['break']['at'] ?? null,
                    'consecutive_closes_beyond' => $state['beyond'], 'minutes_beyond' => $state['beyond'] * 5,
                    'break_direction' => $state['break']['direction'] ?? null,
                    'accepted_above' => $state['accepted'] && ($state['break']['direction'] ?? 0) === 1,
                    'accepted_below' => $state['accepted'] && ($state['break']['direction'] ?? 0) === -1,
                    'retest_time' => $state['retest']['at'] ?? null,
                    'reclaim_high' => $state['retest']['high'] ?? null,
                    'reclaim_low' => $state['retest']['low'] ?? null,
                    'lowest_after_break' => $state['break']['low'] ?? null,
                    'highest_after_break' => $state['break']['high'] ?? null,
                    'evidence_bar_ids' => [$barId],
                    'evidence_window' => ['from' => $state['episode_started_at'], 'through' => gmdate('c', $end)],
                    'reason' => $state['reason'] ?? 'Watching for a price approach.'];
                $readings[] = $reading;
                $current[$side] = $reading;
                if ($reading['status'] !== 'unknown' && $before !== $reading['status']) {
                    $events[] = ['id' => hash('sha256', json_encode([$identity, $barId, $reading['status'], WallInteractionRules::VERSION])), ...$reading];
                }
            }
            $latest = $observations ? end($observations) : null;
            if ($latest && $this->identity($latest, $side) !== $lastIdentity) {
                $current[$side] = $this->unknown($side, $latest['walls'][$side][0]['strike'] ?? null);
            }
        }
        usort($events, fn ($a, $b) => strcmp($a['observed_at'], $b['observed_at']) ?: strcmp($a['side'], $b['side']));
        $lastBarEnd = $bars ? (int) (end($bars)['t'] / 1000) + 300 : null;
        $sessionEnded = $close > 0 && $cutoff->timestamp >= $close;
        $stale = $lastBarEnd && min($cutoff->timestamp, $close) - $lastBarEnd > $rules['maximum_bar_age_seconds'];

        return ['schema_version' => self::SCHEMA, 'symbol' => $symbol, 'session' => $session, 'timeframe' => $timeframe,
            'rule_version' => WallInteractionRules::VERSION, 'rules' => $rules,
            'state' => ! $bars ? 'waiting_for_bars' : ($stale ? 'awaiting_update' : ($sessionEnded ? 'session_review' : 'ready')),
            'as_of' => $lastBarEnd ? gmdate('c', $lastBarEnd) : null, 'evaluated_through' => $cutoff->toIso8601String(),
            'current' => $current, 'events' => $events, 'readings' => $readings,
            'bars' => array_map(fn ($bar) => ['id' => hash('sha256', json_encode([$symbol, $session, $bar], JSON_PRESERVE_ZERO_FRACTION)), ...$bar], $bars),
            'historical_outcome_eligible' => false, 'actionable' => false,
            'interpretation' => 'Labels describe completed price behavior at a modeled level. They do not predict the next move or define an entry.'];
    }

    private function unknown(string $side, int|float|null $strike = null): array
    {
        return ['side' => $side, 'strike' => $strike, 'status' => 'unknown', 'observed_at' => null,
            'reason' => 'Watching for completed price bars at this wall.', 'evidence_bar_ids' => []];
    }

    private function identity(array $o, string $side): ?string
    {
        $strike = $o['walls'][$side][0]['strike'] ?? null;
        if (! is_numeric($strike) || $strike <= 0 || empty($o['basis_key']) || empty($o['scope_key'])) {
            return null;
        }

        return hash('sha256', json_encode([$o['basis_key'], $o['scope_key'], $o['provenance']['quote_source'] ?? null,
            $side, (float) $strike, $o['revision_of'] ?? null]));
    }

    private function validBar(array $bar, int $open, int $close, int $cutoff): bool
    {
        foreach (['o', 'h', 'l', 'c', 't'] as $key) {
            if (! isset($bar[$key]) || ! is_numeric($bar[$key]) || ! is_finite((float) $bar[$key]) || $bar[$key] <= 0) {
                return false;
            }
        }
        $start = (int) ($bar['t'] / 1000);

        return $bar['t'] == $start * 1000 && $start >= $open && $start + 300 <= min($close, $cutoff)
            && ($start - $open) % 300 === 0 && $bar['l'] <= min($bar['o'], $bar['c'])
            && $bar['h'] >= max($bar['o'], $bar['c']) && $bar['l'] <= $bar['h'];
    }

    private function advance(array &$s, array $bar, float $strike, float $tol, int $end, array $r): void
    {
        $distance = (float) $bar['c'] - $strike;
        // Equality belongs to the touch band; only strict closes count beyond it.
        $position = $distance > $tol + 1e-9 ? 1 : ($distance < -$tol - 1e-9 ? -1 : 0);
        $touch = $bar['l'] <= $strike + $tol + 1e-9 && $bar['h'] >= $strike - $tol - 1e-9;
        $previous = $s['previous_close'];
        $s['previous_close'] = (float) $bar['c'];
        $s['reason'] = 'Watching for a price approach.';
        if ($s['origin'] === null) {
            $s['origin'] = $position ?: null;
            $s['status'] = $touch ? 'touch' : 'unknown';
            if ($touch) {
                $s['touch'] = ['at' => gmdate('c', $end), 'time' => $end, 'close' => (float) $bar['c']];
                $s['reason'] = 'The completed bar traded within the wall’s touch band.';
            }

            return;
        }
        if ($s['break'] === null && $s['touch'] && $end - $s['touch']['time'] > $r['reaction_window_minutes'] * 60) {
            $s['touch'] = null;
        }
        if ($touch && $s['touch'] === null) {
            $s['touch'] = ['at' => gmdate('c', $end), 'time' => $end, 'close' => (float) $bar['c']];
        }
        if ($s['break'] !== null) {
            $direction = $s['break']['direction'];
            $previouslyAccepted = $s['accepted'];
            $s['break']['low'] = min($s['break']['low'], $bar['l']);
            $s['break']['high'] = max($s['break']['high'], $bar['h']);
            $s['beyond'] = $position === $direction ? $s['beyond'] + 1 : 0;
            $s['back'] = $position === $s['origin'] ? $s['back'] + 1 : 0;
            if ($s['back'] >= $r['reclaim_closes']) {
                $s['status'] = 'reclaim';
                $s['reason'] = 'Two consecutive completed closes returned to the original side of the wall.';
                $s['break'] = $s['retest'] = $s['failed'] = null;
                $s['accepted'] = $s['confirmed'] = false;
                $s['beyond'] = $s['back'] = 0;
                $s['touch'] = null;

                return;
            }
            if ($s['beyond'] >= $r['acceptance_closes']) {
                $s['accepted'] = true;
            }
            $withinRetest = $end - $s['break']['time'] <= $r['retest_window_minutes'] * 60;
            // Both the retest and subsequent failure must be separate completed bars.
            if ($s['retest'] && ! $s['failed'] && $withinRetest && $position === $direction) {
                $s['failed'] = ['time' => $end, 'close' => (float) $bar['c']];
                $s['status'] = 'failed_reclaim';
                $s['reason'] = 'A retest reached the wall, then a later bar closed back on the break side.';
            } elseif ($s['failed'] && $withinRetest && $s['accepted'] && ! $s['confirmed']
                && $position === $direction && $direction * ($bar['c'] - $s['failed']['close']) >= $tol - 1e-9) {
                $s['confirmed'] = true;
                $s['status'] = $direction === 1 ? 'confirmed_breakout' : 'confirmed_breakdown';
                $s['reason'] = 'Acceptance, a failed retest, and a later close at least one touch-band width farther through the break.';
            } elseif ($touch && ! $s['retest'] && $withinRetest && $previouslyAccepted) {
                $s['retest'] = ['at' => gmdate('c', $end), 'time' => $end, 'high' => $bar['h'], 'low' => $bar['l']];
                $s['status'] = 'testing';
                $s['reason'] = 'Price retested the wall after a break. Waiting for a later completed close.';
            } elseif ($position === $s['origin'] || $position === 0) {
                $s['status'] = 'testing';
                $s['reason'] = $position === 0 ? 'Price closed inside the touch band.' : 'One close returned to the original side; a reclaim needs two.';
            } elseif ($s['confirmed']) {
                $s['status'] = $direction === 1 ? 'confirmed_breakout' : 'confirmed_breakdown';
                $s['reason'] = 'Price remains on the break side after the confirmed sequence.';
            } elseif ($s['accepted'] && ! $s['failed']) {
                $s['status'] = $direction === 1 ? 'acceptance_above' : 'acceptance_below';
                $s['reason'] = 'At least two consecutive five-minute bars closed beyond the touch band.';
            } elseif ($s['failed']) {
                $s['status'] = 'failed_reclaim';
                $s['reason'] = 'The retest failed; the continuation rule has not completed.';
            } else {
                $s['status'] = 'break';
                $s['reason'] = 'One completed close crossed the wall; acceptance needs two consecutive closes.';
            }

            return;
        }
        if ($position === -$s['origin']) {
            $s['break'] = ['at' => gmdate('c', $end), 'time' => $end, 'direction' => $position, 'low' => $bar['l'], 'high' => $bar['h']];
            $s['beyond'] = 1;
            $s['status'] = 'break';
            $s['reason'] = 'One completed close crossed the wall; acceptance needs two consecutive closes.';
        } elseif ($s['touch'] && $end > $s['touch']['time'] && $end - $s['touch']['time'] <= $r['reaction_window_minutes'] * 60
            && $position === $s['origin'] && $tol - 1e-9 <= $s['origin'] * ($bar['c'] - $s['touch']['close'])) {
            $s['status'] = $s['origin'] === 1 ? 'bounce' : 'rejection';
            $s['reason'] = 'After a touch, a later bar closed at least one band width away on the original side.';
        } elseif ($touch) {
            $s['touch'] ??= ['at' => gmdate('c', $end), 'time' => $end, 'close' => (float) $bar['c']];
            $s['status'] = $position === 0 ? 'testing' : 'touch';
            $s['reason'] = $position === 0 ? 'The completed bar closed inside the touch band.' : 'Price reached the touch band and closed on the original side.';
        } elseif ($previous !== null && abs($distance) <= $tol * $r['approach_tolerance_multiple'] && abs($distance) < abs($previous - $strike)) {
            $s['status'] = 'approaching';
            $s['reason'] = 'The latest close moved closer, within three touch-band widths of the wall.';
        } else {
            $s['status'] = 'unknown';
        }
        if ($s['touch'] && $end - $s['touch']['time'] > $r['reaction_window_minutes'] * 60) {
            $s['touch'] = null;
        }
    }
}
