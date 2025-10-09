<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VolController extends Controller
{
    public function term(Request $req)
    {
        $symbol = strtoupper($req->query('symbol','SPY'));
        $cacheKey = "iv_term:{$symbol}";
        return Cache::remember($cacheKey, 86400, function() use ($symbol){
            $date = DB::table('iv_term')
                ->where('symbol',$symbol)
                ->max('data_date');

            if (!$date) return response()->json(['items'=>[],'date'=>null], 200);

            $items = DB::table('iv_term')
                ->where('symbol',$symbol)->where('data_date',$date)
                ->orderBy('exp_date')
                ->get(['exp_date as exp','iv']);

            return response()->json(['symbol'=>$symbol,'date'=>$date,'items'=>$items], 200);
        });
    }

    public function vrp(Request $req)
    {
        $symbol = strtoupper($req->query('symbol','SPY'));
        $cacheKey = "vrp:{$symbol}";
        return Cache::remember($cacheKey, 86400, function() use ($symbol){
            $row = DB::table('vrp_daily')
                ->where('symbol',$symbol)
                ->orderByDesc('data_date')
                ->first(['data_date as date','iv1m','rv20','vrp','z']);

            return response()->json($row ?? (object)[
                'date'=>null,'iv1m'=>null,'rv20'=>null,'vrp'=>null,'z'=>null
            ], 200);
        });
    }

    public function skew(Request $req)
    {
        $symbol = strtoupper($req->query('symbol','SPY'));
        $exp    = $req->query('exp'); // optional YYYY-MM-DD

        $cacheKey = $exp ? "iv_skew:{$symbol}:{$exp}" : "iv_skew:{$symbol}";
        return \Cache::remember($cacheKey, 86400, function() use ($symbol, $exp) {
            // latest data_date for symbol (or for that exp)
            $q = \DB::table('iv_skew')->where('symbol',$symbol);

            if ($exp) {
                $q->where('exp_date',$exp)->orderByDesc('data_date');
                $row = $q->first();
                return response()->json($row ? [
                    'symbol'=>$symbol,
                    'data_date'=>$row->data_date,
                    'exp'=>$row->exp_date,
                    'iv_put_25d'=>$row->iv_put_25d,
                    'iv_call_25d'=>$row->iv_call_25d,
                    'skew_pc'=>$row->skew_pc,
                    'curvature'=>$row->curvature,
                    'skew_pc_dod'=>$row->skew_pc_dod,
                    'curvature_dod'=>$row->curvature_dod,
                ] : (object)[], 200);
            }

            // pick latest data_date, then pick the **nearest expiry** to ~21d by default
            $date = \DB::table('iv_skew')->where('symbol',$symbol)->max('data_date');
            if (!$date) return response()->json((object)['items'=>[],'date'=>null], 200);

            $items = \DB::table('iv_skew')->where('symbol',$symbol)->where('data_date',$date)
                ->orderBy('exp_date')->get();

            return response()->json([
                'symbol'=>$symbol,
                'date'=>$date,
                'items'=>$items->map(fn($r)=>[
                    'exp'=>$r->exp_date,
                    'iv_put_25d'=>$r->iv_put_25d,
                    'iv_call_25d'=>$r->iv_call_25d,
                    'skew_pc'=>$r->skew_pc,
                    'curvature'=>$r->curvature,
                    'skew_pc_dod'=>$r->skew_pc_dod,
                    'curvature_dod'=>$r->curvature_dod,
                ])->values(),
            ], 200);
        });
    }

    public function skewDebug(\Illuminate\Http\Request $req)
    {
        $symbol = strtoupper($req->query('symbol', 'SPY'));
        $expArg = $req->query('exp'); // optional YYYY-MM-DD

        // latest chain date per expiry (same logic the job uses)
        $expMap = \DB::table('option_expirations')
            ->where('symbol', $symbol)
            ->pluck('id', 'expiration_date'); // ['YYYY-MM-DD' => id]

        if ($expMap->isEmpty()) {
            return response()->json([
                'symbol' => $symbol,
                'error'  => 'No expirations recorded for this symbol.',
            ], 200);
        }

        $expIds = array_values($expMap->toArray());

        $latest = \DB::table('option_chain_data')
            ->select('expiration_id', \DB::raw('MAX(data_date) as d'))
            ->whereIn('expiration_id', $expIds)
            ->groupBy('expiration_id');

        $rows = \DB::table('option_chain_data as o')
            ->joinSub($latest, 'ld', fn($j)=>$j
                ->on('o.expiration_id','=','ld.expiration_id')
                ->on('o.data_date','=','ld.d'))
            ->whereIn('o.expiration_id', $expIds)
            ->get(['o.expiration_id','o.option_type','o.strike','o.delta','o.iv','o.underlying_price']);

        if ($rows->isEmpty()) {
            return response()->json([
                'symbol' => $symbol,
                'error'  => 'No option_chain_data found for latest dates.',
            ], 200);
        }

        // Build per-exp quick stats so you can see which expiries are viable
        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/New_York'));
        $expStats = [];
        foreach ($expMap as $expDate => $expId) {
            $slice = $rows->where('expiration_id', $expId);
            $nTotal   = $slice->count();
            $nIvDelta = $slice->filter(fn($r)=> $r->iv !== null && $r->delta !== null)->count();
            $S        = (float) round($slice->avg('underlying_price') ?? 0, 6);

            // quick span estimate with the same moneyness k=ln(K/S)
            $ks = [];
            if ($S > 0) {
                foreach ($slice as $r) {
                    if ($r->iv>0 && $r->strike>0) $ks[] = (float)log($r->strike / $S);
                }
            }
            $span = 0.0;
            foreach ($ks as $k) { $span = max($span, abs($k)); }

            $diffDays = (new \DateTimeImmutable($expDate))->diff($today)->days;
            $expStats[] = [
                'exp'        => $expDate,
                'days_from_today' => $diffDays, // absolute difference in days
                'n_total'    => $nTotal,
                'n_with_iv_and_delta' => $nIvDelta,
                'k_span_abs_max'      => $span,
                'has_enough_data'     => ($nIvDelta >= 20 && $span >= 0.05 && $S > 0),
            ];
        }

        // Choose expiry: the one passed in, else nearest by calendar days
        $exp = $expArg;
        if (!$exp) {
            $best = null; $bestDiff = PHP_INT_MAX;
            foreach ($expStats as $st) {
                if ($st['n_with_iv_and_delta'] === 0) continue;
                if ($st['days_from_today'] < $bestDiff) { $bestDiff = $st['days_from_today']; $best = $st['exp']; }
            }
            $exp = $best ?: array_key_first($expMap->toArray());
        }

        // Deep diagnostics for the chosen expiry
        $expId = $expMap[$exp] ?? null;
        if (!$expId) {
            return response()->json([
                'symbol' => $symbol,
                'exp'    => $exp,
                'error'  => 'Requested expiry not found for this symbol.',
                'exp_stats' => $expStats,
            ], 200);
        }

        $slice = $rows->where('expiration_id', $expId)
            ->filter(fn($r)=> $r->iv !== null && $r->delta !== null && $r->strike !== null);

        $S = (float) round($slice->avg('underlying_price') ?? 0, 6);
        $pts = [];
        if ($S > 0) {
            foreach ($slice as $r) {
                if ($r->iv>0 && $r->strike>0) {
                    $pts[] = ['k'=>(float)log($r->strike / $S), 'iv'=>(float)$r->iv];
                }
            }
            // bounds ±30%, prefer near ATM, keep up to 60
            $pts = array_values(array_slice(
                array_values(array_filter($pts, fn($p)=> abs($p['k']) <= 0.30)),
                0, 1000
            ));
            usort($pts, fn($a,$b)=> abs($a['k']) <=> abs($b['k']));
            $pts = array_slice($pts, 0, 60);
        }

        // Span and counts
        $span = 0.0; foreach ($pts as $p) { $span = max($span, abs($p['k'])); }

        // Interpolate 25Δ IVs (uses the same linear-in-delta logic)
        $iv25c = $this->debugIvAtTargetDelta($slice, +0.25, 'call');
        $iv25p = $this->debugIvAtTargetDelta($slice, -0.25, 'put');

        // Curvature via quadratic (scaled by 0.01 like in the Job)
        $curv = null; $curv_raw = null; $curv_reason = null;
        if ($S <= 0) {
            $curv_reason = 'No/invalid underlying price S.';
        } elseif (count($pts) < 10) {
            $curv_reason = 'Not enough IV points (need ≥10).';
        } elseif ($span < 0.05) {
            $curv_reason = 'Moneyness span too small (need ≥0.05).';
        } else {
            $curv_raw = $this->debugQuadA($pts);            // a
            $curv     = is_finite($curv_raw) ? $curv_raw * 0.01 : null;
            if (!is_finite($curv) || abs($curv) > 1e6) {
                $curv_reason = 'Curvature not finite or absurdly large → nulling.';
                $curv = null;
            }
        }

        // Skew (decimal vol points)
        $skew = (is_null($iv25p) || is_null($iv25c)) ? null : ($iv25p - $iv25c);

        return response()->json([
            'symbol' => $symbol,
            'exp'    => $exp,
            'S'      => $S,
            'counts' => [
                'slice_rows' => $slice->count(),
                'pts_used'   => count($pts),
            ],
            'moneyness' => [
                'k_abs_span' => $span,
                'pts_preview' => array_slice($pts, 0, 8), // small peek
            ],
            'twentyfive_delta' => [
                'iv_call_25d' => $iv25c,
                'iv_put_25d'  => $iv25p,
                'note'        => (!$iv25c || !$iv25p) ? 'Missing call/put 25Δ IV → skew will be null.' : null,
            ],
            'curvature' => [
                'curv_scaled' => $curv,
                'curv_raw_a'  => $curv_raw,
                'reason_if_null' => $curv ? null : $curv_reason,
            ],
            'skew_pc' => $skew,
            'exp_stats' => $expStats,  // overview for all expiries today
        ], 200);
    }

    // ---- helpers (local to controller) ----
    private function debugIvAtTargetDelta($slice, float $target, string $type): ?float
    {
        $col = $slice->where('option_type', $type)
            ->map(fn($r)=> ['d'=>(float)$r->delta, 'iv'=>(float)$r->iv])
            ->filter(fn($p)=> is_finite($p['d']) && is_finite($p['iv']))
            ->sortBy('d')->values()->all();

        if (count($col) === 0) return null;

        foreach ($col as $p) { if (abs($p['d'] - $target) < 1e-6) return $p['iv']; }

        $lo=null; $hi=null;
        foreach ($col as $p) {
            if ($p['d'] <= $target) $lo = $p;
            if ($p['d'] >= $target) { $hi = $p; break; }
        }
        if (!$lo || !$hi || $lo['d'] === $hi['d']) {
            usort($col, fn($a,$b)=> abs($a['d']-$target) <=> abs($b['d']-$target));
            return $col[0]['iv'] ?? null;
        }
        $t = ($target - $lo['d']) / ($hi['d'] - $lo['d']);
        return $lo['iv'] + $t * ($hi['iv'] - $lo['iv']);
    }

    private function debugQuadA(array $pts): ?float
    {
        $n = count($pts);
        $S0=$n; $S1=0; $S2=0; $S3=0; $S4=0; $T0=0; $T1=0; $T2=0;
        foreach ($pts as $p) {
            $k = $p['k']; $iv = $p['iv'];
            $k2 = $k*$k; $k3 = $k2*$k; $k4 = $k3*$k;
            $S1 += $k;  $S2 += $k2; $S3 += $k3; $S4 += $k4;
            $T0 += $iv; $T1 += $iv*$k; $T2 += $iv*$k2;
        }
        $A = [[$S4,$S3,$S2],[$S3,$S2,$S1],[$S2,$S1,$S0]];
        $B = [$T2,$T1,$T0];

        $det = function($M){
            return $M[0][0]*($M[1][1]*$M[2][2]-$M[1][2]*$M[2][1])
                - $M[0][1]*($M[1][0]*$M[2][2]-$M[1][2]*$M[2][0])
                + $M[0][2]*($M[1][0]*$M[2][1]-$M[1][1]*$M[2][0]);
        };
        $D = $det($A);
        if (abs($D) < 1e-12) return null;

        $A_a = [[$B[0],$A[0][1],$A[0][2]],[$B[1],$A[1][1],$A[1][2]],[$B[2],$A[2][1],$A[2][2]]];
        return $det($A_a)/$D;
    }

    public function skewByBucket(\Illuminate\Http\Request $req)
    {
        $symbol = strtoupper($req->query('symbol','SPY'));
        $days   = (int)($req->query('days', 7));

        // latest snapshot
        $date = \DB::table('iv_skew')->where('symbol',$symbol)->max('data_date');
        if (!$date) return response()->json((object)[], 200);

        $rows = \DB::table('iv_skew')
            ->where('symbol',$symbol)->where('data_date',$date)
            ->orderBy('exp_date')->get();

        if ($rows->isEmpty()) return response()->json((object)[], 200);

        // pick nearest by calendar days from *server time*
        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/New_York'));
        $pick = null; $best = PHP_INT_MAX;
        foreach ($rows as $r) {
            $exp = new \DateTimeImmutable($r->exp_date);
            $d = $exp->diff($today)->days;
            $diff = abs($d - $days);
            if ($diff < $best) { $best = $diff; $pick = $r; }
            elseif ($diff === $best && $exp >= $today) { $pick = $r; } // prefer not-in-the-past
        }
        return response()->json($pick ?: (object)[], 200);
    }

    public function skewHistory(Request $req)
    {
        $symbol = strtoupper($req->query('symbol','SPY'));
        $days   = (int)($req->query('days', 7));
        $limit  = max(3, (int)$req->query('limit', 10));

        // 1) Find latest snapshot date
        $date = \DB::table('iv_skew')->where('symbol',$symbol)->max('data_date');
        if (!$date) return response()->json([], 200);

        // 2) On that snapshot, pick the expiry nearest to the requested DTE bucket
        $rows = \DB::table('iv_skew')
            ->where('symbol',$symbol)->where('data_date',$date)
            ->orderBy('exp_date')->get(['exp_date']);

        if ($rows->isEmpty()) return response()->json([], 200);

        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/New_York'));
        $pickExp = null; $best = PHP_INT_MAX;
        foreach ($rows as $r) {
            $exp  = new \DateTimeImmutable($r->exp_date);
            $d    = $exp->diff($today)->days;
            $diff = abs($d - $days);
            if ($diff < $best || ($diff === $best && $exp >= $today)) { // prefer non-past on ties
                $best = $diff; $pickExp = $r->exp_date;
            }
        }
        if (!$pickExp) return response()->json([], 200);

        // 3) Return trailing history for that SAME expiry
        $hist = \DB::table('iv_skew')
            ->where('symbol',$symbol)
            ->where('exp_date',$pickExp)
            ->orderByDesc('data_date')
            ->limit($limit)
            ->get(['data_date','exp_date','skew_pc']);

        // send oldest->newest for nice left-to-right sparkline
        $out = $hist->reverse()->values()->all();

        return response()->json($out, 200);
    }

    public function skewHistoryBucket(Request $req)
    {
        $symbol = strtoupper($req->query('symbol','SPY'));
        $days   = (int)($req->query('days', 7));     // bucket target (0, 7, 21…)
        $limit  = max(3, (int)$req->query('limit', 12)); // how many trading days back

        // Get most recent distinct data_dates for this symbol (latest first)
        $dates = \DB::table('iv_skew')
            ->where('symbol', $symbol)
            ->select('data_date')
            ->distinct()
            ->orderByDesc('data_date')
            ->limit($limit)
            ->pluck('data_date')
            ->all();

        if (!$dates) return response()->json([], 200);

        // Pull ALL rows for those dates in one shot
        $rows = \DB::table('iv_skew')
            ->where('symbol', $symbol)
            ->whereIn('data_date', $dates)
            ->orderBy('data_date')
            ->orderBy('exp_date')
            ->get([
                'data_date', 'exp_date',
                'iv_put_25d','iv_call_25d','skew_pc','curvature'
            ]);

        if ($rows->isEmpty()) return response()->json([], 200);

        // Group by data_date and pick the expiry nearest to requested bucket for each day
        $out = [];
        foreach ($dates as $d) {
            $slice = $rows->where('data_date', $d);
            if ($slice->isEmpty()) continue;

            $day   = new \DateTimeImmutable($d, new \DateTimeZone('America/New_York'));
            $best  = null; $bestDiff = PHP_INT_MAX;

            foreach ($slice as $r) {
                $exp = new \DateTimeImmutable($r->exp_date, new \DateTimeZone('America/New_York'));
                $dte = (int)$day->diff($exp)->days; // absolute calendar days
                $diff = abs($dte - $days);

                // prefer smallest diff; on ties prefer non-negative DTE (not in the past)
                if ($diff < $bestDiff || ($diff === $bestDiff && $exp >= $day)) {
                    $bestDiff = $diff;
                    $best = [
                        'data_date'   => $d,
                        'exp_date'    => $r->exp_date,
                        'dte'         => $dte,
                        'skew_pc'     => $r->skew_pc,
                        'curvature'   => $r->curvature,
                        'iv_put_25d'  => $r->iv_put_25d,
                        'iv_call_25d' => $r->iv_call_25d,
                    ];
                }
            }
            if ($best) $out[] = $best;
        }

        // Return oldest -> newest for left-to-right plotting
        usort($out, fn($a,$b)=> strcmp($a['data_date'],$b['data_date']));
        return response()->json($out, 200);
    }




}
