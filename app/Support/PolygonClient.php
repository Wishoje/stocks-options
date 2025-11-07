<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    public function intradayOptionVolumes(string $symbol): ?array
    {
        $uSym = strtoupper($symbol);
        Log::debug('MassiveClient.intraday.start', ['symbol' => $uSym]);

        $snap = $this->snapshotChainFromMassive($uSym);

        if (!$snap || empty($snap['results'])) {
            Log::warning('MassiveClient.noData', [
                'symbol' => $uSym,
                'has_snap' => (bool) $snap,
                'keys' => $snap ? array_keys($snap) : [],
            ]);
            $blank = $this->blankPayload();
            // include empty contracts + request_id for consistent shape
            $blank['contracts']  = [];
            $blank['request_id'] = $snap['request_id'] ?? null;
            return $blank;
        }

        $agg = $this->fromRawContracts($snap['results'], $snap['request_id'] ?? null);

        // carry through raw results for per-contract ingestion
        $agg['contracts']  = $snap['results'];
        $agg['request_id'] = $snap['request_id'] ?? null;

        Log::debug('MassiveClient.intraday.done', [
            'symbol'  => $uSym,
            'asof'    => $agg['asof'] ?? null,
            'totals'  => $agg['totals'] ?? null,
            'buckets' => isset($agg['by_strike']) ? count($agg['by_strike']) : 0,
            'contracts' => count($agg['contracts'] ?? []),
        ]);

        return $agg;
    }

    protected function fromRawContracts(array $contracts, ?string $requestId): array
    {
        $byStrikeExp = [];
        $totCall = 0; $totPut = 0; $totPrem = 0.0;
        $seen = 0; $used = 0; $skipped = 0;

        foreach ($contracts as $c) {
            $seen++;

            $details = $c['details'] ?? [];
            $day = $c['day'] ?? [];
            $quote = $c['last_quote'] ?? [];

            $side = strtolower($details['contract_type'] ?? '');
            $strike = (float)($details['strike_price'] ?? 0);
            $expiry = substr($details['expiration_date'] ?? '', 0, 10);

            // Volume: intraday cumulative
            $vol = (int)($day['volume'] ?? 0);
            if ($vol <= 0) { $skipped++; continue; }

            // Price: midpoint -> last_trade.price -> bid/ask
            $mid = $quote['midpoint'] ?? null;
            if ($mid === null) {
                $mid = $c['last_trade']['price'] ?? null;
            }
            if ($mid === null) {
                $bid = $quote['bid'] ?? 0;
                $ask = $quote['ask'] ?? 0;
                $mid = ($bid > 0 && $ask > 0) ? ($bid + $ask) / 2 : ($bid ?: $ask ?: 0);
            }
            $px = max(0.0, (float)$mid);

            if ($strike <= 0 || !$expiry || !$side || $px <= 0) { $skipped++; continue; }

            $notional = $px * $vol * 100;

            $key = "{$strike}|{$expiry}";
            if (!isset($byStrikeExp[$key])) {
                $byStrikeExp[$key] = [
                    'strike' => $strike,
                    'exp_date' => $expiry,
                    'call_vol' => 0, 'put_vol' => 0,
                    'call_prem' => 0.0, 'put_prem' => 0.0,
                ];
            }

            if ($side === 'call') {
                $byStrikeExp[$key]['call_vol'] += $vol;
                $byStrikeExp[$key]['call_prem'] += $notional;
                $totCall += (int)$vol;
            } else {
                $byStrikeExp[$key]['put_vol'] += $vol;
                $byStrikeExp[$key]['put_prem'] += $notional;
                $totPut += (int)$vol;
            }
            $totPrem += $notional;
            $used++;
        }

        $clean = array_values($byStrikeExp);
        usort($clean, fn($a, $b) => $a['strike'] <=> $b['strike'] ?: strcmp($a['exp_date'], $b['exp_date']));
        array_walk($clean, function (&$b) {
            $b['call_prem'] = round($b['call_prem'], 2);
            $b['put_prem']  = round($b['put_prem'], 2);
        });

        Log::debug('MassiveClient.contracts.reduction', [
            'request_id' => $requestId,
            'seen' => $seen,
            'used' => $used,
            'skipped' => $skipped,
            'buckets' => count($clean),
            'tot_call_vol' => $totCall,
            'tot_put_vol' => $totPut,
            'tot_prem' => round($totPrem, 2),
        ]);

        return [
            'asof' => now('America/New_York')->subMinutes(1)->toIso8601String(),
            'totals' => [
                'call_vol' => $totCall,
                'put_vol' => $totPut,
                'premium' => round($totPrem, 2),
            ],
            'by_strike' => $clean,
        ];
    }

    protected function blankPayload(): array
    {
        return [
            'asof' => now('America/New_York')->toIso8601String(),
            'totals' => ['call_vol' => 0, 'put_vol' => 0, 'premium' => 0.0],
            'by_strike' => [],
        ];
    }

    private function snapshotChainFromMassive(string $underlying): ?array
    {
        $apiKey  = env('MASSIVE_API_KEY');
        $baseUrl = rtrim(env('MASSIVE_BASE', 'https://api.massive.com'), '/');

        Log::debug('MassiveClient.config', [
            'baseUrl' => $baseUrl,
            'hasKey'  => $apiKey ? true : false,
        ]);

        if (!$apiKey) {
            Log::error('MassiveClient.missingKey', ['env' => 'MASSIVE_API_KEY']);
            return null;
        }

        $initialUrl = "{$baseUrl}/v3/snapshot/options/{$underlying}";
        $allResults = [];
        $requestId  = null;
        $page       = 0;
        $maxPages   = 50;
        $perPage    = 250;

        $url = $initialUrl;

        while ($url && $page < $maxPages) {
            $page++;

            $http = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept'        => 'application/json',
            ])->timeout(30);

            // Only page 1 gets query params
            $params = ($page === 1) ? ['limit' => $perPage, 'sort' => 'strike_price', 'order' => 'asc'] : [];
            Log::debug('MassiveClient.page.request', [
                'symbol' => $underlying,
                'page'   => $page,
                'url'    => $url,
                'params' => $params,
            ]);

            $resp = ($page === 1) ? $http->get($url, $params) : $http->get($url);

            // Fallback if server rejects limit
            if ($page === 1 && $resp->status() === 400 && str_contains($resp->body(), "'Limit' failed")) {
                Log::warning('MassiveClient.limitRejected', [
                    'attempted_limit' => $perPage,
                    'status' => 400,
                    'body' => self::clip($resp->body()),
                ]);
                $perPage = 100; // fallback
                $params  = ['limit' => $perPage, 'sort' => 'strike_price', 'order' => 'asc'];
                $resp    = $http->get($url, $params);
                Log::debug('MassiveClient.limitRetry', ['new_limit' => $perPage, 'status' => $resp->status()]);
            }

            $ok = $resp->ok();
            $status = $resp->status();
            $body = self::clip($resp->body());

            Log::debug('MassiveClient.page.response', [
                'page' => $page,
                'status' => $status,
                'ok' => $ok,
                'bodySnippet' => $body,
            ]);

            if (!$ok) {
                // Special visibility for 429/5xx
                if ($status == 429) {
                    Log::warning('MassiveClient.rateLimited', ['page' => $page, 'body' => $body]);
                } elseif ($status >= 500) {
                    Log::error('MassiveClient.serverError', ['page' => $page, 'status' => $status, 'body' => $body]);
                } else {
                    Log::warning('MassiveClient.pageFailed', ['page' => $page, 'status' => $status, 'body' => $body]);
                }
                break;
            }

            $json = $resp->json();
            $results = $json['results'] ?? [];
            $count = is_array($results) ? count($results) : 0;

            Log::debug('MassiveClient.page.results', [
                'page' => $page,
                'count' => $count,
                'has_next_url' => !empty($json['next_url']),
                'request_id' => $json['request_id'] ?? null,
            ]);

            if ($count === 0) {
                Log::info('MassiveClient.noMoreResults', ['symbol' => $underlying, 'page' => $page]);
                break;
            }

            $allResults = array_merge($allResults, $results);
            $requestId  = $json['request_id'] ?? $requestId;

            // follow next_url as-is, normalize to absolute if necessary
            $url = $json['next_url'] ?? null;
            if ($url && !Str::startsWith($url, 'http')) {
                $url = $baseUrl . $url;
                Log::debug('MassiveClient.nextUrl.normalized', ['page' => $page, 'url' => $url]);
            }
        }

        Log::info('MassiveClient.fetchComplete', [
            'symbol'     => $underlying,
            'pages'      => $page,
            'contracts'  => count($allResults),
            'request_id' => $requestId,
        ]);

        return [
            'request_id' => $requestId,
            'results'    => $allResults,
        ];
    }
}
