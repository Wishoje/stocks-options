<?php

namespace Tests\Unit;

use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Support\QueueReadiness;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class QueueReadinessTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_pass_reports_read_only_evidence_without_claiming_activation_or_exposing_secrets(): void
    {
        [$probe] = $this->probe();
        $report = $probe->inspect();

        $this->assertTrue($report['checks_passed']);
        $this->assertFalse($report['activation_verified']);
        $this->assertSame([], $report['errors']);
        $this->assertCount(13, $report['queues']);
        $this->assertContains('supervisor_lane_coverage_and_timeouts', $report['external_verification_required']);
        $this->assertContains('redis_memory_uncapped_check_host_headroom:default', $report['warnings']);
        $this->assertSame(2, $report['queues'][0]['ready']);
        $this->assertSame(3, $report['queues'][0]['reserved']);
        $this->assertSame(4, $report['queues'][0]['delayed']);
        $this->assertSame(
            ['required' => true, 'verified' => true, 'same_process' => false],
            $report['redis']['default']['cache_isolation'],
        );
        $this->assertStringNotContainsString('secret-sentinel', json_encode($report));
        $this->assertStringNotContainsString('private-redis-host', json_encode($report));
        $this->assertStringNotContainsString(str_repeat('a', 40), json_encode($report));
    }

    public function test_disabled_aof_and_non_durable_fsync_fail_even_when_rdb_is_healthy(): void
    {
        [$probe] = $this->probe(['aof_enabled' => 0], fsync: 'no');
        $report = $probe->inspect();

        $this->assertFalse($report['checks_passed']);
        $this->assertContains('redis_aof_disabled:default', $report['errors']);
        $this->assertContains('redis_durable_fsync_unverified:default', $report['errors']);
        $this->assertSame('ok', $report['redis']['default']['rdb_last_bgsave_status']);
    }

    public function test_eviction_persistence_and_memory_failures_are_not_treated_as_ready(): void
    {
        [$probe] = $this->probe([
            'maxmemory_policy' => 'allkeys-lru',
            'evicted_keys' => 1,
            'aof_last_write_status' => 'err',
            'aof_last_bgrewrite_status' => 'err',
            'rdb_last_bgsave_status' => 'err',
            'used_memory' => 900,
            'maxmemory' => 1000,
        ]);
        $errors = $probe->inspect()['errors'];

        $this->assertContains('redis_noeviction_required:default', $errors);
        $this->assertContains('redis_zero_evictions_unverified:default', $errors);
        $this->assertContains('redis_persistence_error:default:aof_last_write_status', $errors);
        $this->assertContains('redis_persistence_error:default:aof_last_bgrewrite_status', $errors);
        $this->assertContains('redis_persistence_error:default:rdb_last_bgsave_status', $errors);
        $this->assertContains('redis_memory_at_or_above_90_percent:default', $errors);
    }

    public function test_legacy_database_backlog_and_failed_jobs_require_lossless_resolution(): void
    {
        [$probe] = $this->probe(backlog: 3, failures: 2);
        $report = $probe->inspect();

        $this->assertFalse($report['checks_passed']);
        $this->assertSame(3, $report['database']['legacy_queue_jobs']);
        $this->assertSame(2, $report['database']['legacy_database_failed_jobs']);
        $this->assertContains('legacy_database_queue_requires_lossless_drain', $report['errors']);
        $this->assertContains('legacy_database_failed_jobs_require_review', $report['errors']);
    }

    public function test_lease_equal_to_job_timeout_and_missing_monitor_target_fail(): void
    {
        [$probe, $config] = $this->probe();
        $config->set('queue.connections.redis-long.retry_after', 900);
        $config->set('queue.monitor.targets', ['redis:default']);
        $report = $probe->inspect();

        $this->assertFalse($report['checks_passed']);
        $this->assertContains('queue_lease_not_above_job_timeout:redis-long:exports', $report['errors']);
        $this->assertContains('queue_not_monitored:redis:intraday-interactive', $report['errors']);
    }

    public function test_distinct_legacy_long_database_queue_is_also_checked(): void
    {
        [$probe, $config, $database] = $this->probe();
        $config->set('queue.connections.database-long.connection', 'legacy_long');
        $db = Mockery::mock(DatabaseConnection::class);
        $schema = Mockery::mock(SchemaBuilder::class);
        $jobs = Mockery::mock(QueryBuilder::class);
        $database->shouldReceive('connection')->with('legacy_long')->once()->andReturn($db);
        $db->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $schema->shouldReceive('hasTable')->with('jobs')->once()->andReturnTrue();
        $db->shouldReceive('table')->with('jobs')->once()->andReturn($jobs);
        $jobs->shouldReceive('count')->once()->andReturn(7);

        $report = $probe->inspect();

        $this->assertSame(7, $report['database']['legacy_queue_jobs']);
        $this->assertSame(2, $report['database']['legacy_queue_stores_checked']);
        $this->assertContains('legacy_database_queue_requires_lossless_drain', $report['errors']);
    }

    public function test_queue_and_cache_in_one_database_fail_and_disabled_provider_gate_fails(): void
    {
        [$probe, $config] = $this->probe(cacheRunId: str_repeat('a', 40));
        $config->set('database.redis.cache.database', 0);
        $config->set('services.massive.concurrency.enabled', false);
        $errors = $probe->inspect()['errors'];

        $this->assertContains('cache_and_queue_share_redis_database:default', $errors);
        $this->assertContains('isolated_lanes_require_provider_semaphore', $errors);
    }

    public function test_shared_redis_process_fails_even_when_logical_databases_are_distinct(): void
    {
        [$probe] = $this->probe(cacheRunId: str_repeat('a', 40));
        $report = $probe->inspect();

        $this->assertFalse($report['checks_passed']);
        $this->assertContains('cache_and_queue_share_redis_process:default', $report['errors']);
        $this->assertTrue($report['redis']['default']['cache_isolation']['same_process']);
    }

    public function test_bare_export_monitor_does_not_falsely_prove_long_connection_coverage(): void
    {
        [$probe, $config] = $this->probe();
        $config->set('queue.monitor.targets', []);
        $config->set('queue.monitor.connection', 'redis');
        $config->set('queue.monitor.queues', ['default', 'exports']);
        $report = $probe->inspect();

        $this->assertContains('queue_not_monitored:redis-long:exports', $report['errors']);
        $this->assertNotContains('queue_not_monitored:redis:default', $report['errors']);
    }

    public function test_redis_connection_failure_does_not_leak_exception_credentials(): void
    {
        [$probe] = $this->probe(throwRedis: true);
        $report = $probe->inspect();

        $this->assertFalse($report['checks_passed']);
        $this->assertContains('queue_read_unavailable:redis:default', $report['errors']);
        $this->assertStringNotContainsString('secret-sentinel', json_encode($report));
    }

    public function test_nested_redis_info_and_list_config_response_are_supported(): void
    {
        [$probe] = $this->probe(['Persistence' => ['aof_enabled' => 0]], listConfig: true);
        $report = $probe->inspect();

        $this->assertSame('everysec', $report['redis']['default']['appendfsync']);
        $this->assertContains('redis_aof_disabled:default', $report['errors']);
    }

    public function test_different_process_ids_prove_process_isolation_without_exposing_ids(): void
    {
        [$probe, $config] = $this->probe(cacheRunId: str_repeat('b', 40));
        $config->set('database.redis.cache.host', 'other-private-host');
        $report = $probe->inspect();

        $this->assertSame(
            ['required' => true, 'verified' => true, 'same_process' => false],
            $report['redis']['default']['cache_isolation'],
        );
        $this->assertNotContains('cache_and_queue_share_redis_process:default', $report['warnings']);
        $this->assertStringNotContainsString(str_repeat('b', 40), json_encode($report));
        $this->assertFalse($report['activation_verified']);
    }

    public function test_unavailable_or_missing_process_id_never_implies_isolation(): void
    {
        [$probe] = $this->probe(cacheRunId: null);
        $report = $probe->inspect();

        $this->assertSame(
            ['required' => true, 'verified' => false, 'same_process' => null],
            $report['redis']['default']['cache_isolation'],
        );
        $this->assertContains('redis_cache_process_isolation_unverified:default', $report['errors']);
        $this->assertFalse($report['checks_passed']);
    }

    public function test_cache_info_failure_is_safe_and_does_not_claim_isolation(): void
    {
        [$probe] = $this->probe(cacheUnavailable: true);
        $report = $probe->inspect();

        $this->assertFalse($report['redis']['default']['cache_isolation']['verified']);
        $this->assertNull($report['redis']['default']['cache_isolation']['same_process']);
        $this->assertStringNotContainsString('secret-sentinel', json_encode($report));
        $this->assertFalse($report['checks_passed']);
    }

    public function test_running_ttl_must_exceed_hard_timeout_and_redis_lease(): void
    {
        [$probe, $config] = $this->probe();
        $config->set('work_runs.running_ttl_seconds.intraday_refresh', 540);
        $config->set('work_runs.running_ttl_seconds.calculator_refresh', 1080);
        $report = $probe->inspect();

        $this->assertContains('work_run_running_ttl_not_above_hard_timeout:intraday_refresh', $report['errors']);
        $this->assertContains('work_run_running_ttl_not_above_queue_lease:intraday_refresh', $report['errors']);
        $this->assertContains('work_run_running_ttl_not_above_queue_lease:calculator_refresh', $report['errors']);
        $this->assertSame(540, $report['work_run_recovery']['intraday_refresh']['configured_running_ttl_seconds']);
        $this->assertSame(1140, $report['work_run_recovery']['intraday_refresh']['recovery_minimum_age_seconds']);
    }

    public function test_bounded_head_and_due_metrics_are_labeled_without_claiming_global_oldest_or_pure_wait(): void
    {
        [$probe] = $this->probe();
        $report = $probe->inspect(2000);
        $queue = $report['queues'][0];

        $this->assertSame(300, $queue['head_ready_enqueue_age_seconds']);
        $this->assertSame('recorded', $queue['head_ready_enqueue_age_status']);
        $this->assertSame(1900, $queue['oldest_reserved_expiry_unix']);
        $this->assertSame(100, $queue['oldest_reserved_expired_seconds']);
        $this->assertSame(2100, $queue['next_delayed_due_unix']);
        $this->assertSame(0, $queue['oldest_delayed_due_lag_seconds']);
        $this->assertFalse($report['snapshot_is_atomic']);
        $this->assertContains('ready_head_is_not_global_oldest_after_retry_or_delay_migration', $report['metric_limitations']);
        $this->assertStringNotContainsString('serialized-secret-payload', json_encode($report));
    }

    public function test_legacy_ready_payload_does_not_report_an_invented_age(): void
    {
        [$probe] = $this->probe(headPayload: '{"data":{"command":"serialized-secret-payload"}}');
        $queue = $probe->inspect(2000)['queues'][0];

        $this->assertNull($queue['head_ready_enqueue_age_seconds']);
        $this->assertSame('enqueue_timestamp_not_recorded', $queue['head_ready_enqueue_age_status']);
    }

    public function test_empty_queues_do_not_read_payloads_or_sorted_set_members(): void
    {
        [$probe] = $this->probe(readyCount: 0, reservedCount: 0, delayedCount: 0);
        $report = $probe->inspect(2000);
        $queue = $report['queues'][0];

        $this->assertTrue($report['checks_passed']);
        $this->assertNull($queue['head_ready_enqueue_age_seconds']);
        $this->assertNull($queue['oldest_reserved_expiry_unix']);
        $this->assertNull($queue['next_delayed_due_unix']);
    }

    /** All external dependencies are strict read-only mocks. No database is opened. */
    private function probe(
        array $info = [],
        string $fsync = 'everysec',
        int $backlog = 0,
        int $failures = 0,
        bool $throwRedis = false,
        bool $listConfig = false,
        ?string $cacheRunId = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        bool $cacheUnavailable = false,
        mixed $headPayload = '{"gex_enqueued_at":1700,"data":{"command":"serialized-secret-payload"}}',
        int $readyCount = 2,
        int $reservedCount = 3,
        int $delayedCount = 4,
    ): array {
        $queues = [
            'bootstrap-fast', 'intraday-interactive', 'calculator-interactive', 'quotes',
            'intraday', 'intraday-heavy', 'calculator-fill', 'calculator-fill-heavy', 'default', 'exports',
        ];
        $allQueues = array_values(array_unique(array_merge($queues, ['bootstrap', 'prime', 'calculator'])));
        $config = new Repository([
            'queue' => [
                'default' => 'redis', 'long_connection' => 'redis-long', 'long_queue' => 'exports',
                'connections' => [
                    'redis' => ['driver' => 'redis', 'connection' => 'default', 'retry_after' => 1080],
                    'redis-long' => ['driver' => 'redis', 'connection' => 'default', 'retry_after' => 1080],
                    'database' => ['driver' => 'database', 'connection' => null, 'table' => 'jobs'],
                    'database-long' => ['driver' => 'database', 'connection' => null, 'table' => 'jobs'],
                ],
                'failed' => ['database' => null, 'table' => 'failed_jobs'],
                'monitor' => [
                    'enabled' => true,
                    'targets' => array_map(
                        static fn (string $queue): string => ($queue === 'exports' ? 'redis-long' : 'redis').':'.$queue,
                        $allQueues,
                    ),
                ],
            ],
            'queue_lanes' => [
                'isolated' => true, 'legacy' => ['bootstrap', 'prime', 'calculator'], 'queues' => $queues,
            ],
            'queue_contracts' => [
                FetchPolygonIntradayOptionsJob::class => ['max_timeout' => 540],
                FetchCalculatorChainJob::class => ['max_timeout' => 270],
                'fixture' => [
                    'queues' => $allQueues,
                    'max_timeout' => 540,
                    'queue_timeouts' => ['intraday' => 105, 'quotes' => 90, 'exports' => 900],
                ],
            ],
            'work_runs' => ['running_ttl_seconds' => ['intraday_refresh' => 1800, 'calculator_refresh' => 3600]],
            'services' => ['massive' => ['concurrency' => ['enabled' => true, 'limit' => 6]]],
            'database' => ['redis' => [
                'default' => ['host' => 'private-redis-host', 'password' => 'secret-sentinel', 'database' => 0],
                'cache' => ['host' => 'private-redis-host', 'password' => 'secret-sentinel', 'database' => 1],
            ]],
            'cache' => ['default' => 'redis', 'stores' => ['redis' => ['driver' => 'redis', 'connection' => 'cache']]],
        ]);
        $redis = Mockery::mock(Factory::class);
        $connection = Mockery::mock(RedisConnection::class);
        if ($throwRedis) {
            $redis->shouldReceive('connection')->with('default')
                ->andThrow(new RuntimeException('redis://secret-sentinel@private-redis-host'));
        } else {
            $redis->shouldReceive('connection')->with('default')->once()->andReturn($connection);
            $connection->shouldReceive('llen')->withArgs(static fn (string $key): bool => str_starts_with($key, 'queues:'))
                ->times(13)->andReturn($readyCount);
            $connection->shouldReceive('zcard')->withArgs(static fn (string $key): bool => str_ends_with($key, ':reserved'))
                ->times(13)->andReturn($reservedCount);
            $connection->shouldReceive('zcard')->withArgs(static fn (string $key): bool => str_ends_with($key, ':delayed'))
                ->times(13)->andReturn($delayedCount);
            if ($readyCount > 0) {
                $connection->shouldReceive('lindex')->withArgs(
                    static fn (string $key, int $index): bool => str_starts_with($key, 'queues:') && $index === 0,
                )->times(13)->andReturn($headPayload);
            }
            foreach (['reserved' => [$reservedCount, 1900], 'delayed' => [$delayedCount, 2100]] as $suffix => [$count, $score]) {
                if ($count > 0) {
                    $connection->shouldReceive('zrangebyscore')->withArgs(
                        static fn (string $key, string $min, string $max, array $options): bool => str_ends_with($key, ':'.$suffix)
                            && $min === '-inf' && $max === '+inf' && $options === ['withscores' => true, 'limit' => [0, 1]],
                    )->times(13)->andReturn(['serialized-secret-payload' => $score]);
                }
            }
            $connection->shouldReceive('info')->once()->andReturn(array_replace([
                'run_id' => str_repeat('a', 40),
                'redis_version' => '8.4.0', 'used_memory' => 16000000, 'used_memory_peak' => 48000000,
                'maxmemory' => 0, 'maxmemory_policy' => 'noeviction', 'evicted_keys' => 0,
                'aof_enabled' => 1, 'aof_last_write_status' => 'ok', 'aof_last_bgrewrite_status' => 'ok',
                'rdb_last_bgsave_status' => 'ok', 'loading' => 0, 'ignored_secret' => 'secret-sentinel',
            ], $info));
            $connection->shouldReceive('config')->once()->with('GET', 'appendfsync')
                ->andReturn($listConfig ? ['appendfsync', $fsync] : ['appendfsync' => $fsync]);
            $cacheConnection = Mockery::mock(RedisConnection::class);
            $redis->shouldReceive('connection')->with('cache')->once()->andReturn($cacheConnection);
            if ($cacheUnavailable) {
                $cacheConnection->shouldReceive('info')->with('server')->once()
                    ->andThrow(new RuntimeException('redis://secret-sentinel@private-redis-host'));
            } else {
                $cacheConnection->shouldReceive('info')->with('server')->once()->andReturn(['run_id' => $cacheRunId]);
            }
        }
        $database = Mockery::mock(DatabaseManager::class);
        $db = Mockery::mock(DatabaseConnection::class);
        $schema = Mockery::mock(SchemaBuilder::class);
        $jobs = Mockery::mock(QueryBuilder::class);
        $failed = Mockery::mock(QueryBuilder::class);
        $database->shouldReceive('connection')->with(null)->andReturn($db);
        $database->shouldReceive('connection')->withNoArgs()->andReturn($db);
        $db->shouldReceive('getSchemaBuilder')->andReturn($schema);
        foreach (['jobs', 'failed_jobs', 'work_runs', 'work_run_slots'] as $table) {
            $schema->shouldReceive('hasTable')->with($table)->once()->andReturnTrue();
        }
        $db->shouldReceive('table')->with('jobs')->once()->andReturn($jobs);
        $jobs->shouldReceive('count')->once()->andReturn($backlog);
        $db->shouldReceive('table')->with('failed_jobs')->once()->andReturn($failed);
        $failed->shouldReceive('whereIn')->with('connection', ['database', 'database-long'])->once()->andReturnSelf();
        $failed->shouldReceive('count')->once()->andReturn($failures);

        return [new QueueReadiness($config, $redis, $database), $config, $database];
    }
}
