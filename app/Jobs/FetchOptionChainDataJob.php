<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FetchOptionChainDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $symbols;

    public function __construct(array $symbols = ['SPY', 'IWM', 'QQQ'])
    {
        $this->symbols = $symbols; 
    }

    public function handle()
    {
        $date = now()->toDateString();
        $apiKey = env('FINNHUB_API_KEY');

        // Define the "next week" window: from now to 7 days in the future
        $now = now();
        $sevenDaysFromNow = now()->addDays(14);

        foreach ($this->symbols as $symbol) {
            $url = "https://finnhub.io/api/v1/stock/option-chain";
            $response = Http::get($url, [
                'symbol' => $symbol,
                'token' => $apiKey,
            ]);

            if ($response->failed()) {
                \Log::error("Failed to fetch data for $symbol from Finnhub. HTTP Status: " . $response->status());
                \Log::error("Response Body: " . $response->body());
                continue;
            }

            $data = $response->json();

            if (!isset($data['data']) || !is_array($data['data']) || count($data['data']) === 0) {
                \Log::warning("No option data for $symbol from Finnhub.");
                continue;
            }

            $underlyingPrice = $data['lastTradePrice'] ?? null;
            if (!$underlyingPrice) {
                \Log::warning("No underlying price available for $symbol. Skipping gamma computation.");
                continue;
            }

            // Filter to find the earliest expiration within the next 7 days
            $filteredExpirations = [];
            foreach ($data['data'] as $expirationSet) {
                $expirationDate = $expirationSet['expirationDate'] ?? null;
                if (!$expirationDate) {
                    continue;
                }
                $expirationTimestamp = strtotime($expirationDate);

                // Check if expiration date is within the next 7 days
                if ($expirationTimestamp >= $now->timestamp && $expirationTimestamp <= $sevenDaysFromNow->timestamp) {
                    $filteredExpirations[] = $expirationSet;
                }
            }

            if (empty($filteredExpirations)) {
                \Log::warning("No expiration within next week for $symbol.");
                continue;
            }

            // Sort by expiration date to pick the earliest one
            usort($filteredExpirations, function($a, $b) {
                $tA = isset($a['expirationDate']) ? strtotime($a['expirationDate']) : PHP_INT_MAX;
                $tB = isset($b['expirationDate']) ? strtotime($b['expirationDate']) : PHP_INT_MAX;
                return $tA <=> $tB;
            });

            // Process only the earliest next-week expiration set
            $earliestSet = $filteredExpirations[0];
            $expirationDate = $earliestSet['expirationDate'];
            $expirationTimestamp = strtotime($expirationDate);

            // According to the Finnhub response structure, options are nested under:
            // $earliestSet['options']['CALL'] and $earliestSet['options']['PUT']
            // Note: In your posted sample, data was structured as:
            // "options" => array:2 [
            //   "CALL" => [...],
            //   "PUT" => [...]
            // ]
            // Adjust accordingly if the structure differs.

            $calls = $earliestSet['options']['CALL'] ?? [];
            $puts = $earliestSet['options']['PUT'] ?? [];

            // Process calls
            foreach ($calls as $call) {
                $this->storeOptionData($symbol, $date, 'call', $call, $underlyingPrice, $expirationDate, $expirationTimestamp);
            }

            // Process puts
            foreach ($puts as $put) {
                $this->storeOptionData($symbol, $date, 'put', $put, $underlyingPrice, $expirationDate, $expirationTimestamp);
            }

            \Log::info("Processed $symbol options for expiration $expirationDate (next week).");
        }
    }

    protected function storeOptionData($symbol, $date, $optionType, $option, $underlyingPrice, $expirationDate, $expirationTimestamp)
    {
        $strike = $option['strike'] ?? null;
        $openInterest = $option['openInterest'] ?? null;
        // Finnhub gives impliedVolatility in decimal form (e.g., 0.20 = 20%)
        $iv = $option['impliedVolatility'] ?? null; 
        // Construct a unique option symbol
        $optionSymbol = $symbol . "_" . $expirationDate . "_" . $strike . "_" . $optionType;

        // Compute gamma if possible
        $gamma = null;
        if ($expirationDate && $underlyingPrice && $iv && $strike && $iv > 0 && $underlyingPrice > 0) {
            $T = $this->timeToExpirationYears($expirationTimestamp);
            $gamma = $this->computeGamma($underlyingPrice, $strike, $T, $iv, 0.0);
        }

        DB::table('option_chains')->updateOrInsert(
            [
                'symbol' => $symbol,
                'data_date' => $date,
                'option_symbol' => $optionSymbol,
            ],
            [
                'option_type' => $optionType,
                'strike' => $strike,
                'expiration_date' => $expirationDate,
                'open_interest' => $openInterest,
                'gamma' => $gamma,
                'delta' => null, // If needed, compute similarly
                'iv' => $iv,
                'underlying_price' => $underlyingPrice,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    protected function timeToExpirationYears($expirationTimestamp)
    {
        $now = time();
        $secondsToExp = max($expirationTimestamp - $now, 0);
        $yearInSeconds = 365 * 24 * 3600; 
        return $secondsToExp / $yearInSeconds;
    }

    protected function computeGamma($S, $K, $T, $sigma, $r)
    {
        // If T=0 or sigma=0, gamma not defined
        if ($T <= 0 || $sigma <= 0 || $S <= 0) {
            return null;
        }

        // Compute d1
        $d1 = (log($S/$K) + ($r + 0.5 * $sigma * $sigma)*$T) / ($sigma * sqrt($T));

        // N'(d1) = PDF of standard normal at d1
        $nd1 = $this->normPdf($d1);

        // Gamma formula: Gamma = N'(d1)/(S*sigma*sqrt(T)) for r=0
        $gamma = $nd1 / ($S * $sigma * sqrt($T));

        return $gamma;
    }

    protected function normPdf($x)
    {
        // Standard normal PDF
        return (1.0 / sqrt(2.0 * M_PI)) * exp(-0.5 * $x * $x);
    }
}
