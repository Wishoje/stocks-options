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

        // Define a 14-day window
        $now = now();
        $fourteenDaysFromNow = now()->addDays(14);

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

            // Filter all expiration sets within the next 14 days
            $selectedExpirations = [];
            foreach ($data['data'] as $expirationSet) {
                $expirationDate = $expirationSet['expirationDate'] ?? null;
                if (!$expirationDate) {
                    continue;
                }
                $expirationTimestamp = strtotime($expirationDate);

                // Check if expiration date is within the next 14 days
                if ($expirationTimestamp >= $now->timestamp && $expirationTimestamp <= $fourteenDaysFromNow->timestamp) {
                    $selectedExpirations[] = $expirationSet;
                }
            }

            if (empty($selectedExpirations)) {
                \Log::warning("No expiration within next 14 days for $symbol.");
                continue;
            }

            // Process all filtered expiration sets
            foreach ($selectedExpirations as $expirationSet) {
                $expirationDate = $expirationSet['expirationDate'];
                $expirationTimestamp = strtotime($expirationDate);

                $calls = $expirationSet['options']['CALL'] ?? [];
                $puts = $expirationSet['options']['PUT'] ?? [];

                // Process calls
                foreach ($calls as $call) {
                    $this->storeOptionData($symbol, $date, 'call', $call, $underlyingPrice, $expirationDate, $expirationTimestamp);
                }

                // Process puts
                foreach ($puts as $put) {
                    $this->storeOptionData($symbol, $date, 'put', $put, $underlyingPrice, $expirationDate, $expirationTimestamp);
                }

                \Log::info("Processed $symbol options for expiration $expirationDate (within 14 days).");
            }
        }
    }

    protected function storeOptionData($symbol, $date, $optionType, $option, $underlyingPrice, $expirationDate, $expirationTimestamp)
    {
        $strike = $option['strike'] ?? null;
        $openInterest = $option['openInterest'] ?? null;
        $iv = $option['impliedVolatility'] ?? null; 

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
                'delta' => null,
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
        if ($T <= 0 || $sigma <= 0 || $S <= 0) {
            return null;
        }

        $d1 = (log($S/$K) + ($r + 0.5 * $sigma * $sigma)*$T) / ($sigma * sqrt($T));
        $nd1 = $this->normPdf($d1);
        $gamma = $nd1 / ($S * $sigma * sqrt($T));
        return $gamma;
    }

    protected function normPdf($x)
    {
        return (1.0 / sqrt(2.0 * M_PI)) * exp(-0.5 * $x * $x);
    }
}
