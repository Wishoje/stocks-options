<?php

namespace Tests\Support;

use App\Support\ProviderConcurrencyLimiter;
use App\Support\ProviderRequestReplay;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

final class ProviderRedisTestEnvironment
{
    public static function configure($app, string $prefix, int $limit = 6, int $lease = 2): array
    {
        $host = getenv('TEST_REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_REDIS_PORT') ?: 16379);
        if (! in_array($host, ['127.0.0.1', 'localhost'], true)
            || $port < 1024 || in_array($port, [6379, 6380], true)
            || ! preg_match('/^test:gex021:[a-f0-9-]+$/D', $prefix)) {
            throw new RuntimeException('Provider tests require a disposable loopback Redis port and test prefix.');
        }
        $manager = new RedisManager($app, 'phpredis', [
            'options' => ['prefix' => ''],
            'default' => [
                'host' => $host, 'port' => $port, 'database' => 0,
                'password' => null, 'timeout' => 1, 'read_timeout' => 1,
            ],
        ]);
        $app->instance('redis', $manager);
        $app->instance(Factory::class, $manager);
        Redis::swap($manager);
        $app['config']->set('services.massive.concurrency', [
            'enabled' => true, 'connection' => 'default', 'key' => $prefix,
            'limit' => $limit, 'release_after' => $lease, 'metrics_ttl' => 172800,
        ]);
        $app['config']->set('provider_backpressure', [
            'enabled' => true, 'backoff_seconds' => [15, 60, 180],
            'jitter_min_seconds' => 0, 'jitter_max_seconds' => 0,
            'rate' => ['requests' => null, 'window_seconds' => 60],
            'replay' => ['enabled' => true, 'prefix' => $prefix.':replay', 'ttl_seconds' => 1200],
        ]);
        $replay = new ProviderRequestReplay($app['config'], $manager);
        $app->instance(ProviderRequestReplay::class, $replay);
        $app->instance(ProviderConcurrencyLimiter::class, new ProviderConcurrencyLimiter);

        return [$manager, $manager->connection(), $replay, $app->make(ProviderConcurrencyLimiter::class)];
    }
}
