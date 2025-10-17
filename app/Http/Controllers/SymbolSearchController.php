<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class SymbolSearchController extends Controller
{
    public function lookup(Request $req)
    {
        $q = trim((string)$req->query('q',''));
        if ($q === '') {
            return response()->json(['items'=>[]], 200);
        }

        $token =  env('FINNHUB_API_KEY');
        if (!$token) {
            // No token configured — return empty (don’t 500 the UI)
            return response()->json(['items'=>[]], 200);
        }

        // cache small searches briefly to be kind to your rate limit
        $cacheKey = "fh_search:".mb_strtolower($q);
        $items = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($q, $token) {
            $resp = Http::timeout(6)->retry(2, 250)->get(
                'https://finnhub.io/api/v1/search',
                ['q' => $q, 'token' => $token]
            );

            if (!$resp->ok()) return [];

            // Finnhub returns: { count, result: [{ symbol, description, type, primaryExchange, ... }] }
            return collect($resp->json('result', []))
                // filter to US-like equities/ETFs; tweak to your needs
                ->filter(function ($r) {
                    $type = $r['type'] ?? '';
                    return in_array($type, ['Common Stock','Preferred Stock','REIT','ETF','ETP','ADR','Unit','Closed End Fund','Index']);
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
        });

        return response()->json(['items'=>$items], 200);
    }
}
