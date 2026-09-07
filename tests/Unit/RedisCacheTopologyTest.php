<?php

namespace Tests\Unit;

use App\Support\RedisCacheTopology;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RedisCacheTopologyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_distinct_processes_pass_without_exposing_identifiers_or_claiming_a_restart_drill(): void
    {
        [$probe] = $this->probe();
        $report = $probe->inspect();

        $this->assertTrue($report['checks_passed']);
        $this->assertFalse($report['activation_verified']);
        $this->assertFalse($report['snapshot_is_atomic']);
        foreach ($report['cache_isolation'] as $isolation) {
            $this->assertSame(['verified' => true, 'same_process' => false], $isolation);
        }
        $this->assertSame('default', $report['roles']['locks']);
        $this->assertSame('queue', $report['roles']['queue']);
        $this->assertCount(4, $report['redis']);
        $this->assertSame(['verified' => true, 'same_process' => true], $report['coordination_original_process']);
        $encoded = json_encode($report);
        foreach (['secret-fixture', 'private-host', str_repeat('a', 40)] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertContains('disposable_cache_restart_and_owned_lock_survival_drill',
            $report['external_verification_required']);
    }

    public function test_different_logical_databases_do_not_prove_cache_lock_or_queue_isolation(): void
    {
        [$probe] = $this->probe(['cache' => ['run_id' => str_repeat('b', 40)]]);
        $report = $probe->inspect();
        $this->assertFalse($report['checks_passed']);
        $this->assertContains('cache_shares_redis_process:locks', $report['errors']);
        $this->assertContains('cache_shares_redis_process:provider', $report['errors']);
        $this->assertTrue($report['cache_isolation']['locks']['same_process']);

        [$probe] = $this->probe(['cache' => ['run_id' => str_repeat('c', 40)]]);
        $this->assertContains('cache_shares_redis_process:queue', $probe->inspect()['errors']);
    }

    public function test_lock_and_queue_eviction_policies_fail_independently_of_cache_policy(): void
    {
        [$probe] = $this->probe([
            'cache' => ['maxmemory_policy' => 'allkeys-lru', 'evicted_keys' => 10],
            'default' => ['maxmemory_policy' => 'volatile-lru'],
            'queue' => ['evicted_keys' => 1],
        ]);
        $report = $probe->inspect();
        $this->assertContains('redis_noeviction_required:locks', $report['errors']);
        $this->assertContains('redis_noeviction_required:provider', $report['errors']);
        $this->assertContains('redis_zero_evictions_unverified:queue', $report['errors']);
        $this->assertSame(10, $report['redis']['cache']['evicted_keys']);
        $this->assertSame('allkeys-lru', $report['redis']['cache']['maxmemory_policy']);
    }

    public function test_unknown_identity_and_a_restart_during_inspection_never_imply_isolation(): void
    {
        [$probe] = $this->probe(['cache' => ['run_id' => null]]);
        $report = $probe->inspect();
        $this->assertContains('redis_process_identity_unavailable:cache', $report['errors']);
        $this->assertSame(['verified' => false, 'same_process' => null], $report['cache_isolation']['locks']);

        [$probe] = $this->probe(afterIds: ['cache' => str_repeat('d', 40)]);
        $report = $probe->inspect();
        $this->assertContains('redis_process_changed_or_unverified:cache', $report['errors']);
        $this->assertFalse($report['checks_passed']);
        $this->assertNull($report['cache_isolation']['queue']['same_process']);
    }

    public function test_connection_failures_are_safe_and_subsequent_inspection_can_recover(): void
    {
        [$probe] = $this->probe(fail: 'cache');
        $report = $probe->inspect();
        $this->assertFalse($report['checks_passed']);
        $this->assertContains('redis_read_unavailable:cache', $report['errors']);
        $this->assertStringNotContainsString('secret-fixture', json_encode($report));
        $this->assertStringNotContainsString('private-host', json_encode($report));

        // No sticky negative capability state is retained by the service.
        [$recovered] = $this->probe();
        $this->assertTrue($recovered->inspect()['checks_passed']);
    }

    public function test_nested_or_raw_info_is_supported_and_uncapped_memory_is_explicit(): void
    {
        [$probe] = $this->probe(['default' => ['maxmemory' => 0]], format: 'nested');
        $report = $probe->inspect();
        $this->assertTrue($report['checks_passed']);
        $this->assertContains('redis_memory_uncapped_check_host_headroom:default', $report['warnings']);
        [$probe] = $this->probe(format: 'raw');
        $this->assertTrue($probe->inspect()['checks_passed']);
    }

    public function test_invalid_configuration_does_not_probe_or_echo_untrusted_connection_names(): void
    {
        $config = $this->configuration();
        $config->set('cache.default', 'redis://secret-fixture@private-host');
        $config->set('queue.default', 'database');
        $config->set('queue.long_connection', 'database');
        $config->set('services.massive.concurrency.connection', 'redis://secret-fixture@private-host');
        $config->set('database.redis.default', null);
        $config->set('cache.coordination_enabled', false);
        $redis = Mockery::mock(Factory::class);
        $redis->shouldNotReceive('connection');

        $report = (new RedisCacheTopology($config, $redis))->inspect();
        $this->assertFalse($report['checks_passed']);
        $this->assertContains('cache_store_is_not_redis', $report['errors']);
        $this->assertContains('queue_driver_is_not_redis:queue', $report['errors']);
        $this->assertStringNotContainsString('secret-fixture', json_encode($report));
    }

    public function test_provider_connection_is_checked_even_if_it_differs_from_lock_connection(): void
    {
        [$probe, $config] = $this->probe();
        $config->set('services.massive.concurrency.connection', 'cache');
        $report = $probe->inspect();
        $this->assertContains('cache_shares_redis_process:provider', $report['errors']);
        $this->assertFalse($report['checks_passed']);
    }

    public function test_coordination_must_be_enabled_on_original_noeviction_process(): void
    {
        [$probe] = $this->probe(['coordination' => ['run_id' => str_repeat('d', 40)]]);
        $this->assertContains('coordination_original_process_unverified', $probe->inspect()['errors']);
        [$probe] = $this->probe(['coordination' => ['maxmemory_policy' => 'allkeys-lru']]);
        $this->assertContains('redis_noeviction_required:coordination', $probe->inspect()['errors']);
    }

    public function test_disabled_coordination_cannot_make_an_isolated_cache_eligible(): void
    {
        [$probe, $config] = $this->probe(coordination: false);
        $config->set('cache.coordination_enabled', false);
        $report = $probe->inspect();
        $this->assertContains('coordination_cache_selector_disabled', $report['errors']);
        $this->assertContains('cache_shares_redis_process:coordination', $report['errors']);
        $this->assertFalse($report['checks_passed']);
    }

    public function test_rate_limiter_must_not_remain_on_payload_cache_when_coordination_is_enabled(): void
    {
        [$probe, $config] = $this->probe();
        $config->set('cache.limiter', null);
        $this->assertContains('rate_limiter_coordination_store_required', $probe->inspect()['errors']);
    }

    private function configuration(): Repository
    {
        return new Repository([
            'cache' => ['default' => 'redis', 'coordination_enabled' => true, 'limiter' => 'coordination', 'stores' => [
                'redis' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default'],
                'coordination' => ['driver' => 'redis', 'connection' => 'coordination', 'lock_connection' => 'default'],
            ]],
            'queue' => ['default' => 'redis', 'long_connection' => 'redis-long', 'connections' => [
                'redis' => ['driver' => 'redis', 'connection' => 'queue'],
                'redis-long' => ['driver' => 'redis', 'connection' => 'queue'],
            ]],
            'database' => ['redis' => [
                'cache' => ['host' => 'private-host', 'password' => 'secret-fixture', 'database' => 1],
                'default' => ['host' => 'private-host', 'password' => 'secret-fixture', 'database' => 0],
                'queue' => ['host' => 'private-host', 'password' => 'secret-fixture', 'database' => 0],
                'coordination' => ['host' => 'private-host', 'password' => 'secret-fixture', 'database' => 1],
            ]],
            'services' => ['massive' => ['concurrency' => ['connection' => 'default']]],
        ]);
    }

    private function probe(array $overrides = [], array $afterIds = [], ?string $fail = null, string $format = 'flat', bool $coordination = true): array
    {
        $config = $this->configuration();
        $redis = Mockery::mock(Factory::class);
        foreach (array_merge(['cache' => 'a', 'default' => 'b', 'queue' => 'c'], $coordination ? ['coordination' => 'b'] : []) as $name => $letter) {
            $connection = Mockery::mock(Connection::class);
            if ($fail === $name) {
                $redis->shouldReceive('connection')->with($name)->once()
                    ->andThrow(new RuntimeException('redis://secret-fixture@private-host'));

                continue;
            }
            $redis->shouldReceive('connection')->with($name)->once()->andReturn($connection);
            $info = array_replace([
                'run_id' => str_repeat($letter, 40), 'maxmemory_policy' => 'noeviction',
                'maxmemory' => 536870912, 'used_memory' => 1048576, 'evicted_keys' => 0,
            ], $overrides[$name] ?? []);
            $response = match ($format) {
                'nested' => ['Server' => $info],
                'raw' => implode("\r\n", array_map(fn ($key, $value) => $key.':'.$value,
                    array_keys($info), array_values($info))),
                default => $info,
            };
            $connection->shouldReceive('info')->withNoArgs()->once()->andReturn($response);
            $connection->shouldReceive('info')->with('server')->once()
                ->andReturn(['run_id' => $afterIds[$name] ?? $info['run_id']]);
        }

        return [new RedisCacheTopology($config, $redis), $config];
    }
}
