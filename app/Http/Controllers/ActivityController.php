<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ActivityController extends Controller
{
    public function index(Request $req)
    {
        $symbol      = \App\Support\Symbols::canon($req->query('symbol','SPY'));
        $exp         = $req->query('exp');                  // optional
        $minZ        = (float)($req->query('min_z', 2.0));
        $minVolOI    = (float)($req->query('min_vol_oi', 1.0));
        $minVol      = (int)($req->query('min_vol', 0));
        $minPremium  = (float)($req->query('min_premium', 0));
        $limit       = (int)($req->query('limit', 50));     // global cap
        $perExp      = (int)($req->query('per_expiry', 5)); // top-N per expiry
        $sideFilter  = $req->query('only_side');            // 'call'|'put'|null
        $withPrem    = filter_var($req->query('with_premium', true), FILTER_VALIDATE_BOOLEAN);
        $nearPct     = (float)($req->query('near_spot_pct', 0)); // e.g. 10 => ±10%
        $sort        = $req->query('sort', 'z_score');      // z_score|vol_oi|premium

        $ttl = now()->addMinutes(15);
        $key = 'ua:v2:'.md5(json_encode([
            $symbol,$exp,$minZ,$minVolOI,$minVol,$minPremium,$limit,$perExp,$sideFilter,$withPrem,$nearPct,$sort
        ]));

        return Cache::remember($key, $ttl, function () use ($symbol,$exp,$minZ,$minVolOI,$minVol,$minPremium,$limit,$perExp,$sideFilter,$withPrem,$nearPct,$sort) {
            $latest = DB::table('unusual_activity')->where('symbol',$symbol)->max('data_date');
            if (!$latest) return response()->json(['symbol'=>$symbol,'data_date'=>null,'items'=>[]], 200);

            // optional near-spot filter boundaries
            $spot = null; $lo = null; $hi = null;
            if ($nearPct > 0) {
                $spot = $this->getSpot($symbol, $latest);
                if ($spot && $spot > 0) {
                    $lo = $spot * (1 - $nearPct/100);
                    $hi = $spot * (1 + $nearPct/100);
                }
            }

            $q = DB::table('unusual_activity')
                ->where('symbol',$symbol)
                ->where('data_date',$latest);

            if ($exp) $q->where('exp_date',$exp);

            // OR logic to match the job (see #2)
            if ($minZ>0 && $minVolOI>0) {
                $q->where(function($w) use ($minZ,$minVolOI) {
                    $w->where('z_score','>=',$minZ)
                    ->orWhere('vol_oi','>=',$minVolOI);
                });
            } elseif ($minZ>0) {
                $q->where('z_score','>=',$minZ);
            } elseif ($minVolOI>0) {
                $q->where('vol_oi','>=',$minVolOI);
            }

            if ($lo !== null && $hi !== null && $lo < $hi) {
                $q->whereBetween('strike', [$lo,$hi]);
            }

            if ($sideFilter === 'call') {
                $q->whereRaw("(JSON_EXTRACT(meta,'$.call_vol') + 0) > (JSON_EXTRACT(meta,'$.put_vol') + 0)");
             } elseif ($sideFilter === 'put') {
                $q->whereRaw("(JSON_EXTRACT(meta,'$.put_vol') + 0) > (JSON_EXTRACT(meta,'$.call_vol') + 0)");
            }

            $rows = $q->orderByDesc('z_score')
                      ->orderByDesc('vol_oi')
                      ->get(['exp_date','strike','z_score','vol_oi','meta']);

            // group + per-expiry pick
            $grouped = [];
            foreach ($rows as $r) {
                $m = json_decode($r->meta ?? '[]', true) ?: [];
                if ($minVol > 0 && (int)($m['total_vol'] ?? 0) < $minVol) continue;

                // optional min premium prefilter (uses stored premium if present)
                if ($minPremium > 0 && isset($m['premium_usd']) && (float)$m['premium_usd'] < $minPremium) continue;

                $grouped[$r->exp_date] = $grouped[$r->exp_date] ?? [];
                $grouped[$r->exp_date][] = $r;
            }

            $picked = [];
            foreach ($grouped as $ed => $arr) {
                usort($arr, function($a,$b){
                    return ($b->z_score <=> $a->z_score) ?: ($b->vol_oi <=> $a->vol_oi);
                });
                $picked = array_merge($picked, array_slice($arr, 0, max(1,$perExp)));
            }

            // optional premium-based sort
            if ($sort === 'premium') {
                usort($picked, function($a,$b){
                    $ma = json_decode($a->meta ?? '[]', true) ?: [];
                    $mb = json_decode($b->meta ?? '[]', true) ?: [];
                    return (float)($mb['premium_usd'] ?? 0) <=> (float)($ma['premium_usd'] ?? 0);
                });
            } else {
                usort($picked, fn($a,$b)=>($b->z_score <=> $a->z_score) ?: ($b->vol_oi <=> $a->vol_oi));
            }

            $picked = array_slice($picked, 0, max(1,$limit));

            // attach premium if requested (prefer stored meta)
           $items = array_map(function($r) use ($withPrem, $symbol) {
                $meta = json_decode($r->meta ?? '[]', true) ?: [];

                if ($withPrem && (!isset($meta['premium_usd']) || $meta['premium_usd'] === null)) {
                    [$callVol,$putVol] = [(int)($meta['call_vol'] ?? 0), (int)($meta['put_vol'] ?? 0)];
                    // uses the new estimator that reads option_chain_data
                    [$callPrem, $putPrem] = $this->estimatePremiumUSD(
                        $symbol,
                        $r->exp_date,
                        (float)$r->strike,
                        $callVol,
                        $putVol
                    );
                    $meta['call_prem']   = round($callPrem, 2);
                    $meta['put_prem']    = round($putPrem, 2);
                    $meta['premium_usd'] = round($callPrem + $putPrem, 2);
                }

                return [
                    'exp_date' => $r->exp_date,
                    'strike'   => (float)$r->strike,
                    'z_score'  => (float)$r->z_score,
                    'vol_oi'   => (float)$r->vol_oi,
                    'meta'     => $meta,
                ];
            }, $picked);

            return response()->json(['symbol'=>$symbol,'data_date'=>$latest,'items'=>$items], 200);
        });
    }

    // prefer last close for same date, else last print
    private function getSpot(string $symbol, string $dataDate): ?float
    {
        // 1) Prefer end-of-day close if you have it
        $row = DB::table('underlying_prices')
            ->where('symbol',$symbol)
            ->whereDate('price_date',$dataDate)
            ->select('close')
            ->first();
        if ($row && $row->close !== null) return (float)$row->close;

        // 2) Fall back to the average underlying_price from today's chain rows
        $avg = DB::table('option_chain_data as o')
            ->join('option_expirations as e','e.id','=','o.expiration_id')
            ->where('e.symbol',$symbol)
            ->whereDate('o.data_date',$dataDate)
            ->avg('o.underlying_price');

        return $avg ? (float)$avg : null;
    }

    // fallback estimator if meta lacks premium
    
    private function estimatePremiumUSD(string $symbol, string $exp, float $strike, int $callVol, int $putVol): array
    {
        // 1) If you *do* have an option_quotes table, keep using it first
        if (\Schema::hasTable('option_quotes')) {
            $rows = DB::table('option_quotes')
                ->where('symbol', $symbol)
                ->whereDate('expiration_date', $exp)
                ->where('strike', $strike)
                ->whereIn('option_type', ['call','put'])
                ->select('option_type','bid','ask','mark','last')
                ->get();

            $mid = ['call'=>null,'put'=>null];
            foreach ($rows as $q) {
                $m = null;
                if ($q->mark !== null)                         $m = (float)$q->mark;
                elseif ($q->bid !== null && $q->ask !== null)  $m = ((float)$q->bid + (float)$q->ask) / 2.0;
                elseif ($q->last !== null)                     $m = (float)$q->last;
                elseif ($q->bid !== null)                      $m = (float)$q->bid;
                elseif ($q->ask !== null)                      $m = (float)$q->ask;
                $mid[$q->option_type] = $m;
            }
            $callPrem = max(0.0, (float)($mid['call'] ?? 0)) * max(0,$callVol) * 100.0;
            $putPrem  = max(0.0, (float)($mid['put']  ?? 0)) * max(0,$putVol)  * 100.0;
            return [$callPrem, $putPrem];
        }

        // 2) Try direct mid from columns if you added any later (safe-select)
        $ocdCols = array_values(array_filter([
            \Schema::hasColumn('option_chain_data','mid_price')   ? 'o.mid_price'   : null,
            \Schema::hasColumn('option_chain_data','last_price')  ? 'o.last_price'  : null,
            \Schema::hasColumn('option_chain_data','close')       ? 'o.close'       : null,
            \Schema::hasColumn('option_chain_data','bid')         ? 'o.bid'         : null,
            \Schema::hasColumn('option_chain_data','ask')         ? 'o.ask'         : null,
        ]));

        $calcMid = function($row) {
            foreach (['mid_price','mark','last_price','last','close'] as $k) {
                if (property_exists($row,$k) && $row->$k !== null) return (float)$row->$k;
            }
            if (property_exists($row,'bid') && property_exists($row,'ask') && $row->bid !== null && $row->ask !== null) {
                return ((float)$row->bid + (float)$row->ask)/2.0;
            }
            if (property_exists($row,'bid') && $row->bid !== null) return (float)$row->bid;
            if (property_exists($row,'ask') && $row->ask !== null) return (float)$row->ask;
            return null;
        };

        if (!empty($ocdCols)) {
            $rows = DB::table('option_chain_data as o')
                ->join('option_expirations as e','e.id','=','o.expiration_id')
                ->where('e.symbol', $symbol)
                ->whereDate('e.expiration_date', $exp)
                ->where('o.strike', $strike)
                ->whereIn('o.option_type', ['call','put'])
                ->selectRaw('o.option_type' . ', ' . implode(',', $ocdCols))
                ->get();

            $mid = ['call'=>null,'put'=>null];
            foreach ($rows as $q) { $mid[$q->option_type] = $calcMid($q); }
            $callPrem = max(0.0, (float)($mid['call'] ?? 0)) * max(0,$callVol) * 100.0;
            $putPrem  = max(0.0, (float)($mid['put']  ?? 0)) * max(0,$putVol)  * 100.0;
            return [$callPrem, $putPrem];
        }

        // 3) Theoretical price from IV if no quotes stored
        $rows = DB::table('option_chain_data as o')
            ->join('option_expirations as e','e.id','=','o.expiration_id')
            ->where('e.symbol', $symbol)
            ->whereDate('e.expiration_date', $exp)
            ->where('o.strike', $strike)
            ->whereIn('o.option_type', ['call','put'])
            ->select('o.option_type','o.iv','o.underlying_price')
            ->get();

        // Figure T in years from now to $exp
        $T = max(0.0, (strtotime($exp) - time()) / (365.0*24*3600));
        $S = null; // spot from rows if provided
        foreach ($rows as $r) {
            if ($r->underlying_price !== null) { $S = (float)$r->underlying_price; break; }
        }
        if ($S === null) {
            // As a last resort, try EOD close or chain-average for today
            $S = $this->getSpot($symbol, date('Y-m-d'));
        }

        $mid = ['call'=>null,'put'=>null];
        foreach ($rows as $r) {
            $iv = $r->iv !== null ? (float)$r->iv : null; // already decimal in your job
            if ($S && $iv && $iv > 0 && $T > 0) {
                $mid[$r->option_type] = $this->bsPrice($r->option_type, $S, $strike, $T, $iv, 0.0);
            }
        }

        $callPrem = max(0.0, (float)($mid['call'] ?? 0)) * max(0,$callVol) * 100.0;
        $putPrem  = max(0.0, (float)($mid['put']  ?? 0)) * max(0,$putVol)  * 100.0;
        return [$callPrem, $putPrem];
    }

    private function normCdf(float $x): float
    {
        // Abramowitz-Stegun (or Hart) rational approximation of standard normal CDF
        // Accuracy ~1e-7; no extensions required.
        $sign = $x < 0 ? -1.0 : 1.0;
        $x    = abs($x) / sqrt(2.0);

        // Coefficients for erf approximation
        $a1 = 0.254829592;
        $a2 = -0.284496736;
        $a3 = 1.421413741;
        $a4 = -1.453152027;
        $a5 = 1.061405429;
        $p  = 0.3275911;

        $t  = 1.0 / (1.0 + $p * $x);
        $y  = 1.0 - ((((( $a5 * $t + $a4) * $t + $a3) * $t + $a2) * $t + $a1) * $t) * exp(-$x*$x);

        // erf(x) ≈ sign * y  =>  Φ(z) = 0.5 * (1 + erf(z/√2))
        return 0.5 * (1.0 + $sign * $y);
    }

    // Replace your current bsPrice() with this version
    private function bsPrice(string $type, float $S, float $K, float $T, float $sigma, float $r = 0.0): float
    {
        if ($S <= 0.0 || $K <= 0.0 || $T <= 0.0 || $sigma <= 0.0) {
            return 0.0;
        }

        $sqrtT = sqrt($T);
        $vsqrt = $sigma * $sqrtT;

        // Guard against extremely tiny vsqrt to avoid INF/NaN
        if ($vsqrt <= 0.0) {
            return 0.0;
        }

        $d1 = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / $vsqrt;
        $d2 = $d1 - $vsqrt;

        $Nd1  = $this->normCdf($d1);
        $Nd2  = $this->normCdf($d2);
        $Nmd1 = $this->normCdf(-$d1);
        $Nmd2 = $this->normCdf(-$d2);

        $df = exp(-$r * $T); // discount factor

        if ($type === 'call') {
            return $S * $Nd1 - $K * $df * $Nd2;
        } elseif ($type === 'put') {
            return $K * $df * $Nmd2 - $S * $Nmd1;
        }

        return 0.0;
    }



}
