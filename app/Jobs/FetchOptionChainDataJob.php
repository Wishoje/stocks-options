<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    protected $symbols;
    protected $days;   // for example, we might specify 7 or 14 if we want to restrict fetch

    /**
     * @param array $symbols Array of symbols (e.g. ['SPY','AMZN','TSLA'])
     * @param int|null $days Number of days from now to filter expirations (optional)
     */
    public function __construct(array $symbols, ?int $days = null)
    {
        $this->symbols = array_map(fn($s)=>\App\Support\Symbols::canon($s), $symbols);
        $this->days = $days;  // if null, we might fetch all available or have a default
    }

    public function handle()
    {
        $date = $this->tradingDate(now());
        $apiKey = env('FINNHUB_API_KEY');

        // If $days is specified, define an end window
        // Otherwise, you could skip filtering or use a default
        $now = now();
        $endWindow = $this->days 
            ? now()->addDays($this->days) 
            : now()->addDays(14);  // fallback default

        foreach ($this->symbols as $symbol) {
            $url = "https://finnhub.io/api/v1/stock/option-chain";
            $response = Http::retry(3, 250, throw: false)
                ->get($url, ['symbol' => $symbol, 'token' => $apiKey]);

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

             \Artisan::call('chain:snapshot', ['date' => $date]);

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
        $ivRaw = $option['impliedVolatility'] ?? null;
        if ($ivRaw !== null) {
            $iv = ($ivRaw > 1.0) ? $ivRaw / 100.0 : $ivRaw; // percent -> decimal
        } else {
            $iv = null;
        }
        $volume     = $option['volume'] ?? null;
    
        $T    = $this->timeToExpirationYears($expirationTimestamp);
        $delta = null;
        $vega  = null;
        $gamma = null;

        if ($iv && $iv > 0 && $strike && $strike > 0 && $underlyingPrice > 0 && $T > 0) {
            $gamma = $this->computeGamma($underlyingPrice, $strike, $T, $iv, 0.0);
            $delta = $this->computeDelta($optionType, $underlyingPrice, $strike, $T, $iv, 0.0);
            $vega  = $this->computeVega($underlyingPrice, $strike, $T, $iv, 0.0);
        }

        OptionChainData::updateOrCreate(
            [
                'expiration_id' => $expiration->id,
                'data_date'     => $date,
                'option_type'   => $optionType,
                'strike'        => $strike,
            ],
            [
                'open_interest'    => $openInterest,
                'volume'           => $volume,
                'gamma'            => $gamma,
                'delta'            => $delta,
                'vega'             => $vega,
                'iv'               => $iv,
                'underlying_price' => $underlyingPrice,
                'data_timestamp'   => now(),
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

    protected function computeDelta(string $type, float $S, float $K, float $T, float $sigma, float $r = 0.0): ?float
    {
        if ($T <= 0 || $sigma <= 0 || $S <= 0 || $K <= 0) return null;

        // if your API returns IV in percent (e.g., 24.5), convert: $sigma /= 100.0;
        $d1 = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
        $Nd1 = $this->normCdf($d1);
        if ($type === 'call') return $Nd1;
        if ($type === 'put')  return $Nd1 - 1.0;
        return null;
    }

    protected function computeVega(float $S, float $K, float $T, float $sigma, float $r = 0.0): ?float
    {
        if ($T <= 0 || $sigma <= 0 || $S <= 0 || $K <= 0) return null;
        $d1  = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
        $nd1 = $this->normPdf($d1);
        // Black–Scholes vega is per 1 vol (i.e., per 100%); multiply by 0.01 if you want per 1 vol point
        return $S * $nd1 * sqrt($T);
    }

    protected function tradingDate(\Carbon\Carbon $now): string
    {
        // Use America/New_York and roll back to previous business day on weekends.
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) {
            $ny->previousWeekday();
        }
        return $ny->toDateString();
    }

    protected function normCdf(float $x): float
    {
        // constants for A&S 7.1.26
        $p = 0.2316419;
        $b1 = 0.319381530;
        $b2 = -0.356563782;
        $b3 = 1.781477937;
        $b4 = -1.821255978;
        $b5 = 1.330274429;

        $t = 1.0 / (1.0 + $p * abs($x));
        $nd = $this->normPdf($x); // 1/sqrt(2π) * e^{-x^2/2}
        $poly = ($b1*$t) + ($b2*$t*$t) + ($b3*$t*$t*$t) + ($b4*$t*$t*$t*$t) + ($b5*$t*$t*$t*$t*$t);
        $cdf = 1.0 - $nd * $poly;

        return ($x >= 0.0) ? $cdf : 1.0 - $cdf;
    }

}
