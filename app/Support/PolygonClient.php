<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Unified market-data client for Massive + Polygon.
 *
 * - Massive endpoints (v3):
 *    • GET /v3/snapshot/options/{underlyingAsset}
 *    • GET /v3/snapshot/options/{underlyingAsset}/{optionContract}
 *    • GET /v3/snapshot (unified, supports type/ticker.any_of/etc.)
 *
 * - Polygon endpoints (subset used here, expand as needed):
 *    • GET /v2/aggs/ticker/{ticker}/range/{multiplier}/{timespan}/{from}/{to}
 */
class PolygonClient
{
    /** ====== LOGGING HELPERS ====== */
    private const LOG_BODY_MAX = 800;

    private static function clip(?string $s, int $max = self::LOG_BODY_MAX): string
    {
        if ($s === null) return '<null>';
        $s = trim($s);
        return strlen($s) > $max ? substr($s, 0, $max).'…<clipped>' : $s;
    }

    /** ====== HTTP CORE ====== */

    /**
     * Generic GET with retries, jittered backoff, JSON return or null on failure.
     *
     * @param array<string,mixed> $headers
     * @param array<string,mixed> $query
     * @return array<string,mixed>|null
     */
    private function safeGet(string $url, array $headers = [], array $query = [], int $maxRetries = 3, int $timeout = 30): ?array
    {
        $attempt = 0;
        $lastStatus = null;
        $lastBody   = null;

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                $resp = Http::withHeaders($headers)
                    ->timeout($timeout)
                    ->get($url, $query);

                $ok     = $resp->ok();
                $status = $resp->status();
                $lastStatus = $status;
                $lastBody   = self::clip($resp->body());

                Log::debug('Http.safeGet.response', [
                    'url'     => $url,
                    'attempt' => $attempt,
                    'status'  => $status,
                    'ok'      => $ok,
                    'qs'      => $query,
                    'snippet' => $lastBody,
                ]);

                if ($ok) {
                    return $resp->json();
                }

                // Retry on 429/5xx; break on other 4xx
                if ($status == 429 || $status >= 500) {
                    $sleep = $this->backoffSleepMs($attempt);
                    usleep($sleep * 1000);
                    continue;
                }

                // Non-retryable error
                break;
            } catch (\Throwable $e) {
                Log::warning('Http.safeGet.exception', [
                    'url'     => $url,
                    'attempt' => $attempt,
                    'msg'     => $e->getMessage(),
                ]);
                // Retry network-ish failures
                $sleep = $this->backoffSleepMs($attempt);
                usleep($sleep * 1000);
            }
        }

        Log::warning('Http.safeGet.failed', [
            'url'    => $url,
            'status' => $lastStatus,
            'body'   => $lastBody,
        ]);

        return null;
    }

    private function backoffSleepMs(int $attempt): int
    {
        $base = [250, 600, 1200, 2400, 4000]; // ms caps
        $idx  = min($attempt - 1, count($base) - 1);
        $jitter = random_int(0, 200);
        return $base[$idx] + $jitter;
    }

    /** ====== MASSIVE CONFIG ====== */

    private function massiveHeaders(): array
    {
        $apiKey = env('MASSIVE_API_KEY');
        return [
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept'        => 'application/json',
        ];
    }

    private function massiveBase(): string
    {
        return rtrim(env('MASSIVE_BASE', 'https://api.massive.com'), '/');
    }

    private function hasMassiveKey(): bool
    {
        return (bool) env('MASSIVE_API_KEY');
    }

    /** ====== POLYGON CONFIG ====== */

    private function polygonHeaders(): array
    {
        // Polygon uses apiKey via query (?apiKey=) most of the time, but we also add Accept for consistency.
        return ['Accept' => 'application/json'];
    }

    private function polygonBase(): string
    {
        return rtrim(env('POLYGON_BASE', 'https://api.polygon.io'), '/');
    }

    private function polygonKey(): ?string
    {
        return env('POLYGON_API_KEY');
    }

    /** ====== PUBLIC: MASSIVE SNAPSHOTS ====== */

    /**
     * Massive: Option Chain Snapshot with auto-pagination via next_url.
     * Mirrors docs: GET /v3/snapshot/options/{underlyingAsset}
     *
     * @param array{limit?:int,order?:string,sort?:string,contract_type?:'call'|'put',
     *              strike_price?:float,'strike_price.gte'?:float,'strike_price.gt'?:float,
     *              'strike_price.lte'?:float,'strike_price.lt'?:float,
     *              expiration_date?:string,'expiration_date.gte'?:string,'expiration_date.gt'?:string,
     *              'expiration_date.lte'?:string,'expiration_date.lt'?:string} $filters
     *
     * @return array{request_id:?string,results:array<int,array<string,mixed>>}
     */
    public function massiveOptionChainSnapshot(string $underlying, array $filters = []): array
    {
        if (!$this->hasMassiveKey()) {
            Log::error('Massive.missingKey', ['env' => 'MASSIVE_API_KEY']);
            return ['request_id' => null, 'results' => []];
        }

        $u = strtoupper($underlying);
        $base = $this->massiveBase();
        $url  = "{$base}/v3/snapshot/options/{$u}";

        // Respect docs: limit default 10, max 250; we’ll default to 250 if not provided
        if (!isset($filters['limit'])) {
            $filters['limit'] = 250;
        }
        if (!isset($filters['sort'])) {
            $filters['sort'] = 'strike_price';
        }
        if (!isset($filters['order'])) {
            $filters['order'] = 'asc';
        }

        $headers = $this->massiveHeaders();

        $all   = [];
        $rid   = null;
        $page  = 0;
        $limitPages = 100;

        while ($url && $page < $limitPages) {
            $page++;

            // First request includes filters; next pages follow next_url (already contains query)
            $json = $this->safeGet($url, $headers, $page === 1 ? $filters : []);
            if (!$json) break;

            $results = $json['results'] ?? [];
            if (is_array($results) && count($results)) {
                $all = array_merge($all, $results);
            }

            if (!$rid && isset($json['request_id'])) {
                $rid = $json['request_id'];
            }

            $next = $json['next_url'] ?? null;
            if ($next && !Str::startsWith($next, 'http')) {
                $next = $base . $next;
            }
            $url = $next;

            Log::debug('Massive.chain.page', [
                'underlying' => $u,
                'page'       => $page,
                'got'        => is_array($results) ? count($results) : 0,
                'has_next'   => (bool)$url,
            ]);

            if (!is_array($results) || count($results) === 0) {
                break;
            }
        }

        Log::info('Massive.chain.complete', [
            'underlying' => $u,
            'pages'      => $page,
            'contracts'  => count($all),
            'request_id' => $rid,
        ]);

        return ['request_id' => $rid, 'results' => $all];
    }

    /**
     * Massive: Option Contract Snapshot
     * GET /v3/snapshot/options/{underlyingAsset}/{optionContract}
     *
     * @return array<string,mixed>|null
     */
    public function massiveOptionContractSnapshot(string $underlying, string $optionContract): ?array
    {
        if (!$this->hasMassiveKey()) {
            Log::error('Massive.missingKey', ['env' => 'MASSIVE_API_KEY']);
            return null;
        }
        $u = strtoupper($underlying);
        $c = strtoupper($optionContract);
        $url = $this->massiveBase() . "/v3/snapshot/options/{$u}/{$c}";
        return $this->safeGet($url, $this->massiveHeaders());
    }

    /**
     * Massive: Unified Snapshot (multi-asset)
     * GET /v3/snapshot
     *
     * $params accepts:
     *  - type (stocks|options|fx|crypto|indices)
     *  - ticker|ticker.any_of (<=250 comma-separated)
     *  - order, sort, limit (<=250)
     */
    public function massiveUnifiedSnapshot(array $params = []): ?array
    {
        if (!$this->hasMassiveKey()) {
            Log::error('Massive.missingKey', ['env' => 'MASSIVE_API_KEY']);
            return null;
        }
        $url = $this->massiveBase() . '/v3/snapshot';

        // Default: try to be generous but within limits
        if (!isset($params['limit'])) $params['limit'] = 250;

        return $this->safeGet($url, $this->massiveHeaders(), $params);
    }

    /** ====== PUBLIC: INTRADAY OPTION VOLUMES/NOTIONAL (from Massive chain snapshot) ====== */

    /**
     * Aggregate intraday option volumes/premium by (strike, expiration) using Massive chain snapshot.
     *
     * @return array{
     *   asof:string,
     *   totals:array{call_vol:int,put_vol:int,premium:float},
     *   by_strike:array<int,array{
     *     strike:float,exp_date:string,
     *     call_vol:int,put_vol:int,
     *     call_prem:float,put_prem:float
     *   }>,
     *   contracts?:array<int,array<string,mixed>>,
     *   request_id?:?string
     * }
     */
    public function intradayOptionVolumes(string $symbol): array
    {
        $uSym = strtoupper($symbol);
        Log::debug('IntradayVolumes.start', ['symbol' => $uSym]);

        $snap = $this->massiveOptionChainSnapshot($uSym, [
            // Keep filters permissive so we see the full board and can bucket properly.
            'limit' => 250,
            'sort'  => 'strike_price',
            'order' => 'asc',
        ]);

        $results = $snap['results'] ?? [];
        $rid     = $snap['request_id'] ?? null;

        if (!is_array($results) || count($results) === 0) {
            $blank = $this->blankPayload();
            $blank['contracts']  = [];
            $blank['request_id'] = $rid;
            Log::warning('IntradayVolumes.emptyResults', ['symbol' => $uSym, 'request_id' => $rid]);
            return $blank;
        }

        $agg = $this->reduceContractsToBuckets($results, $rid);
        $agg['contracts']  = $results;
        $agg['request_id'] = $rid;

        Log::debug('IntradayVolumes.done', [
            'symbol'     => $uSym,
            'asof'       => $agg['asof'] ?? null,
            'buckets'    => isset($agg['by_strike']) ? count($agg['by_strike']) : 0,
            'contracts'  => count($results),
            'totals'     => $agg['totals'] ?? null,
        ]);

        return $agg;
    }

    /** ====== POLYGON HELPERS (OPTIONAL) ====== */

    /**
     * Polygon minute aggregates helper (stocks/indices/ETFs mostly).
     * Example: /v2/aggs/ticker/SPY/range/1/minute/2025-11-01/2025-11-11?adjusted=true&sort=asc&limit=50000
     *
     * @return array<string,mixed>|null
     */
    public function polygonMinuteAggs(string $ticker, int $multiplier, string $timespan, string $from, string $to, array $params = []): ?array
    {
        $apiKey = $this->polygonKey();
        if (!$apiKey) {
            Log::warning('Polygon.missingKey', ['env' => 'POLYGON_API_KEY']);
            return null;
        }

        $base = $this->polygonBase();
        $url  = "{$base}/v2/aggs/ticker/".strtoupper($ticker)."/range/{$multiplier}/{$timespan}/{$from}/{$to}";
        $params = array_merge(['apiKey' => $apiKey, 'sort' => 'asc', 'limit' => 50000, 'adjusted' => 'true'], $params);

        return $this->safeGet($url, $this->polygonHeaders(), $params, maxRetries: 4, timeout: 45);
    }

    /** ====== PRIVATE: REDUCTION + STABLE SHAPES ====== */

    /**
     * @param array<int,array<string,mixed>> $contracts
     * @return array{
     *   asof:string,
     *   totals:array{call_vol:int,put_vol:int,premium:float},
     *   by_strike:array<int,array{
     *     strike:float,exp_date:string,
     *     call_vol:int,put_vol:int,
     *     call_prem:float,put_prem:float
     *   }>
     * }
     */
    private function reduceContractsToBuckets(array $contracts, ?string $requestId): array
    {
        $by = [];
        $totCall = 0; $totPut = 0; $totPrem = 0.0;
        $seen=0; $used=0; $skipped=0;

        foreach ($contracts as $c) {
            $seen++;

            $details = $c['details']     ?? [];
            $day     = $c['day']         ?? [];
            $quote   = $c['last_quote']  ?? [];
            $trade   = $c['last_trade']  ?? [];

            $side   = strtolower($details['contract_type'] ?? '');
            $strike = (float)($details['strike_price'] ?? 0);
            $expiry = substr($details['expiration_date'] ?? '', 0, 10);

            // Intraday volume
            $vol = (int)($day['volume'] ?? 0);
            if ($vol <= 0) { $skipped++; continue; }

            // Price midpoint preference: quote.midpoint -> trade.price -> bid/ask -> 0
            $mid = $quote['midpoint'] ?? ($trade['price'] ?? null);
            if ($mid === null) {
                $bid = (float)($quote['bid'] ?? 0);
                $ask = (float)($quote['ask'] ?? 0);
                $mid = ($bid > 0 && $ask > 0) ? ($bid + $ask) / 2 : ($bid ?: $ask ?: 0);
            }
            $px = max(0.0, (float)$mid);

            if ($strike <= 0 || !$expiry || !$side || $px <= 0) { $skipped++; continue; }

            $notional = $px * $vol * 100;

            $key = "{$strike}|{$expiry}";
            if (!isset($by[$key])) {
                $by[$key] = [
                    'strike'    => $strike,
                    'exp_date'  => $expiry,
                    'call_vol'  => 0,    'put_vol'  => 0,
                    'call_prem' => 0.0,  'put_prem' => 0.0,
                ];
            }

            if ($side === 'call') {
                $by[$key]['call_vol']  += $vol;
                $by[$key]['call_prem'] += $notional;
                $totCall += $vol;
            } else { // 'put'
                $by[$key]['put_vol']  += $vol;
                $by[$key]['put_prem'] += $notional;
                $totPut += $vol;
            }

            $totPrem += $notional;
            $used++;
        }

        $clean = array_values($by);
        usort($clean, fn($a, $b) => $a['strike'] <=> $b['strike'] ?: strcmp($a['exp_date'], $b['exp_date']));
        array_walk($clean, function (&$b) {
            $b['call_prem'] = round($b['call_prem'], 2);
            $b['put_prem']  = round($b['put_prem'],  2);
        });

        Log::debug('IntradayVolumes.reduction', [
            'request_id'   => $requestId,
            'seen'         => $seen,
            'used'         => $used,
            'skipped'      => $skipped,
            'buckets'      => count($clean),
            'tot_call_vol' => $totCall,
            'tot_put_vol'  => $totPut,
            'tot_premium'  => round($totPrem, 2),
        ]);

        return [
            'asof'   => now('America/New_York')->subMinutes(1)->toIso8601String(),
            'totals' => [
                'call_vol' => $totCall,
                'put_vol'  => $totPut,
                'premium'  => round($totPrem, 2),
            ],
            'by_strike' => $clean,
        ];
    }

    private function blankPayload(): array
    {
        return [
            'asof'   => now('America/New_York')->toIso8601String(),
            'totals' => ['call_vol' => 0, 'put_vol' => 0, 'premium' => 0.0],
            'by_strike' => [],
        ];
    }
}
