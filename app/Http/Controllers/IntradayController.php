<?php

namespace App\Http\Controllers;

use App\Jobs\FetchPolygonIntradayOptionsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IntradayController extends Controller
{
    // POST /api/intraday/pull { symbols: ["SPY","QQQ"] }
    private function k2($v): string { return number_format((float)$v, 2, '.', ''); }

    public function pull(Request $request)
    {
        $symbols = $request->input('symbols', []);
        if (!is_array($symbols) || empty($symbols)) {
            return response()->json(['error' => 'symbols[] required'], 422);
        }

        // dispatch immediately (batch is fine or straight dispatch)
        Bus::dispatch(new FetchPolygonIntradayOptionsJob($symbols));

        return response()->json(['ok' => true]);
    }

    // GET /api/intraday/summary?symbol=SPY
    public function summary(Request $request)
    {
        $symbol = strtoupper($request->query('symbol', 'SPY'));

        // pick NY trading date same logic as job
        $tradeDate = $this->tradingDate(now());

        // grab that one "totals row": exp_date NULL, strike NULL, option_type NULL
        $row = \App\Models\OptionLiveCounter::query()
            ->where('symbol', $symbol)
            ->where('trade_date', $tradeDate)
            ->whereNull('exp_date')
            ->whereNull('strike')
            ->whereNull('option_type')
            ->orderByDesc('updated_at')
            ->first();

        if (!$row) {
            // nothing yet today
            return response()->json([
                'asof'   => null,
                'totals' => [
                    'call_vol' => 0,
                    'put_vol'  => 0,
                    'total'    => 0,
                    'pcr_vol'  => null,
                    'premium'  => 0,
                ],
            ]);
        }

        // We only stored combined volume (calls+puts) and combined premium_usd on that row.
        // If you want split call_vol / put_vol in summary, we have 2 options:
        //   (1) re-sum below from per-strike rows by option_type
        //   (2) extend FetchPolygonIntradayOptionsJob to store separate counters.
        //
        // We'll do (1) here so you don't have to change the job yet.

        [$callVol, $putVol] = $this->sumTypeVolumes($symbol, $tradeDate);

        $pcr = null;
        if ($callVol > 0) {
            // pcr = puts / calls
            $pcr = round($putVol / $callVol, 3);
        }

        return response()->json([
            'asof' => $row->asof,
            'totals' => [
                'call_vol' => $callVol,
                'put_vol'  => $putVol,
                'total'    => (int) $row->volume,         // job sets volume = call+put
                'pcr_vol'  => $pcr,
                'premium'  => (float) $row->premium_usd,  // est notional
            ],
        ]);
    }

    // GET /api/intraday/volume-by-strike?symbol=SPY
    public function volumeByStrike(Request $request)
    {
        $symbol = strtoupper($request->query('symbol', 'SPY'));
        $tradeDate = $this->tradingDate(now());

        // Pull latest rows for this symbol+day WHERE strike is not null (so skip the totals row)
        // We'll aggregate per strike across expirations.
        $rows = \App\Models\OptionLiveCounter::query()
            ->where('symbol', $symbol)
            ->where('trade_date', $tradeDate)
            ->whereNotNull('strike')
            ->orderBy('strike')
            ->get([
                'strike',
                'option_type',
                'volume',
            ]);

        // Roll up: strike -> { call_vol, put_vol }
        $byStrike = [];
        foreach ($rows as $r) {
            $K = (string)$r->strike;
            if (!isset($byStrike[$K])) {
                $byStrike[$K] = [
                    'strike'   => (float)$r->strike,
                    'call_vol' => 0,
                    'put_vol'  => 0,
                ];
            }
            if ($r->option_type === 'call') {
                $byStrike[$K]['call_vol'] += (int)$r->volume;
            } elseif ($r->option_type === 'put') {
                $byStrike[$K]['put_vol'] += (int)$r->volume;
            }
        }

        // turn dict -> array sorted by strike asc
        $items = array_values($byStrike);
        usort($items, fn($a,$b) => $a['strike'] <=> $b['strike']);

        return response()->json([
            'items' => $items,
        ]);
    }

    private function tradingDate(\Carbon\Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) {
            $ny->previousWeekday();
        }
        return $ny->toDateString();
    }

    /**
     * helper for summary(): sum today's call/put vol across all buckets
     */
    private function sumTypeVolumes(string $symbol, string $tradeDate): array
    {
        $callVol = \App\Models\OptionLiveCounter::query()
            ->where('symbol', $symbol)
            ->where('trade_date', $tradeDate)
            ->where('option_type', 'call')
            ->sum('volume');

        $putVol = \App\Models\OptionLiveCounter::query()
            ->where('symbol', $symbol)
            ->where('trade_date', $tradeDate)
            ->where('option_type', 'put')
            ->sum('volume');

        return [(int)$callVol, (int)$putVol];
    }

     public function ua(Request $request)
    {
        // TODO: replace with true intraday UA logic.
        // For now just call the same service you use for /api/ua so the UI has data.
        return app(\App\Http\Controllers\ActivityController::class)->index($request);
    }

      /**
     * GET /api/intraday/strikes?symbol=SPY
     * Returns a single payload with:
     *  - call/put volume by strike (today)
     *  - call/put premium by strike (today)
     *  - EOD OI by strike (call/put)
     *  - Vol/OI, PCR by strike
     *  - Repriced Net GEX by strike (uses live S, IV-by-strike if available, fallback IV)
     */
    public function strikesComposite(Request $request)
    {
        $symbol = strtoupper($request->query('symbol', 'SPY'));
        $tradeDate = $this->tradingDate(now());

        $cacheKey = "intraday:strikesComposite:{$symbol}:{$tradeDate}";
        return Cache::remember($cacheKey, 15, function () use ($symbol, $tradeDate) {
            // 1) Intraday volume & premium by strike (today)
            $rows = DB::table('option_live_counters')
                ->where('symbol', $symbol)
                ->where('trade_date', $tradeDate)
                ->whereNotNull('strike')
                ->select('strike', 'option_type',
                         DB::raw('SUM(volume) as vol'),
                         DB::raw('SUM(COALESCE(premium_usd,0)) as prem'))
                ->groupBy('strike', 'option_type')
                ->get();

            $byK = [];
            foreach ($rows as $r) {
                $k = $this->k2($r->strike);
                if (!isset($byK[$k])) {
                    $byK[$k] = [
                        'strike'         => (float)$r->strike,
                        'call_vol'       => 0, 'put_vol' => 0,
                        'call_prem'      => 0.0, 'put_prem' => 0.0,
                        'oi_call_eod'    => 0, 'oi_put_eod' => 0,
                        'vol_oi'         => null, 'pcr' => null,
                        'net_gex_live'   => null, 'net_gex_delta' => null,
                    ];
                }
                if ($r->option_type === 'call') {
                    $byK[$k]['call_vol']  += (int)$r->vol;
                    $byK[$k]['call_prem'] += (float)$r->prem;
                } elseif ($r->option_type === 'put') {
                    $byK[$k]['put_vol']  += (int)$r->vol;
                    $byK[$k]['put_prem'] += (float)$r->prem;
                }
            }

            // 2) Join EOD OI by strike (sum over expiries)
            $eodOi = $this->eodOiByStrike($symbol);
           foreach ($eodOi as $kRaw => $oi) {
                $k = $this->k2($kRaw);
                if (!isset($byK[$k])) {
                    $byK[$k] = [
                        'strike' => (float)$k,
                        'call_vol'=>0,'put_vol'=>0,
                        'call_prem'=>0.0,'put_prem'=>0.0,
                        'oi_call_eod'=>0,'oi_put_eod'=>0,
                        'vol_oi'=>null,'pcr'=>null,
                        'net_gex_live'=>null,'net_gex_delta'=>null,
                    ];
                }
                $byK[$k]['oi_call_eod'] = (int)($oi['call'] ?? 0);
                $byK[$k]['oi_put_eod']  = (int)($oi['put']  ?? 0);
            }

            // 3) Compute convenience ratios
            foreach ($byK as &$row) {
                $totVol = $row['call_vol'] + $row['put_vol'];
                $totOi  = $row['oi_call_eod'] + $row['oi_put_eod'];
                $row['pcr']    = $row['call_vol'] > 0 ? round($row['put_vol'] / max(1, $row['call_vol']), 4) : null;
                $row['vol_oi'] = $totOi > 0 ? round($totVol / $totOi, 4) : null;
            }
            unset($row);

            // 4) Repriced Net GEX by strike
            $repriced = $this->repricedGexCompute($symbol, array_values($byK));

            // merge back
            foreach ($repriced as $k => $vals) {
                if (isset($byK[$k])) {
                    $byK[$k]['net_gex_live']  = $vals['net_gex_live'];
                    $byK[$k]['net_gex_delta'] = $vals['net_gex_delta'];
                }
            }

            // output
            $items = array_values($byK);
            usort($items, fn($a,$b) => $a['strike'] <=> $b['strike']);

            // assemble totals ‘header’ similar to /intraday/summary for FE convenience
            $totCall = array_sum(array_column($items,'call_vol'));
            $totPut  = array_sum(array_column($items,'put_vol'));
            $pcr = $totCall > 0 ? round($totPut / $totCall, 3) : null;
            $asof = DB::table('option_live_counters')
                ->where('symbol',$symbol)->where('trade_date',$tradeDate)
                ->max('asof');

            return response()->json([
                'asof' => $asof,
                'totals' => [
                    'call_vol' => $totCall,
                    'put_vol'  => $totPut,
                    'pcr_vol'  => $pcr,
                    'premium'  => array_sum(array_map(fn($r)=>$r['call_prem']+$r['put_prem'],$items)),
                ],
                'items' => $items,
            ]);
        });
    }

    /**
     * Optional separate endpoint if you want to fetch just the GEX series.
     * GET /api/intraday/repriced-gex-by-strike?symbol=SPY
     */
    public function repricedGexByStrike(Request $request)
    {
        $symbol = strtoupper($request->query('symbol', 'SPY'));
        $tradeDate = $this->tradingDate(now());

        $cacheKey = "intraday:repricedGex:{$symbol}:{$tradeDate}";
        $data = Cache::remember($cacheKey, 15, function () use ($symbol) {
            // build 'byK' minimal with OI
            $eodOi = $this->eodOiByStrike($symbol);
            $byK = [];
            foreach ($eodOi as $k => $oi) {
                $byK[] = [
                    'strike' => (float)$k,
                    'oi_call_eod' => (int)($oi['call'] ?? 0),
                    'oi_put_eod'  => (int)($oi['put']  ?? 0),
                ];
            }
            $repr = $this->repricedGexCompute($symbol, $byK);
            $items = [];
            foreach ($repr as $k => $vals) {
                $items[] = [
                    'strike'        => (float)$k,
                    'net_gex_live'  => $vals['net_gex_live'],
                    'net_gex_delta' => $vals['net_gex_delta'],
                ];
            }
            usort($items, fn($a,$b)=>$a['strike'] <=> $b['strike']);
            return ['items'=>$items];
        });

        return response()->json($data);
    }

    /** ---------- helpers ---------- */

    /**
     * Return EOD OI by strike (sum across expiries), split by call/put.
     * Array keyed by strike string: ['123.0' => ['call'=>..., 'put'=>...], ...]
     */
    private function eodOiByStrike(string $symbol): array
    {
        // Pick previous trading day present in option_chain_data
        $latest = DB::table('option_chain_data as o')
            ->join('option_expirations as e','e.id','=','o.expiration_id')
            ->where('e.symbol',$symbol)
            ->max('o.data_date');

        if (!$latest) return [];

        $rows = DB::table('option_chain_data as o')
            ->join('option_expirations as e','e.id','=','o.expiration_id')
            ->where('e.symbol',$symbol)
            ->whereDate('o.data_date',$latest)
            ->select('o.strike','o.option_type',
                     DB::raw('SUM(COALESCE(o.open_interest,0)) as oi'))
            ->groupBy('o.strike','o.option_type')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $k = $this->k2($r->strike);
            if (!isset($out[$k])) $out[$k] = ['call'=>0,'put'=>0];
            if ($r->option_type === 'call') $out[$k]['call'] += (int)$r->oi;
            elseif ($r->option_type === 'put') $out[$k]['put'] += (int)$r->oi;
        }
        return $out;
    }

    /**
     * Compute repriced net GEX by strike using live S and per-strike IV if available.
     * Returns array keyed by strike string.
     *
     * net_gex = (call_oi - put_oi) * contract_mult * S^2 * gamma(S,K,σ,τ)
     */
    private function repricedGexCompute(string $symbol, array $byK): array
    {
        // live underlying spot (fallback to last cached)
        $S = $this->currentSpot($symbol) ?? 100.0;

        // map of IV by strike (quick sample from latest intraday snapshots, if you have them)
        $ivMap = $this->latestIvByStrike($symbol); // [strike_str => iv]
        // time to expiry: use next weekly as an anchor (in years)
        $tau = $this->approxTimeToNearestExpiryYears($symbol) ?? (1.0/365.0); // fallback ~1 day

        $r = 0.00; // risk-free (ignore intraday)
        $q = 0.00; // dividend yield (ignore intraday)
        $mult = 100.0;

        // EOD net gex at open repriced baseline (optional): use same sigma for delta calc
        // We can cache baseline as 0 for brevity; or compute once from your EOD gex if available.
        $baseline = 0.0;

        $out = [];
        foreach ($byK as $row) {
            $K  = (float)$row['strike'];
            $kS = $this->k2($K);

            $oiCall = (int)($row['oi_call_eod'] ?? 0);
            $oiPut  = (int)($row['oi_put_eod']  ?? 0);
            if (($oiCall + $oiPut) === 0) { $out[$kS] = ['net_gex_live'=>0.0,'net_gex_delta'=>0.0]; continue; }

            $sigma = (float)($ivMap[$kS] ?? 0.20); // 20% fallback
            $gamma = $this->bsGamma($S, $K, $r, $q, max(0.0001,$sigma), max(1e-6,$tau));
            $net   = ($oiCall - $oiPut) * $mult * $S * $S * $gamma;

            $out[$kS] = ['net_gex_live'=>round($net,3), 'net_gex_delta'=>round($net-$baseline,3)];
        }
        return $out;
    }

    private function bsGamma(float $S, float $K, float $r, float $q, float $sigma, float $tau): float
    {
        // d1 = [ln(S/K) + (r - q + 0.5 σ^2) τ] / (σ √τ)
        $sqrtTau = sqrt($tau);
        $d1 = (log(max(1e-12,$S/$K)) + ($r - $q + 0.5*$sigma*$sigma)*$tau) / ($sigma*$sqrtTau);
        // φ(d1) / (S σ √τ) * e^{-q τ}
        $phi = exp(-0.5*$d1*$d1) / sqrt(2*M_PI);
        return $phi * exp(-$q*$tau) / (max(1e-12,$S) * $sigma * $sqrtTau);
    }

        private function latestIvByStrike(string $symbol): array
        {
            // Use the Schema facade (not a function) and bail if table isn’t there
            if (!Schema::hasTable('intraday_option_volumes')) {
                return [];
            }

            // Pull a recent window and collapse IV by strike.
            // Use AVG for broad DB compatibility (median needs window funcs not always available).
            $since = now()->subMinutes(30);

            $rows = DB::table('intraday_option_volumes')
                ->where('symbol', $symbol)
                ->where('captured_at', '>=', $since)
                ->whereNotNull('implied_volatility')
                ->select(
                    'strike_price as strike',
                    DB::raw('AVG(implied_volatility) as iv')
                )
                ->groupBy('strike_price')
                ->get();

            $out = [];
            foreach ($rows as $r) {
                $out[(string)$r->strike] = max(0.01, (float) $r->iv);
            }
            return $out;
        }

    private function approxTimeToNearestExpiryYears(string $symbol): ?float
    {
        $nowNy = now('America/New_York');
        $exp = DB::table('option_snapshots')
            ->where('symbol',$symbol)
            ->where('expiry','>=',$nowNy->toDateString())
            ->orderBy('expiry')
            ->value('expiry');

        if (!$exp) return null;
        $days = max(0.25, \Carbon\Carbon::parse($exp, 'America/New_York')->endOfDay()->diffInHours($nowNy)/24.0);
        return $days / 365.0;
    }

    private function currentSpot(string $symbol): ?float
    {
        // pull from recent option_snapshots as you already do
        return DB::table('option_snapshots')
            ->where('symbol', $symbol)
            ->where('fetched_at', '>=', now()->subMinutes(20))
            ->orderByDesc('fetched_at')
            ->value('underlying_price');
    }

    
}
