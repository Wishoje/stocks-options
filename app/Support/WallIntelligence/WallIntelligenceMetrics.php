<?php

namespace App\Support\WallIntelligence;

/** Pure calculations in USD per 1% move. History always holds the expiry set fixed. */
final class WallIntelligenceMetrics
{
    public const VERSION = 'wall-intelligence.v1';

    public function calculate(array $levels, array $current, array $history, array $sessions, ?float $close, float $minSideRatio = .35): array
    {
        $expiries = array_values($levels['expiration_dates'] ?? []);
        sort($expiries);
        $date = $levels['data_date'];
        $strikes = $this->byStrike($current);
        $currentComparable = $this->comparable($current, $expiries, $date, $minSideRatio);
        $days = [];
        foreach ($sessions as $session) {
            $rows = $session === $date ? $current : ($history[$session] ?? []);
            $days[$session] = [
                'comparable' => $this->comparable($rows, $expiries, $session, $minSideRatio),
                'strikes' => $this->byStrike($rows),
                'expiries' => array_values(array_unique(array_column($rows, 'expiry'))),
            ];
        }
        $walls = [];
        foreach (['put' => ['put_support', 'put_wall_2', 'put_wall_3'], 'call' => ['call_resistance', 'call_wall_2', 'call_wall_3']] as $side => $fields) {
            $walls[$side] = [];
            foreach ($fields as $field) {
                $strike = WallSnapshotContract::number($levels[$field] ?? null);
                if ($strike === null || in_array($strike, array_column($walls[$side], 'strike'), true)) {
                    continue;
                }
                $key = (string) $strike;
                $reading = $strikes[$key] ?? null;
                if ($reading === null) {
                    continue;
                }
                $peers = $this->neighbors($strikes, $strike);
                $mean = count($peers) ? array_sum(array_map(fn ($r) => abs($r['net_gex']), $peers)) / count($peers) : 0;
                $contributions = array_values(array_filter($current, fn ($r) => (float) $r['strike'] === $strike));
                $denominator = array_sum(array_map(fn ($r) => abs($r['net_gex']), $contributions));
                $contributions = array_map(fn ($r) => [
                    'expiry' => $r['expiry'], 'data_date' => $r['data_date'],
                    'net_gex' => $r['net_gex'], 'call_gex' => $r['call_gex'], 'put_gex' => $r['put_gex'],
                    'open_interest' => $r['open_interest'],
                    'share_pct' => $denominator > 0 ? 100 * abs($r['net_gex']) / $denominator : null,
                ], $contributions);
                usort($contributions, fn ($a, $b) => abs($b['net_gex']) <=> abs($a['net_gex']) ?: strcmp($a['expiry'], $b['expiry']));
                $series = [];
                foreach ($days as $session => $day) {
                    $r = $day['strikes'][$key] ?? null;
                    // A newly listed expiry/strike is not a zero historical reading.
                    $sameStrikeExpiries = $r && $r['expiries'] === $reading['expiries'];
                    $usable = $currentComparable && $day['comparable'] && $sameStrikeExpiries && $r['inputs_valid'] && $reading['inputs_valid'];
                    $isCurrent = $session === $date;
                    $scopeMatches = count(array_diff($expiries, $day['expiries'])) === 0
                        && count(array_diff($day['expiries'], $expiries)) === 0;
                    $status = match (true) {
                        $isCurrent => $usable ? 'current' : 'current_reference',
                        $usable => 'comparable',
                        $day['expiries'] !== [] && (! $scopeMatches || ($r && ! $sameStrikeExpiries)) => 'expiry_scope_changed',
                        default => 'no_comparison',
                    };
                    $series[] = [
                        'date' => $session, 'net_gex' => $usable ? $r['net_gex'] : null,
                        // Display the same current exposure as the headline without
                        // treating it as evidence for a historical change or streak.
                        'display_net_gex' => $isCurrent ? $reading['net_gex'] : ($usable ? $r['net_gex'] : null),
                        'display_status' => $status, 'is_current' => $isCurrent,
                        'selected_expiry_count' => count($expiries),
                        'recorded_expiry_count' => count(array_intersect($expiries, $day['expiries'])),
                        'open_interest' => $usable ? $r['open_interest'] : null,
                        'rank' => $usable ? $this->rank($day['strikes'], $strike, $side) : null,
                        'comparable' => $usable,
                        'reason' => $usable ? null : 'same_session_and_expiry_strike_inputs_required',
                    ];
                }
                $previous = $series[1] ?? null;
                $change = WallSnapshotContract::magnitudeChangePct($reading['net_gex'], $previous['net_gex'] ?? null, $previous['comparable'] ?? false);
                $streak = 0;
                foreach ($series as $point) {
                    if (! $point['comparable'] || $point['rank'] === null || $point['rank'] > 3) {
                        break;
                    }
                    $streak++;
                }
                $signedDistance = $close !== null && $close > 0 ? 100 * ($strike - $close) / $close : null;
                $walls[$side][] = [
                    'strike' => $strike, 'net_gex' => $reading['net_gex'],
                    'call_gex' => $reading['call_gex'], 'put_gex' => $reading['put_gex'],
                    'relative_magnitude' => $mean > 0 ? abs($reading['net_gex']) / $mean : null,
                    'neighbor_strikes' => array_column($peers, 'strike'), 'neighbor_mean_abs_gex' => $mean > 0 ? $mean : null,
                    'distance_pct' => $signedDistance === null ? null : abs($signedDistance), 'signed_distance_pct' => $signedDistance,
                    'expiry_contributions' => $contributions,
                    'distinct_expiry_count' => count(array_filter($contributions, fn ($r) => abs($r['net_gex']) > 0)),
                    'dominant_expiry' => $denominator > 0 ? ($contributions[0]['expiry'] ?? null) : null,
                    'dominant_expiry_share_pct' => $contributions[0]['share_pct'] ?? null,
                    'gex_magnitude_change_1d_pct' => $change,
                    'gex_sign_changed' => ($previous['comparable'] ?? false) ? $reading['net_gex'] * $previous['net_gex'] < 0 : null,
                    'oi_change_1d' => ($previous['comparable'] ?? false) ? $reading['open_interest'] - $previous['open_interest'] : null,
                    'comparison_date' => $previous['date'] ?? null,
                    'top_three_streak_sessions' => $streak ?: null, 'history' => $series,
                    'strength_score' => null, 'score_status' => 'rubric_pending',
                    'interaction_status' => 'unknown', 'actionable' => false,
                ];
            }
        }

        return [
            'schema_version' => self::VERSION, 'model_version' => 'legacy-eod-fixed-expiry.v1',
            'symbol' => $levels['symbol'], 'timeframe' => $levels['timeframe'], 'data_date' => $date,
            'view_context' => $levels['view_context'] ?? null, 'expiration_dates' => $expiries,
            'scope_key' => hash('sha256', json_encode([self::VERSION, 'legacy-eod-fixed-expiry.v1', $levels['symbol'], $levels['timeframe'], $levels['view_context']['view'] ?? 'legacy', $expiries, 'America/New_York', 'USD/1pct', 'call-minus-put'])),
            'unit' => 'USD per 1% underlying move', 'inventory_convention' => 'call GEX minus put GEX',
            'reference_price' => ['value' => $close, 'date' => $date, 'kind' => 'daily_close', 'live' => false],
            'provenance' => [
                'analysis_session' => $levels['view_context']['session_date'] ?? $date,
                'market_timezone' => 'America/New_York',
                'oi_dates' => array_values(array_unique(array_column($current, 'data_date'))),
                'greeks_timestamp' => null, 'reference_price_timestamp' => null,
                'timestamp_note' => 'Daily dates are available; exact synchronized observation timestamps are not supplied by this EOD adapter.',
            ],
            'rules' => [
                'neighbors' => 'Nearest two available strikes below and two above; target excluded; mean absolute net GEX, including zero peers.',
                'expiry_share' => 'Absolute net GEX for an expiry divided by the sum of absolute expiry net GEX at this strike.',
                'history' => 'Retrospective EOD reconstruction using the current fixed expiry set across five consecutive market sessions. A gap stops the streak; not point-in-time signal validation.',
                'history_display' => 'display_net_gex shows the current headline even when comparable is false. Only comparable readings support daily changes and streaks. expiry_scope_changed is a different expiry basket, not zero exposure.',
                'persistence' => 'Consecutive comparable sessions in the top three net-GEX strikes on the same side, including the current session; capped at five.',
                'change' => '100 * (abs(current) - abs(previous)) / abs(previous). Zero baseline returns null. Sign changes are separate. GEX changes do not establish OI changes.',
            ],
            'walls' => $walls,
        ];
    }

    public function byStrike(array $rows): array
    {
        $strikes = [];
        foreach ($rows as $row) {
            $key = (string) (float) $row['strike'];
            $strikes[$key] ??= ['strike' => (float) $row['strike'], 'net_gex' => 0.0, 'call_gex' => 0.0, 'put_gex' => 0.0, 'open_interest' => 0.0, 'inputs_valid' => true, 'expiries' => []];
            foreach (['net_gex', 'call_gex', 'put_gex', 'open_interest'] as $field) {
                $strikes[$key][$field] += $row[$field];
            }
            $strikes[$key]['inputs_valid'] = $strikes[$key]['inputs_valid'] && $row['inputs_valid'];
            $strikes[$key]['expiries'][] = $row['expiry'];
        }
        foreach ($strikes as &$row) {
            sort($row['expiries']);
        }
        unset($row);
        uasort($strikes, fn ($a, $b) => $a['strike'] <=> $b['strike']);

        return $strikes;
    }

    private function neighbors(array $strikes, float $strike): array
    {
        $below = array_values(array_filter($strikes, fn ($r) => $r['strike'] < $strike));
        $above = array_values(array_filter($strikes, fn ($r) => $r['strike'] > $strike));

        return [...array_slice($below, -2), ...array_slice($above, 0, 2)];
    }

    private function rank(array $strikes, float $strike, string $side): ?int
    {
        $ranked = array_values(array_filter($strikes, fn ($r) => $side === 'put' ? $r['net_gex'] < 0 : $r['net_gex'] > 0));
        usort($ranked, fn ($a, $b) => abs($b['net_gex']) <=> abs($a['net_gex']) ?: $a['strike'] <=> $b['strike']);
        foreach ($ranked as $index => $row) {
            if ($row['strike'] === $strike) {
                return $index + 1;
            }
        }

        return null;
    }

    private function comparable(array $rows, array $expiries, string $date, float $ratio): bool
    {
        $sides = [];
        foreach ($rows as $row) {
            if ($row['data_date'] !== $date) {
                return false;
            }
            $sides[$row['expiry']] ??= ['call' => 0, 'put' => 0];
            $sides[$row['expiry']]['call'] += $row['call_rows'] > 0 ? 1 : 0;
            $sides[$row['expiry']]['put'] += $row['put_rows'] > 0 ? 1 : 0;
        }
        $found = array_keys($sides);
        sort($found);
        if ($found !== $expiries || $found === []) {
            return false;
        }
        foreach ($sides as $side) {
            if (min($side) === 0 || min($side) / max($side) < $ratio) {
                return false;
            }
        }

        return true;
    }
}
