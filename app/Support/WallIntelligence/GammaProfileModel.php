<?php

namespace App\Support\WallIntelligence;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use DomainException;

/** Fixed-IV Black-Scholes scenarios; call-minus-put inventory convention. */
final class GammaProfileModel
{
    public const VERSION = 'eod-fixed-iv-gamma-profile.v1';

    public function calculate(array $contracts, float $spot, CarbonImmutable $at): array
    {
        if (! is_finite($spot) || $spot <= 0 || count($contracts) > 30000) {
            throw new DomainException('The selected gamma profile cannot be calculated.');
        }
        $prepared = [];
        $totalOi = $usedOi = 0.;
        $excluded = $floorRows = 0;
        $expiryTimes = [];
        $prices = [];
        $lower = $spot * .9;
        $upper = $spot * 1.1;
        // 401 regular samples across +/-10%, plus narrow 0DTE centers and shoulders.
        for ($i = 0; $i <= 400; $i++) {
            $prices[] = $lower + ($upper - $lower) * $i / 400;
        }
        $prices[] = $spot;
        foreach ($contracts as $row) {
            $oi = WallSnapshotContract::number($row['oi'] ?? null);
            $iv = WallSnapshotContract::number($row['iv'] ?? null);
            $strike = WallSnapshotContract::number($row['strike'] ?? null);
            $expiry = $row['expiry'];
            $totalOi += max(0, $oi ?? 0);
            if (! array_key_exists($expiry, $expiryTimes)) {
                $close = MarketSession::describe(CarbonImmutable::parse($expiry, 'America/New_York'))['closes_at'];
                $expiryTimes[$expiry] = $close ? CarbonImmutable::parse($close)->getTimestamp() : null;
            }
            $seconds = $expiryTimes[$expiry] === null ? -1 : $expiryTimes[$expiry] - $at->getTimestamp();
            if ($oi === null || $oi < 0 || $strike === null || $strike <= 0 || $seconds < 0
                || ! in_array($row['type'], ['call', 'put'], true) || ($oi > 0 && ($iv === null || $iv <= 0))) {
                $excluded++;

                continue;
            }
            if ($oi == 0) {
                continue;
            }
            // EOD 0DTE gamma is singular at expiry: declare a one-minute floor.
            $volTime = $iv * sqrt(max(60, $seconds) / (365 * 86400));
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
            $floorRows += $seconds < 60 ? 1 : 0;
            $prepared[] = ['log_strike' => log($strike), 'vol_time' => $volTime,
                'weight' => $weight];
            if ($seconds < 60) {
                foreach ([-1, 0, 1] as $offset) {
                    $price = $strike * exp($offset * $volTime);
                    if ($price >= $lower && $price <= $upper) {
                        $prices[] = $price;
                    }
                }
            }
        }
        $audit = ['contract_rows' => count($contracts), 'excluded_rows' => $excluded,
            'known_oi_coverage_pct' => $totalOi > 0 && is_finite($totalOi) ? 100 * ($usedOi / $totalOi) : null,
            'zero_dte_floor_rows' => $floorRows, 'historical_outcome_eligible' => false];
        // Coverage is an input gate, never a claim about the fraction of GEX captured.
        if (! $prepared || ! is_finite($totalOi) || $totalOi <= 0 || $usedOi / $totalOi < .98 || $excluded / max(1, count($contracts)) > .1) {
            return ['status' => 'not_ready', 'reason' => 'model_basis_not_ready', 'audit' => $audit,
                'curve' => [], 'crossings' => [], 'gamma_flip' => null, 'regime' => null, 'net_gex_at_spot' => null];
        }
        sort($prices, SORT_NUMERIC);
        $prices = array_values(array_unique(array_map(fn ($p) => round($p, 8), $prices)));
        // Preserve the exact reference price rather than deriving its sign by interpolation.
        $curve = array_map(fn ($price) => $this->evaluate($prepared, $price), $prices);
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

        return ['status' => 'ready', 'model_version' => self::VERSION,
            'regime' => $reference['sign'] > 0 ? 'positive_gamma' : ($reference['sign'] < 0 ? 'negative_gamma' : 'balanced'),
            'current_sign' => $reference['sign'], 'net_gex_at_spot' => $reference['net_gex'], 'reference_point' => $reference,
            'flip_status' => count($crossings) > 1 ? 'multiple_crossings' : ($single !== null ? 'single_crossing' : 'no_crossing'),
            'gamma_flip' => $single, 'nearest_crossing' => $nearest[0] ?? null,
            'distance_to_flip_pct' => $single !== null ? 100 * abs($single - $spot) / $spot : null,
            'crossings' => $crossings, 'curve' => $curve, 'audit' => $audit,
            'grid' => ['lower_price' => $lower, 'upper_price' => $upper, 'range_pct' => 10, 'base_points' => 401,
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
