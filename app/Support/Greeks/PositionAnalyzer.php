<?php

namespace App\Support\Greeks;
use App\Support\Greeks\ImpliedVol;

use Carbon\Carbon;

final class PositionAnalyzer
{
    public static function yf(Carbon $now, Carbon $expiry): float {
        $hrs = max(0.0, $expiry->clone()->endOfDay()->diffInHours($now));
        return $hrs/(365.0*24.0);
    }

    /** Leg shape:
     * [
     *   'type'  => 'call'|'put',
     *   'side'  => 'long'|'short',
     *   'qty'   => 1,
     *   'strike'=> 450.0,
     *   'iv'    => 0.24,         // decimal; optional (fallback to chain mid IV or payload default_iv)
     *   'price' => 2.15,         // entry price per contract; optional
     *   'expiry'=> '2025-12-20',
     * ]
     */
    public static function analyze(array $legs, float $S, float $r=0.0, float $q=0.0, ?float $defaultIv=null, ?array $scenarios=null): array
    {
        $now = now('America/New_York');
        $mult = 100.0;

        // ---- NOW snapshot ----
        $nowGreeks = ['delta'=>0,'gamma'=>0,'theta'=>0,'vega'=>0,'rho'=>0,'price'=>0];

        foreach ($legs as $L) {
            $qty   = (int)($L['qty'] ?? 1);
            $qty  *= ($L['side'] ?? 'long') === 'short' ? -1 : 1;
            $type  = strtolower($L['type'] ?? 'call');
            $K     = (float)$L['strike'];
            $iv    = (float)($L['iv'] ?? $defaultIv ?? 0.20);
            $exp   = Carbon::parse($L['expiry'], 'America/New_York');
            $T     = max(1e-6, self::yf($now, $exp));
            $iv = $L['iv'] ?? null;

            // If no IV but we have an entry price for the leg, back it out:
            if ($iv === null && isset($L['price'])) {
                $iv = ImpliedVol::fromPrice($type, $S, $K, $T, $r, $q, (float)$L['price']);
            }

            $iv = (float)($iv ?? $defaultIv ?? 0.20);
            $iv = max(0.01, $iv);

            $g = BlackScholes::greeks($type, $S, $K, $T, max(0.01,$iv), $r, $q);
            foreach ($nowGreeks as $k => $_) {
                $nowGreeks[$k] += $g[$k] * $qty * $mult; // portfolio scaling
            }
        }

        // ---- payoff curve today (theoretical prices), used for PNL-at-close style view ----
        $low  = $S * 0.6; $high = $S * 1.4; $steps = 120;
        $dx   = ($high-$low)/$steps;
        $payoff = [];

        for ($i=0; $i<=$steps; $i++) {
            $spot = $low + $i*$dx;
            $pv = 0.0;
            foreach ($legs as $L) {
                $qty   = (int)($L['qty'] ?? 1);
                $qty  *= ($L['side'] ?? 'long') === 'short' ? -1 : 1;
                $type  = strtolower($L['type'] ?? 'call');
                $K     = (float)$L['strike'];
                $price = (float)($L['price'] ?? 0.0);     // entry
                $iv    = (float)($L['iv'] ?? $defaultIv ?? 0.20);
                $exp   = Carbon::parse($L['expiry'], 'America/New_York');
                $T     = max(1e-6, self::yf($now, $exp));
                $iv = $L['iv'] ?? null;

                // If no IV but we have an entry price for the leg, back it out:
                if ($iv === null && isset($L['price'])) {
                    $iv = ImpliedVol::fromPrice($type, $S, $K, $T, $r, $q, (float)$L['price']);
                }

                $iv = (float)($iv ?? $defaultIv ?? 0.20);
                $iv = max(0.01, $iv);


                $prNow = BlackScholes::greeks($type, $spot, $K, $T, max(0.01,$iv), $r, $q)['price'];
                $pv   += ($prNow - $price) * $qty * $mult;
            }
            $payoff[] = ['S' => round($spot,2), 'pnl' => round($pv,2)];
        }

        // ---- scenarios (spot %, iv pts, days) ----
        $sc = $scenarios ?? [
            'spot_pct' => [-0.1,-0.05,0,0.05,0.1],  // ±10%, ±5%, 0
            'iv_pts'   => [-0.05,-0.02,0,0.02,0.05],// -5 to +5 vol points
            'days'     => [0, 5, 10],               // T-steps
        ];

        $grid = [];
        foreach ($sc['days'] as $d) {
            foreach ($sc['spot_pct'] as $dpct) {
                foreach ($sc['iv_pts'] as $dvol) {
                    $S2 = $S * (1.0 + $dpct);
                    $pnl = 0.0; $agg = ['delta'=>0,'gamma'=>0,'theta'=>0,'vega'=>0,'rho'=>0,'price'=>0];
                    foreach ($legs as $L) {
                        $qty   = (int)($L['qty'] ?? 1);
                        $qty  *= ($L['side'] ?? 'long') === 'short' ? -1 : 1;
                        $type  = strtolower($L['type'] ?? 'call');
                        $K     = (float)$L['strike'];
                        $iv0   = (float)($L['iv'] ?? $defaultIv ?? 0.20);
                        $iv2   = max(0.01, $iv0 + $dvol);         // iv points
                        $price = (float)($L['price'] ?? 0.0);
                        $exp   = Carbon::parse($L['expiry'], 'America/New_York');
                        $T     = max(1e-6, self::yf($now->clone()->addDays($d), $exp));
                        $iv = $L['iv'] ?? null;

                        // If no IV but we have an entry price for the leg, back it out:
                        if ($iv === null && isset($L['price'])) {
                            $iv = ImpliedVol::fromPrice($type, $S, $K, $T, $r, $q, (float)$L['price']);
                        }

                        $iv = (float)($iv ?? $defaultIv ?? 0.20);
                        $iv = max(0.01, $iv);

                        $g = BlackScholes::greeks($type, $S2, $K, $T, $iv2, $r, $q);
                        foreach ($agg as $k => $_) { $agg[$k] += $g[$k] * $qty * $mult; }
                        $pnl += ($g['price'] - $price) * $qty * $mult;
                    }
                    $grid[] = [
                        'd_days'=>$d, 'd_spot'=>$dpct, 'd_iv'=>$dvol,
                        'pnl'=>round($pnl,2),
                        'greeks'=>array_map(fn($v)=>round($v,6), $agg),
                    ];
                }
            }
        }

        return [
            'now'     => array_map(fn($v)=>is_numeric($v)?round($v,6):$v, $nowGreeks),
            'payoff'  => $payoff,
            'grid'    => $grid,
        ];
    }
}
