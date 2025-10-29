<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class ComputeUAJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(
        public array $symbols,
        public int   $lookbackDays = 30,
        public float $Z_SCORE_MIN  = 3.0,
        public float $VOL_OI_MIN   = 0.50,
        public int   $VOL_MIN      = 50
        //public float $Z_SCORE_MIN = 2.0,
        //public float $VOL_OI_MIN = 0.25,
        //public int   $VOL_MIN    = 10,
    ) {}

    public function handle(): void
    {
        foreach ($this->symbols as $raw) {
            $symbol = \App\Support\Symbols::canon($raw);

            $latest = DB::table('option_chain_data as o')
                ->join('option_expirations as e','e.id','=','o.expiration_id')
                ->where('e.symbol',$symbol)
                ->max('o.data_date');

            if (!$latest) continue;

            // 1) Find expiries that have data today (fast path)
            $expiries = DB::table('option_chain_data as o')
                ->join('option_expirations as e','e.id','=','o.expiration_id')
                ->where('e.symbol', $symbol)
                ->whereDate('o.data_date', $latest)
                ->pluck('e.expiration_date');

            if ($expiries->isEmpty()) continue;

            foreach ($expiries as $exp) {
            // 2) Today’s per-strike volume & OI (call+put aggregated)
            // ---- build a safe select list for price fields (only what exists)
            $priceCols = [];
            if (Schema::hasColumn('option_chain_data','mid_price'))   $priceCols[] = 'o.mid_price';
            if (Schema::hasColumn('option_chain_data','mark'))        $priceCols[] = 'o.mark';
            if (Schema::hasColumn('option_chain_data','last_price'))  $priceCols[] = 'o.last_price';
            if (Schema::hasColumn('option_chain_data','close'))       $priceCols[] = 'o.close';
            if (Schema::hasColumn('option_chain_data','bid'))         $priceCols[] = 'o.bid';
            if (Schema::hasColumn('option_chain_data','ask'))         $priceCols[] = 'o.ask';

            $select = array_merge(
                ['o.strike','o.option_type','o.volume','o.open_interest'],
                $priceCols
            );

            $todayRows = DB::table('option_chain_data as o')
                ->join('option_expirations as e','e.id','=','o.expiration_id')
                ->where('e.symbol', $symbol)
                ->whereDate('e.expiration_date', $exp)
                ->whereDate('o.data_date', $latest)
                ->get($select);  // ← only selects columns that actually exist

            if ($todayRows->isEmpty()) continue;

            $aggToday = [];
            $oiToday  = [];

            foreach ($todayRows as $r) {
                $k = (float)$r->strike;
                $isCall = $r->option_type === 'call';
                $vol    = (int)($r->volume ?? 0);

                // volumes
                $aggToday[$k]['call_vol'] = ($aggToday[$k]['call_vol'] ?? 0) + ($isCall ? $vol : 0);
                $aggToday[$k]['put_vol']  = ($aggToday[$k]['put_vol']  ?? 0) + (!$isCall ? $vol : 0);

                // OI
                $oiToday[$k] = ($oiToday[$k] ?? 0) + (int)($r->open_interest ?? 0);

                // pick a unit price for premium calc (prefer mid/mark/last/close, then bid/ask mid)
                $px = null;
                if (property_exists($r,'mid_price')   && $r->mid_price   !== null) $px = (float)$r->mid_price;
                elseif (property_exists($r,'mark')     && $r->mark       !== null) $px = (float)$r->mark;
                elseif (property_exists($r,'last_price')&& $r->last_price!== null) $px = (float)$r->last_price;
                elseif (property_exists($r,'close')    && $r->close      !== null) $px = (float)$r->close;
                elseif (property_exists($r,'bid') && property_exists($r,'ask') && $r->bid !== null && $r->ask !== null) {
                    $px = ((float)$r->bid + (float)$r->ask) / 2.0;
                } elseif (property_exists($r,'bid') && $r->bid !== null) {
                    $px = (float)$r->bid;
                } elseif (property_exists($r,'ask') && $r->ask !== null) {
                    $px = (float)$r->ask;
                }

                if ($px !== null) {
                    $aggToday[$k]['call_prem'] = ($aggToday[$k]['call_prem'] ?? 0) + ($isCall ? $vol * $px * 100 : 0);
                    $aggToday[$k]['put_prem']  = ($aggToday[$k]['put_prem']  ?? 0) + (!$isCall ? $vol * $px * 100 : 0);
                }
            }
                foreach ($aggToday as $k=>$v) {
                    $aggToday[$k]['total_vol']   = (int)($v['call_vol'] ?? 0) + (int)($v['put_vol'] ?? 0);
                    $aggToday[$k]['total_prem']  = null;
                    if (array_key_exists('call_prem',$v) || array_key_exists('put_prem',$v)) {
                        $aggToday[$k]['total_prem'] = (float)($v['call_prem'] ?? 0) + (float)($v['put_prem'] ?? 0);
                    }
                }

                // 3) Build 30d baseline of total volume per strike
                $start = Carbon::parse($latest, 'America/New_York')->subDays($this->lookbackDays)->toDateString();
                $hist = DB::table('option_chain_data as o')
                    ->join('option_expirations as e','e.id','=','o.expiration_id')
                    ->where('e.symbol', $symbol)
                    ->whereDate('e.expiration_date', $exp)
                    ->whereBetween('o.data_date', [$start, $latest])
                    ->selectRaw('o.data_date, o.strike, SUM(o.volume) as total_vol') // call+put
                    ->groupBy('o.data_date','o.strike')
                    ->get();

                // reshape: strike => list of historical totals
                $series = [];
                foreach ($hist as $h) {
                    $k = (float)$h->strike;
                    $series[$k] = $series[$k] ?? [];
                    $series[$k][] = (int)$h->total_vol;
                }

                $payload = [];
                foreach ($aggToday as $k => $v) {
                    $histArr = $series[$k] ?? [];
                    // need at least ~5 observations to form a useful baseline
                    if (count($histArr) < 5) continue;

                    [$mu, $sigma] = $this->winsorizedStats($histArr);
                    $sigma = max($sigma, 1.0); // prevent /0

                    $z = ((int)$v['total_vol'] - $mu) / $sigma;
                    $oi = max(1, (int)($oiToday[$k] ?? 0));
                    $ratio = (int)$v['total_vol'] / $oi;

                    if ($v['total_vol'] >= $this->VOL_MIN && ($z >= $this->Z_SCORE_MIN || $ratio >= $this->VOL_OI_MIN)) {
                      $payload[] = [
                        'symbol'    => $symbol,
                        'data_date' => $latest,
                        'exp_date'  => $exp,
                        'strike'    => $k,
                        'z_score'   => round($z, 3),
                        'vol_oi'    => round($ratio, 4),
                        'meta'      => json_encode([
                            'call_vol'    => (int)$v['call_vol'],
                            'put_vol'     => (int)$v['put_vol'],
                            'total_vol'   => (int)$v['total_vol'],
                            'premium_usd' => $v['total_prem'] !== null ? round($v['total_prem'], 2) : null,
                            'call_prem'   => isset($v['call_prem']) ? round($v['call_prem'],2) : null,
                            'put_prem'    => isset($v['put_prem'])  ? round($v['put_prem'],2) : null,
                            'mu'          => round($mu,2),
                            'sigma'       => round($sigma,2),
                        ], JSON_THROW_ON_ERROR),
                        'created_at'=> now(),
                        'updated_at'=> now(),
                        ];

                    }
                }

                // upsert flagged rows for this symbol/exp/date
                if (!empty($payload)) {
                    DB::table('unusual_activity')->upsert(
                        $payload,
                        ['symbol','data_date','exp_date','strike'],
                        ['z_score','vol_oi','meta','updated_at']
                    );
                }
            }
        }
    }

    // --- helpers ---
    protected function winsorizedStats(array $xs, float $p=0.05): array {
        sort($xs);
        $n = count($xs);
        $lo = (int)floor($p * $n);
        $hi = (int)ceil((1-$p) * $n) - 1;
        $trim = array_slice($xs, $lo, $hi-$lo+1);
        $mu = array_sum($trim)/max(1,count($trim));
        $var=0; foreach ($trim as $x) { $d=$x-$mu; $var += $d*$d; }
        $sigma = sqrt($var/max(1,count($trim)-1));
        return [$mu,$sigma];
    }

    protected function tradingDate(Carbon $now): string {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }
}
