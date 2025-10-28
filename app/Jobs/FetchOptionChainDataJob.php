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
use App\Models\OptionExpiration;
use Carbon\Carbon;

class FetchOptionChainDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    protected array $symbols;
    protected ?int $days;

    protected array $buffer = [];
    protected int $bufferLimit = 5000;

    public $timeout = 900;
    public $tries = 1;
    public function backoff(): array { return [30, 120, 300]; }

    protected bool $debug = false;
    protected int $totalBuffered = 0;
    protected int $totalFlushed = 0;

    public function __construct(array $symbols, ?int $days = null)
    {
        $this->symbols = array_map(fn($s) => \App\Support\Symbols::canon($s), $symbols);
        $this->days = $days ?? 90;
        $this->onQueue('ingest');
        $this->debug = (bool) env('FETCH_OPTIONS_DEBUG', false);
    }

    public function handle(): void
    {
        foreach (DB::getConnections() as $conn) {
            $conn->disableQueryLog();
        }

        $date = $this->tradingDate(now());
        $apiKey = env('POLYGON_API_KEY');
        $now = now();
        $endWindow = $this->days ? now()->addDays($this->days) : now()->addDays(14);
        $jobStart = microtime(true);

        foreach ($this->symbols as $symbol) {
            $symStart = microtime(true);

            /** ---- Step 1: Get all expirations ---- */
            $expirations = $this->getExpirations($symbol, $apiKey);
            if (!$expirations) {
                Log::warning("No Polygon expirations for {$symbol}");
                continue;
            }

            $expDates = array_filter($expirations, fn($d) =>
                strtotime($d) >= $now->timestamp && strtotime($d) <= $endWindow->timestamp
            );
            sort($expDates);
            $expDates = array_slice($expDates, 0, 16);
            if (!$expDates) continue;

            $expIds = $this->ensureExpirationIds($symbol, $expDates);

            /** ---- Step 2: Get full chain snapshot ---- */
            $t = microtime(true);
            $chain = $this->getOptionChainForExpirations($symbol, $expDates, $apiKey);

            $this->dbg('http.fetch_chain', $symbol, $t);

            if (!$chain || empty($chain['results'])) {
                Log::warning("No chain data for {$symbol}");
                continue;
            }

            // Underlying price
            $S = $chain['underlying_price'] ?? $this->getUnderlyingSpot($symbol, $apiKey);
            $haveSpot = is_numeric($S) && $S > 0;
            if (!$haveSpot) {
                Log::notice("No underlying price for {$symbol}; inserting rows without greeks");
            }

            /** ---- Step 3: Iterate and buffer ---- */
            foreach ($chain['results'] as $row) {
                $expDate = $row['expiration_date'] ?? null;
                if (!$expDate || !isset($expIds[$expDate])) continue;
                $expId = $expIds[$expDate];
                $tsExp = strtotime($expDate);

                // Filter out empty OI & Vol
                $oi = (int) ($row['open_interest'] ?? 0);
                $vol = (int) ($row['volume'] ?? 0);
                if ($oi === 0 && $vol === 0) continue;

                $type = str_contains($row['contract_type'] ?? '', 'put') ? 'put' : 'call';
                $K = $row['strike_price'] ?? null;
                $iv = $row['implied_volatility'] ?? null;
                if (!$K) continue;

                $T = $this->timeToExpirationYears($tsExp);
                $delta = $vega = $gamma = null;
                if ($haveSpot && $iv && $iv > 0 && $K > 0 && $T > 0) {
                    $gamma = $this->computeGamma($S, $K, $T, $iv, 0.0);
                    $delta = $this->computeDelta($type, $S, $K, $T, $iv, 0.0);
                    $vega  = $this->computeVega($S, $K, $T, $iv, 0.0);
                }

                $this->buffer[] = [
                    'expiration_id'    => $expId,
                    'data_date'        => $date,
                    'option_type'      => $type,
                    'strike'           => $K,
                    'open_interest'    => $oi,
                    'volume'           => $vol,
                    'gamma'            => $gamma,
                    'delta'            => $delta,
                    'vega'             => $vega,
                    'iv'               => $iv,
                    'underlying_price' => $haveSpot ? $S : null,
                    'data_timestamp'   => now(),
                ];

                if (count($this->buffer) >= $this->bufferLimit) {
                    $this->flushBuffer();
                }
            }

            $this->flushBuffer();
            Log::info(sprintf('Processed %s options (<=%sd) in %.1f ms',
                $symbol, $this->days, (microtime(true) - $symStart) * 1000
            ));
        }

        Log::info(sprintf(
            'FetchOptionChainDataJob finished. buffered=%d flushed=%d total=%.1f ms peak_mem=%.1f MB',
            $this->totalBuffered,
            $this->totalFlushed,
            (microtime(true) - $jobStart) * 1000,
            memory_get_peak_usage(true) / 1048576
        ));
    }

    protected function getExpirations(string $symbol, string $apiKey): array
    {
        $resp = Http::retry(2, 200, throw:false)   // <— don't throw on 4xx
            ->timeout(20)
            ->get("https://api.polygon.io/v3/reference/options/contracts", [
                'underlying_ticker' => $symbol,
                'apiKey'  => $apiKey,
            ]);

        if ($resp->failed()) {
            Log::warning("Polygon expirations fail {$symbol}: {$resp->status()} ".$resp->body());
            return [];
        }

        $dates = [];
        foreach ($resp->json('results', []) as $r) {
            if (isset($r['expiration_date'])) $dates[$r['expiration_date']] = true;
        }
        return array_keys($dates);
    }

    // getOptionChain()
   // in getOptionChainForExpirations()

    protected function getOptionChainForExpirations(string $symbol, array $expDates, string $apiKey): ?array
    {
        $base = "https://api.polygon.io/v3/snapshot/options/{$symbol}";
        $underlying = null;
        $all = [];

        foreach ($expDates as $edate) {
            // ✅ use helper that respects Polygon's max=250 and retries with smaller limits
            $resp = $this->fetchSnapshotFirstPage($base, ['expiration_date' => $edate], $apiKey);

            // ❌ remove the hard stop
            // dd($resp);

            if (!$resp->ok()) {
                Log::warning("Polygon chain fail {$symbol} {$edate}: {$resp->status()} ".$resp->body());
                continue;
            }

            $pages = 0;
            do {
                $json = $resp->json();
                $pages++;

                // Underlying across shapes
                $underlying = $underlying
                    ?? ($json['underlying_asset']['price'] ?? null)
                    ?? ($json['underlying_asset']['last']['price'] ?? null)
                    ?? ($json['underlying_asset']['day']['close'] ?? null)
                    ?? ($json['underlyingAsset']['price'] ?? null);

                $rows = $json['results'] ?? $json['options'] ?? $json['result'] ?? [];
                if (!is_array($rows)) $rows = [];

                if ($this->debug) {
                    Log::debug('Polygon chain page', [
                        'symbol' => $symbol,
                        'expiry' => $edate,
                        'rows'   => is_countable($rows) ? count($rows) : 0,
                        'keys'   => array_slice(array_keys($json), 0, 6),
                    ]);
                }

                foreach ($rows as $r) {
                    $d = $r['details'] ?? [];
                    $g = $r['greeks']  ?? [];

                    $exp = $r['expiration_date'] ?? $d['expiration_date'] ?? $edate;
                    $cp  = $r['contract_type']   ?? $d['contract_type']   ?? null;
                    $k   = $r['strike_price']    ?? $d['strike_price']    ?? null;
                    $oi  = $r['open_interest']   ?? $d['open_interest']   ?? ($r['oi'] ?? null);
                    $vol = $r['volume']          ?? $d['volume']          ?? ($r['day']['volume'] ?? null);
                    $iv  = $r['implied_volatility'] ?? $d['implied_volatility'] ?? ($g['iv'] ?? null);

                    if ((!$cp || !$k || !$exp) && isset($r['option_symbol'])) {
                        $parsed = $this->parseOptionSymbol($r['option_symbol']);
                        $exp = $exp ?: $parsed['expiration_date'];
                        $cp  = $cp  ?: $parsed['contract_type'];
                        $k   = $k   ?: $parsed['strike_price'];
                    }

                    if (is_string($cp)) {
                        $lc = strtolower($cp);
                        $cp = str_starts_with($lc, 'p') ? 'put' : (str_starts_with($lc, 'c') ? 'call' : null);
                    } elseif (is_int($cp)) {
                        $cp = $cp === 1 ? 'call' : ($cp === 2 ? 'put' : null);
                    }

                    $all[] = [
                        'expiration_date'    => $exp,
                        'contract_type'      => $cp,
                        'strike_price'       => is_numeric($k) ? (float)$k : null,
                        'open_interest'      => $oi !== null ? (int)$oi : null,
                        'volume'             => $vol !== null ? (int)$vol : null,
                        'implied_volatility' => is_numeric($iv) ? (float)$iv : null,
                    ];
                }

                $next = $json['next_url'] ?? null;
                if ($next) {
                    $resp = Http::retry(2, 200, throw:false)
                        ->timeout(25)
                        ->get($next, ['apiKey' => $apiKey]);
                }
            } while ($resp->ok() && ($json['next_url'] ?? null) && $pages < 10); // allow more pages safely
        }

        $all = array_values(array_filter($all, fn($x) =>
            !empty($x['expiration_date']) && !empty($x['contract_type']) && !empty($x['strike_price'])
        ));

        return [
            'underlying_price' => $underlying,
            'results'          => $all,
        ];
    }


    // getUnderlyingSpot()
    protected function getUnderlyingSpot(string $symbol, string $apiKey): ?float
    {
        $r = Http::retry(1,150, throw:false)->timeout(10)->get(
            "https://api.polygon.io/v2/snapshot/locale/us/markets/stocks/tickers/{$symbol}",
            ['apiKey'=>$apiKey]
        );
        if ($r->ok()) {
            $t = $r->json('ticker') ?? [];
            $p = $t['lastTrade']['p']
            ?? $t['lastQuote']['p']
            ?? $t['day']['c']
            ?? $t['prevDay']['c']
            ?? null;
            if (is_numeric($p) && $p > 0) return (float)$p;
        }

        $r = Http::retry(1,150, throw:false)->timeout(10)->get(
            "https://api.polygon.io/v2/aggs/ticker/{$symbol}/prev",
            ['adjusted'=>true, 'apiKey'=>$apiKey]
        );
        if ($r->ok()) {
            $p = $r->json('results.0.c');
            if (is_numeric($p) && $p > 0) return (float)$p;
        }

        $from = now('America/New_York')->subDays(3)->startOfDay()->valueOf(); // ms
        $to   = now('America/New_York')->valueOf();                            // ms
        $r = Http::retry(1,150, throw:false)->timeout(10)->get(
            "https://api.polygon.io/v2/aggs/ticker/{$symbol}/range/1/minute/{$from}/{$to}",
            ['adjusted'=>true, 'sort'=>'desc', 'limit'=>1, 'apiKey'=>$apiKey]
        );
        if ($r->ok()) {
            $p = $r->json('results.0.c');
            if (is_numeric($p) && $p > 0) return (float)$p;
        }

        return null;
    }


    protected function ensureExpirationIds(string $symbol, array $dates): array
    {
        $existing = OptionExpiration::where('symbol', $symbol)
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
        if ($toInsert) DB::table('option_expirations')->insert($toInsert);

        $all = OptionExpiration::where('symbol', $symbol)
            ->whereIn('expiration_date', $dates)
            ->get(['id','expiration_date'])
            ->keyBy('expiration_date');

        return collect($dates)->mapWithKeys(fn($d) => [$d => $all[$d]->id ?? null])->all();
    }

    protected function flushBuffer(): void
    {
        if (!$this->buffer) return;

        foreach (array_chunk($this->buffer, 2000) as $chunk) {
            DB::table('option_chain_data')->upsert(
                $chunk,
                ['expiration_id','data_date','option_type','strike'],
                ['open_interest','volume','gamma','delta','vega','iv','underlying_price','data_timestamp']
            );
            $this->totalFlushed += count($chunk);
        }
        $this->totalBuffered += count($this->buffer);
        $this->buffer = [];
    }

    protected function timeToExpirationYears(int $expTs): float
    {
        return max($expTs - time(), 0) / (365 * 24 * 3600);
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
        $Nd1 = $this->normCdf($d1);
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
        $b1 = 0.319381530; $b2 = -0.356563782; $b3 = 1.781477937; $b4 = -1.821255978; $b5 = 1.330274429;
        $t = 1.0 / (1.0 + $p * abs($x));
        $nd = (1.0 / sqrt(2.0 * M_PI)) * exp(-0.5 * $x * $x);
        $poly = ($b1*$t)+($b2*$t*$t)+($b3*$t*$t*$t)+($b4*$t*$t*$t*$t)+($b5*$t*$t*$t*$t*$t);
        $cdf = 1.0 - $nd * $poly;
        return ($x >= 0.0) ? $cdf : 1.0 - $cdf;
    }

    protected function tradingDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }

    protected function dbg(string $stage, string $symbol, float $start, array $extra = []): void
    {
        if (!$this->debug) return;
        $ms = (microtime(true) - $start) * 1000;
        Log::debug('FetchOptionChainDataJob timing', array_merge(['stage'=>$stage, 'symbol'=>$symbol, 'ms'=>round($ms,1)], $extra));
    }

    protected function parseOptionSymbol(string $occ): array
    {
        // Example: AAPL241220C00180000 (OCC symbology)
        //           └──sym  └date  └C/P └ strike (1/1000)
        if (!preg_match('/^([A-Z\.]{1,6})(\d{6})([CP])(\d{8})$/', $occ, $m)) {
            return ['expiration_date'=>null,'contract_type'=>null,'strike_price'=>null];
        }
        $y = substr($m[2], 0, 2);
        $mth = substr($m[2], 2, 2);
        $d = substr($m[2], 4, 2);
        $exp = sprintf('20%02d-%02d-%02d', $y, $mth, $d);
        return [
            'expiration_date' => $exp,
            'contract_type'   => $m[3] === 'P' ? 'put' : 'call',
            'strike_price'    => ((int)$m[4]) / 1000.0,
        ];
    }

    // replace your helper with this version (respect max=250 and clearer match text)
    private function fetchSnapshotFirstPage(string $base, array $params, string $apiKey)
    {
        foreach ([250, 200, 100, 50] as $lim) {
            $resp = Http::retry(2, 200, throw:false)
                ->timeout(25)
                ->get($base, $params + ['limit' => $lim, 'apiKey' => $apiKey]);

            if ($resp->ok()) return $resp;

            $body = (string) $resp->body();
            // bail early only on explicit non-limit validation errors
            if ($resp->status() !== 400 || stripos($body, "Limit") === false) {
                return $resp;
            }

            Log::notice("Polygon snapshot limit {$lim} rejected; trying smaller limit");
        }
        return $resp; // last response (still an error)
    }
}
