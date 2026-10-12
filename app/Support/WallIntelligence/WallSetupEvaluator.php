<?php

namespace App\Support\WallIntelligence;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;

/** Causal replay of already recorded evidence. No provider calls, persistence or future anchors. */
final class WallSetupEvaluator
{
    public const SCHEMA = 'wall-setups.v1';

    public function analyze(array $interaction, array $observations): array
    {
        $rules = WallSetupRules::profile();
        $session = $interaction['session'];
        $calendar = $session ? MarketSession::describe(CarbonImmutable::parse($session.' 12:00', 'America/New_York')) : [];
        $sessionClose = strtotime($calendar['closes_at'] ?? '') ?: 0;
        $cutoff = strtotime($interaction['evaluated_through'] ?? '') ?: 0;
        $observations = array_values(array_filter($observations, fn ($o) => ($o['scope']['symbol'] ?? null) === $interaction['symbol']
            && ($o['scope']['session'] ?? null) === $session
            && ($o['scope']['timeframe'] ?? null) === $interaction['timeframe']));
        usort($observations, fn ($a, $b) => strtotime($a['observed_at']) <=> strtotime($b['observed_at']));
        $bars = array_column($interaction['bars'], null, 'id');
        $history = [];
        $current = [];
        foreach (['put', 'call'] as $side) {
            $previous = null;
            $candidate = null;
            $latest = null;
            foreach ($interaction['readings'] as $row) {
                if ($row['side'] !== $side) {
                    continue;
                }
                $at = strtotime($row['observed_at']);
                $anchor = $this->anchor($observations, $row);
                $bar = $bars[$row['evidence_bar_ids'][0] ?? ''] ?? null;
                if (! $anchor || ! $bar || $interaction['rule_version'] !== WallInteractionRules::VERSION) {
                    $candidate = $previous = null;

                    continue;
                }
                $sameEpisode = $previous && $row['wall_identity'] === $previous['wall_identity']
                    && $row['episode_started_at'] === $previous['episode_started_at']
                    && $at - strtotime($previous['observed_at']) === 300;
                if (! $sameEpisode) {
                    $candidate = $previous = null;
                }
                $position = $this->position($row['close'], $row['strike'], $row['tolerance']);
                $sequence = $this->sequence($row);
                $reclaimRetest = ($candidate['pattern'] ?? null) === 'reclaim' && ($sequence['pattern'] ?? null) === 'reversal'
                    && ! in_array($candidate['status'], $rules['terminal_states'], true);
                $oldSequence = ! empty($candidate['terminal_at']) && $sequence && strtotime($sequence['from']) <= strtotime($candidate['terminal_at']);
                if ($sequence && ! $reclaimRetest && ! $oldSequence && ($candidate['sequence'] ?? null) !== $sequence['key']) {
                    $candidate = $this->start($row, $sequence, $sessionClose, $rules);
                }
                if ($candidate) {
                    $terminal = in_array($candidate['status'], $rules['terminal_states'], true);
                    if (! $terminal && ($at >= $sessionClose || $at > $candidate['expires_timestamp'])) {
                        $candidate['status'] = 'expired';
                        $candidate['reason'] = 'The confirmation or entry window has ended. Wait for a new price sequence.';
                    } elseif (! $terminal && $position === -$candidate['direction']) {
                        $candidate['status'] = 'invalidated';
                        $candidate['reason'] = 'A completed close crossed the invalidation boundary.';
                    } elseif (! $terminal) {
                        $confirmed = $this->confirm($candidate, $row, $bar, $previous, $position);
                        if ($confirmed && ! $candidate['confirmed_at']) {
                            $this->freeze($candidate, $row, $anchor, $observations, $rules, $sessionClose);
                        }
                        if ($candidate['confirmed_at'] && ! in_array($candidate['status'], $rules['terminal_states'], true)) {
                            $distance = $candidate['direction'] * ($row['close'] - $row['strike']);
                            if ($candidate['ever_eligible'] && $candidate['target']
                                && $candidate['direction'] * ($row['close'] - $candidate['target']['strike']) >= 0) {
                                $candidate['status'] = 'target_reached';
                                $candidate['reason'] = 'A later completed close reached the reference target. This does not assume an executed trade.';
                            } elseif ($distance > 3 * $row['tolerance'] + 1e-9) {
                                $candidate['status'] = 'entry_missed';
                                $candidate['reason'] = 'The completed close moved beyond the entry zone. Wait for a new setup rather than extending the zone.';
                            } elseif ($distance > $row['tolerance'] + 1e-9) {
                                $candidate['status'] = 'conditions_met';
                                $candidate['ever_eligible'] = true;
                                $candidate['reason'] = 'The price sequence, entry zone and target room passed at this recorded close.';
                            } else {
                                $candidate['status'] = 'watch';
                                $candidate['reason'] = 'Wait for a completed close inside the entry zone before the window expires.';
                            }
                        }
                    }
                    if (in_array($candidate['status'], $rules['terminal_states'], true)) {
                        $candidate['terminal_at'] ??= $row['observed_at'];
                    }
                }
                $latest = $this->snapshot($row, $candidate, $anchor, $observations, $rules);
                $history[] = $latest;
                $previous = $row;
            }
            $now = $interaction['current'][$side] ?? [];
            if (! $latest) {
                $latest = $this->watch($side, $now['strike'] ?? null);
            } elseif ($latest['pattern'] && (($now['wall_identity'] ?? null) !== $latest['wall_identity'] || ($now['observed_at'] ?? null) !== $latest['observed_at'])) {
                $latest = [...$latest, 'status' => 'invalidated', 'reason' => 'The wall comparison changed. Wait for a new price sequence.', 'entry_eligible_at_observation' => false];
            }
            if ($sessionClose && $cutoff >= $sessionClose && ! in_array($latest['status'], $rules['terminal_states'], true)) {
                $latest['status'] = 'expired';
                $latest['reason'] = 'This session has ended. Inspect a recorded time to review its setup conditions.';
            } elseif (! empty($latest['expires_at']) && $cutoff > strtotime($latest['expires_at']) && ! in_array($latest['status'], $rules['terminal_states'], true)) {
                $latest['status'] = 'expired';
                $latest['reason'] = 'The setup window has ended. Wait for a new price sequence.';
            } elseif ($latest['status'] === 'conditions_met' && ($interaction['state'] === 'awaiting_update'
                || $cutoff - strtotime($latest['observed_at']) > WallInteractionRules::profile()['maximum_bar_age_seconds'])) {
                $latest['status'] = 'awaiting_update';
                $latest['reason'] = 'Waiting for the next completed-bar assessment. The last recorded conditions are shown below.';
            }
            $current[$side] = $latest;
        }
        usort($history, fn ($a, $b) => strcmp($a['observed_at'], $b['observed_at']) ?: strcmp($a['side'], $b['side']));

        return ['schema_version' => self::SCHEMA, 'rule_version' => WallSetupRules::VERSION,
            'symbol' => $interaction['symbol'], 'session' => $session, 'timeframe' => $interaction['timeframe'],
            'as_of' => $interaction['as_of'], 'rules' => $rules, 'current' => $current, 'readings' => $history,
            'price_basis' => 'completed_five_minute_close', 'price_delay_seconds' => 900,
            'actionable' => false, 'historical_outcome_eligible' => false, 'win_probability' => null,
            'interpretation' => $rules['interpretation']];
    }

    private function anchor(array $observations, array $row): ?array
    {
        $match = null;
        foreach ($observations as $o) {
            if (strtotime($o['observed_at']) > strtotime($row['bar_start'])) {
                break;
            }
            if ($o['observed_at'] === $row['wall_observed_at'] && ($o['basis_key'] ?? null) === $row['basis_key']
                && (float) ($o['walls'][$row['side']][0]['strike'] ?? 0) === (float) $row['strike']
                && ($o['evidence_hash'] ?? null) === ($row['wall_evidence_hash'] ?? null)) {
                $match = $o;
            }
        }

        return $match;
    }

    private function position(float $price, float $strike, float $tol): int
    {
        return $price > $strike + $tol + 1e-9 ? 1 : ($price < $strike - $tol - 1e-9 ? -1 : 0);
    }

    private function sequence(array $row): ?array
    {
        if ($row['break_time']) {
            return ['key' => 'continuation:'.$row['break_time'], 'pattern' => 'continuation', 'direction' => $row['break_direction'], 'from' => $row['break_time']];
        }
        if ($row['status'] === 'reclaim') {
            return ['key' => 'reclaim:'.$row['observed_at'], 'pattern' => 'reclaim', 'direction' => $this->position($row['close'], $row['strike'], $row['tolerance']), 'from' => $row['observed_at']];
        }
        if (in_array($row['status'], ['bounce', 'rejection'], true) && $row['first_touch_time']) {
            return ['key' => 'reversal:'.$row['first_touch_time'], 'pattern' => 'reversal', 'direction' => $row['status'] === 'bounce' ? 1 : -1, 'from' => $row['first_touch_time']];
        }

        return null;
    }

    private function start(array $row, array $sequence, int $close, array $rules): array
    {
        $minutes = match ($sequence['pattern']) {
            'continuation' => $rules['continuation_confirmation_minutes'],
            'reclaim' => $rules['reclaim_retest_minutes'],
            default => $rules['reversal_confirmation_minutes'],
        };
        $d = $sequence['direction'];
        $entry = [$row['strike'] + $d * $row['tolerance'], $row['strike'] + $d * 3 * $row['tolerance']];
        sort($entry, SORT_NUMERIC);

        return ['id' => hash('sha256', $row['wall_identity'].$row['episode_started_at'].$sequence['key'].WallSetupRules::VERSION),
            'sequence' => $sequence['key'], 'pattern' => $sequence['pattern'], 'direction' => $d,
            'started_at' => $sequence['from'], 'confirmed_at' => null, 'retest_at' => null,
            'entry_zone' => ['low' => $entry[0], 'high' => $entry[1]],
            'invalidation' => ['price' => $row['strike'] - $d * $row['tolerance'], 'close_side' => $d === 1 ? 'below' : 'above'],
            'target' => null, 'target_room_ratio' => null, 'geometry_passed' => false,
            'status' => 'watch', 'reason' => $sequence['pattern'] === 'continuation'
                ? 'Wait for acceptance, a separate retest, its failure, and a further close through the break.'
                : ($sequence['pattern'] === 'reclaim' ? 'Wait for a later retest and a separate close away on the reclaimed side.' : 'Wait for a second consecutive close away after the wall reaction.'),
            'expires_timestamp' => min($close, strtotime($sequence['from']) + $minutes * 60), 'ever_eligible' => false];
    }

    private function confirm(array &$candidate, array $row, array $bar, ?array $previous, int $position): bool
    {
        if ($candidate['confirmed_at']) {
            return true;
        }
        if ($candidate['pattern'] === 'continuation') {
            return in_array($row['status'], ['confirmed_breakout', 'confirmed_breakdown'], true);
        }
        if ($candidate['pattern'] === 'reclaim') {
            if ($candidate['retest_at'] && strtotime($row['observed_at']) > strtotime($candidate['retest_at']) && $position === $candidate['direction']) {
                return true;
            }
            if ($row['observed_at'] !== $candidate['started_at'] && $bar['l'] <= $row['strike'] + $row['tolerance'] && $bar['h'] >= $row['strike'] - $row['tolerance']) {
                $candidate['retest_at'] ??= $row['observed_at'];
            }

            return false;
        }

        return $previous && in_array($previous['status'], ['bounce', 'rejection'], true)
            && $previous['first_touch_time'] === $row['first_touch_time']
            && $position === $candidate['direction']
            && $this->position($previous['close'], $row['strike'], $row['tolerance']) === $position;
    }

    private function persistence(array $observations, array $row, array $anchor): array
    {
        $times = [];
        foreach ($observations as $o) {
            $at = strtotime($o['observed_at']);
            if ($at > strtotime($row['bar_start'])) {
                break;
            }
            if ($at < strtotime($row['episode_started_at'])) {
                continue;
            }
            if (($o['basis_key'] ?? null) === $anchor['basis_key'] && ($o['scope_key'] ?? null) === $anchor['scope_key']
                && ($o['provenance']['quote_source'] ?? null) === ($anchor['provenance']['quote_source'] ?? null)
                && (float) ($o['walls'][$row['side']][0]['strike'] ?? 0) === (float) $row['strike']) {
                $times[$at] = $at;
            } else {
                $times = [];
            }
        }

        return ['observations' => count($times), 'minutes' => count($times) > 1 ? (max($times) - min($times)) / 60 : 0];
    }

    private function freeze(array &$candidate, array $row, array $anchor, array $observations, array $rules, int $sessionClose): void
    {
        $candidate['confirmed_at'] = $row['observed_at'];
        $candidate['expires_timestamp'] = min($sessionClose, strtotime($row['observed_at']) + $rules['entry_window_minutes'] * 60);
        $candidate['confirmation_wall_hash'] = $anchor['evidence_hash'] ?? null;
        $candidate['confirmation_scope'] = $anchor['scope'];
        $persistence = $this->persistence($observations, $row, $anchor);
        $net = $anchor['walls'][$row['side']][0]['net_gex'] ?? null;
        $validModel = ($anchor['model_version'] ?? null) === IntradayWallModel::MODEL
            && ($anchor['scope']['units'] ?? null) === 'USD_per_1pct_move'
            && is_numeric($net) && is_finite((float) $net) && ($row['side'] === 'put' ? $net < 0 : $net > 0)
            && ($anchor['audit']['oi_input_coverage_pct'] ?? 0) >= $rules['minimum_model_oi_coverage_pct'];
        $targets = [];
        foreach (['put', 'call'] as $side) {
            foreach ($anchor['walls'][$side] ?? [] as $wall) {
                $strike = $wall['strike'] ?? null;
                if (is_numeric($strike) && is_finite((float) $strike) && $strike > 0 && is_numeric($wall['net_gex'] ?? null)
                    && is_finite((float) $wall['net_gex']) && ($side === 'put' ? $wall['net_gex'] < 0 : $wall['net_gex'] > 0)
                    && $candidate['direction'] * ($strike - $row['strike']) > 1e-9) {
                    $targets[] = ['strike' => (float) $strike, 'side' => $side, 'net_gex' => $wall['net_gex'], 'observed_at' => $anchor['observed_at'], 'wall_evidence_hash' => $anchor['evidence_hash'] ?? null];
                }
            }
        }
        usort($targets, fn ($a, $b) => abs($a['strike'] - $row['strike']) <=> abs($b['strike'] - $row['strike']));
        $candidate['target'] = $targets[0] ?? null;
        $farEntry = $candidate['direction'] === 1 ? $candidate['entry_zone']['high'] : $candidate['entry_zone']['low'];
        $risk = abs($farEntry - $candidate['invalidation']['price']);
        $ratio = $candidate['target'] && $risk > 0 ? $candidate['direction'] * ($candidate['target']['strike'] - $farEntry) / $risk : null;
        $candidate['target_room_ratio'] = $ratio;
        $candidate['geometry_passed'] = $validModel && $ratio !== null && $ratio >= $rules['minimum_target_to_invalidation_ratio'];
        if (! $validModel || $persistence['observations'] < $rules['minimum_wall_observations'] || $persistence['minutes'] < $rules['minimum_wall_duration_minutes']) {
            $candidate['status'] = 'no_setup';
            $candidate['reason'] = 'Wait for a new confirmed sequence with a comparable modeled wall.';
        } elseif (! $candidate['geometry_passed']) {
            $candidate['status'] = 'no_setup';
            $candidate['reason'] = $candidate['target'] ? 'The nearest recorded level leaves too little room beyond the entry zone.' : 'No recorded target supports this direction. No entry is proposed.';
        }
    }

    private function snapshot(array $row, ?array $candidate, array $anchor, array $observations, array $rules): array
    {
        $persistence = $this->persistence($observations, $row, $anchor);
        $checks = [
            ['key' => 'ordered_price_confirmation', 'label' => 'Ordered price confirmation', 'passed' => ! empty($candidate['confirmed_at'])],
            ['key' => 'wall_persistence', 'label' => 'Same leading wall across 3 readings and 10 minutes', 'passed' => $persistence['observations'] >= $rules['strong_persistence_observations'] && $persistence['minutes'] >= $rules['strong_persistence_minutes']],
            ['key' => 'supported_geometry', 'label' => 'Recorded target with at least 1.5× room versus invalidation distance', 'passed' => $candidate['geometry_passed'] ?? false],
        ];
        $passed = count(array_filter($checks, fn ($check) => $check['passed']));
        $status = $candidate['status'] ?? 'watch';

        return [...$this->watch($row['side'], $row['strike']),
            'id' => $candidate['id'] ?? null, 'pattern' => $candidate['pattern'] ?? null,
            'direction' => isset($candidate['direction']) ? ($candidate['direction'] === 1 ? 'bullish' : 'bearish') : null,
            'status' => $status, 'status_at_observation' => $status, 'reason' => $candidate['reason'] ?? 'Watch for a touch, then a completed rejection, reclaim or break-and-retest sequence.',
            'observed_at' => $row['observed_at'], 'close' => $row['close'], 'wall_identity' => $row['wall_identity'],
            'episode_started_at' => $row['episode_started_at'], 'evidence_window' => $row['evidence_window'],
            'evidence_bar_ids' => $row['evidence_bar_ids'], 'wall_evidence_hash' => $row['wall_evidence_hash'],
            'scope' => $anchor['scope'], 'basis_key' => $anchor['basis_key'], 'provenance' => $anchor['provenance'],
            'entry_zone' => $candidate['entry_zone'] ?? null, 'invalidation' => $candidate['invalidation'] ?? null,
            'target' => $candidate['target'] ?? null, 'target_room_ratio' => $candidate['target_room_ratio'] ?? null,
            'confirmed_at' => $candidate['confirmed_at'] ?? null, 'confirmation_wall_hash' => $candidate['confirmation_wall_hash'] ?? null,
            'confirmation_scope' => $candidate['confirmation_scope'] ?? null,
            'expires_at' => isset($candidate['expires_timestamp']) ? gmdate('c', $candidate['expires_timestamp']) : null,
            'entry_eligible_at_observation' => $status === 'conditions_met',
            'evidence_grade' => $passed === 3 ? 'strong' : ($passed === 2 ? 'moderate' : 'weak'),
            'evidence_checks' => $checks, 'persistence' => $persistence,
        ];
    }

    private function watch(string $side, int|float|null $strike): array
    {
        return ['side' => $side, 'strike' => $strike, 'status' => 'watch', 'status_at_observation' => 'watch',
            'reason' => 'Watching for completed price behavior at this wall.', 'observed_at' => null,
            'pattern' => null, 'direction' => null, 'evidence_grade' => null, 'evidence_checks' => [],
            'entry_zone' => null, 'invalidation' => null, 'target' => null, 'entry_eligible_at_observation' => false,
            'live_entry_eligible' => false, 'actionable' => false, 'win_probability' => null];
    }
}
