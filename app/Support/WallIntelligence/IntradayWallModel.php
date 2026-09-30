<?php

namespace App\Support\WallIntelligence;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use DomainException;

final class IntradayWallModel
{
    public const SCHEMA = 'intraday-walls.v1';

    public const MODEL = 'frozen-eod-iv-bs.v1';

    public function basis(string $symbol, string $session, string $sourceDate, array $expiries, array $contracts): array
    {
        sort($expiries, SORT_STRING);
        usort($contracts, fn ($a, $b) => [$a['expiry'], $a['strike'], $a['type']] <=> [$b['expiry'], $b['strike'], $b['type']]);
        $scope = ['symbol' => $symbol, 'session' => $session, 'timeframe' => '14d', 'expiries' => $expiries,
            'model_version' => self::MODEL, 'inventory_convention' => 'call_minus_put', 'multiplier' => 100,
            'risk_free_rate' => 0, 'dividend_yield' => 0, 'units' => 'USD_per_1pct_move'];
        $scope['expiry_time_convention'] = 'NYSE_regular_session_close';
        $basis = ['scope' => $scope, 'source_date' => $sourceDate, 'contracts' => $contracts];
        $basis['scope_key'] = WallSnapshotContract::hash($scope);
        $basis['basis_key'] = WallSnapshotContract::hash($basis);

        return $basis;
    }

    public function observe(array $basis, float $spot, CarbonImmutable $at, string $quoteSource): array
    {
        if (! is_finite($spot) || $spot <= 0 || ! MarketSession::describe($at)['is_rth']
            || $at->setTimezone('America/New_York')->toDateString() !== $basis['scope']['session']) {
            throw new DomainException('A positive session price is required.');
        }
        $byStrike = [];
        $excluded = 0;
        $totalOi = 0;
        $usedOi = 0;
        $expiryTimes = [];
        foreach ($basis['contracts'] as $contract) {
            $oi = WallSnapshotContract::number($contract['oi'] ?? null);
            $strike = WallSnapshotContract::number($contract['strike'] ?? null);
            $iv = WallSnapshotContract::number($contract['iv'] ?? null);
            $totalOi += max(0, $oi ?? 0);
            if (! array_key_exists($contract['expiry'], $expiryTimes)) {
                $close = MarketSession::describe(CarbonImmutable::parse($contract['expiry'], 'America/New_York'))['closes_at'];
                $expiryTimes[$contract['expiry']] = $close ? CarbonImmutable::parse($close)->getTimestamp() : 0;
            }
            $seconds = $expiryTimes[$contract['expiry']] - $at->getTimestamp();
            if ($oi === null || $oi < 0 || $strike === null || $strike <= 0 || $seconds <= 0
                || ! in_array($contract['type'], ['call', 'put'], true) || ($oi > 0 && ($iv === null || $iv <= 0))) {
                $excluded++;

                continue;
            }
            $gex = 0.;
            if ($oi > 0) {
                $volTime = $iv * sqrt($seconds / (365 * 86400));
                $d1 = (log($spot / $strike) + .5 * $volTime * $volTime) / $volTime;
                $gamma = exp(-.5 * $d1 * $d1) / (sqrt(2 * M_PI) * $spot * $volTime);
                $gex = $gamma * $oi * $spot * $spot; // 100 shares * .01 price move.
            }
            if (! is_finite($gex)) {
                $excluded++;

                continue;
            }
            $usedOi += $oi;
            $key = (string) $strike;
            $byStrike[$key] ??= ['strike' => $strike, 'call_gex' => 0., 'put_gex' => 0., 'net_gex' => 0.];
            $byStrike[$key][$contract['type'].'_gex'] += $gex;
        }
        if (! $byStrike || $usedOi <= 0) {
            throw new DomainException('No model observations can be calculated.');
        }
        ksort($byStrike, SORT_NUMERIC);
        foreach ($byStrike as &$row) {
            $row['net_gex'] = $row['call_gex'] - $row['put_gex'];
        }
        unset($row);
        $rows = array_values($byStrike);
        $rank = function (string $side) use ($rows): array {
            $walls = array_values(array_filter($rows, fn ($r) => $side === 'put' ? $r['net_gex'] < 0 : $r['net_gex'] > 0));
            usort($walls, fn ($a, $b) => (abs($b['net_gex']) <=> abs($a['net_gex'])) ?: ($a['strike'] <=> $b['strike']));

            return array_slice($walls, 0, 3);
        };

        return ['schema_version' => self::SCHEMA, 'model_version' => self::MODEL, 'scope' => $basis['scope'],
            'scope_key' => $basis['scope_key'], 'basis_key' => $basis['basis_key'],
            'observed_at' => $at->utc()->toIso8601String(), 'spot' => $spot,
            'walls' => ['put' => $rank('put'), 'call' => $rank('call')], 'strike_data' => $rows,
            'net_gex' => array_sum(array_column($rows, 'net_gex')),
            'provenance' => ['quote_source' => $quoteSource, 'time_kind' => 'provider_quote_asof',
                'oi_date' => $basis['source_date'], 'iv_date' => $basis['source_date'], 'greeks_source_at' => null,
                'greeks_evaluated_at' => $at->utc()->toIso8601String(), 'oi_observed_at' => null],
            'audit' => ['excluded_rows' => $excluded, 'contract_rows' => count($basis['contracts']),
                'oi_input_coverage_pct' => $totalOi > 0 ? 100 * $usedOi / $totalOi : null,
                'historical_outcome_eligible' => false, 'interaction_status' => 'unknown']];
    }

    public function timeline(array $observations): array
    {
        usort($observations, fn ($a, $b) => strcmp($a['observed_at'], $b['observed_at']));
        $segments = [];
        $previous = null;
        foreach ($observations as $observation) {
            $reason = match (true) {
                $previous === null => 'first_observation',
                $observation['scope_key'] !== $previous['scope_key'] => 'scope_changed',
                $observation['basis_key'] !== $previous['basis_key'] => 'inputs_changed',
                $observation['provenance']['quote_source'] !== $previous['provenance']['quote_source'] => 'quote_source_changed',
                strtotime($observation['observed_at']) - strtotime($previous['observed_at']) > (int) config('wall_tracking.comparison_gap_seconds', 900) => 'observation_gap',
                strtotime($observation['observed_at']) <= strtotime($previous['observed_at']) => 'same_time_revision',
                default => null,
            };
            if ($reason !== null) {
                $segments[] = ['id' => count($segments) + 1, 'start_reason' => $reason, 'observations' => []];
            }
            $segments[array_key_last($segments)]['observations'][] = $observation;
            $previous = $observation;
        }
        foreach ($segments as &$segment) {
            foreach (['put', 'call'] as $side) {
                // A missing wall interrupts that side's comparisons; never bridge it.
                $run = [];
                foreach ($segment['observations'] as $observation) {
                    $wall = $observation['walls'][$side][0] ?? null;
                    if ($wall === null) {
                        $run = [];
                    } else {
                        $run[] = $wall['strike'];
                    }
                }
                $migration = count($run) > 1 ? end($run) - reset($run) : null;
                $segment['migration'][$side] = ['amount' => $migration,
                    'direction' => $migration === null ? null : ($migration > 0 ? 'up' : ($migration < 0 ? 'down' : 'unchanged')),
                    'comparable_readings' => count($run)];
            }
        }
        unset($segment);

        return $segments;
    }
}
