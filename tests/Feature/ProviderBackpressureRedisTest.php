<?php

namespace Tests\Feature;

use App\Exceptions\ProviderDeferred;
use App\Support\ProviderConcurrencyLimiter;
use App\Support\ProviderRequestReplay;
use App\Support\QueueLanes;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\ProviderRedisTestEnvironment;
use Tests\TestCase;

class ProviderBackpressureRedisTest extends TestCase
{
    private string $prefix;

    private $manager;

    private $redis;

    private ProviderRequestReplay $replay;

    private ProviderConcurrencyLimiter $limiter;

    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'test:gex021:'.Str::uuid();
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis is required for the disposable Redis suite.');
        }
        try {
            [$this->manager, $this->redis, $this->replay, $this->limiter] =
                ProviderRedisTestEnvironment::configure($this->app, $this->prefix);
            $this->redis->ping();
        } catch (\Throwable $exception) {
            if (filter_var(getenv('CI') ?: false, FILTER_VALIDATE_BOOL)) {
                $this->fail('A disposable loopback Redis service is required in CI.');
            }
            $this->markTestSkipped('Start disposable Redis on TEST_REDIS_PORT (default16379).');
        }
    }

    public function test_successful_pages_replay_before_cooldown_and_late_failure_retries_only_the_failed_page(): void
    {
        $physical = 0;
        $ready = false;
        $first = '{"status":"OK","results":[{"strike":100,"volume":42}]}';
        $second = '{"status":"OK","results":[{"strike":101,"volume":51}]}';
        $run = function () use (&$physical, &$ready, $first, $second): array {
            $rows = [];
            foreach (['page-one' => $first, 'page-two' => $second] as $page => $body) {
                $response = $this->limiter->massive(function () use (&$physical, &$ready, $page, $body): Response {
                    $physical++;

                    return $page === 'page-two' && ! $ready
                        ? $this->response(503, '{"status":"ERROR"}', ['Retry-After' => '120'])
                        : $this->response(200, $body);
                }, requestKey: hash('sha256', $page));
                array_push($rows, ...$response->json('results'));
            }

            return $rows;
        };
        $deadline = $this->replay->withExecution('run:one', function () use ($run) {
            try {
                $run();
                $this->fail('The second page must defer.');
            } catch (ProviderDeferred $exception) {
                $this->assertSame(ProviderDeferred::SERVER_ERROR, $exception->reason);
                $this->assertSame(2, $this->replay->executedRequestCount());

                return $exception->notBefore;
            }
        });
        $this->assertSame(2, $physical);
        $this->replay->withExecution('run:one', function () use ($first): void {
            $response = $this->limiter->massive(
                fn () => throw new RuntimeException('Replay must not require provider capacity.'),
                requestKey: hash('sha256', 'page-one')
            );
            $this->assertSame($first, $response->body());
            $this->assertSame(0, $this->replay->executedRequestCount());
        });
        CarbonImmutable::setTestNow($deadline->addSecond());
        $ready = true;
        $rows = $this->replay->withExecution('run:one', $run);
        $this->assertSame([['strike' => 100, 'volume' => 42], ['strike' => 101, 'volume' => 51]], $rows);
        $this->assertSame(3, $physical);
        $this->assertSame(0, (int) $this->redis->exists($this->prefix.':class:background:1'));
    }

    public function test_concurrent_provider_failures_cannot_shorten_a_shared_retry_after_deadline(): void
    {
        $other = new ProviderConcurrencyLimiter;
        $later = null;
        $result = $this->replay->withExecution('outer', function () use ($other, &$later) {
            try {
                $this->limiter->massive(function () use ($other, &$later): Response {
                    $this->replay->withExecution('inner', function () use ($other, &$later): void {
                        try {
                            $other->massive(fn () => $this->response(429, '{}', ['Retry-After' => '300']));
                        } catch (ProviderDeferred $exception) {
                            $later = $exception->notBefore;
                        }
                    });

                    return $this->response(429, '{}', ['Retry-After' => '60']);
                });
            } catch (ProviderDeferred $exception) {
                return $exception;
            }
        });
        $this->assertSame(ProviderDeferred::RATE_LIMITED, $result->reason);
        $this->assertTrue($result->notBefore->equalTo($later));
        $this->assertSame($later->getTimestamp(), (int) $this->redis->get($this->prefix.':cooldown-until'));
        $this->assertGreaterThan(250, (int) $this->redis->ttl($this->prefix.':cooldown-until'));
    }

    public function test_http_date_retry_after_is_respected_and_repeated_failures_use_the_context_backoff(): void
    {
        $future = CarbonImmutable::now('UTC')->addMinutes(2)->setMicrosecond(0);
        $deadline = $this->replay->withExecution('date-run', function () use ($future) {
            try {
                $this->limiter->massive(fn () => $this->response(429, '{}', [
                    'Retry-After' => $future->format('D, d M Y H:i:s').' GMT',
                ]));
            } catch (ProviderDeferred $exception) {
                return $exception->notBefore;
            }
        });
        $this->assertTrue($deadline->greaterThanOrEqualTo($future));
        CarbonImmutable::setTestNow($deadline->addSecond());
        $secondAttempt = CarbonImmutable::now('UTC');
        $this->replay->withExecution('date-run', function () use ($secondAttempt): void {
            try {
                $this->limiter->massive(fn () => $this->response(500, '{}'));
                $this->fail('A repeated server failure must defer.');
            } catch (ProviderDeferred $exception) {
                $this->assertTrue($exception->notBefore->greaterThanOrEqualTo($secondAttempt->addSeconds(60)));
                $this->assertSame(1, $this->replay->executedRequestCount());
            }
        });
    }

    public function test_timeout_releases_capacity_and_records_one_physical_attempt(): void
    {
        $this->replay->withExecution('timeout-run', function (): void {
            try {
                $this->limiter->massive(fn () => throw new ConnectionException('cURL error 28: timed out'));
                $this->fail('The request must defer.');
            } catch (ProviderDeferred $exception) {
                $this->assertSame(ProviderDeferred::TIMEOUT, $exception->reason);
                $this->assertFalse($exception->isAdmissionDeferral());
                $this->assertSame(1, $this->replay->executedRequestCount());
            }
        });
        $this->assertSame(0, (int) $this->redis->exists($this->prefix.':class:background:1'));
    }

    public function test_optional_sliding_rate_window_reserves_interactive_allowance(): void
    {
        config()->set('provider_backpressure.rate.requests', 2);
        $this->limiter->massive(fn () => 'background');
        $this->replay->withExecution('rate-run', function (): void {
            try {
                $this->limiter->massive(fn () => throw new RuntimeException('The second background HTTP call is forbidden.'));
                $this->fail('The background rate window must be full.');
            } catch (ProviderDeferred $exception) {
                $this->assertSame(ProviderDeferred::RATE_WINDOW, $exception->reason);
                $this->assertTrue($exception->isAdmissionDeferral());
                $this->assertSame(0, $this->replay->executedRequestCount());
            }
        });
        $result = $this->limiter->withPriority(
            QueueLanes::PRIORITY_INTERACTIVE,
            fn () => $this->limiter->massive(fn () => 'interactive')
        );
        $this->assertSame('interactive', $result);
        $this->assertSame(1, (int) $this->redis->zcard($this->prefix.':rate:background'));
        $this->assertSame(1, (int) $this->redis->zcard($this->prefix.':rate:interactive'));
    }

    public function test_replay_ttl_is_not_extended_and_storage_caps_are_atomic(): void
    {
        config()->set('provider_backpressure.replay.ttl_seconds', 10);
        $key = $this->prefix.':replay:execution:'.hash('sha256', 'bounded');
        $this->replay->withExecution('bounded', function () use ($key): void {
            $this->replay->remember(str_repeat('a', 64), $this->response(200, '{"results":[1]}'));
            $this->redis->pexpire($key, 4000);
            $this->replay->remember(str_repeat('b', 64), $this->response(200, '{"results":[2]}'));
            $this->assertGreaterThan(0, (int) $this->redis->pttl($key));
            $this->assertLessThanOrEqual(4000, (int) $this->redis->pttl($key));
            $bytes = (int) $this->redis->hget($key, '__bytes');
            config()->set('provider_backpressure.replay.max_execution_bytes', $bytes + 1);
            $this->replay->remember(str_repeat('c', 64), $this->response(200, '{"results":[3]}'));
            $this->assertNull($this->replay->lookup(str_repeat('c', 64)));
            $this->assertSame($bytes, (int) $this->redis->hget($key, '__bytes'));
            $this->assertSame(2, (int) $this->redis->hget($key, '__pages'));
        });
        $this->replay->forgetExecution('bounded');
        $this->assertSame(0, (int) $this->redis->exists($key));
    }

    public function test_page_count_cap_and_eviction_are_explicit_misses_without_cross_generation_reuse(): void
    {
        config()->set('provider_backpressure.replay.max_pages', 1);
        $this->replay->withExecution('generation:one', function (): void {
            $this->replay->remember(str_repeat('a', 64), $this->response(200, '{"results":[1]}'));
            $this->replay->remember(str_repeat('b', 64), $this->response(200, '{"results":[2]}'));
            $this->assertNotNull($this->replay->lookup(str_repeat('a', 64)));
            $this->assertNull($this->replay->lookup(str_repeat('b', 64)));
        });
        $this->replay->withExecution('generation:two', function (): void {
            $this->assertNull($this->replay->lookup(str_repeat('a', 64)));
        });
        $this->replay->forgetExecution('generation:one');
        $this->replay->withExecution('generation:one', function (): void {
            $this->assertNull($this->replay->lookup(str_repeat('a', 64)));
        });
    }

    public function test_coordination_outage_fails_closed_without_consuming_a_provider_attempt(): void
    {
        Redis::shouldReceive('connection')->andThrow(new RuntimeException('password=secret-sentinel'));
        $this->replay->withExecution('outage', function (): void {
            try {
                $this->limiter->massive(fn () => throw new RuntimeException('No HTTP is permitted.'));
                $this->fail('Admission must fail closed.');
            } catch (ProviderDeferred $exception) {
                $this->assertSame(ProviderDeferred::COORDINATION, $exception->reason);
                $this->assertTrue($exception->isAdmissionDeferral());
                $this->assertSame(0, $this->replay->executedRequestCount());
                $this->assertStringNotContainsString('secret-sentinel', $exception->getMessage());
            }
        });
    }

    public function test_failed_shared_cooldown_write_retains_this_worker_deadline_after_reads_recover(): void
    {
        $broken = new class($this->redis->client(), $this->prefix.':cooldown-until') extends PhpRedisConnection
        {
            public int $failedWrites = 0;

            public function __construct($client, private string $failedKey)
            {
                parent::__construct($client);
            }

            public function eval($script, $numberOfKeys, ...$arguments)
            {
                if (($arguments[0] ?? null) === $this->failedKey) {
                    $this->failedWrites++;
                    throw new RuntimeException('simulated write failure password=secret-sentinel');
                }

                return parent::eval($script, $numberOfKeys, ...$arguments);
            }
        };
        Redis::shouldReceive('connection')->andReturn($broken);
        $this->assertSame($broken, Redis::connection('default'));
        $deadline = $this->replay->withExecution('partial-outage', function () {
            try {
                $this->limiter->massive(fn () => $this->response(429, '{}', ['Retry-After' => '300']));
            } catch (ProviderDeferred $exception) {
                return $exception->notBefore;
            }
        });
        $this->assertSame(1, $broken->failedWrites);
        $this->assertNull($this->redis->get($this->prefix.':cooldown-until'));
        Redis::swap($this->manager);
        $this->replay->withExecution('after-read-recovery', function () use ($deadline): void {
            try {
                $this->limiter->massive(fn () => throw new RuntimeException('Local Retry-After must survive.'));
                $this->fail('The worker-local deadline must still apply.');
            } catch (ProviderDeferred $exception) {
                $this->assertSame(ProviderDeferred::COOLDOWN, $exception->reason);
                $this->assertTrue($exception->notBefore->equalTo($deadline));
                $this->assertSame(0, $this->replay->executedRequestCount());
            }
        });
    }

    public function test_multi_process_burst_preserves_three_slots_per_class_and_six_total(): void
    {
        $children = [];
        foreach (['background', 'interactive'] as $priority) {
            for ($i = 0; $i < 5; $i++) {
                $children[] = $this->startChild($priority, 'burst');
            }
        }
        foreach ($children as $child) {
            $this->awaitLine($child, 'READY');
        }
        $this->redis->setex($this->prefix.':start', 20, '1');
        foreach ($children as $child) {
            $this->finishChild($child);
        }
        $observed = $this->redis->hgetall($this->prefix.':observed');
        $this->assertLessThanOrEqual(6, (int) $observed['peak']);
        $this->assertLessThanOrEqual(3, (int) $observed['background:peak']);
        $this->assertLessThanOrEqual(3, (int) $observed['interactive:peak']);
        $this->assertGreaterThan(0, (int) $observed['background:completed']);
        $this->assertGreaterThan(0, (int) $observed['interactive:completed']);
        $this->assertSame(0, (int) $observed['total']);
    }

    public function test_killed_worker_slot_expires_and_another_worker_can_acquire_it(): void
    {
        config()->set('services.massive.concurrency.limit', 2);
        $child = $this->startChild('background', 'die', 2);
        $this->awaitLine($child, 'ACQUIRED');
        try {
            $this->limiter->massive(fn () => throw new RuntimeException('Dead worker lease is still reserved.'));
            $this->fail('The reserved slot must reject admission.');
        } catch (ProviderDeferred $exception) {
            $this->assertSame(ProviderDeferred::CAPACITY, $exception->reason);
        }
        proc_terminate($child['process']);
        $remaining = (int) $this->redis->pttl($this->prefix.':class:background:1');
        $this->assertGreaterThan(0, $remaining);
        usleep(($remaining + 100) * 1000);
        $this->assertSame('recovered', $this->limiter->massive(fn () => 'recovered'));
    }

    private function response(int $status, string $body, array $headers = []): Response
    {
        return new Response(new PsrResponse($status, $headers, $body));
    }

    private function startChild(string $priority, string $mode, int $limit = 6): array
    {
        $process = proc_open([
            PHP_BINARY, base_path('tests/Support/provider-limiter-worker.php'),
            $this->prefix, $priority, $mode, (string) $limit,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $child = ['process' => $process, 'out' => $pipes[1], 'err' => $pipes[2]];
        $this->processes[] = $child;

        return $child;
    }

    private function awaitLine(array $child, string $expected): void
    {
        $end = microtime(true) + 10;
        while (microtime(true) < $end) {
            $line = fgets($child['out']);
            if ($line !== false && str_contains($line, $expected)) {
                return;
            }
            usleep(10000);
        }
        $this->fail('Disposable limiter child did not reach '.$expected.'. '.stream_get_contents($child['err']));
    }

    private function finishChild(array $child): void
    {
        $end = microtime(true) + 10;
        while (proc_get_status($child['process'])['running'] && microtime(true) < $end) {
            usleep(10000);
        }
        $this->assertFalse(proc_get_status($child['process'])['running'], 'Disposable child must terminate.');
        $this->assertSame('', trim(stream_get_contents($child['err'])));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        foreach ($this->processes as $child) {
            if (is_resource($child['process'])) {
                if (proc_get_status($child['process'])['running']) {
                    proc_terminate($child['process']);
                }
                fclose($child['out']);
                fclose($child['err']);
                proc_close($child['process']);
            }
        }
        if (isset($this->redis)) {
            $cursor = 0;
            do {
                $batch = $this->redis->scan($cursor, ['match' => $this->prefix.':*', 'count' => 100]);
                if ($batch === false) {
                    break;
                }
                [$cursor, $keys] = $batch;
                if (is_array($keys) && $keys !== []) {
                    $this->redis->del(...$keys);
                }
            } while ((string) $cursor !== '0');
        }
        parent::tearDown();
    }
}
