<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class QScoreController extends Controller
{
    public function show(Request $req)
    {
        $symbol = \App\Support\Symbols::canon($req->query('symbol', 'SPY'));
        $date   = $this->tradingDate(now());

        // ---- VOL (VRP) ----
        $vrpRow = DB::table('vrp_daily')
            ->where('symbol', $symbol)
            ->orderByDesc('data_date')
            ->first(['data_date','vrp','z','iv1m','rv20']);

        [$volScore, $volExpl] = $this->scoreVol($vrpRow);

        // ---- OPTION ---- (net GEX + OI tilt)
        $opt = DB::table('option_chain_data as o')
            ->join('option_expirations as e','e.id','=','o.expiration_id')
            ->where('e.symbol', $symbol)
            ->where('o.data_date', $date)
            ->selectRaw("
                SUM(COALESCE(o.gamma,0) * COALESCE(o.open_interest,0)) as net_gex,
                SUM(CASE WHEN o.option_type='call' THEN COALESCE(o.open_interest,0) ELSE 0 END) as call_oi,
                SUM(CASE WHEN o.option_type='put'  THEN COALESCE(o.open_interest,0) ELSE 0 END)  as put_oi
            ")
            ->first();

        [$optScore, $optExpl] = $this->scoreOption($opt);

        // ---- MOMENTUM ---- (20/50 SMA + 10d/20d total return)
        [$mScore, $mExpl] = $this->scoreMomentum($symbol, $date);

        // ---- SEASONALITY ---- (optional; neutral fallback)
        [$sScore, $sExpl] = $this->scoreSeasonality($symbol, $date);

        return response()->json([
            'symbol' => $symbol,
            'date'   => $date,
            'scores' => [
                'option' => ['score'=>$optScore, 'expl'=>$optExpl],
                'vol'    => ['score'=>$volScore, 'expl'=>$volExpl],
                'momo'   => ['score'=>$mScore, 'expl'=>$mExpl],
                'season' => ['score'=>$sScore, 'expl'=>$sExpl],
            ],
        ], 200);
    }

    protected function scoreVol($vrpRow): array
    {
        if (!$vrpRow) {
            return [2.0, 'No VRP yet — treating volatility as Neutral.'];
        }
        // Map z to 0..4 (cap range)
        $z = $vrpRow->z ?? null;
        if ($z === null) {
            // fallback using raw VRP sign/magnitude
            $vrp = (float)($vrpRow->vrp ?? 0);
            if ($vrp > 0.06)  return [3.2, 'IV rich vs realized — favors selling premium.'];
            if ($vrp < -0.03) return [0.8, 'IV cheap vs realized — favors buying premium.'];
            return [2.0, 'Stable/neutral volatility regime.'];
        }
        $z = max(-3, min(3, $z));
        // z ≥ +1.0 -> high (IV rich); z ≤ -1.0 -> low (IV cheap)
        $score = 2 + ( $z / 3 ) * 2; // maps z=-3..+3 ⇒ 0..4 around 2
        $score = max(0, min(4, $score));
        $expl  = $z >= 1
            ? 'IV rich vs realized (VRP high) — short-vol setups favored.'
            : ($z <= -1
                ? 'IV cheap vs realized (VRP low) — long-vol setups favored.'
                : 'VRP near average — neutral volatility backdrop.');
        return [$score, $expl];
    }

    protected function scoreOption($opt): array
    {
        if (!$opt) return [2.0, 'No option positioning data — Neutral.'];

        $netGex = (float)($opt->net_gex ?? 0);
        $callOi = (float)($opt->call_oi ?? 0);
        $putOi  = (float)($opt->put_oi  ?? 0);
        $tilt   = ($callOi + $putOi) > 0 ? $callOi / ($callOi + $putOi) : 0.5; // 0..1

        // Heuristic: positive net GEX + call tilt -> supportive (market damped)
        $score  = 2.0;
        if ($netGex > 0) $score += 0.8;
        if ($netGex < 0) $score -= 0.8;

        if ($tilt > 0.6) $score += 0.4;
        if ($tilt < 0.4) $score -= 0.4;

        $score = max(0, min(4, $score));

        $expl = match (true) {
            $netGex > 0 && $tilt > 0.55 => 'Positive net GEX with call-heavy OI — dip-buying supported.',
            $netGex > 0                   => 'Positive net GEX — moves tend to be damped.',
            $netGex < 0 && $tilt < 0.45 => 'Negative net GEX with put-heavy OI — whippier downside risk.',
            $netGex < 0                   => 'Negative net GEX — fragiler tape.',
            default                       => 'Balanced positioning — Neutral.',
        };
        return [$score, $expl];
    }

    protected function scoreMomentum(string $symbol, string $date): array
    {
        // pull last ~60 closes
        $px = DB::table('prices_daily')
            ->where('symbol', $symbol)
            ->where('trade_date','<=',$date)
            ->orderByDesc('trade_date')->limit(70)->get(['trade_date','close'])
            ->sortBy('trade_date')->values();

        if ($px->count() < 50) return [2.0, 'Not enough price history — Neutral.'];

        $cl = $px->pluck('close')->map(fn($x)=>(float)$x)->values()->toArray();
        $n = count($cl);

        $sma20 = array_sum(array_slice($cl, $n-20)) / 20;
        $sma50 = array_sum(array_slice($cl, $n-50)) / 50;
        $c0    = $cl[$n-1];
        $c10   = $cl[$n-11] ?? $cl[0];
        $c20   = $cl[$n-21] ?? $cl[0];
        $r10   = $c10>0 ? ($c0/$c10 - 1) : 0;
        $r20   = $c20>0 ? ($c0/$c20 - 1) : 0;

        $score = 2.0;
        if ($c0 > $sma50) $score += 0.7;
        if ($sma20 > $sma50) $score += 0.6;
        if ($r10 > 0.02) $score += 0.4;
        if ($r20 > 0.03) $score += 0.4;
        if ($c0 < $sma50) $score -= 0.7;
        if ($sma20 < $sma50) $score -= 0.6;
        if ($r10 < -0.02) $score -= 0.4;
        if ($r20 < -0.03) $score -= 0.4;

        $score = max(0, min(4, $score));

        $expl = match (true) {
            $c0 > $sma50 && $sma20 > $sma50 && $r20 > 0 => 'Price above trend with improving 20>50d — bullish momentum.',
            $c0 < $sma50 && $sma20 < $sma50 && $r20 < 0 => 'Below trend and weakening — bearish momentum.',
            default => 'Mixed signals — range/rotation risk.'
        };
        return [$score, $expl];
    }

    protected function scoreSeasonality(string $symbol, string $date): array
    {
        $row = \DB::table('seasonality_5d')
            ->where('symbol',$symbol)
            ->where('data_date',$date)
            ->first(['d1','d2','d3','d4','d5','cum5','z']);

        if (!$row) return [2.0, 'No seasonality data — Neutral next 5 sessions.'];

        $score = 2.0;
        $expl  = 'Seasonality near average — Neutral.';
        if ($row->z !== null) {
            $z = max(-3, min(3, (float)$row->z));
            $score = max(0, min(4, 2 + ($z * 2/3)));
            $expl  = $z >= 1 ? 'Favorable next-5-day tendency.' :
                    ($z <= -1 ? 'Unfavorable next-5-day tendency.' : 'Near average.');
        } else if ($row->cum5 !== null) {
            if ($row->cum5 > 0.01) { $score = 3.0; $expl = 'Mildly favorable 5-day tendency.'; }
            if ($row->cum5 < -0.01){ $score = 1.0; $expl = 'Mildly unfavorable 5-day tendency.'; }
        }
        return [$score, $expl];
    }


    protected function tradingDate(\Carbon\Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }
}
