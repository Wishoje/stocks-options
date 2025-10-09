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
use Illuminate\Support\Facades\Cache;


class ComputeVolMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(public array $symbols) {}

    public function handle(): void
    {
        $date = $this->tradingDate(now());

        foreach ($this->symbols as $symbol) {
            $symbol = \App\Support\Symbols::canon($symbol);

            // ---------- 1) TERM STRUCTURE ----------
            // From option_chain_data (latest data_date for each expiry),
            // choose ATM IV as (call_atm_iv + put_atm_iv)/2 fallback to simple OTM vwap
            $rows = $this->computeTermStructure($symbol, $date);

            // replace for idempotency
            DB::table('iv_term')->where('symbol',$symbol)->where('data_date',$date)->delete();
            if (!empty($rows)) {
                DB::table('iv_term')->insert($rows);
            }

            // ---------- 2) VRP ----------
            // Pick 1M IV ≈ expiry nearest 21 trading days out
            $iv1m = $this->pick1MIV($symbol, $date);
            $rv20 = $this->realizedVol20($symbol, $date);

            $vrp = (is_null($iv1m) || is_null($rv20)) ? null : ($iv1m - $rv20);
            $z   = $this->zscoreVRP($symbol, $date, $vrp);

            DB::table('vrp_daily')->updateOrInsert(
                ['symbol'=>$symbol,'data_date'=>$date],
                ['iv1m'=>$iv1m,'rv20'=>$rv20,'vrp'=>$vrp,'z'=>$z,'updated_at'=>now(),'created_at'=>now()]
            );

            $this->computeSkewCurvature($symbol, $date);

            Cache::forget("iv_term:{$symbol}");

            Cache::forget("vrp:{$symbol}");
        }
    }

    protected function computeTermStructure(string $symbol, string $date): array
    {
        // Use latest data_date per expiration on/<= today (you already store daily)
        $exp = DB::table('option_expirations')->where('symbol',$symbol)->pluck('id','expiration_date'); // date => id

        if ($exp->isEmpty()) return [];

        $expByDate = $exp->toArray(); // [exp_date => expiration_id]
        $expirationIds = array_values($expByDate);

        // Pull latest data_date per expiration_id
        $latest = DB::table('option_chain_data')
            ->select('expiration_id', DB::raw('MAX(data_date) as d'))
            ->whereIn('expiration_id', $expirationIds)
            ->groupBy('expiration_id');

        $oc = DB::table('option_chain_data as o')
            ->joinSub($latest, 'ld', fn($j) => $j
                ->on('o.expiration_id','=','ld.expiration_id')
                ->on('o.data_date','=','ld.d'))
            ->whereIn('o.expiration_id',$expirationIds)
            ->get(['o.expiration_id','o.option_type','o.strike','o.iv','o.underlying_price','o.volume']);

        if ($oc->isEmpty()) return [];

        $rows = [];
        foreach ($expByDate as $expDate => $expirationId) {
            $slice = $oc->where('expiration_id',$expirationId);
            if ($slice->isEmpty()) continue;

            // ATM midpoint IV:
            $S = (float) round(
                $slice->whereNotNull('underlying_price')->avg('underlying_price') ?? 0,
                4
            );
            if ($S <= 0) continue;

            // nearest strike on each side
            $callATM = $slice->where('option_type','call')->sortBy(fn($r)=>abs($r->strike - $S))->first();
            $putATM  = $slice->where('option_type','put' )->sortBy(fn($r)=>abs($r->strike - $S))->first();

            $ivATM = null;
            if (!empty($callATM?->iv) && !empty($putATM?->iv)) {
                $ivATM = 0.5 * ($callATM->iv + $putATM->iv);
            } elseif (!empty($callATM?->iv)) {
                $ivATM = $callATM->iv;
            } elseif (!empty($putATM?->iv)) {
                $ivATM = $putATM->iv;
            }

            // Fallback: simple OTM vwap (calls K>=S, puts K<=S)
            if (is_null($ivATM)) {
                $calls = $slice->where('option_type','call')->filter(fn($r)=>$r->strike >= $S && $r->iv>0);
                $puts  = $slice->where('option_type','put' )->filter(fn($r)=>$r->strike <= $S && $r->iv>0);
                $ivCall = $this->vwapIV($calls);
                $ivPut  = $this->vwapIV($puts);
                if (!is_null($ivCall) && !is_null($ivPut)) {
                    $ivATM = 0.5*($ivCall+$ivPut);
                } else {
                    $ivATM = $ivCall ?? $ivPut; // last fallback
                }
            }

            if (!is_null($ivATM)) {
                $rows[] = [
                    'symbol'    => $symbol,
                    'data_date' => $date,
                    'exp_date'  => $expDate,
                    'iv'        => (float)$ivATM,
                    'created_at'=> now(),
                    'updated_at'=> now(),
                ];
            }
        }
        // Return sorted by tenor
        usort($rows, fn($a,$b)=>strcmp($a['exp_date'],$b['exp_date']));
        return $rows;
    }

    protected function vwapIV($collection): ?float
    {
        $w = 0.0; $sum = 0.0;
        foreach ($collection as $r) {
            $vol = max(1.0, (float)($r->volume ?? 1)); // avoid zero weight
            if ($r->iv > 0) { $w += $vol; $sum += $vol * (float)$r->iv; }
        }
        return $w > 0 ? $sum / $w : null;
    }

    protected function pick1MIV(string $symbol, string $date): ?float
    {
        // 1) Prefer iv_term if present
        $rows = DB::table('iv_term')
            ->where('symbol',$symbol)->where('data_date',$date)
            ->orderBy('exp_date')->get(['exp_date','iv']);

        if ($rows->isNotEmpty()) {
            $target = \Carbon\Carbon::parse($date)->addDays(21)->toDateString();
            $best=null; $bestDiff=PHP_INT_MAX;
            foreach ($rows as $r) {
                if (is_null($r->iv)) continue;
                $d = abs(strtotime($r->exp_date) - strtotime($target));
                if ($d < $bestDiff) { $bestDiff = $d; $best = (float)$r->iv; }
            }
            if (!is_null($best)) return $best;
        }

        // 2) Fallback: compute directly from latest option_chain_data per expiry
        $exp = DB::table('option_expirations')->where('symbol',$symbol)->pluck('id','expiration_date');
        if ($exp->isEmpty()) return null;

        $expirationIds = array_values($exp->toArray());

        $latest = DB::table('option_chain_data')
            ->select('expiration_id', DB::raw('MAX(data_date) as d'))
            ->whereIn('expiration_id', $expirationIds)
            ->groupBy('expiration_id');

        $oc = DB::table('option_chain_data as o')
            ->joinSub($latest, 'ld', fn($j)=>$j
                ->on('o.expiration_id','=','ld.expiration_id')
                ->on('o.data_date','=','ld.d'))
            ->whereIn('o.expiration_id',$expirationIds)
            ->get(['o.expiration_id','o.option_type','o.strike','o.iv','o.underlying_price']);

        if ($oc->isEmpty()) return null;

        $targetTs = strtotime(\Carbon\Carbon::parse($date)->addDays(21)->toDateString());

        $bestIv = null; $bestDiff = PHP_INT_MAX;
        foreach ($exp as $expDate => $expId) {
            $slice = $oc->where('expiration_id',$expId);
            if ($slice->isEmpty()) continue;
            $S = (float)($slice->first()->underlying_price ?? 0);
            if ($S <= 0) continue;

            $callATM = $slice->where('option_type','call')->sortBy(fn($r)=>abs($r->strike - $S))->first();
            $putATM  = $slice->where('option_type','put' )->sortBy(fn($r)=>abs($r->strike - $S))->first();

            $ivATM = null;
            if (!empty($callATM?->iv) && !empty($putATM?->iv))      $ivATM = 0.5*($callATM->iv + $putATM->iv);
            elseif (!empty($callATM?->iv))                          $ivATM = $callATM->iv;
            elseif (!empty($putATM?->iv))                           $ivATM = $putATM->iv;

            if (is_null($ivATM)) continue;

            $diff = abs(strtotime($expDate) - $targetTs);
            if ($diff < $bestDiff) { $bestDiff = $diff; $bestIv = (float)$ivATM; }
        }
        return $bestIv;
    }


    protected function realizedVol20(string $symbol, string $date): ?float
    {
        // need last 21 closes up to $date
        $prices = DB::table('prices_daily')
            ->where('symbol',$symbol)
            ->where('trade_date','<=',$date)
            ->orderByDesc('trade_date')
            ->limit(22) // a bit more to be safe
            ->get(['trade_date','close'])
            ->sortBy('trade_date')
            ->values();

        if ($prices->count() < 21) return null;

        // log returns for last 20 intervals
        $rets = [];
        for ($i=1; $i<$prices->count(); $i++) {
            $c0 = (float)$prices[$i-1]->close;
            $c1 = (float)$prices[$i]->close;
            if ($c0>0 && $c1>0) $rets[] = log($c1/$c0);
        }
        if (count($rets) < 20) return null;

        // sample std dev of daily logs; annualize with sqrt(252)
        $mean = array_sum($rets)/count($rets);
        $var = 0.0; foreach ($rets as $r){ $var += ($r-$mean)*($r-$mean); }
        $sd = sqrt($var / max(1, count($rets)-1));
        return $sd * sqrt(252.0);
    }

    protected function zscoreVRP(string $symbol, string $date, ?float $vrp): ?float
    {
        if (is_null($vrp)) return null;

        // trailing 252d window *prior to* date
        $hist = DB::table('vrp_daily')
            ->where('symbol',$symbol)
            ->where('data_date','<',$date)
            ->orderByDesc('data_date')
            ->limit(252)
            ->pluck('vrp')
            ->filter(fn($x)=>!is_null($x))
            ->values();

        if ($hist->count() < 30) return null; // need a base

        $m = $hist->avg();
        // std
        $var=0.0; foreach($hist as $x){ $var += ($x-$m)*($x-$m); }
        $sd = sqrt($var / max(1,$hist->count()-1));
        return $sd > 0 ? ($vrp - $m)/$sd : null;
    }

    protected function tradingDate(\Carbon\Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }

    protected function computeSkewCurvature(string $symbol, string $date): void
    {
        // map exp_date => expiration_id
        $exp = \DB::table('option_expirations')
            ->where('symbol', $symbol)
            ->pluck('id','expiration_date'); // ['YYYY-MM-DD' => id]

        if ($exp->isEmpty()) return;

        $expirationIds = array_values($exp->toArray());

        // latest data_date per expiry (we want today's if present)
        $latest = \DB::table('option_chain_data')
            ->select('expiration_id', \DB::raw('MAX(data_date) as d'))
            ->whereIn('expiration_id', $expirationIds)
            ->groupBy('expiration_id');

        $rows = \DB::table('option_chain_data as o')
            ->joinSub($latest, 'ld', fn($j)=>$j
                ->on('o.expiration_id','=','ld.expiration_id')
                ->on('o.data_date','=','ld.d'))
            ->whereIn('o.expiration_id',$expirationIds)
            ->get(['o.expiration_id','o.option_type','o.strike','o.delta','o.iv','o.underlying_price']);

        if ($rows->isEmpty()) return;

        foreach ($exp as $expDate => $expId) {
            $slice = $rows->where('expiration_id', $expId)
                        ->filter(fn($r)=> !is_null($r->iv) && !is_null($r->delta) && !is_null($r->strike));

            if ($slice->isEmpty()) continue;

            // 25Δ IVs (linear interpolation in delta)
            $iv25c = $this->ivAtTargetDelta($slice->where('option_type','call'), +0.25);
            $iv25p = $this->ivAtTargetDelta($slice->where('option_type','put' ), -0.25);

            // Curvature via quadratic fit IV ~ a*k^2 + b*k + c where k=ln(K/S)
            $S = (float) round($slice->avg('underlying_price') ?? 0, 6);
            $curv = null;
            if ($S > 0) {
                // choose points around ATM; e.g. 10–30 total samples
                $pts = $slice->filter(fn($r)=> $r->iv>0 && $r->strike>0)
                            ->sortBy(fn($r)=>abs($r->strike - $S))
                            ->take(30)
                            ->map(fn($r)=>[
                                'k' => (float)log($r->strike / $S),
                                'iv'=> (float)$r->iv
                            ])->values()->all();
                if (count($pts) >= 6) {
                    $pts = $slice->filter(fn($r)=> $r->iv>0 && $r->strike>0)
                    ->map(fn($r)=>[
                        'k'  => (float)log($r->strike / $S),
                        'iv' => (float)$r->iv,
                    ])
                    // keep points within +/- 30% log-moneyness
                    ->filter(fn($p)=> abs($p['k']) <= 0.30)
                    // prefer nearer to ATM but allow breadth
                    ->sortBy(fn($p)=>abs($p['k']))
                    ->take(60) // a few dozen points is fine
                    ->values()->all();
                }

                if (count($pts) >= 10) {
                    $span = 0.0;
                    foreach ($pts as $p) { $span = max($span, abs($p['k'])); }
                    // require at least ~5% moneyness span, else curvature too unstable
                    if ($span >= 0.05) {
                        $curvRaw = $this->quadA($pts);
                        $curv = is_finite($curvRaw) ? $curvRaw * 0.01 : null; 
                        // sanity clamp: drop absurd values
                        if (!is_finite($curv) || abs($curv) > 1e6) $curv = null;
                    } else {
                        $curv = null;
                    }
                }
            }

            // skew in vol points (decimal)
            $skew = (is_null($iv25p) || is_null($iv25c)) ? null : ($iv25p - $iv25c);

            if (!is_finite($curv) || abs($curv) > 1e9) { $curv = null; }
            if (!is_finite($skew) || abs($skew) > 10)  { $skew = null; }

            // compute DoD deltas vs prior date (same exp)
            $prev = \DB::table('iv_skew')
                ->where('symbol',$symbol)->where('exp_date',$expDate)
                ->where('data_date','<',$date)
                ->orderByDesc('data_date')
                ->first(['skew_pc','curvature']);

            $skew_dod = (!is_null($skew) && $prev) ? ($skew - (float)$prev->skew_pc) : null;
            $curv_dod = (!is_null($curv) && $prev) ? ($curv - (float)$prev->curvature) : null;

            \DB::table('iv_skew')->updateOrInsert(
                ['symbol'=>$symbol,'data_date'=>$date,'exp_date'=>$expDate],
                [
                    'iv_put_25d' => $iv25p,
                    'iv_call_25d'=> $iv25c,
                    'skew_pc'    => $skew,
                    'curvature'  => $curv,
                    'skew_pc_dod'=> $skew_dod,
                    'curvature_dod'=>$curv_dod,
                    'updated_at'=>now(),'created_at'=>now(),
                ]
            );
        }
    }

    protected function ivAtTargetDelta($collection, float $target): ?float
    {
        // Find two surrounding points by |delta - target|
        $pts = $collection->map(fn($r)=> ['d'=>(float)$r->delta, 'iv'=>(float)$r->iv])
                        ->filter(fn($p)=> is_finite($p['d']) && is_finite($p['iv']))
                        ->sortBy('d')->values()->all();
        if (count($pts) === 0) return null;

        // exact match?
        foreach ($pts as $p) { if (abs($p['d'] - $target) < 1e-6) return $p['iv']; }

        // find lower<=target<=upper by delta
        $lo=null; $hi=null;
        foreach ($pts as $p) {
            if ($p['d'] <= $target) $lo = $p;
            if ($p['d'] >= $target) { $hi = $p; break; }
        }
        if (!$lo || !$hi || $lo['d'] === $hi['d']) {
            // fallback: nearest
            usort($pts, fn($a,$b)=> abs($a['d']-$target) <=> abs($b['d']-$target));
            return $pts[0]['iv'] ?? null;
        }
        // linear in delta
        $t = ($target - $lo['d']) / ($hi['d'] - $lo['d']);
        return $lo['iv'] + $t * ($hi['iv'] - $lo['iv']);
    }

    protected function quadA(array $pts): ?float
    {
        // least squares for y = a*k^2 + b*k + c
        $n = count($pts);
        $s = fn($f)=> array_reduce($pts, fn($acc,$p)=> $acc + $f($p), 0.0);

        $S0 = $n;
        $S1 = $s(fn($p)=> $p['k']);
        $S2 = $s(fn($p)=> $p['k']*$p['k']);
        $S3 = $s(fn($p)=> $p['k']*$p['k']*$p['k']);
        $S4 = $s(fn($p)=> $p['k']*$p['k']*$p['k']*$p['k']);

        $T0 = $s(fn($p)=> $p['iv']);
        $T1 = $s(fn($p)=> $p['iv']*$p['k']);
        $T2 = $s(fn($p)=> $p['iv']*$p['k']*$p['k']);

        // Solve 3x3 normal equations
        // [S4 S3 S2][a] = [T2]
        // [S3 S2 S1][b]   [T1]
        // [S2 S1 S0][c]   [T0]
        $A = [
            [$S4,$S3,$S2],
            [$S3,$S2,$S1],
            [$S2,$S1,$S0],
        ];
        $B = [$T2,$T1,$T0];

        $det = function($M){
            return $M[0][0]*($M[1][1]*$M[2][2]-$M[1][2]*$M[2][1])
                - $M[0][1]*($M[1][0]*$M[2][2]-$M[1][2]*$M[2][0])
                + $M[0][2]*($M[1][0]*$M[2][1]-$M[1][1]*$M[2][0]);
        };
        $D = $det($A);
        if (abs($D) < 1e-12) return null;

        // Cramer's rule for a
        $A_a = [
            [$B[0],$A[0][1],$A[0][2]],
            [$B[1],$A[1][1],$A[1][2]],
            [$B[2],$A[2][1],$A[2][2]],
        ];
        return $det($A_a)/$D;
    }

}
