<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\OptionExpiration;
use Carbon\Carbon;

class FetchOptionChainDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /** @var string[] */
    protected array $symbols;

    /** @var int|null Max days out to keep expirations (client-side filter) */
    protected ?int $days;

    // ----- Tunables (adjust as needed) -----
    /** Keep strikes within ±X% of spot */
    protected float $strikeBandPct = 0.40;

    /** Minimal activity to keep a row (drop dead contracts quickly) */
    protected int $minKeepOI  = 1;
    protected int $minKeepVol = 1;

    /** Compute Greeks only near ATM to cut CPU load (set to 1.0 to compute across full band) */
    protected float $greeksNearPct = 0.15; // ±15% around spot

    /** Cache guard to prevent duplicate pulls per symbol/day */
    protected int $guardMinutes = 10;

    public function __construct(array $symbols, ?int $days = null)
    {
        $this->symbols = array_values(array_unique(array_map(
            static fn($s) => \App\Support\Symbols::canon($s),
            $symbols
        )));
        $this->days = $days;
    }

    public function handle(): void
    {
        $apiKey = env('FINNHUB_API_KEY');
        if (!$apiKey) {
            Log::error('FINNHUB_API_KEY is missing.');
            return;
        }

        $date = $this->tradingDate(now());
        $endWindow = ($this->days ? now()->addDays($this->days) : now()->addDays(90))->endOfDay();

        foreach ($this->symbols as $symbol) {
            // Duplicate-work guard
            if (!Cache::add("optchain:pulling:{$symbol}:{$date}", 1, now()->addMinutes($this->guardMinutes))) {
                Log::info("Skip {$symbol} — another worker is already pulling for {$date}");
                continue;
            }

            // Pull whole chain once (Finnhub doesn’t let us server-filter by expiry in this endpoint)
            $url = 'https://finnhub.io/api/v1/stock/option-chain';
            $resp = Http::retry(3, 250, throw: false)
                ->timeout(15)
                ->acceptJson()
                ->get($url, ['symbol' => $symbol, 'token' => $apiKey]);

            if ($resp->failed()) {
                Log::error("Finnhub option-chain failed for {$symbol}: {$resp->status()} {$resp->body()}");
                continue;
            }

            $json = $resp->json() ?? [];
            $sets = $json['data'] ?? [];
            if (!is_array($sets) || !$sets) {
                Log::warning("No option data for {$symbol}");
                continue;
            }

            $spot = (float)($json['lastTradePrice'] ?? 0);
            if ($spot <= 0) {
                Log::warning("No/invalid spot for {$symbol}, skipping greeks and most filtering.");
            }

            // Filter expirations to our window
            $nowTs = now()->timestamp;
            $expWindowSets = [];
            foreach ($sets as $set) {
                $expDate = $set['expirationDate'] ?? null;
                if (!$expDate) continue;
                $expTs = strtotime($expDate);
                if ($expTs === false) continue;

                if ($expTs >= $nowTs && $expTs <= $endWindow->timestamp) {
                    $expWindowSets[] = [
                        'date' => $expDate,
                        'ts'   => $expTs,
                        'opts' => $set['options'] ?? [],
                    ];
                }
            }

            if (!$expWindowSets) {
                Log::info("No expirations in window for {$symbol} (days={$this->days})");
                continue;
            }

            // Preload/create expiration ids in bulk
            $expDates = collect($expWindowSets)->pluck('date')->unique()->values();
            $expMap = OptionExpiration::query()
                ->where('symbol', $symbol)
                ->whereIn('expiration_date', $expDates)
                ->pluck('id', 'expiration_date');

            $toInsert = [];
            foreach ($expDates as $d) {
                if (!isset($expMap[$d])) $toInsert[] = ['symbol' => $symbol, 'expiration_date' => $d];
            }
            if ($toInsert) {
                DB::table('option_expirations')->insert($toInsert);
                // refresh map
                $expMap = OptionExpiration::query()
                    ->where('symbol', $symbol)
                    ->whereIn('expiration_date', $expDates)
                    ->pluck('id', 'expiration_date');
            }

            // Strike filters
            $minStrike = $spot > 0 ? $spot * (1 - $this->strikeBandPct) : 0;
            $maxStrike = $spot > 0 ? $spot * (1 + $this->strikeBandPct) : INF;

            // Greeks near-ATM band
            $nearMin = $spot > 0 ? $spot * (1 - $this->greeksNearPct) : 0;
            $nearMax = $spot > 0 ? $spot * (1 + $this->greeksNearPct) : INF;

            $totalKept = 0;
            foreach ($expWindowSets as $set) {
                $expDate = $set['date'];
                $expTs   = $set['ts'];
                $expId   = (int)$expMap[$expDate];

                $rows = [];

                foreach (['CALL' => 'call', 'PUT' => 'put'] as $apiSide => $side) {
                    $list = $set['opts'][$apiSide] ?? [];
                    if (!$list) continue;

                    foreach ($list as $opt) {
                        $strike = (float)($opt['strike'] ?? 0);
                        if ($strike <= 0 || $strike < $minStrike || $strike > $maxStrike) continue;

                        $oi  = (int)($opt['openInterest'] ?? 0);
                        $vol = (int)($opt['volume'] ?? 0);
                        if ($oi < $this->minKeepOI && $vol < $this->minKeepVol) continue;

                        // IV normalize
                        $ivRaw = $opt['impliedVolatility'] ?? null;
                        $iv = null;
                        if ($ivRaw !== null) {
                            $iv = (float)$ivRaw;
                            if ($iv > 1.0) $iv = $iv / 100.0;
                            if ($iv <= 0) $iv = null;
                        }

                        // Time to expiry (years)
                        $T = $this->timeToExpirationYears($expTs);

                        // Greeks: only if IV present and near ATM
                        $gamma = $delta = $vega = null;
                        if ($iv !== null && $T > 0 && $strike >= $nearMin && $strike <= $nearMax && $spot > 0) {
                            $gamma = $this->computeGamma($spot, $strike, $T, $iv, 0.0);
                            $delta = $this->computeDelta($side, $spot, $strike, $T, $iv, 0.0);
                            $vega  = $this->computeVega ($spot, $strike, $T, $iv, 0.0);
                        }

                        $rows[] = [
                            'expiration_id'     => $expId,
                            'data_date'         => $date,
                            'option_type'       => $side,
                            'strike'            => $strike,
                            'open_interest'     => $oi,
                            'volume'            => $vol,
                            'gamma'             => $gamma,
                            'delta'             => $delta,
                            'vega'              => $vega,
                            'iv'                => $iv,
                            'underlying_price'  => $spot ?: null,
                            'data_timestamp'    => now(),
                        ];
                    }
                }

                if ($rows) {
                    DB::transaction(function () use ($rows) {
                        DB::table('option_chain_data')->upsert(
                            $rows,
                            ['expiration_id', 'data_date', 'option_type', 'strike'],
                            ['open_interest', 'volume', 'gamma', 'delta', 'vega', 'iv', 'underlying_price', 'data_timestamp']
                        );
                    });
                    $totalKept += count($rows);
                }
            }

            Log::info("Fetched {$symbol}: kept {$totalKept} rows (days=" . ($this->days ?? 14) . ", band=" . ($this->strikeBandPct * 100) . "%).");
        }
    }

    // ---------- Helpers ----------

    protected function tradingDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }

    protected function timeToExpirationYears(int $expirationTimestamp): float
    {
        $now = time();
        $secondsToExp = max($expirationTimestamp - $now, 0);
        $yearInSeconds = 365 * 24 * 3600;
        return $secondsToExp / $yearInSeconds;
    }

    protected function normPdf(float $x): float
    {
        return (1.0 / sqrt(2.0 * M_PI)) * exp(-0.5 * $x * $x);
    }

    protected function normCdf(float $x): float
    {
        // Abramowitz & Stegun approximation
        $p = 0.2316419;
        $b1 = 0.319381530;
        $b2 = -0.356563782;
        $b3 = 1.781477937;
        $b4 = -1.821255978;
        $b5 = 1.330274429;

        $t = 1.0 / (1.0 + $p * abs($x));
        $nd = $this->normPdf($x);
        $poly = ($b1*$t) + ($b2*$t*$t) + ($b3*$t*$t*$t) + ($b4*$t*$t*$t*$t) + ($b5*$t*$t*$t*$t*$t);
        $cdf = 1.0 - $nd * $poly;

        return ($x >= 0.0) ? $cdf : 1.0 - $cdf;
    }

    protected function computeGamma(float $S, float $K, float $T, float $sigma, float $r = 0.0): ?float
    {
        if ($T <= 0 || $sigma <= 0 || $S <= 0 || $K <= 0) return null;
        $d1 = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
        $nd1 = $this->normPdf($d1);
        return $nd1 / ($S * $sigma * sqrt($T));
    }

    protected function computeDelta(string $type, float $S, float $K, float $T, float $sigma, float $r = 0.0): ?float
    {
        if ($T <= 0 || $sigma <= 0 || $S <= 0 || $K <= 0) return null;
        $d1 = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
        $Nd1 = $this->normCdf($d1);
        return $type === 'call' ? $Nd1 : ($type === 'put' ? $Nd1 - 1.0 : null);
    }

    protected function computeVega(float $S, float $K, float $T, float $sigma, float $r = 0.0): ?float
    {
        if ($T <= 0 || $sigma <= 0 || $S <= 0 || $K <= 0) return null;
        $d1  = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
        $nd1 = $this->normPdf($d1);
        // per 100% vol; multiply by 0.01 if you want per vol point
        return $S * $nd1 * sqrt($T);
    }
}
