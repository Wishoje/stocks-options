<?php

namespace App\Support\WallIntelligence;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use DomainException;

/** Fixed-IV Black-Scholes scenarios; call-minus-put inventory convention. */
final class GammaProfileModel
{
    public const VERSION = 'eod-fixed-iv-gamma-profile.v1';

    public const INTRADAY_VERSION = 'intraday-fixed-reference-iv-gamma-profile.v1';

    public function calculate(array $contracts, float $spot, CarbonImmutable $at): array
    {
        return $this->profile($contracts, $spot, $at, false);
    }

    public function calculateIntraday(array $contracts, float $spot, CarbonImmutable $at): array
    {
        return $this->profile($contracts, $spot, $at, true);
    }

    private function profile(array $contracts, float $spot, CarbonImmutable $at, bool $intraday): array
    {
        if (! is_finite($spot) || $spot <= 0 || count($contracts) > 30000) {
            throw new DomainException('The selected gamma profile cannot be calculated.');
        }
        $started = hrtime(true);
        $budgetExceeded = fn () => $intraday && hrtime(true) - $started > 250_000_000;
        $unavailable = fn (string $reason) => ['status' => 'not_ready', 'reason' => $reason,
            'model_version' => self::INTRADAY_VERSION, 'curve' => [], 'crossings' => [],
            'gamma_flip' => null, 'regime' => null, 'net_gex_at_spot' => null];
        $prepared = [];
        $totalOi = $usedOi = 0.;
        $excluded = $floorRows = $unknownOi = 0;
        $expiryTimes = [];
        $prices = [];
        $lower = $spot * .9;
        $upper = $spot * 1.1;
        // Intraday collection keeps a smaller grid and adds narrow-contract shoulders.
        $steps = $intraday ? 200 : 400;
        for ($i = 0; $i <= $steps; $i++) {
            $prices[] = $lower + ($upper - $lower) * $i / $steps;
        }
        $prices[] = $spot;
        foreach ($contracts as $row) {
            $oi = WallSnapshotContract::number($row['oi'] ?? null);
            $unknownOi += $oi === null || $oi < 0 ? 1 : 0;
            $iv = WallSnapshotContract::number($row['iv'] ?? null);
            $strike = WallSnapshotContract::number($row['strike'] ?? null);
            $expiry = $row['expiry'];
            $totalOi += max(0, $oi ?? 0);
            if (! array_key_exists($expiry, $expiryTimes)) {
                $close = MarketSession::describe(CarbonImmutable::parse($expiry, 'America/New_York'))['closes_at'];
                $expiryTimes[$expiry] = $close ? CarbonImmutable::parse($close)->getTimestamp() : null;
            }
            $seconds = $expiryTimes[$expiry] === null ? -1 : $expiryTimes[$expiry] - $at->getTimestamp();
            if ($oi === null || $oi < 0 || $strike === null || $strike <= 0 || ($intraday ? $seconds <= 0 : $seconds < 0)
                || ! in_array($row['type'], ['call', 'put'], true) || ($oi > 0 && ($iv === null || $iv <= 0))) {
                $excluded++;

                continue;
            }
            if ($oi == 0) {
                continue;
            }
            // EOD 0DTE gamma is singular at expiry: declare a one-minute floor.
            $volTime = $iv * sqrt(($intraday ? $seconds : max(60, $seconds)) / (365 * 86400));
            if ($volTime <= 0 || ! is_finite($volTime * $volTime)) {
                $excluded++;

                continue;
            }
            $weight = ($row['type'] === 'call' ? 1 : -1) * $oi / (sqrt(2 * M_PI) * $volTime);
            if (! is_finite($weight * $upper * max(1, count($contracts)))) {
                $excluded++;

                continue;
            }
            $usedOi += $oi;
            $floorRows += ! $intraday && $seconds < 60 ? 1 : 0;
            $prepared[] = ['log_strike' => log($strike), 'vol_time' => $volTime,
                'weight' => $weight];
            if ((! $intraday && $seconds < 60) || ($intraday && $strike * $volTime < ($upper - $lower) / $steps)) {
                foreach ([-1, 0, 1] as $offset) {
                    $price = $strike * exp($offset * $volTime);
                    if ($price >= $lower && $price <= $upper) {
                        $prices[] = $price;
                    }
                }
            }
        }
        $audit = ['contract_rows' => count($contracts), 'excluded_rows' => $excluded,
            'unknown_oi_rows' => $unknownOi,
            'known_oi_coverage_pct' => $totalOi > 0 && is_finite($totalOi) ? 100 * ($usedOi / $totalOi) : null,
            'zero_dte_floor_rows' => $floorRows, 'historical_outcome_eligible' => false];
        // Coverage is an input gate, never a claim about the fraction of GEX captured.
        $minimumCoverage = $intraday ? (float) config('wall_tracking.minimum_oi_coverage_pct', 95) / 100 : .98;
        $maximumRowFailure = $intraday ? (float) config('wall_tracking.maximum_unknown_oi_row_pct', 10) / 100 : .1;
        $rowFailures = $intraday ? $unknownOi : $excluded;
        if (! $prepared || ! is_finite($totalOi) || $totalOi <= 0 || $usedOi / $totalOi < $minimumCoverage || $rowFailures / max(1, count($contracts)) > $maximumRowFailure) {
            return ['status' => 'not_ready', 'reason' => 'model_basis_not_ready', 'audit' => $audit,
                'curve' => [], 'crossings' => [], 'gamma_flip' => null, 'regime' => null, 'net_gex_at_spot' => null];
        }
        sort($prices, SORT_NUMERIC);
        $prices = array_values(array_unique(array_map(fn ($p) => round($p, 8), $prices)));
        if ($intraday && count($prices) > 601) {
            return $unavailable('profile_grid_limit');
        }
        // Preserve the exact reference price rather than deriving its sign by interpolation.
        $curve = [];
        foreach ($prices as $price) {
            if ($budgetExceeded()) {
                return $unavailable('profile_compute_budget');
            }
            $curve[] = $this->evaluate($prepared, $price);
        }
        $reference = $this->evaluate($prepared, $spot);
        $crossings = [];
        $previous = null;
        foreach ($curve as $point) {
            if ($point['gross_gex'] < .01) {
                $previous = null; // Never interpret numerical underflow as a crossing.

                continue;
            }
            if ($point['sign'] === 0) {
                continue;
            }
            if ($previous && $previous['sign'] !== $point['sign']) {
                $left = $previous['price'];
                $right = $point['price'];
                for ($iteration = 0; $iteration < 40; $iteration++) {
                    if ($budgetExceeded()) {
                        return $unavailable('profile_compute_budget');
                    }
                    $mid = $this->evaluate($prepared, ($left + $right) / 2);
                    if ($mid['sign'] === 0 || $right - $left < $spot * 1e-9) {
                        break;
                    }
                    if ($mid['sign'] === $previous['sign']) {
                        $left = $mid['price'];
                    } else {
                        $right = $mid['price'];
                    }
                }
                $crossings[] = ['price' => $mid['price'], 'below_sign' => $previous['sign'], 'above_sign' => $point['sign']];
                if ($intraday && count($crossings) > 16) {
                    return $unavailable('profile_crossing_limit');
                }
            }
            $previous = $point;
        }
        $nearest = $crossings;
        usort($nearest, fn ($a, $b) => abs($a['price'] - $spot) <=> abs($b['price'] - $spot));
        $single = count($crossings) === 1 ? $crossings[0]['price'] : null;
        foreach ($crossings as $crossing) {
            $curve[] = $this->evaluate($prepared, $crossing['price']);
        }
        $curve[] = $reference;
        usort($curve, fn ($a, $b) => $a['price'] <=> $b['price']);
        // String keys avoid PHP truncating floating prices to integer keys.
        $unique = [];
        foreach ($curve as $point) {
            $unique[sprintf('%.10f', $point['price'])] = $point;
        }
        $curve = array_values($unique);

        return ['status' => 'ready', 'model_version' => $intraday ? self::INTRADAY_VERSION : self::VERSION,
            'regime' => $reference['sign'] > 0 ? 'positive_gamma' : ($reference['sign'] < 0 ? 'negative_gamma' : 'balanced'),
            'current_sign' => $reference['sign'], 'net_gex_at_spot' => $reference['net_gex'], 'reference_point' => $reference,
            'flip_status' => count($crossings) > 1 ? 'multiple_crossings' : ($single !== null ? 'single_crossing' : 'no_crossing'),
            'gamma_flip' => $single, 'nearest_crossing' => $nearest[0] ?? null,
            'distance_to_flip_pct' => $single !== null ? 100 * abs($single - $spot) / $spot : null,
            'crossings' => $crossings, 'curve' => $curve, 'audit' => $audit,
            'grid' => ['lower_price' => $lower, 'upper_price' => $upper, 'range_pct' => 10, 'base_points' => $steps + 1,
                'method' => 'uniform_prices_plus_0dte_centers_and_shoulders; sign-change brackets refined by bisection',
                'limitation' => 'Crossings are identified inside the sampled range; narrow crossings between samples may not be resolved.'],
        ];
    }

    private function evaluate(array $contracts, float $price): array
    {
        $net = $gross = 0.;
        $log = log($price);
        foreach ($contracts as $contract) {
            $v = $contract['vol_time'];
            $d1 = ($log - $contract['log_strike'] + .5 * $v * $v) / $v;
            // Gamma * OI * 100 shares * price^2 * .01, signed by option side.
            $gex = $contract['weight'] * $price * exp(-.5 * $d1 * $d1);
            $net += $gex;
            $gross += abs($gex);
        }
        $tolerance = max(.01, $gross * 1e-10);

        return ['price' => $price, 'net_gex' => $net, 'gross_gex' => $gross,
            'sign' => abs($net) <= $tolerance ? 0 : ($net > 0 ? 1 : -1)];
    }
}
