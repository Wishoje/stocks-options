<?php
// app/Support/Prices.php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class Prices
{
    /**
     * Get daily OHLC for one date (EOD) with provider failover.
     * Returns: ['date'=>'YYYY-MM-DD','open'=>..., 'high'=>..., 'low'=>..., 'close'=>..., 'volume'=>...] or null.
     */
    public static function daily(string $symbol, string $date): ?array
    {
        $symbol = \App\Support\Symbols::canon($symbol);
        $date   = Carbon::parse($date)->toDateString();

        // If you only have Options Starter, skip Polygon for equity OHLC entirely:
        $usePolygonEquities = (bool) env('POLYGON_EQUITIES_ENABLED', false);

        if ($usePolygonEquities) {
            if ($out = self::polygonDaily($symbol, $date)) return $out;
        }

        // (optional) keep Finnhub if you still want it as a second provider
        $denyKey = "px:finnhub:deny:{$symbol}";
        if (!Cache::get($denyKey)) {
            $out = self::finnhubDaily($symbol, $date, $denyKey);
            if ($out) return $out;
        }

        // Yahoo fallback
        return self::yahooDaily($symbol, $date);
}


    protected static function polygonDaily(string $symbol, string $date): ?array
    {
        $apiKey = env('POLYGON_API_KEY');
        if (!$apiKey) return null;

        // bail early if we previously saw NOT_AUTHORIZED
        if (Cache::get("px:polygon:deny:{$symbol}")) return null;

        $url = "https://api.polygon.io/v2/aggs/ticker/{$symbol}/range/1/day/{$date}/{$date}";
        $resp = Http::retry(1, 200, throw:false)
            ->timeout(15)->connectTimeout(10)
            ->get($url, ['adjusted'=>true, 'sort'=>'asc', 'apiKey'=>$apiKey]);

        if ($resp->status() === 403) {
            Cache::put("px:polygon:deny:{$symbol}", 1, now()->addDay());
            Log::notice("Polygon equities denied {$symbol} {$date} (cached 24h)");
            return null;
        }

        if ($resp->failed()) {
            Log::warning("Polygon daily fail {$symbol} {$date}: {$resp->status()} ".$resp->body());
            return null;
        }

        $j = $resp->json();
        $r = $j['results'][0] ?? null;
        if (!$r) return null;

        return [
            'date'   => $date,
            'open'   => $r['o'] ?? null,
            'high'   => $r['h'] ?? null,
            'low'    => $r['l'] ?? null,
            'close'  => $r['c'] ?? null,
            'volume' => $r['v'] ?? null,
        ];
    }


    protected static function finnhubDaily(string $symbol, string $date, string $denyKey): ?array
    {
        $token = env('FINNHUB_API_KEY');
        if (!$token) return null;

        // /stock/candle expects UNIX seconds for 'from'/'to'
        $from = Carbon::parse($date, 'America/New_York')->startOfDay()->timestamp;
        $to   = Carbon::parse($date, 'America/New_York')->endOfDay()->timestamp;

        $resp = Http::retry(0, 0, throw:false) // do NOT retry 403s
            ->timeout(15)->connectTimeout(10)
            ->get('https://finnhub.io/api/v1/stock/candle', [
                'symbol' => $symbol, 'resolution'=>'D', 'from'=>$from, 'to'=>$to, 'token'=>$token
            ]);

        if ($resp->status() === 403) {
            // Cache the deny for 24h to avoid repeated 403 spam.
            Cache::put($denyKey, 1, now()->addDay());
            Log::warning("Finnhub candle deny {$symbol}: 403 ".$resp->body());
            return null;
        }
        if ($resp->failed()) {
            Log::warning("Finnhub candle fail {$symbol}: {$resp->status()} ".$resp->body());
            return null;
        }

        $j = $resp->json();
        if (($j['s'] ?? '') !== 'ok' || empty($j['c'][0])) return null;

        return [
            'date'   => $date,
            'open'   => $j['o'][0] ?? null,
            'high'   => $j['h'][0] ?? null,
            'low'    => $j['l'][0] ?? null,
            'close'  => $j['c'][0] ?? null,
            'volume' => $j['v'][0] ?? null,
        ];
    }

    protected static function yahooDaily(string $symbol, string $date): ?array
    {
        // Lightweight fallback via chart endpoint (no key). Subject to throttling.
        // Range 1d including target date.
        $period1 = Carbon::parse($date, 'America/New_York')->startOfDay()->timestamp;
        $period2 = Carbon::parse($date, 'America/New_York')->endOfDay()->timestamp;

        $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}";
        $resp = Http::retry(1, 300, throw:false)
            ->timeout(15)->connectTimeout(10)
            ->get($url, ['interval'=>'1d', 'period1'=>$period1, 'period2'=>$period2]);

        if ($resp->failed()) {
            Log::warning("Yahoo daily fail {$symbol} {$date}: {$resp->status()}");
            return null;
        }

        $j = $resp->json();
        $res = $j['chart']['result'][0] ?? null;
        $q   = $res['indicators']['quote'][0] ?? null;
        $c   = $q['close'][0] ?? null;
        if ($c === null) return null;

        return [
            'date'   => $date,
            'open'   => $q['open'][0]  ?? null,
            'high'   => $q['high'][0]  ?? null,
            'low'    => $q['low'][0]   ?? null,
            'close'  => $c,
            'volume' => $q['volume'][0]?? null,
        ];
    }

    protected static function polygonSpot(string $symbol): ?array
    {
        $apiKey = env('POLYGON_API_KEY');
        if (!$apiKey) return null;

        $url = "https://api.polygon.io/v2/snapshot/locale/us/markets/stocks/tickers/{$symbol}";
        $resp = Http::retry(1, 250, throw:false)
            ->timeout(10)->connectTimeout(5)
            ->get($url, ['apiKey'=>$apiKey]);

        if ($resp->failed()) {
            Log::warning("Polygon spot fail {$symbol}: {$resp->status()} ".$resp->body());
            return null;
        }

        $j = $resp->json();
        $t = $j['ticker'] ?? [];

        // 15-minute delayed last trade price
        $p = $t['lastTrade']['p'] ?? ($t['day']['c'] ?? null);
        if (!$p) return null;

        return [
            'date'   => now('America/New_York')->toDateString(),
            'open'   => $t['day']['o'] ?? null,
            'high'   => $t['day']['h'] ?? null,
            'low'    => $t['day']['l'] ?? null,
            'close'  => $p,
            'volume' => $t['day']['v'] ?? null,
        ];
    }

}
