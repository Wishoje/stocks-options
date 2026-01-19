<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PolygonClient
{
    /** Max chars of any HTTP body we log (to avoid noisy logs) */
    private const LOG_BODY_MAX = 600;

    /** Helper: shorten any string for logs */
    private static function clip(?string $s, int $max = self::LOG_BODY_MAX): string
    {
        if ($s === null) return '<null>';
        $s = trim($s);
        return strlen($s) > $max ? substr($s, 0, $max) . '…<clipped>' : $s;
    }

    /** Build a preconfigured HTTP client (auth, UA, sane timeouts/retries). */
   private function http()
    {
        $key  = config('services.massive.key');
        $mode = config('services.massive.mode', 'header');
        $hdr  = config('services.massive.header', 'X-API-Key');

        if (empty($key)) {
            Log::error('PolygonClient.auth.missingKey', ['has_key' => false]);
            // Throwing makes the caller hit the noData path cleanly.
            throw new \RuntimeException('Massive API key missing');
        }

        $client = Http::withHeaders([
                'Accept'     => 'application/json',
                'User-Agent' => 'Acceptd-Options/1.0 (+massive-snapshot)',
            ])
            ->timeout(10)
            // Don’t aggressively retry auth failures; keep general retry for others
            ->retry(2, fn () => random_int(250, 600), throw: false);

        if ($mode === 'bearer') {
            $client = $client->withToken($key); // sets Authorization: Bearer <key>
        } elseif ($mode === 'header') {
            $client = $client->withHeaders([$hdr => $key]);
        }
        return $client;
    }

    public function intradayOptionVolumes(string $symbol): ?array
    {
        $uSym = strtoupper($symbol);
        // Log::debug('PolygonClient.intraday.start', ['symbol' => $uSym]);

        $snap = $this->snapshotChainFromMassive($uSym);

        if (!$snap || empty($snap['results'])) {
            Log::warning('PolygonClient.noData', [
                'symbol'   => $uSym,
                'has_snap' => (bool) $snap,
                'keys'     => $snap ? array_keys($snap) : [],
            ]);
            $blank = $this->blankPayload();
            $blank['contracts']  = [];
            $blank['request_id'] = $snap['request_id'] ?? null;
            return $blank;
        }

        $agg = $this->fromRawContracts($snap['results'], $snap['request_id'] ?? null);

        // carry through raw results for per-contract ingestion
        $agg['contracts']  = $snap['results'];
        $agg['request_id'] = $snap['request_id'] ?? null;

        // Log::debug('PolygonClient.intraday.done', [
        //     'symbol'     => $uSym,
        //     'asof'       => $agg['asof'] ?? null,
        //     'totals'     => $agg['totals'] ?? null,
        //     'buckets'    => isset($agg['by_strike']) ? count($agg['by_strike']) : 0,
        //     'contracts'  => count($agg['contracts'] ?? []),
        // ]);

        return $agg;
    }

    protected function fromRawContracts(array $contracts, ?string $requestId): array
    {
        $byStrikeExp = [];
        $totCall = 0; $totPut = 0; $totPrem = 0.0;
        $seen = 0; $used = 0; $skipped = 0;

        foreach ($contracts as $c) {
            $seen++;

            $details = (array)($c['details'] ?? []);
            $day     = (array)($c['day'] ?? []);              // Massive sometimes returns []
            $trade   = (array)($c['last_trade'] ?? []);
            $quote   = (array)($c['quote'] ?? $c['last_quote'] ?? []); // support either key

            $sideRaw = strtolower((string)($details['contract_type'] ?? ''));
            // normalize side; skip unknown
            $side = in_array($sideRaw, ['call', 'put'], true) ? $sideRaw : null;

            $strike = (float)($details['strike_price'] ?? 0);
            $expiry = substr((string)($details['expiration_date'] ?? ''), 0, 10);

            // intraday volume (force non-negative int)
            $vol = max(0, (int)($day['volume'] ?? 0));

            // ---- Price selection hierarchy ----
            // Keep earlier pick if we already found a price; do not overwrite later.
            $mid = null;

            // 1) prefer intraday VWAP
            if (isset($day['vwap']) && is_numeric($day['vwap'])) {
                $mid = (float)$day['vwap'];
            }

            // 2) fall back to day.close
            if ($mid === null && isset($day['close']) && is_numeric($day['close'])) {
                $mid = (float)$day['close'];
            }

            // 3) fall back to last trade price
            if ($mid === null && isset($trade['price']) && is_numeric($trade['price'])) {
                $mid = (float)$trade['price'];
            }

            // 4) fall back to quote midpoint, or single-sided quote if needed
            if ($mid === null) {
                $qp = $quote['midpoint'] ?? null;
                if (is_numeric($qp)) {
                    $mid = (float)$qp;
                } else {
                    $bid = (float)($quote['bid'] ?? 0);
                    $ask = (float)($quote['ask'] ?? 0);
                    if ($bid > 0 && $ask > 0) {
                        $mid = ($bid + $ask) / 2;
                    } elseif ($bid > 0 || $ask > 0) {
                        $mid = max($bid, $ask);
                    }
                }
            }

            // if both price and volume missing/useless, skip
            if ($vol <= 0 && $mid === null) { $skipped++; continue; }

            // If we still don't have a valid price, premium will be 0; allow volume counting.
            $px = max(0.0, (float)($mid ?? 0));

            // sanity for required fields
            if ($strike <= 0 || !$expiry || !$side) { $skipped++; continue; }

            $notional = $px * $vol * 100;

            $key = "{$strike}|{$expiry}";
            if (!isset($byStrikeExp[$key])) {
                $byStrikeExp[$key] = [
                    'strike'    => $strike,
                    'exp_date'  => $expiry,
                    'call_vol'  => 0,
                    'put_vol'   => 0,
                    'call_prem' => 0.0,
                    'put_prem'  => 0.0,
                ];
            }

            if ($side === 'call') {
                $byStrikeExp[$key]['call_vol']  += $vol;
                $byStrikeExp[$key]['call_prem'] += $notional;
                $totCall += $vol;
            } else {
                $byStrikeExp[$key]['put_vol']  += $vol;
                $byStrikeExp[$key]['put_prem'] += $notional;
                $totPut += $vol;
            }
            $totPrem += $notional;
            $used++;
        }

        $clean = array_values($byStrikeExp);
        usort(
            $clean,
            fn($a, $b) => $a['strike'] <=> $b['strike'] ?: strcmp($a['exp_date'], $b['exp_date'])
        );

        array_walk($clean, function (&$b) {
            $b['call_prem'] = round($b['call_prem'], 2);
            $b['put_prem']  = round($b['put_prem'], 2);
        });

        // Log::debug('PolygonClient.contracts.reduction', [
        //     'request_id'   => $requestId,
        //     'seen'         => $seen,
        //     'used'         => $used,
        //     'skipped'      => $skipped,
        //     'buckets'      => count($clean),
        //     'tot_call_vol' => $totCall,
        //     'tot_put_vol'  => $totPut,
        //     'tot_prem'     => round($totPrem, 2),
        // ]);

        return [
            'asof' => now('America/New_York')->subMinutes(1)->toIso8601String(),
            'totals' => [
                'call_vol' => $totCall,
                'put_vol'  => $totPut,
                'premium'  => round($totPrem, 2),
            ],
            'by_strike' => $clean,
        ];
    }

    protected function blankPayload(): array
    {
        return [
            'asof'   => null,        // <-- no fake current time
            'totals' => ['call_vol'=>0,'put_vol'=>0,'premium'=>0.0],
            'by_strike' => [],
        ];
    }

    /** Paginate through Massive options snapshot for a symbol. */
   protected function snapshotChainFromMassive(string $symbol): ?array
    {
        $base    = rtrim(config('services.massive.base', 'https://api.massive.com'), '/');
        $mode    = config('services.massive.mode', 'header');
        $qparam  = config('services.massive.qparam', 'apiKey');
        $apiKey  = config('services.massive.key');

        $path    = "/v3/snapshot/options/{$symbol}";
        $cursor  = null;

        $all = ['results' => [], 'status' => null, 'request_id' => null];

        for ($hops = 0; $hops < 25; $hops++) {
            $url = $cursor ?: ($base . $path);

            // attach ?apiKey=... if using query auth
            if (!$cursor && $mode === 'query') {
                $join = str_contains($url, '?') ? '&' : '?';
                $url .= $join . urlencode($qparam) . '=' . urlencode($apiKey);
            }

            $resp = $this->http()->get($url);

            // If unauthorized, don’t keep retrying needlessly
            if ($resp->status() === 401) {
                Log::warning('PolygonClient.auth.unauthorized', [
                    'url' => $url,
                    'hint' => 'Check MASSIVE_AUTH_MODE / header / query param and key value',
                ]);
                return null;
            }

            if ($resp->status() === 429) {
                $retryAfter = (int)($resp->header('Retry-After') ?? 1);
                usleep(max(1, $retryAfter) * 1_000_000);
                $resp = $this->http()->get($url);
            }

            if (!$resp->ok()) {
                Log::warning('PolygonClient.httpError', [
                    'url'  => $url,
                    'code' => $resp->status(),
                    'body' => self::clip($resp->body()),
                ]);
                break;
            }

            $json = $resp->json() ?: [];
            $all['status']     = $json['status']     ?? $all['status'];
            $all['request_id'] = $json['request_id'] ?? $all['request_id'];

            if (!empty($json['results'])) {
                array_push($all['results'], ...$json['results']);
            }

            $cursor = $json['next_url'] ?? null;
            if ($cursor && $mode === 'query') {
                // ensure next_url also carries apiKey when in query mode
                $cursor .= (str_contains($cursor, '?') ? '&' : '?')
                    . urlencode($qparam) . '=' . urlencode($apiKey);
            }

            if (!$cursor) break;
        }

        return $all['results'] ? $all : null;
    }

    public function underlyingQuote(string $symbol): ?array
    {
        $uSym = strtoupper($symbol);
        // Log::debug('PolygonClient.underlying.start', ['symbol' => $uSym]);

        $base    = rtrim(config('services.massive.base', 'https://api.massive.com'), '/');
        $mode    = config('services.massive.mode', 'header');
        $qparam  = config('services.massive.qparam', 'apiKey');
        $apiKey  = config('services.massive.key');

        // Massive v2 single-stock snapshot
        // GET /v2/snapshot/locale/us/markets/stocks/tickers/{stocksTicker}
        $path = "/v2/snapshot/locale/us/markets/stocks/tickers/{$uSym}";
        $url  = $base . $path;

        if ($mode === 'query') {
            $join = str_contains($url, '?') ? '&' : '?';
            $url .= $join . urlencode($qparam) . '=' . urlencode($apiKey);
        }

        $resp = $this->http()->get($url);

        if ($resp->status() === 401) {
            Log::warning('PolygonClient.underlying.unauthorized', ['url' => $url]);
            return null;
        }

        if ($resp->status() === 429) {
            $retryAfter = (int)($resp->header('Retry-After') ?? 1);
            usleep(max(1, $retryAfter) * 1_000_000);
            $resp = $this->http()->get($url);
        }

        if (!$resp->ok()) {
            Log::warning('PolygonClient.underlying.httpError', [
                'url'  => $url,
                'code' => $resp->status(),
                'body' => self::clip($resp->body()),
            ]);
            return null;
        }

        $json = $resp->json() ?: [];

        // Massive v2 snapshot schema:
        // {
        //   "request_id": "...",
        //   "status": "OK",
        //   "ticker": { "day": {...}, "lastQuote": {...}, "lastTrade": {...}, "prevDay": {...}, ... }
        // }
        $results = (array)($json['ticker'] ?? []);

        $day     = (array)($results['day']     ?? []);
        $prevDay = (array)($results['prevDay'] ?? []);
        $last    = (array)($results['lastTrade'] ?? []);
        $quote   = (array)($results['lastQuote'] ?? []);

        $lastPrice = null;

        // Prefer most recent trade
        if (isset($last['p']) && is_numeric($last['p'])) {
            $lastPrice = (float)$last['p'];
        } elseif (isset($day['c']) && is_numeric($day['c'])) {
            // fall back to day close
            $lastPrice = (float)$day['c'];
        } elseif (isset($quote['P']) && is_numeric($quote['P'])) {
            // fall back to ask (or you could average bid/ask)
            $lastPrice = (float)$quote['P'];
        }

        if ($lastPrice === null || $lastPrice <= 0) {
            Log::warning('PolygonClient.underlying.noPrice', [
                'symbol' => $uSym,
                'body'   => self::clip($resp->body(), 200),
            ]);
            return null;
        }

        $prevClose = null;
        if (isset($prevDay['c']) && is_numeric($prevDay['c'])) {
            $prevClose = (float)$prevDay['c'];
        } elseif (isset($day['previous_close']) && is_numeric($day['previous_close'])) {
            $prevClose = (float)$day['previous_close'];
        }

        // Use ticker.updated as asof if present
        $asof = $results['updated'] ?? $results['min']['t'] ?? null;

        // Log::debug('PolygonClient.underlying.done', [
        //     'symbol'     => $uSym,
        //     'last_price' => $lastPrice,
        //     'prev_close' => $prevClose,
        //     'asof'       => $asof,
        // ]);

        return [
            'symbol'     => $uSym,
            'last_price' => $lastPrice,
            'prev_close' => $prevClose,
            'asof'       => $asof,
            'source'     => 'massive-v2-snapshot',
        ];
    }



}
