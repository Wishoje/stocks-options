<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ComputePositioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(public array $symbols) {}

    public function handle(): void
    {
        $date = $this->tradingDate(now());

        foreach ($this->symbols as $raw) {
            $symbol = \App\Support\Symbols::canon($raw);

            // map exp_date => expiration_id
            $expMap = DB::table('option_expirations')
                ->where('symbol', $symbol)
                ->pluck('id', 'expiration_date'); // ['YYYY-MM-DD' => id]

            if ($expMap->isEmpty()) {
                continue;
            }

            $expIds = array_values($expMap->toArray());

            // latest per-expiry snapshot
            $latest = DB::table('option_chain_data')
                ->select('expiration_id', DB::raw('MAX(data_date) as d'))
                ->whereIn('expiration_id', $expIds)
                ->groupBy('expiration_id');

            $rows = DB::table('option_chain_data as o')
                ->joinSub($latest, 'ld', fn($j)=>$j
                    ->on('o.expiration_id','=','ld.expiration_id')
                    ->on('o.data_date','=','ld.d'))
                ->whereIn('o.expiration_id', $expIds)
                ->get([
                    'o.expiration_id','o.option_type','o.delta','o.gamma','o.open_interest',
                    'o.underlying_price','o.strike'
                ]);

            if ($rows->isEmpty()) {
                continue;
            }

            // ---------- DEX per expiry ----------
            DB::table('dex_by_expiry')
                ->where('symbol', $symbol)
                ->where('data_date', $date)
                ->delete(); // idempotent

            $dexTotalAll = 0.0;

            foreach ($expMap as $expDate => $expId) {
                $slice = $rows->where('expiration_id', $expId);
                if ($slice->isEmpty()) continue;

                $dex = 0.0;
                foreach ($slice as $r) {
                    $oi = (float)($r->open_interest ?? 0);
                    $d  = (float)($r->delta ?? 0);
                    if ($oi === 0.0 || $d === 0.0) continue;
                    // NOTE: your Fetch job stores put deltas negative. Good.
                    $dex += $d * $oi * 100.0;
                }

                if (is_finite($dex)) {
                    $dexTotalAll += $dex;
                    DB::table('dex_by_expiry')->insert([
                        'symbol'    => $symbol,
                        'data_date' => $date,
                        'exp_date'  => $expDate,
                        'dex_total' => $dex,
                        'created_at'=> now(), 'updated_at'=> now(),
                    ]);
                }
            }

            // ---------- Gamma Regime Strength ----------
            // Strength = |Σ G_notional| / (Σ |G_notional|)
            // where G_notional ≈ gamma * S^2 * OI * 100
            // (units cancel; we use it only as a relative regime measure 0..1).
            $S = (float) round($rows->avg('underlying_price') ?? 0, 6);
            $num = 0.0; $den = 0.0;
            if ($S > 0) {
                foreach ($rows as $r) {
                    $oi = (float)($r->open_interest ?? 0);
                    $g  = (float)($r->gamma ?? 0);
                    if ($oi === 0.0 || $g === 0.0) continue;

                    $gNotional = $g * $S * $S * $oi * 100.0;
                    $num += $gNotional;
                    $den += abs($gNotional);
                }
            }
            $strength = ($den > 0) ? min(1.0, max(0.0, abs($num) / $den)) : null;

            // cache 24h for the GEX controller to join in
            Cache::put("gamma_strength:{$symbol}:{$date}", [
                'date' => $date,
                'strength' => $strength,
                'sign' => ($num >= 0 ? +1 : -1), // +1 net long gamma, -1 net short gamma
            ], now()->addDay());
        }
    }

    protected function tradingDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }
}
