<?php

namespace Tests\Feature;

use App\Exceptions\ProviderConcurrencyUnavailable;
use App\Support\ProviderConcurrencyLimiter;
use App\Support\QueueLanes;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProviderConcurrencyLimiterRedisTest extends TestCase
{
    private string $keyPrefix;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis')) {
            $this->redisUnavailable('The phpredis extension is required.');
        }

        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            $this->redisUnavailable('A disposable Redis connection is required.');
        }

        $this->keyPrefix = 'test:provider-concurrency:'.Str::uuid();
        config()->set('services.massive.concurrency', [
            'enabled' => true,
            'connection' => 'default',
            'key' => $this->keyPrefix,
            'limit' => 2,
            'release_after' => 5,
            'block_for' => 5,
            'sleep_milliseconds' => 1,
            'metrics_ttl' => 60,
        ]);
    }

    public function test_each_priority_class_leaves_capacity_for_the_other(): void
    {
        $limiter = app(ProviderConcurrencyLimiter::class);

        $result = $limiter->withPriority(
            QueueLanes::PRIORITY_BACKGROUND,
            fn () => $limiter->massive(function () use ($limiter): string {
                $startedAt = microtime(true);
                try {
                    $limiter->withPriority(
                        QueueLanes::PRIORITY_BACKGROUND,
                        fn () => $limiter->massive(fn (): string => 'unexpected'),
                        blockForSeconds: 0
                    );
                    $this->fail('Background work must not occupy the reserved class capacity.');
                } catch (ProviderConcurrencyUnavailable) {
                    // Expected: limit=2 gives each class at most one slot.
                }
                $this->assertLessThan(1.0, microtime(true) - $startedAt);

                return $limiter->withPriority(
                    QueueLanes::PRIORITY_INTERACTIVE,
                    fn () => $limiter->massive(fn (): string => 'interactive-progress')
                );
            })
        );

        $this->assertSame('interactive-progress', $result);

        $metrics = Redis::connection()->hgetall(
            $this->keyPrefix.':metrics:'.gmdate('Y-m-d')
        );
        $this->assertSame(1, (int) ($metrics['background:acquired'] ?? 0));
        $this->assertSame(1, (int) ($metrics['background:acquire_timeout'] ?? 0));
        $this->assertSame(1, (int) ($metrics['interactive:acquired'] ?? 0));
    }

    public function test_permits_are_released_when_provider_code_throws(): void
    {
        $limiter = app(ProviderConcurrencyLimiter::class);

        try {
            $limiter->massive(fn () => throw new RuntimeException('simulated provider failure'));
            $this->fail('The simulated provider call must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated provider failure', $exception->getMessage());
        }

        $this->assertSame('reacquired', $limiter->massive(fn (): string => 'reacquired'));

        $metrics = Redis::connection()->hgetall(
            $this->keyPrefix.':metrics:'.gmdate('Y-m-d')
        );
        $this->assertSame(2, (int) ($metrics['background:acquired'] ?? 0));
        $this->assertSame(1, (int) ($metrics['background:provider_exception'] ?? 0));
        $this->assertSame(1, (int) ($metrics['background:completed'] ?? 0));
    }

    private function redisUnavailable(string $message): void
    {
        if (filter_var(getenv('CI') ?: false, FILTER_VALIDATE_BOOL)) {
            $this->fail($message.' Redis integration tests may not skip in CI.');
        }

        $this->markTestSkipped($message);
    }

    protected function tearDown(): void
    {
        if (isset($this->keyPrefix) && extension_loaded('redis')) {
            try {
                $connection = Redis::connection();
                $connection->del(
                    $this->keyPrefix.':class:background:1',
                    $this->keyPrefix.':class:interactive:1',
                    $this->keyPrefix.':metrics:'.gmdate('Y-m-d')
                );
            } catch (\Throwable) {
                // The disposable Redis service may already be unavailable.
            }
        }

        parent::tearDown();
    }
}
