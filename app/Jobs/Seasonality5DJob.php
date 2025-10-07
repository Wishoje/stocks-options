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

class Seasonality5DJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /**
     * @param array $symbols Symbols to compute
     * @param int   $lookbackYears How many past years to include (e.g. 15)
     * @param int   $calWindowDays +/- how many calendar days around today's MM-DD to include
     */
    public function __construct(
        public array $symbols,
        public int   $lookbackYears = 15,
        public int   $calWindowDays = 2
    ) {}

    public function handle(): void
    {
        $asOf = $this->tradingDate(now());

        foreach ($this->symbols as $sym) {
            $symbol = \App\Support\Symbols::canon($sym);

            // ---- Load ALL history we have for this symbol (we want multi-year) ----
            $rows = DB::table('prices_daily')
                ->where('symbol', $symbol)
                ->orderBy('trade_date')
                ->get(['trade_date','close']);

            // Need enough history to compute 5-day windows robustly
            if ($rows->count() < 120) {
                DB::table('seasonality_5d')->updateOrInsert(
                    ['symbol'=>$symbol, 'data_date'=>$asOf],
                    [
                        'd1'=>null,'d2'=>null,'d3'=>null,'d4'=>null,'d5'=>null,
                        'cum5'=>null,'z'=>null,
                        'meta'=>json_encode([
                            'reason'=>'not_enough_history',
                            'obs'=>$rows->count()
                        ]),
                        'lookback_years' => $this->lookbackYears,
                        'lookback_days'  => null,
                        'window_days'    => $this->calWindowDays,
                        'updated_at'=>now(),'created_at'=>now()
                    ]
                );
                continue;
            }

            // ---- Index by date and build arrays for fast lookup ----
            $dates = $rows->pluck('trade_date')->values()->all();
            $closes= $rows->pluck('close')->map(fn($v)=>(float)$v)->values()->all();
            $n     = count($dates);

            // Map date -> index
            $ixByDate = [];
            for ($i=0; $i<$n; $i++) {
                $ixByDate[$dates[$i]] = $i;
            }

            // Helper to get next trading index +k (bounded)
            $nextIdx = function(int $i, int $k) use ($n) {
                $j = $i + $k;
                return ($j >= 0 && $j < $n) ? $j : null;
            };

            // ---- Build unconditional rolling 5d distribution (for z baseline) ----
            $uncondCum5 = [];
            for ($i=0; $i+5 < $n; $i++) {
                $p0 = (float)$closes[$i];
                $p5 = (float)$closes[$i+5];
                if ($p0 > 0 && $p5 > 0) {
                    $uncondCum5[] = ($p5/$p0) - 1.0;
                }
            }

            $muAll = null; $sdAll = null;
            if (count($uncondCum5) >= 60) {
                $muAll = array_sum($uncondCum5)/count($uncondCum5);
                $var   = 0.0;
                foreach ($uncondCum5 as $r) { $var += ($r - $muAll)*($r - $muAll); }
                $sdAll = sqrt($var / max(1, count($uncondCum5)-1));
                if ($sdAll <= 0) $sdAll = null;
            }

            // ---- Calendar-window seasonality set (across past years) ----
            // Find anchors near today's month/day in each of the last lookbackYears
            $today = Carbon::parse($asOf, 'America/New_York');
            $anchors = [];

            for ($y=1; $y <= $this->lookbackYears; $y++) {
                $target = $today->copy()->subYears($y);

                // Build a calendar window [target - W .. target + W]
                $winStart = $target->copy()->subDays($this->calWindowDays);
                $winEnd   = $target->copy()->addDays($this->calWindowDays);

                // Collect all trading dates in that calendar window
                // Use linear scan via binary search boundaries
                $cand = [];
                foreach ($dates as $d) {
                    if ($d >= $winStart->toDateString() && $d <= $winEnd->toDateString()) {
                        $cand[] = $d;
                    } elseif ($d > $winEnd->toDateString()) {
                        break;
                    }
                }

                // Prefer the trading day closest to the anniversary
                if (!empty($cand)) {
                    usort($cand, fn($a,$b) =>
                        abs(strtotime($a) - $target->timestamp) <=> abs(strtotime($b) - $target->timestamp)
                    );
                    $anchors[] = $cand[0];
                }
            }

            // Compute forward returns from each anchor
            $d1s=[]; $d2s=[]; $d3s=[]; $d4s=[]; $d5s=[]; $cum5s=[];
            foreach ($anchors as $adate) {
                if (!isset($ixByDate[$adate])) continue;
                $i0 = $ixByDate[$adate];

                // Need 5 forward trading days
                $i1=$nextIdx($i0,1); $i2=$nextIdx($i0,2); $i3=$nextIdx($i0,3);
                $i4=$nextIdx($i0,4); $i5=$nextIdx($i0,5);
                if (in_array(null, [$i1,$i2,$i3,$i4,$i5], true)) continue;

                $p0=$closes[$i0]; $p1=$closes[$i1]; $p2=$closes[$i2];
                $p3=$closes[$i3]; $p4=$closes[$i4]; $p5=$closes[$i5];

                if ($p0<=0 || $p1<=0 || $p2<=0 || $p3<=0 || $p4<=0 || $p5<=0) continue;

                $d1s[] = ($p1/$p0) - 1.0;
                $d2s[] = ($p2/$p1) - 1.0;
                $d3s[] = ($p3/$p2) - 1.0;
                $d4s[] = ($p4/$p3) - 1.0;
                $d5s[] = ($p5/$p4) - 1.0;
                $cum5s[]= ($p5/$p0) - 1.0;
            }

            $avg = fn($xs)=>count($xs)? array_sum($xs)/count($xs): null;

            $d1 = $avg($d1s);  $d2 = $avg($d2s);  $d3 = $avg($d3s);
            $d4 = $avg($d4s);  $d5 = $avg($d5s);  $m5 = $avg($cum5s);

            // z-score vs UNCONDITIONAL distribution so it’s comparable across dates
            $z = null;
            if (!is_null($m5) && !is_null($muAll) && !is_null($sdAll)) {
                $z = ($m5 - $muAll) / $sdAll;
                // cap for stability
                $z = max(-3, min(3, $z));
            }

            DB::table('seasonality_5d')->updateOrInsert(
                ['symbol'=>$symbol, 'data_date'=>$asOf],
                [
                    'd1'=>$d1,'d2'=>$d2,'d3'=>$d3,'d4'=>$d4,'d5'=>$d5,
                    'cum5'=>$m5,'z'=>$z,
                    'meta'=>json_encode([
                        'method' => 'calendar_window_across_years',
                        'window_days' => $this->calWindowDays,
                        'lookback_years' => $this->lookbackYears,
                        'samples' => [
                            'n_anchors' => count($anchors),
                            'n_valid'   => count($cum5s),
                            'uncond_n'  => count($uncondCum5),
                        ],
                        'as_of' => $asOf,
                    ]),
                    'updated_at'=>now(),'created_at'=>now()
                ]
            );
        }
    }

    protected function tradingDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }
}
