<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\OptionExpiration;
use App\Models\OptionChainData;
use Carbon\Carbon;

class FetchOptionChainDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $symbols;
    protected $days;   // for example, we might specify 7 or 14 if we want to restrict fetch

    /**
     * @param array $symbols Array of symbols (e.g. ['SPY','AMZN','TSLA'])
     * @param int|null $days Number of days from now to filter expirations (optional)
     */
    public function __construct(array $symbols, ?int $days = null)
    {
        $this->symbols = $symbols;
        $this->days = $days;  // if null, we might fetch all available or have a default
    }

    public function handle()
    {
        $date = now()->toDateString();
        $apiKey = env('FINNHUB_API_KEY');

        // If $days is specified, define an end window
        // Otherwise, you could skip filtering or use a default
        $now = now();
        $endWindow = $this->days 
            ? now()->addDays($this->days) 
            : now()->addDays(14);  // fallback default

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

            // Filter all expiration sets within our defined window
            $selectedExpirations = [];
            foreach ($data['data'] as $expirationSet) {
                $expirationDate = $expirationSet['expirationDate'] ?? null;
                if (!$expirationDate) {
                    continue;
                }
                $expirationTimestamp = strtotime($expirationDate);

                if ($expirationTimestamp >= $now->timestamp && $expirationTimestamp <= $endWindow->timestamp) {
                    $selectedExpirations[] = $expirationSet;
                }
            }

            // If no expirations match, skip
            if (empty($selectedExpirations)) {
                \Log::warning("No expiration within next {$this->days} days for $symbol.");
                continue;
            }

            // Process each set
            foreach ($selectedExpirations as $expirationSet) {
                $expirationDate = $expirationSet['expirationDate'];
                $expirationTimestamp = strtotime($expirationDate);

                $calls = $expirationSet['options']['CALL'] ?? [];
                $puts  = $expirationSet['options']['PUT']  ?? [];

                // Process calls
                foreach ($calls as $call) {
                    $this->storeOptionData($symbol, $date, 'call', $call, $underlyingPrice, $expirationDate, $expirationTimestamp);
                }

                // Process puts
                foreach ($puts as $put) {
                    $this->storeOptionData($symbol, $date, 'put', $put, $underlyingPrice, $expirationDate, $expirationTimestamp);
                }
            }

            \Log::info("Processed $symbol options for up to {$this->days} days out.");
        }
    }

    protected function storeOptionData(
        string $symbol, 
        string $date, 
        string $optionType, 
        array $option, 
        float $underlyingPrice, 
        string $expirationDate, 
        int $expirationTimestamp
    ) {
        // 1) Find or create the expiration row
        $expiration = OptionExpiration::firstOrCreate(
            [
                'symbol'          => $symbol,
                'expiration_date' => $expirationDate,
            ]
        );

        // 2) Build an "identifier" for the chain data if needed
        // But usually, you'll do an updateOrCreate with a unique "data_date + strike + option_type"
        // because you might have multiple daily records for the same strike. 
        // Or if you only store 1 row per day, that's your condition.
    
        $strike     = $option['strike'] ?? null;
        $openInterest = $option['openInterest'] ?? null;
        $iv         = $option['impliedVolatility'] ?? null;
        $volume     = $option['volume'] ?? null;
    
        // Compute gamma
        $gamma = null;
        if ($iv && $iv > 0 && $strike && $strike > 0 && $underlyingPrice > 0) {
            $T = $this->timeToExpirationYears($expirationTimestamp);
            $gamma = $this->computeGamma($underlyingPrice, $strike, $T, $iv, 0.0);
        }
    
        // 3) Upsert into option_chain_data
        OptionChainData::updateOrCreate(
            [
                'expiration_id' => $expiration->id,
                'data_date'     => $date,
                'option_type'   => $optionType,
                'strike'        => $strike,
            ],
            [
                'open_interest'     => $openInterest,
                'volume'            => $volume,
                'gamma'             => $gamma,
                'delta'             => null,
                'iv'                => $iv,
                'underlying_price'  => $underlyingPrice,
            ]
        );
    }

    protected function timeToExpirationYears(int $expirationTimestamp)
    {
        $now = time();
        $secondsToExp = max($expirationTimestamp - $now, 0);
        $yearInSeconds = 365 * 24 * 3600;
        return $secondsToExp / $yearInSeconds;
    }

    protected function computeGamma(float $S, float $K, float $T, float $sigma, float $r)
    {
        if ($T <= 0 || $sigma <= 0 || $S <= 0 || $K <= 0) {
            return null;
        }

        $d1 = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
        $nd1 = $this->normPdf($d1);
        $gamma = $nd1 / ($S * $sigma * sqrt($T));
        return $gamma;
    }

    protected function normPdf(float $x)
    {
        return (1.0 / sqrt(2.0 * M_PI)) * exp(-0.5 * $x * $x);
    }
}
