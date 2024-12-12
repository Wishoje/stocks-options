<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GexController extends Controller
{
    public function getGexLevels(Request $request)
    {
        $symbol = $request->query('symbol', 'SPY'); 
        // In a real scenario, you might also need to specify which expiration you want or automatically pick the earliest next-week expiry from the DB.

        // 1. Fetch option data for the symbol and earliest next-week expiration
        // For simplicity, let's pick the earliest expiration date available in DB for that symbol.
        $expirationDate = DB::table('option_chains')
            ->where('symbol', $symbol)
            ->orderBy('expiration_date', 'asc')
            ->value('expiration_date');

        if (!$expirationDate) {
            return response()->json(['error' => 'No data for selected symbol'], 404);
        }

        $options = DB::table('option_chains')
            ->where('symbol', $symbol)
            ->where('expiration_date', $expirationDate)
            ->get();

        // 2. Compute Net GEX per strike
        // Group by strike and sum gamma * OI * 100 for calls and puts
        $strikes = [];
        foreach ($options as $opt) {
            if (!isset($strikes[$opt->strike])) {
                $strikes[$opt->strike] = [
                    'call_gamma_sum' => 0,
                    'put_gamma_sum' => 0,
                ];
            }

            // GEX contribution = gamma * OI * 100
            $contribution = ($opt->gamma ?? 0) * ($opt->open_interest ?? 0) * 100;

            if ($opt->option_type == 'call') {
                $strikes[$opt->strike]['call_gamma_sum'] += $contribution;
            } else {
                $strikes[$opt->strike]['put_gamma_sum'] += $contribution;
            }
        }

        // Compute net GEX (call - put or call + put depending on your definition)
        $strikeData = [];
        foreach ($strikes as $strike => $vals) {
            $netGEX = $vals['call_gamma_sum'] + $vals['put_gamma_sum']; 
            // Depending on your methodology, you might do: $vals['call_gamma_sum'] - $vals['put_gamma_sum']
            $strikeData[] = [
                'strike' => $strike,
                'net_gex' => $netGEX
            ];
        }

        // Sort by strike ascending
        usort($strikeData, fn($a, $b) => $a['strike'] <=> $b['strike']);

        // 3. Identify HVL: find where net_gex crosses from negative to positive
        $HVL = null;
        for ($i = 0; $i < count($strikeData) - 1; $i++) {
            if ($strikeData[$i]['net_gex'] < 0 && $strikeData[$i+1]['net_gex'] >= 0) {
                // Simple linear interpolation (optional)
                $HVL = $strikeData[$i+1]['strike'];
                break;
            }
        }

        // If HVL not found, pick a default or leave it null
        if (!$HVL && count($strikeData) > 0) {
            // If all negative or all positive, HVL might be the lowest strike or something else
            $HVL = $strikeData[0]['strike'];
        }

        // 4. Find top positive GEX strikes (Call Resistance)
        $positiveStrikes = array_filter($strikeData, fn($d) => $d['net_gex'] > 0);
        usort($positiveStrikes, fn($a, $b) => $b['net_gex'] <=> $a['net_gex']); 
        // Highest positive (call resistance)
        $callResistance = $positiveStrikes[0]['strike'] ?? null;
        $callWall2 = $positiveStrikes[1]['strike'] ?? null;
        $callWall3 = $positiveStrikes[2]['strike'] ?? null;

        // 5. Find top negative GEX strikes (Put Support)
        $negativeStrikes = array_filter($strikeData, fn($d) => $d['net_gex'] < 0);
        // Sort by absolute negative first
        usort($negativeStrikes, fn($a, $b) => abs($b['net_gex']) <=> abs($a['net_gex']));
        $putSupport = $negativeStrikes[0]['strike'] ?? null;
        $putWall2 = $negativeStrikes[1]['strike'] ?? null;
        $putWall3 = $negativeStrikes[2]['strike'] ?? null;

        return response()->json([
            'symbol' => $symbol,
            'expiration_date' => $expirationDate,
            'HVL' => $HVL,
            'call_resistance' => $callResistance,
            'call_wall_2' => $callWall2,
            'call_wall_3' => $callWall3,
            'put_support' => $putSupport,
            'put_wall_2' => $putWall2,
            'put_wall_3' => $putWall3
        ]);
    }
}
