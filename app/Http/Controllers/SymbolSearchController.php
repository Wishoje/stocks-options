<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class SymbolSearchController extends Controller
{
    public function lookup(Request $req)
    {
        $q = trim((string)$req->query('q', ''));
        if ($q === '') {
            return response()->json(['items' => []], 200);
        }

        $cacheKey = "sym_search:" . mb_strtolower($q);

        $items = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($q) {
            // 1) Try Finnhub first
            $finnhubKey = config('services.finnhub.api_key');
            if ($finnhubKey) {
                $items = $this->searchWithFinnhub($q, $finnhubKey);
                if (!empty($items)) {
                    return $items;
                }
            }

            // 2) Fallback to Massive if Finnhub missing or empty
            return $this->searchWithMassive($q);
        });

        return response()->json(['items' => $items], 200);
    }

    protected function searchWithFinnhub(string $q, string $token): array
    {
        $resp = Http::timeout(6)
            ->retry(2, 250, throw: false)
            ->get('https://finnhub.io/api/v1/search', [
                'q'     => $q,
                'token' => $token,
            ]);

        if (!$resp->ok()) {
            // treat 401/403/429 etc as “no data” so we’ll fall back
            return [];
        }

        return collect($resp->json('result', []))
            ->filter(function ($r) {
                $type = $r['type'] ?? '';
                return in_array($type, [
                    'Common Stock', 'Preferred Stock', 'REIT', 'ETF',
                    'ETP', 'ADR', 'Unit', 'Closed End Fund', 'Index',
                ]);
            })
            ->take(10)
            ->map(function ($r) {
                return [
                    'symbol'   => $r['symbol'] ?? '',
                    'name'     => $r['description'] ?? '',
                    'exchange' => $r['primaryExchange'] ?? ($r['exchange'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    protected function searchWithMassive(string $q): array
    {
        $base   = rtrim(config('services.massive.base', 'https://api.massive.com'), '/');
        $key    = config('services.massive.key');
        $mode   = config('services.massive.mode', 'header'); // header|bearer|query
        $header = config('services.massive.header', 'X-API-Key');
        $qparam = config('services.massive.qparam', 'apiKey');

        if (empty($key)) {
            return [];
        }

        $client = Http::acceptJson()
            ->timeout(10)
            ->retry(2, 250, throw: false);

        if ($mode === 'bearer') {
            $client = $client->withToken($key);
        } elseif ($mode === 'header') {
            $client = $client->withHeaders([$header => $key]);
        }

        $url = "{$base}/v3/reference/tickers";
        $params = [
            'search' => $q,
            'market' => 'stocks',
            'active' => true,
            'limit'  => 10,
        ];

        if ($mode === 'query') {
            $params[$qparam] = $key;
        }

        $resp = $client->get($url, $params);
        if (!$resp->ok()) {
            return [];
        }

        return collect($resp->json('results', []))
            ->filter(fn($r) => ($r['market'] ?? '') === 'stocks')
            ->take(10)
            ->map(function ($r) {
                return [
                    'symbol'   => $r['ticker'] ?? '',
                    'name'     => $r['name'] ?? '',
                    'exchange' => $r['primary_exchange'] ?? null,
                ];
            })
            ->values()
            ->all();
    }
}
