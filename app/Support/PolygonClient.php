<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PolygonClient // keeping the name so other code doesn't break
{
    public function intradayOptionVolumes(string $symbol): ?array
    {
        $uSym = strtoupper($symbol);

        Log::debug('intradayOptionVolumes.start', [
            'symbol' => $uSym,
        ]);

        $snap = $this->snapshotChainFromPolygon($uSym);

        Log::debug('intradayOptionVolumes.afterSnapshot', [
            'symbol'         => $uSym,
            'snap_is_null'   => $snap === null,
            'result_count'   => is_array($snap['results'] ?? null) ? count($snap['results']) : null,
            'keys_in_snap'   => is_array($snap) ? array_keys($snap) : null,
            'snap_sample'   => $snap,
        ]);

        if (
            !$snap ||
            empty($snap['results']) ||
            !is_array($snap['results'])
        ) {
            Log::warning('intradayOptionVolumes.blankPayloadFallback', [
                'symbol' => $uSym,
                'snap'   => $snap,
            ]);

            return $this->blankPayload();
        }

        $rolled = $this->fromRawContracts($snap['results']);

        Log::debug('intradayOptionVolumes.rolled', [
            'symbol'       => $uSym,
            'totals'       => $rolled['totals'] ?? null,
            'bucket_count' => isset($rolled['by_strike']) ? count($rolled['by_strike']) : null,
            'first_bucket' => $rolled['by_strike'][0] ?? null,
        ]);

        return $rolled;
    }

    /**
     * Take raw per-contract snapshots (the shape Polygon returns)
     * and roll them up by (strike, expiration_date).
     */
    protected function fromRawContracts(array $contracts): array
    {
        Log::debug('fromRawContracts.start', [
            'contracts_count' => count($contracts),
        ]);

        $byStrikeExp = [];
        $totCall = 0;
        $totPut = 0;
        $totPrem = 0.0;

        foreach ($contracts as $c) {
            $side = strtolower($c['details']['contract_type'] ?? '');
            $strike = (float)($c['details']['strike_price'] ?? 0);
            $expiry = substr($c['details']['expiration_date'] ?? '', 0, 10);
            $vol = (int)($c['day']['volume'] ?? $c['session']['volume'] ?? $c['last_trade']['size'] ?? 0);

            $px = (float)($c['last_quote']['midpoint'] ?? (
                ($bid = (float)($c['last_quote']['bid'] ?? 0)) > 0 && ($ask = (float)($c['last_quote']['ask'] ?? 0)) > 0
                    ? ($bid + $ask) / 2.0
                    : ($bid > 0 ? $bid : ($ask > 0 ? $ask : (
                        $c['day']['vwap'] ?? $c['session']['vwap'] ?? $c['day']['close'] ?? $c['last_trade']['price'] ?? 0.0
                    )))
            ));
            $px = max(0.0, $px);

            if ($strike <= 0 || !$expiry || $vol <= 0 || ($side !== 'call' && $side !== 'put')) {
                continue; // Simplified skip
            }

            $notional = $px * $vol * 100.0;

            $key = $strike . '|' . $expiry;
            $bucket = &$byStrikeExp[$key];
            if (!isset($bucket)) {
                $bucket = [
                    'strike'    => $strike,
                    'exp_date'  => $expiry,
                    'call_vol'  => 0,
                    'put_vol'   => 0,
                    'call_prem' => 0.0,
                    'put_prem'  => 0.0,
                ];
            }

            if ($side === 'call') {
                $bucket['call_vol'] += $vol;
                $bucket['call_prem'] += $notional;
                $totCall += $vol;
            } else {
                $bucket['put_vol'] += $vol;
                $bucket['put_prem'] += $notional;
                $totPut += $vol;
            }
            $totPrem += $notional;
        }

        // Flatten, sort, round
        $clean = array_values($byStrikeExp);
        usort($clean, fn($a, $b) => $a['strike'] <=> $b['strike'] ?: strcmp($a['exp_date'], $b['exp_date']));
        array_walk($clean, fn(&$b) => $b['call_prem'] = round($b['call_prem'], 2) && $b['put_prem'] = round($b['put_prem'], 2));

        Log::debug('fromRawContracts.done', [
            'bucket_count' => count($clean),
            'totCall'      => $totCall,
            'totPut'       => $totPut,
            'totPrem'      => $totPrem,
        ]);

        return [
            'asof'      => now('America/New_York')->subMinutes(15)->toIso8601String(),
            'totals'    => ['call_vol' => (int)$totCall, 'put_vol' => (int)$totPut, 'premium' => round($totPrem, 2)],
            'by_strike' => $clean,
        ];
    }

    protected function blankPayload(): array
    {
        return [
            'asof'   => now('America/New_York')->subMinutes(15)->toIso8601String(),
            'totals' => [
                'call_vol' => 0,
                'put_vol'  => 0,
                'premium'  => 0.0,
            ],
            'by_strike' => [],
        ];
    }

    /**
     * Pull ALL pages from Polygon /v3/snapshot/options/{underlyingAsset}
     * and merge them.
     */
    private function snapshotChainFromPolygon(string $underlying): ?array
    {
        $apiKey  = env('POLYGON_API_KEY');
        $baseUrl = rtrim(env('POLYGON_BASE', 'https://api.polygon.io'), '/');

        if (!$apiKey) {
            Log::warning('snapshotChainFromPolygon.missingApiKey');
            return null;
        }

        $url            = "{$baseUrl}/v3/snapshot/options/{$underlying}";
        $allResults     = [];
        $requestId      = null;
        $status         = null;
        $pageSafety     = 0;
        $MAX_CONTRACTS  = 5000; // Increased for larger chains if needed
        $LIMIT_PER_PAGE = 1000; // Polygon max is 1000

        Log::debug('snapshotChainFromPolygon.start', [
            'underlying' => $underlying,
            'url'        => $url,
        ]);

        while ($url && $pageSafety < 20 && count($allResults) < $MAX_CONTRACTS) { // Increased safety
            $pageSafety++;

            if (!Str::startsWith($url, 'http')) {
                $url = $baseUrl . $url;
            }

            Log::debug('snapshotChainFromPolygon.request', [
                'pageSafety' => $pageSafety,
                'final_url'  => $url,
            ]);

            $resp = Http::get($url, [
                'order'  => 'asc',
                'sort'   => 'ticker',
                'limit'  => $LIMIT_PER_PAGE,
                'apiKey' => $apiKey,
            ]);

            Log::debug('snapshotChainFromPolygon.responseMeta', [
                'status' => $resp->status(),
                'ok'     => $resp->ok(),
            ]);

            if (!$resp->ok()) {
                Log::warning('snapshotChainFromPolygon.httpFail', [
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ]);
                break;
            }

            $json = $resp->json();

            Log::debug('snapshotChainFromPolygon.pageJsonMeta', [
                'is_array'        => is_array($json),
                'keys'            => is_array($json) ? array_keys($json) : null,
                'results_count'   => is_array($json['results'] ?? null) ? count($json['results']) : null,
                'has_next_url'    => isset($json['next_url']),
                'sample_result_0' => $json['results'][0] ?? null,
            ]);

            if (!is_array($json)) {
                Log::warning('snapshotChainFromPolygon.badJson', ['json' => $json]);
                break;
            }

            $results = $json['results'] ?? [];
            if (!is_array($results) || empty($results)) {
                Log::debug('snapshotChainFromPolygon.noMoreResults');
                break;
            }

            $allResults = array_merge($allResults, $results);
            $requestId  = $json['request_id'] ?? $requestId;
            $status     = $json['status']     ?? $status;

            $url        = $json['next_url'] ?? null;
        }

        Log::debug('snapshotChainFromPolygon.done', [
            'underlying'         => $underlying,
            'total_results'      => count($allResults),
            'request_id'         => $requestId,
            'status'             => $status,
            'first_contract'     => $allResults[0] ?? null,
            'last_contract'      => $allResults[count($allResults)-1] ?? null,
        ]);

        return [
            'request_id' => $requestId,
            'status'     => $status,
            'results'    => $allResults,
        ];
    }
}