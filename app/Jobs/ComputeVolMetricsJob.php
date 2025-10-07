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
}
