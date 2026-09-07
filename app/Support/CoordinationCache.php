<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/** Keep shared nonpayload state on its original namespace during cache cutover. */
final class CoordinationCache
{
    public const STORE = 'coordination';

    public static function store(): Factory|Repository
    {
        // The facade root preserves legacy driver resolution and method mocks.
        // Enabled mode never falls back to the disposable payload cache.
        return config('cache.coordination_enabled', false)
            ? Cache::store(self::STORE)
            : Cache::getFacadeRoot();
    }
}
