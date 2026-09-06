<?php

namespace Tests\Unit;

use App\Support\ProviderRequestReplay;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Http\Client\Response;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProviderRequestReplayTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fingerprint_is_stable_secret_free_and_preserves_request_scope(): void
    {
        $first = ProviderRequestReplay::fingerprint('https://api.massive.test/options/SPY?cursor=one&apiKey=secret-one', [
            'limit' => 250, 'expiration_date' => '2026-09-18', 'api_key' => 'secret-one',
        ]);
        $second = ProviderRequestReplay::fingerprint('https://api.massive.test/options/SPY?cursor=one&apiKey=secret-two', [
            'api_key' => 'secret-two', 'expiration_date' => '2026-09-18', 'limit' => 250,
        ]);
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertStringNotContainsString('secret', $first);
        $this->assertNotSame($first, ProviderRequestReplay::fingerprint('https://api.massive.test/options/QQQ?cursor=one'));
        $this->assertNotSame(
            ProviderRequestReplay::fingerprint('https://api.massive.test/options/SPY?cursor=one&cursor=two'),
            ProviderRequestReplay::fingerprint('https://api.massive.test/options/SPY?cursor=two&cursor=one')
        );
        $this->assertNotSame(
            ProviderRequestReplay::fingerprint('https://api.massive.test/options/SPY', ['tickers' => ['SPY', 'QQQ']]),
            ProviderRequestReplay::fingerprint('https://api.massive.test/options/SPY', ['tickers' => ['QQQ', 'SPY']])
        );
    }

    public function test_nested_execution_counts_physical_requests_and_restores_context_after_exceptions(): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldNotReceive('connection');
        $replay = new ProviderRequestReplay(new Repository, $factory);

        $replay->withExecution('parent', function () use ($replay): void {
            $replay->recordPhysicalRequest();
            try {
                $replay->withExecution('child', function () use ($replay): void {
                    $this->assertSame(0, $replay->executedRequestCount());
                    $replay->recordPhysicalRequest();
                    $this->assertSame(1, $replay->executedRequestCount());
                    throw new RuntimeException('fixture');
                });
            } catch (RuntimeException) {
                $this->assertSame(2, $replay->executedRequestCount());
            }
            $replay->withExecution('parent', function () use ($replay): void {
                $replay->recordPhysicalRequest();
                $this->assertSame(1, $replay->executedRequestCount());
            });
            $this->assertSame(3, $replay->executedRequestCount());
        });
        $this->assertSame(0, $replay->executedRequestCount());
        $this->assertSame(1, $replay->recordFailure());
        $this->assertNull($replay->lookup(str_repeat('a', 64)));
    }

    public function test_only_a_bounded_compressed_body_is_stored_and_auth_headers_are_not_replayed(): void
    {
        [$replay, $connection] = $this->replay();
        $encoded = null;
        $requestKey = ProviderRequestReplay::fingerprint('https://api.massive.test/options/SPY');
        $connection->shouldReceive('eval')->once()->andReturnUsing(function ($script, $keys, $key, $field, $body, $ttl, $maxBytes, $maxPages) use (&$encoded, $requestKey): int {
            $this->assertSame($requestKey, $field);
            $this->assertStringNotContainsString('raw-scope-secret', $key);
            $this->assertSame(1200, $ttl);
            $this->assertSame(16 * 1024 * 1024, $maxBytes);
            $this->assertSame(1024, $maxPages);
            $encoded = $body;

            return 1;
        });
        $connection->shouldReceive('hstrlen')->once()->andReturnUsing(function () use (&$encoded) {
            return strlen($encoded);
        });
        $connection->shouldReceive('hget')->once()->andReturnUsing(function () use (&$encoded) {
            return $encoded;
        });
        $body = '{"status":"OK","results":[{"ticker":"O:SPY","volume":42}]}';
        $response = new Response(new PsrResponse(200, [
            'Authorization' => 'secret-sentinel', 'Set-Cookie' => 'secret-cookie',
            'Content-Type' => 'application/json',
        ], $body));

        $replay->withExecution('raw-scope-secret', function () use ($replay, $requestKey, $response, $body): void {
            $replay->remember($requestKey, $response);
            $restored = $replay->lookup($requestKey);
            $this->assertSame(200, $restored?->status());
            $this->assertSame($body, $restored?->body());
            $this->assertSame('', $restored?->header('Authorization'));
            $this->assertSame('', $restored?->header('Set-Cookie'));
            $this->assertSame(0, $replay->executedRequestCount());
        });
        $this->assertStringNotContainsString('secret-sentinel', $encoded);
        $this->assertStringNotContainsString('secret-cookie', $encoded);
    }

    public function test_error_malformed_secret_and_oversized_success_bodies_are_not_stored(): void
    {
        [$replay, $connection, $config] = $this->replay();
        $connection->shouldNotReceive('eval');
        $config->set('provider_backpressure.replay.max_page_bytes', 150);
        $responses = [
            new Response(new PsrResponse(429, [], '{"status":"ERROR"}')),
            new Response(new PsrResponse(503, [], '{"status":"ERROR"}')),
            new Response(new PsrResponse(200, [], '{"status":"ERROR"}')),
            new Response(new PsrResponse(200, [], 'invalid json')),
            new Response(new PsrResponse(200, [], '{"results":[],"next_url":"https://api.massive.test?apiKey=secret-sentinel"}')),
            new Response(new PsrResponse(200, [], '{"results":[],"echo":"secret-sentinel"}')),
            new Response(new PsrResponse(200, [], '{"results":["'.str_repeat('x', 150).'"]}')),
        ];

        $replay->withExecution('scope', function () use ($replay, $responses): void {
            foreach ($responses as $response) {
                $replay->remember(str_repeat('a', 64), $response);
            }
            $this->assertSame(0, $replay->executedRequestCount());
        });
    }

    public function test_oversized_cached_entry_is_rejected_before_transferring_its_body(): void
    {
        [$replay, $connection] = $this->replay();
        $connection->shouldReceive('hstrlen')->once()->andReturn(100 * 1024 * 1024);
        $connection->shouldNotReceive('hget');

        $this->assertNull($replay->withExecution('scope', fn () => $replay->lookup(str_repeat('a', 64))));
    }

    public function test_cache_failure_is_a_miss_and_never_replaces_a_provider_success(): void
    {
        [$replay, $connection] = $this->replay();
        $connection->shouldReceive('hstrlen')->once()->andThrow(new RuntimeException('password=secret-sentinel'));
        $connection->shouldReceive('eval')->once()->andThrow(new RuntimeException('password=secret-sentinel'));
        $replay->withExecution('scope', function () use ($replay): void {
            $this->assertNull($replay->lookup(str_repeat('a', 64)));
            $replay->remember(str_repeat('a', 64), new Response(new PsrResponse(200, [], '{"results":[]}')));
            $this->assertSame(0, $replay->executedRequestCount());
        });
    }

    public function test_execution_cleanup_deletes_only_the_exact_hashed_scope(): void
    {
        [$replay, $connection] = $this->replay();
        $connection->shouldReceive('del')->once()
            ->with('test:replay:execution:'.hash('sha256', 'scope:*'));
        $replay->forgetExecution('scope:*');
        $this->addToAssertionCount(1);
    }

    private function replay(): array
    {
        $config = new Repository([
            'provider_backpressure' => [
                'enabled' => true,
                'replay' => ['enabled' => true, 'prefix' => 'test:replay'],
            ],
            'services' => ['massive' => ['key' => 'secret-sentinel']],
        ]);
        $connection = Mockery::mock();
        $connection->shouldReceive('hincrby')->andReturn(1);
        $connection->shouldReceive('expire')->andReturn(1);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($connection);

        return [new ProviderRequestReplay($config, $factory), $connection, $config];
    }
}
