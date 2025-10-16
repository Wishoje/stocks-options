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

class ComputeBlindSpotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(public array $symbols, public int $lookaheadDays = 60) {}

    // ---------- Tunables ----------
    protected float $spotWindowPct   = 0.18;   // analyze strikes within ±18% of spot
    protected float $oiFloorPct      = 30.0;   // keep strikes with OI >= 30th pct
    protected int   $smoothWindow    = 5;      // SMA window over strikes
    protected float $pthresh         = 25.0;   // threshold on |S| at the 25th percentile
    protected int   $minWidthN       = 3;      // min # of strikes in a corridor
    protected float $minStrength     = 0.25;   // min strength for a corridor (0..1)
    protected bool  $useGrossGamma   = false;  // set true to use gross γ (recommended)
    // --------------------------------

    public function handle(): void
    {
        $today = $this->tradingDate(now());

        foreach ($this->symbols as $raw) {
            $symbol = \App\Support\Symbols::canon($raw);

            // upcoming expiries in window
            $limit = Carbon::parse($today, 'America/New_York')->addDays($this->lookaheadDays)->toDateString();
            $expDates = DB::table('option_expirations')
                ->where('symbol', $symbol)
                ->whereBetween('expiration_date', [$today, $limit])
                ->pluck('expiration_date');

            foreach ($expDates as $expDate) {
                // latest snapshot for this expiry
                $latest = DB::table('option_chain_data as o')
                    ->join('option_expirations as e','e.id','=','o.expiration_id')
                    ->where('e.symbol', $symbol)
                    ->whereDate('e.expiration_date', $expDate)
                    ->max('o.data_date');

                if (!$latest) continue;

                // Pull chain rows
                $rows = DB::table('option_chain_data as o')
                    ->join('option_expirations as e','e.id','=','o.expiration_id')
                    ->where('e.symbol', $symbol)
                    ->whereDate('e.expiration_date', $expDate)
                    ->whereDate('o.data_date', $latest)
                    ->get(['o.strike','o.option_type','o.gamma','o.open_interest']);

                if ($rows->isEmpty()) continue;

                // ---------- 1) Get spot ----------
                $spot = DB::table('prices_daily')
                    ->where('symbol', $symbol)
                    ->whereDate('date','<=',$latest)
                    ->orderByDesc('date')
                    ->value('close');

                // fallback: OI-weighted median strike if prices missing
                if (!$spot) {
                    $byK = [];
                    foreach ($rows as $r) {
                        $k = (float)$r->strike;
                        $byK[$k] = ($byK[$k] ?? 0) + (float)($r->open_interest ?? 0);
                    }
                    ksort($byK);
                    $tot = array_sum($byK);
                    if ($tot > 0) {
                        $acc = 0;
                        foreach ($byK as $k => $w) {
                            $acc += $w;
                            if ($acc >= 0.5 * $tot) { $spot = (float)$k; break; }
                        }
                    }
                    if (!$spot) {
                        // final fallback: mean of strikes
                        $keys = array_keys($byK);
                        $spot = count($keys) ? array_sum($keys)/count($keys) : null;
                    }
                }
                if (!$spot) continue;

                // ---------- 2) Aggregate per strike ----------
                $net = [];     // net gamma * OI * 100 (calls - puts)
                $gross = [];   // |gamma| * OI * 100
                $oi = [];      // total OI per strike
                foreach ($rows as $r) {
                    $k = (float)$r->strike;
                    $g = (float)($r->gamma ?? 0);
                    $oiRow = (float)($r->open_interest ?? 0);
                    $val = $g * $oiRow * 100.0;

                    $net[$k]   = ($net[$k]   ?? 0.0) + ($r->option_type === 'call' ? +$val : -$val);
                    $gross[$k] = ($gross[$k] ?? 0.0) + abs($val);
                    $oi[$k]    = ($oi[$k]    ?? 0.0) + $oiRow;
                }
                ksort($net); ksort($gross); ksort($oi);

                // choose source for "density"
                $src = $this->useGrossGamma ? $gross : $net;

                // ---------- 3) Keep strikes near spot & above OI floor ----------
                $kAll = array_keys($src);
                $kAll = array_values(array_filter($kAll, function($k) use ($spot) {
                    return abs($k/$spot - 1.0) <= $this->spotWindowPct;
                }));
                if (count($kAll) < $this->minWidthN + 2) continue;

                $oiVals = array_intersect_key($oi, array_flip($kAll));
                $oiFloor = max(1.0, $this->percentile(array_values($oiVals), $this->oiFloorPct));
                $kept = array_values(array_filter($kAll, function($k) use ($oi, $oiFloor) {
                    return ($oi[$k] ?? 0) >= $oiFloor;
                }));
                if (count($kept) < $this->minWidthN + 2) continue;

                // ---------- 4) Build ordered arrays for smoothing ----------
                $K = $kept;
                sort($K, SORT_NUMERIC);
                $V = array_map(fn($k)=>$src[$k] ?? 0.0, $K);
                $n = count($V);
                if ($n < $this->smoothWindow) continue;

                // ---------- 5) Smooth ----------
                $w = max(1, $this->smoothWindow);
                $half = intdiv($w, 2);
                $S = [];
                for ($i=0; $i<$n; $i++) {
                    $sum=0; $cnt=0;
                    for ($j=$i-$half; $j<=$i+$half; $j++) {
                        if ($j>=0 && $j<$n) { $sum += $V[$j]; $cnt++; }
                    }
                    $S[$i] = $cnt ? $sum/$cnt : $V[$i];
                }

                // ---------- 6) Threshold on |S| using NON-ZERO values ----------
                $abs = array_values(array_filter(array_map('abs', $S), fn($x)=>$x>0));
                if (empty($abs)) continue;
                $th = max($this->percentile($abs, $this->pthresh), 1e-6);

                // ---------- 7) Corridors where |S| < th with quality gates ----------
                $corridors = [];
                for ($i=0; $i<$n; ) {
                    if (abs($S[$i]) < $th) {
                        $j = $i; $sumNorm=0; $len=0;
                        while ($j<$n && abs($S[$j]) < $th) {
                            $sumNorm += (abs($S[$j]) / $th); // ~0..1
                            $len++; $j++;
                        }
                        $from = (float)$K[$i];
                        $to   = (float)$K[$j-1];
                        $strength = 1.0 - min(1.0, ($sumNorm / max(1, $len)));

                        if ($len >= $this->minWidthN && $strength >= $this->minStrength) {
                            $corridors[] = [
                                'from'=>$from, 'to'=>$to,
                                'width_n'=>$len,
                                'strength'=>round($strength, 4),
                            ];
                        }
                        $i = $j;
                    } else {
                        $i++;
                    }
                }

                // Optional: merge corridors that are separated by <= 1 strike
                $corridors = $this->mergeCloseCorridors($corridors);

                DB::table('blind_spots')->updateOrInsert(
                    ['symbol'=>$symbol,'data_date'=>$latest,'exp_date'=>$expDate],
                    [
                        'corridors_json' => json_encode($corridors, JSON_THROW_ON_ERROR),
                        'updated_at'=>now(),'created_at'=>now()
                    ]
                );
            }
        }
    }

    // ---------- helpers ----------
    protected function percentile(array $xs, float $p): float {
        if (empty($xs)) return 0.0;
        sort($xs, SORT_NUMERIC);
        $idx = ($p/100) * (count($xs)-1);
        $lo = (int)floor($idx); $hi = (int)ceil($idx);
        if ($lo === $hi) return (float)$xs[$lo];
        $w = $idx - $lo;
        return (1-$w) * (float)$xs[$lo] + $w * (float)$xs[$hi];
    }

    protected function tradingDate(Carbon $now): string {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }

    protected function mergeCloseCorridors(array $cs): array {
        if (count($cs) < 2) return $cs;
        usort($cs, fn($a,$b)=>$a['from'] <=> $b['from']);
        $out = [$cs[0]];
        for ($i=1; $i<count($cs); $i++) {
            $prev = &$out[count($out)-1];
            $cur  =  $cs[$i];
            // if overlaps or within 1 strike gap, merge
            if ($cur['from'] <= $prev['to'] || abs($cur['from'] - $prev['to']) <= 1e-6) {
                $prev['to'] = max($prev['to'], $cur['to']);
                $prev['width_n'] += $cur['width_n'];
                $prev['strength'] = round(max($prev['strength'], $cur['strength']), 4);
            } else {
                $out[] = $cur;
            }
        }
        return $out;
    }
}
