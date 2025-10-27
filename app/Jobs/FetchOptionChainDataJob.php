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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use App\Models\OptionExpiration;

class FetchOptionChainDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;
    protected array $symbols;
    protected ?int  $days;

    protected array $buffer = [];
    protected int   $bufferLimit = 1500;

    public $timeout = 900;
    public $tries   = 1;

    public function __construct(array $symbols, ?int $days = null)
    {
        $this->symbols = array_map(fn($s)=>\App\Support\Symbols::canon($s), $symbols);
        $this->days    = $days;
        $this->onQueue('ingest');
    }

    public function handle(): void
    {
        foreach (DB::getConnections() as $conn) {
            $conn->disableQueryLog();
        }

        $date      = $this->tradingDate(now());
        $apiKey    = env('FINNHUB_API_KEY');
        $now       = now();
        $endWindow = $this->days ? now()->addDays($this->days) : now()->addDays(14);

        foreach ($this->symbols as $symbol) {
            $response = Http::retry(3, 250, throw: false)
                ->timeout(25)->connectTimeout(10)
                ->get('https://finnhub.io/api/v1/stock/option-chain', [
                    'symbol' => $symbol,
                    'token'  => $apiKey,
                ]);

            if ($response->failed()) {
                Log::error("Finnhub fail {$symbol} ({$response->status()}): ".$response->body());
                continue;
            }

            $data = $response->json();
            if (!isset($data['data']) || !is_array($data['data']) || !count($data['data'])) {
                Log::warning("No option data for {$symbol}.");
                continue;
            }

            $S = $data['lastTradePrice'] ?? null;
            if (!$S) {
                Log::warning("No underlying price for {$symbol}. Skipping greeks.");
                continue;
            }

            // filter expiries within window
            $rawExpirations = [];
            foreach ($data['data'] as $set) {
                $ed = $set['expirationDate'] ?? null;
                if (!$ed) continue;
                $ts = strtotime($ed);
                if ($ts >= $now->timestamp && $ts <= $endWindow->timestamp) {
                    $rawExpirations[$ed] = true;
                }
            }

            // build, sort, and cap nearest expiries
            $expDates = array_keys($rawExpirations);
            sort($expDates);                 // chronological
            $expDates = array_slice($expDates, 0, length: 16); // keep nearest 16

            if (!$expDates) {
                Log::warning("No expirations within next {$this->days}d for {$symbol}.");
                continue;
            }

            $expIds = $this->ensureExpirationIds($symbol, $expDates);

            $already = DB::table('option_chain_data as o')
                ->whereIn('o.expiration_id', array_values($expIds))
                ->whereDate('o.data_date', $date)
                ->exists();

            if ($already) {
                Log::info("Skip {$symbol}: already ingested for {$date}.");
                continue;
            }

            foreach ($data['data'] as $set) {
                $expDate = $set['expirationDate'] ?? null;
                if (!$expDate || !isset($expIds[$expDate])) continue;

                 $hasThisExpiryToday = DB::table('option_chain_data')
                    ->where('expiration_id', $expIds[$expDate])
                    ->whereDate('data_date', $date)
                    ->limit(1)
                    ->exists();
                if ($hasThisExpiryToday) continue;

                $expId = $expIds[$expDate];
                $tsExp = strtotime($expDate);

                $calls = $set['options']['CALL'] ?? [];
                $puts  = $set['options']['PUT']  ?? [];

                foreach ($calls as $row) {
                    if (
                        (!isset($row['volume']) || (int)$row['volume'] === 0) &&
                        (!isset($row['openInterest']) || (int)$row['openInterest'] === 0)
                    ) {
                        // nothing to aggregate later; skip persisting
                        continue;
                    }
                    $this->bufferRow($date, 'call', $row, $S, $expId, $tsExp);
                }
                foreach ($puts  as $row) {
                    if (
                        (!isset($row['volume']) || (int)$row['volume'] === 0) &&
                        (!isset($row['openInterest']) || (int)$row['openInterest'] === 0)
                    ) {
                        // nothing to aggregate later; skip persisting
                        continue;
                    }
                    $this->bufferRow($date, 'put',  $row, $S, $expId, $tsExp);
                }

                if (count($this->buffer) >= $this->bufferLimit) {
                    $this->flushBuffer();
                }
            }

            $this->flushBuffer();
            Log::info("Processed {$symbol} options (<= {$this->days}d).");
        }

        // run snapshot once
        Artisan::call('chain:snapshot', ['date' => $date]);
    }

    protected function ensureExpirationIds(string $symbol, array $dates): array
    {
        $existing = OptionExpiration::where('symbol',$symbol)
            ->whereIn('expiration_date', $dates)
            ->get(['id','expiration_date'])
            ->keyBy('expiration_date');

        $toInsert = [];
        foreach ($dates as $d) {
            if (!$existing->has($d)) {
                $toInsert[] = [
                    'symbol' => $symbol,
                    'expiration_date' => $d,
                    'created_at'=>now(), 'updated_at'=>now()
                ];
            }
        }
        if ($toInsert) {
            DB::table('option_expirations')->insert($toInsert);
            $existing = OptionExpiration::where('symbol',$symbol)
                ->whereIn('expiration_date', $dates)
                ->get(['id','expiration_date'])
                ->keyBy('expiration_date');
        }

        $map = [];
        foreach ($dates as $d) $map[$d] = $existing[$d]->id ?? null;
        return $map;
    }

    protected function bufferRow(string $date, string $type, array $opt, float $S, int $expirationId, int $expTs): void
    {
        $K   = $opt['strike'] ?? null;
        if (!$K) return;

        $ivR = $opt['impliedVolatility'] ?? null;
        $iv  = $ivR !== null ? ($ivR > 1.0 ? $ivR/100.0 : $ivR) : null;
        $oi  = $opt['openInterest'] ?? null;
        $vol = $opt['volume'] ?? null;

        $T = $this->timeToExpirationYears($expTs);
        $delta = $vega = $gamma = null;
        if ($iv && $iv > 0 && $K > 0 && $S > 0 && $T > 0) {
            $gamma = $this->computeGamma($S, $K, $T, $iv, 0.0);
            $delta = $this->computeDelta($type, $S, $K, $T, $iv, 0.0);
            $vega  = $this->computeVega($S, $K, $T, $iv, 0.0);
        }

        $this->buffer[] = [
            'expiration_id'    => $expirationId,
            'data_date'        => $date,
            'option_type'      => $type,
            'strike'           => $K,
            'open_interest'    => $oi,
            'volume'           => $vol,
            'gamma'            => $gamma,
            'delta'            => $delta,
            'vega'             => $vega,
            'iv'               => $iv,
            'underlying_price' => $S,
            'data_timestamp'   => now(),
        ];
    }

    protected function flushBuffer(): void
    {
        if (!$this->buffer) return;

        // canonical order to reduce lock thrash
        usort($this->buffer, fn($a,$b) =>
            ($a['expiration_id'] <=> $b['expiration_id']) ?:
            strcmp($a['data_date'], $b['data_date'])      ?:
            strcmp($a['option_type'], $b['option_type'])  ?:
            ($a['strike'] <=> $b['strike'])
        );

        foreach (array_chunk($this->buffer, 600) as $chunk) {

           $this->withDeadlockRetry(function() use ($chunk) {
               DB::transaction(function () use ($chunk) {
                   DB::table('option_chain_data')->upsert(
                       $chunk,
                       ['expiration_id','data_date','option_type','strike'],
                       ['open_interest','volume','gamma','delta','vega','iv','underlying_price','data_timestamp']
                   );
               }, 1);
           });

        }
        $this->buffer = [];
    }

    protected function timeToExpirationYears(int $expTs): float
    {
        $secondsToExp = max($expTs - time(), 0);
        return $secondsToExp / (365 * 24 * 3600);
    }

    protected function computeGamma(float $S, float $K, float $T, float $sigma, float $r)
    {
        if ($T<=0 || $sigma<=0 || $S<=0 || $K<=0) return null;
        $d1 = (log($S/$K) + ($r + 0.5*$sigma*$sigma)*$T) / ($sigma*sqrt($T));
        $nd1= (1.0/sqrt(2.0*M_PI))*exp(-0.5*$d1*$d1);
        return $nd1 / ($S * $sigma * sqrt($T));
    }

    protected function computeDelta(string $type, float $S, float $K, float $T, float $sigma, float $r=0.0): ?float
    {
        if ($T<=0 || $sigma<=0 || $S<=0 || $K<=0) return null;
        $d1 = (log($S/$K) + ($r + 0.5*$sigma*$sigma)*$T) / ($sigma*sqrt($T));
        $Nd1 = $this->normCdf($d1);  // restored from old code
        return $type==='call' ? $Nd1 : ($type==='put' ? $Nd1 - 1.0 : null);
    }

    protected function computeVega(float $S, float $K, float $T, float $sigma, float $r=0.0): ?float
    {
        if ($T<=0 || $sigma<=0 || $S<=0 || $K<=0) return null;
        $d1 = (log($S/$K) + ($r + 0.5*$sigma*$sigma)*$T) / ($sigma*sqrt($T));
        $nd1= (1.0/sqrt(2.0*M_PI))*exp(-0.5*$d1*$d1);
        return $S * $nd1 * sqrt($T);
    }

    private function normCdf(float $x): float
    {
        $p = 0.2316419;
        $b1 = 0.319381530;
        $b2 = -0.356563782;
        $b3 = 1.781477937;
        $b4 = -1.821255978;
        $b5 = 1.330274429;

        $t = 1.0 / (1.0 + $p * abs($x));
        $nd = (1.0 / sqrt(2.0 * M_PI)) * exp(-0.5 * $x * $x);
        $poly = ($b1*$t) + ($b2*$t*$t) + ($b3*$t*$t*$t) + ($b4*$t*$t*$t*$t) + ($b5*$t*$t*$t*$t*$t);
        $cdf = 1.0 - $nd * $poly;

        return ($x >= 0.0) ? $cdf : 1.0 - $cdf;
    }

    protected function tradingDate(\Carbon\Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }

    protected function withDeadlockRetry(callable $fn, int $tries = 3, int $sleepMs = 150) {
        beginning:
        try { return $fn(); }
        catch (\Illuminate\Database\QueryException $e) {
            if ($tries > 1 && str_contains($e->getMessage(), 'Deadlock')) {
                usleep($sleepMs * 1000);
                $tries--;
                $sleepMs *= 2; // backoff
                goto beginning;
            }
            throw $e;
        }
    }
}
