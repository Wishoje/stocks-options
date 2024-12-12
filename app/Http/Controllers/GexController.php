<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GexController extends Controller
{
    public function getGexLevels(Request $request)
    {
        $symbol = $request->query('symbol', 'SPY'); 

        // Define a 14-day window
        $now = now();
        $fourteenDaysFromNow = now()->addDays(14);

        // Fetch all options for this symbol with expiration within the next 14 days
        $options = DB::table('option_chains')
            ->where('symbol', $symbol)
            ->whereBetween('expiration_date', [$now->toDateString(), $fourteenDaysFromNow->toDateString()])
            ->get();

        if ($options->isEmpty()) {
            return response()->json(['error' => 'No data found for the selected symbol in the next 14 days'], 404);
        }

        // Aggregate GEX per strike across ALL expirations
        $strikes = [];
        foreach ($options as $opt) {
            if (!isset($strikes[$opt->strike])) {
                $strikes[$opt->strike] = [
                    'call_gamma_sum' => 0,
                    'put_gamma_sum' => 0,
                ];
            }

            // GEX contribution = gamma * OI * 100
            // Note: This aggregates over all expirations, since we're looping through all retrieved options.
            $contribution = ($opt->gamma ?? 0) * ($opt->open_interest ?? 0) * 100;

            if ($opt->option_type == 'call') {
                $strikes[$opt->strike]['call_gamma_sum'] += $contribution;
            } else {
                $strikes[$opt->strike]['put_gamma_sum'] += $contribution;
            }
        }

        // Compute net GEX (example: call_gamma_sum + put_gamma_sum)
        $strikeData = [];
        foreach ($strikes as $strike => $vals) {
            $netGEX = $vals['call_gamma_sum'] - $vals['put_gamma_sum']; 
            // If you prefer call - put, just change the above line.
            $strikeData[] = [
                'strike' => $strike,
                'net_gex' => $netGEX
            ];
        }

        // Sort by strike
        usort($strikeData, fn($a, $b) => $a['strike'] <=> $b['strike']);

        // Identify HVL: find where net_gex crosses from negative to positive
        $HVL = null;
        for ($i = 0; $i < count($strikeData) - 1; $i++) {
            if ($strikeData[$i]['net_gex'] < 0 && $strikeData[$i+1]['net_gex'] >= 0) {
                $HVL = $strikeData[$i+1]['strike'];
                break;
            }
        }

        if (!$HVL && count($strikeData) > 0) {
            // If no crossover found, pick the first strike as a fallback
            $HVL = $strikeData[0]['strike'];
        }

        // Find top positive GEX strikes (Call Resistance / Walls)
        $positiveStrikes = array_filter($strikeData, fn($d) => $d['net_gex'] > 0);
        usort($positiveStrikes, fn($a, $b) => $b['net_gex'] <=> $a['net_gex']);

        $callResistance = $positiveStrikes[0]['strike'] ?? null;
        $callWall2 = $positiveStrikes[1]['strike'] ?? null;
        $callWall3 = $positiveStrikes[2]['strike'] ?? null;

        // Find top negative GEX strikes (Put Support / Walls)
        $negativeStrikes = array_filter($strikeData, fn($d) => $d['net_gex'] < 0);
        usort($negativeStrikes, fn($a, $b) => abs($b['net_gex']) <=> abs($a['net_gex']));

        $putSupport = $negativeStrikes[0]['strike'] ?? null;
        $putWall2 = $negativeStrikes[1]['strike'] ?? null;
        $putWall3 = $negativeStrikes[2]['strike'] ?? null;
        
        // Return aggregated GEX levels
        return response()->json([
            'symbol' => $symbol,
            'timeframe' => 'Next 14 days',
            'HVL' => $HVL,
            'call_resistance' => $callResistance !== null ? floatval($callResistance) : null,
            'call_wall_2' => $callWall2 !== null ? floatval($callWall2) : null,
            'call_wall_3' => $callWall3 !== null ? floatval($callWall3) : null,
            'put_support' => $putSupport !== null ? floatval($putSupport) : null,
            'put_wall_2' => $putWall2 !== null ? floatval($putWall2) : null,
            'put_wall_3' => $putWall3 !== null ? floatval($putWall3) : null,
        ]);
    }
}
