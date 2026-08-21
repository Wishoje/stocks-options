<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

final class IntradayCompositeCache
{
    public const STALE_SECONDS = 604800;

    public static function key(string $symbol, string $tradeDate): string
    {
        return "intraday:strikesComposite:{$symbol}:{$tradeDate}";
    }

    public static function createdKey(string $cacheKey): string
    {
        return "illuminate:cache:flexible:created:{$cacheKey}";
    }

    public static function promoteLegacy(string $cacheKey, int $staleSeconds): void
    {
        $createdKey = self::createdKey($cacheKey);
        if (Cache::has($createdKey)) {
            return;
        }

        $cached = Cache::get($cacheKey);
        if ($cached === null) {
            return;
        }

        Cache::put($cacheKey, $cached, $staleSeconds);
        Cache::add($createdKey, now()->getTimestamp(), $staleSeconds);
    }

    public static function forgetUnready(string $cacheKey): void
    {
        Cache::forget($cacheKey);
        Cache::forget(self::createdKey($cacheKey));
    }

    public static function markPublished(string $symbol, string $tradeDate): void
    {
        $cacheKey = self::key($symbol, $tradeDate);
        Cache::forget('intraday:repricedGex:'.$symbol.':'.$tradeDate);

        // Keep the last complete payload available to users. Moving only the
        // freshness marker back makes the next request serve it immediately
        // while one lock-protected deferred refresh rebuilds the cache.
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Cache::put($cacheKey, $cached, self::STALE_SECONDS);
            Cache::put(self::createdKey($cacheKey), 0, self::STALE_SECONDS);
        }

        Cache::forget("intraday:resolvedTradeDate:{$symbol}:{$tradeDate}");
    }
}
