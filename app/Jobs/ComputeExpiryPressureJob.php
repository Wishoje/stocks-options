<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ComputeExpiryPressureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(public array $symbols, public int $days = 3) {}

    public function handle(): void
    {
        $date = $this->tradingDate(now());

        foreach ($this->symbols as $raw) {
            $symbol = \App\Support\Symbols::canon($raw);

            // find expiries within N trading days
            $end = $this->addTradingDays(Carbon::now('America/New_York')->toDateString(), $this->days);
            $expDates = DB::table('option_expirations')
                ->where('symbol', $symbol)
                ->whereBetween('expiration_date', [$date, $end])
                ->orderBy('expiration_date')
                ->pluck('expiration_date');

            if ($expDates->isEmpty()) continue;

            foreach ($expDates as $expDate) {

                // get the latest data_date for this expiry
                $latest = DB::table('option_chain_data as o')
                    ->join('option_expirations as e','e.id','=','o.expiration_id')
                    ->where('e.symbol', $symbol)
                    ->whereDate('e.expiration_date', $expDate)
                    ->max('o.data_date');

                if (!$latest) continue;

                // pull snapshot rows for that latest date and expiry
                $rows = DB::table('option_chain_data as o')
                    ->join('option_expirations as e','e.id','=','o.expiration_id')
                    ->where('e.symbol', $symbol)
                    ->whereDate('e.expiration_date', $expDate)
                    ->whereDate('o.data_date', $latest)
                    ->get(['o.strike','o.option_type','o.open_interest','o.underlying_price']);

                if ($rows->isEmpty()) continue;

                // spot proxy = avg underlying_price of snapshot
                $spot = (float) round($rows->avg('underlying_price') ?? 0, 4);

                // 1) Build OI histogram near spot (window ±10% or fixed strikes)
                $oiByStrike = [];
                foreach ($rows as $r) {
                    $k = (float) $r->strike;
                    $oiByStrike[$k] = ($oiByStrike[$k] ?? 0) + (int)($r->open_interest ?? 0);
                }
                ksort($oiByStrike);

                // keep strikes within ±10% of spot (fallback: keep all if spot=0)
                $filtered = [];
                $lo = $spot>0 ? $spot * 0.9 : min(array_keys($oiByStrike));
                $hi = $spot>0 ? $spot * 1.1 : max(array_keys($oiByStrike));
                foreach ($oiByStrike as $k=>$v) {
                    if ($k >= $lo && $k <= $hi) $filtered[$k] = $v;
                }
                if (empty($filtered)) $filtered = $oiByStrike;

                // 2) Rolling density (cluster) – simple moving sum across strike neighbors
                // choose a window of ~5 strikes
                $keys = array_values(array_keys($filtered));
                $vals = array_values(array_map('intval', $filtered));
                $n    = count($keys);
                $w    = min(5, max(3, (int)floor($n/12))); // adapt if many/few strikes
                if ($w % 2 === 0) $w++;                    // make odd
                $half = intdiv($w, 2);

                $densities = [];
                for ($i=0; $i<$n; $i++) {
                    $sum = 0;
                    for ($j=$i-$half; $j<=$i+$half; $j++) {
                        if ($j>=0 && $j<$n) $sum += $vals[$j];
                    }
                    $densities[$i] = $sum;
                }
                $maxDen = max(1, max($densities));

                // 3) Score each center strike by density × proximity (to spot)
                // proximity weight = exp(-distance / (0.5% spot)) capped
                $clusters = [];
                foreach ($densities as $i=>$den) {
                    $k  = $keys[$i];
                    $dP = $spot>0 ? abs($k - $spot) / $spot : 0.0;
                    $proxW = exp(-( $dP / 0.005 )); // sharp drop after ~0.5%
                    $rawScore = ($den / $maxDen) * $proxW; // 0..1
                    $clusters[] = [
                        'strike'   => $k,
                        'width_n'  => $w,
                        'density'  => $den,
                        'distance' => round($k - $spot, 4),
                        'score'    => $rawScore,
                    ];
                }

                // pick top clusters (merge near-duplicates: within 0.2% of spot/strike step)
                usort($clusters, fn($a,$b)=> $b['score'] <=> $a['score']);
                $uniq = [];
                foreach ($clusters as $c) {
                    $tooClose = false;
                    foreach ($uniq as $u) {
                        if (abs($u['strike'] - $c['strike']) <= max(0.2, $spot*0.002)) {
                            $tooClose = true; break;
                        }
                    }
                    if (!$tooClose) $uniq[] = $c;
                    if (count($uniq) >= 6) break;
                }

                // overall pin_score = 0..100 from best cluster
                $pinScore = (int) round(min(100, max(0, ($uniq[0]['score'] ?? 0)*100)));

                // 4) Max Pain: brute-force over candidate prices = observed strikes (near ±10%)
                // payoff = calls: max(0, P-K) + puts: max(0, K-P)
                $byK = [];
                foreach ($rows as $r) {
                    $k = (float) $r->strike;
                    $type = $r->option_type;
                    $oi   = (int)($r->open_interest ?? 0);
                    $byK[$k][$type] = ($byK[$k][$type] ?? 0) + $oi;
                }
                $candidates = array_keys($filtered);
                sort($candidates);

                $bestP = null; $bestCost = INF;
                foreach ($candidates as $P) {
                    $cost = 0.0;
                    foreach ($byK as $K => $bucket) {
                        $callOi = $bucket['call'] ?? 0;
                        $putOi  = $bucket['put']  ?? 0;
                        $cost += max(0, $P - $K) * $callOi * 100.0;
                        $cost += max(0, $K - $P) * $putOi  * 100.0;
                    }
                    if ($cost < $bestCost) { $bestCost = $cost; $bestP = $P; }
                }

                DB::table('expiry_pressure')->updateOrInsert(
                    ['symbol'=>$symbol, 'data_date'=>$date, 'exp_date'=>$expDate],
                    [
                        'pin_score'    => $pinScore,
                        'clusters_json'=> json_encode($uniq, JSON_THROW_ON_ERROR),
                        'max_pain'     => $bestP,
                        'updated_at'   => now(),
                        'created_at'   => now(),
                    ]
                );
            }
        }
    }

    protected function tradingDate(Carbon $now): string {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }

    protected function addTradingDays(string $start, int $n): string {
        $d = Carbon::parse($start, 'America/New_York');
        $left = $n;
        while ($left > 0) {
            $d->addDay();
            if (!$d->isWeekend()) $left--;
        }
        return $d->toDateString();
    }
}
