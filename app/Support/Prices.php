<?php

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

        // 1) Massive
        if ($out = self::massiveDaily($symbol, $date)) {
            return $out;
        }

        // 2) Finnhub (skip if rate-limited/denied)
        $denyKey = "px:finnhub:deny:{$symbol}";
        if (!Cache::get($denyKey)) {
            if ($out = self::finnhubDaily($symbol, $date, $denyKey)) {
                return $out;
            }
        }

        // 4) Yahoo
        return self::yahooDaily($symbol, $date);
    }


    protected static function massiveDaily(string $symbol, string $date): ?array
    {
        $base   = rtrim(config('services.massive.base', 'https://api.massive.com'), '/');
        $key    = config('services.massive.key');
        $mode   = config('services.massive.mode', 'header'); // header|bearer|query
        $header = config('services.massive.header', 'X-API-Key');
        $qparam = config('services.massive.qparam', 'apiKey');

        if (empty($key)) {
            return null;
        }

        $client = Http::acceptJson()
            ->timeout(10)
            ->retry(2, 250, throw: false);

        if ($mode === 'bearer') {
            $client = $client->withToken($key);
        } elseif ($mode === 'header') {
            $client = $client->withHeaders([$header => $key]);
        }

        $url = "{$base}/v1/open-close/{$symbol}/{$date}";
        $params = ['adjusted' => true];

        if ($mode === 'query') {
            $params[$qparam] = $key;
        }

        $resp = $client->get($url, $params);
        if ($resp->failed()) {
            Log::warning("Massive open-close fail {$symbol} {$date}: {$resp->status()} ".$resp->body());
            return null;
        }

        $j = $resp->json();
        $close = $j['close'] ?? null;
        if ($close === null || $close <= 0) {
            return null;
        }

        return [
            'date'   => $j['from'] ?? $date,
            'open'   => $j['open']   ?? null,
            'high'   => $j['high']   ?? null,
            'low'    => $j['low']    ?? null,
            'close'  => $close,
            'volume' => $j['volume'] ?? null,
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
}