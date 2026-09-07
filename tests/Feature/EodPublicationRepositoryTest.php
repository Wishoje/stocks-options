<?php

namespace Tests\Feature;

use App\Support\EodCacheVersion;
use App\Support\EodLegacyPublicationInventory;
use App\Support\EodPublicationRepository;
use App\Support\EodSnapshotHealth;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\MySqlTestCase;

class EodPublicationRepositoryTest extends MySqlTestCase
{
    // Real commits are essential: the compatibility mirror must not run at a
    // test transaction's simulated commit boundary.
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'cache.default' => 'array',
            'eod_publications.write_enabled' => false,
            'eod_publications.read_enabled' => false,
            'eod_snapshot_health.enabled' => false,
        ]);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::disableQueryLog();
        DB::flushQueryLog();
        DB::purge('publication_observer');
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_import_preserves_exact_tokens_order_and_unrelated_cached_values(): void
    {
        Cache::put('unrelated', 'keep', 3600);
        $repo = app(EodPublicationRepository::class);
        $prepared = $repo->prepare([
            $this->publicationHead('SPY', 'old', 10), $this->publicationHead('SPY', 'new', 20),
            $this->publicationHead('QQQ', 'quote', 15, EodCacheVersion::DOMAIN_ACTIVITY),
        ], 100);
        $this->assertSame(2, $prepared['heads_imported']);
        $this->enable();
        $this->assertSame('new', app(EodCacheVersion::class)->current('gex', 'spy'));
        $this->assertSame('quote', app(EodCacheVersion::class)->current('activity', 'qqq'));
        $this->assertSame(20, (int) DB::table('eod_cache_publications')->where('symbol', 'SPY')->value('issued_at_microseconds'));
        $this->assertSame('keep', Cache::get('unrelated'));
    }

    public function test_import_cannot_overwrite_an_initialized_registry(): void
    {
        $repo = app(EodPublicationRepository::class);
        $repo->prepare([], 100);
        $this->expectException(RuntimeException::class);
        $repo->prepare([], 200);
    }

    public function test_import_refuses_unknown_order_without_writing_control_or_heads(): void
    {
        try {
            app(EodPublicationRepository::class)->prepare([$this->publicationHead('SPY', 'v1', 0)], 100);
            $this->fail('Missing order must not be invented.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('eod_cache_publication_state')->count());
            $this->assertSame(0, DB::table('eod_cache_publications')->count());
        }
    }

    public function test_durable_reads_require_both_writers_and_prepared_state(): void
    {
        config()->set('eod_publications.read_enabled', true);
        try {
            app(EodCacheVersion::class)->current('gex', 'SPY');
            $this->fail('Read-only cutover is unsafe.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('require durable writes', $exception->getMessage());
        }
        config()->set('eod_publications.write_enabled', true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('have not been prepared');
        app(EodCacheVersion::class)->current('gex', 'SPY');
    }

    public function test_missing_heads_never_reuse_initial_payload_namespace(): void
    {
        app(EodPublicationRepository::class)->prepare([], 100);
        $this->enable();
        Cache::put('gex:SPY:initial:14d', 'old payload', 3600);
        $version = app(EodCacheVersion::class)->current('gex', 'SPY');
        $this->assertStringStartsWith('unpublished:v3:', $version);
        $this->assertNull(Cache::get(app(EodCacheVersion::class)->key('gex', 'gex', 'SPY', '14d')));
        Cache::flush(); // Disposable array store only; never used in rollout.
        $this->assertSame($version, app(EodCacheVersion::class)->current('gex', 'SPY'));
    }

    public function test_pre_cutover_finalizer_cannot_create_or_replace_a_publication(): void
    {
        $repo = app(EodPublicationRepository::class);
        $repo->prepare([$this->publicationHead('SPY', 'imported', 50)], 100);
        $this->enable();
        $repo->publish(['SPY', 'QQQ'], ['gex'], 'delayed', 99);
        $this->assertSame('imported', app(EodCacheVersion::class)->current('gex', 'SPY'));
        $this->assertStringStartsWith('unpublished:v3:', app(EodCacheVersion::class)->current('gex', 'QQQ'));
        $repo->publish(['QQQ'], ['gex'], 'at-floor', 100);
        $this->assertStringStartsWith('unpublished:v3:', app(EodCacheVersion::class)->current('gex', 'QQQ'));
    }

    public function test_order_and_idempotency_survive_cache_loss_and_stale_cache_restore(): void
    {
        $this->prepare();
        $versions = app(EodCacheVersion::class);
        $versions->publish(['SPY'], ['gex'], 'latest', 300);
        Cache::flush();
        Cache::forever($versions->publicationKey('gex', 'SPY'), ['version' => 'restored-old', 'issued_at_microseconds' => 200]);
        $this->assertSame('latest', $versions->current('gex', 'SPY'));
        $versions->publish(['SPY'], ['gex'], 'delayed', 200);
        $versions->publish(['SPY'], ['gex'], 'latest', 300);
        $this->assertSame('latest', $versions->current('gex', 'SPY'));
        $this->assertSame('latest', Cache::get($versions->publicationKey('gex', 'SPY'))['version']);
        $this->assertSame(1, DB::table('eod_cache_publications')->count());
    }

    public function test_equal_time_uses_stable_lexical_tie_break(): void
    {
        $this->prepare();
        $versions = app(EodCacheVersion::class);
        foreach (['token-b', 'token-a', 'token-c', 'token-b'] as $token) {
            $versions->publish(['SPY'], ['activity'], $token, 200);
        }
        $this->assertSame('token-c', $versions->current('activity', 'SPY'));
    }

    public function test_publication_is_targeted_and_does_not_flush_locks_or_other_symbols(): void
    {
        $this->prepare();
        $versions = app(EodCacheVersion::class);
        $before = $versions->current('gex', 'QQQ');
        Cache::put('queue-sentinel', 'keep', 3600);
        Cache::put('session-sentinel', 'keep', 3600);
        $lock = Cache::lock('coordination-sentinel', 60);
        $this->assertTrue($lock->get());
        $versions->publish(['SPY'], ['volatility'], 'new-vol', 200);
        $this->assertSame($before, $versions->current('gex', 'QQQ'));
        $this->assertStringStartsWith('unpublished:v3:', $versions->current('gex', 'SPY'));
        $this->assertFalse(Cache::lock('coordination-sentinel', 60)->get());
        $this->assertSame('keep', Cache::get('queue-sentinel'));
        $this->assertSame('keep', Cache::get('session-sentinel'));
        $lock->release();
    }

    public function test_bulk_heads_take_one_sql_query_and_preserve_legacy_read_key_admission(): void
    {
        $this->prepare();
        app(EodCacheVersion::class)->publish(['SPY'], ['expiry-pressure'], 'v1', 200);
        DB::enableQueryLog();
        $result = app(EodCacheVersion::class)->currentMany('expiry-pressure', ['SPY', 'spy', 'QQQ', 'BAD SYMBOL', '??', 'ŚPY']);
        $queries = DB::getQueryLog();
        $this->assertCount(1, $queries);
        $this->assertCount(5, $result);
        $this->assertSame('v1', $result['SPY']);
        $this->assertStringStartsWith('unpublished:v3:', $result['BAD SYMBOL']);
        $this->assertStringStartsWith('unpublished:v3:', $result['ŚPY']);
        $this->assertStringContainsString('eod_cache_publications', $queries[0]['query']);
    }

    public function test_nested_rollback_cannot_leak_any_domain_or_redis_mirror(): void
    {
        $this->prepare();
        $versions = app(EodCacheVersion::class);
        $versions->publish(['SPY'], EodCacheVersion::ALL_DOMAINS, 'old', 200);
        DB::beginTransaction();
        $versions->publish(['SPY'], EodCacheVersion::ALL_DOMAINS, 'rolled-back', 300);
        foreach (EodCacheVersion::ALL_DOMAINS as $domain) {
            $this->assertSame('old', Cache::get($versions->publicationKey($domain, 'SPY'))['version']);
        }
        DB::rollBack();
        foreach (EodCacheVersion::ALL_DOMAINS as $domain) {
            $this->assertSame('old', $versions->current($domain, 'SPY'));
            $this->assertSame('old', Cache::get($versions->publicationKey($domain, 'SPY'))['version']);
        }
    }

    public function test_other_connection_cannot_observe_partial_multidomain_commit(): void
    {
        $this->prepare();
        $versions = app(EodCacheVersion::class);
        $versions->publish(['SPY'], EodCacheVersion::ALL_DOMAINS, 'old', 200);
        config()->set('database.connections.publication_observer', config('database.connections.'.config('database.default')));
        DB::beginTransaction();
        $versions->publish(['SPY'], EodCacheVersion::ALL_DOMAINS, 'new', 300);
        $observer = DB::connection('publication_observer');
        $this->assertSame(['old'], $observer->table('eod_cache_publications')->where('symbol', 'SPY')->distinct()->pluck('version')->all());
        DB::commit();
        $this->assertSame(['new'], $observer->table('eod_cache_publications')->where('symbol', 'SPY')->distinct()->pluck('version')->all());
        foreach (EodCacheVersion::ALL_DOMAINS as $domain) {
            $this->assertSame('new', Cache::get($versions->publicationKey($domain, 'SPY'))['version']);
        }
    }

    public function test_delayed_mirror_callback_reads_latest_committed_head(): void
    {
        $this->prepare();
        $repo = app(EodPublicationRepository::class);
        $versions = app(EodCacheVersion::class);
        $versions->publish(['SPY'], ['activity'], 'old', 200);
        $delayed = fn () => $repo->mirror(['SPY'], ['activity']);
        $versions->publish(['SPY'], ['activity'], 'new', 300);
        $delayed();
        $this->assertSame('new', Cache::get($versions->publicationKey('activity', 'SPY'))['version']);
    }

    public function test_mirror_failure_does_not_rollback_authority_and_retry_repairs_it(): void
    {
        $this->prepare();
        $cache = Cache::getFacadeRoot();
        Cache::shouldReceive('forever')->once()->andReturn(false);
        try {
            app(EodCacheVersion::class)->publish(['SPY'], ['activity'], 'committed', 200);
            $this->fail('Mirror error must be visible.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('mirror needs repair', $exception->getMessage());
            $this->assertSame('committed', DB::table('eod_cache_publications')->value('version'));
        } finally {
            Cache::swap($cache);
        }
        app(EodPublicationRepository::class)->mirror(['SPY']);
        $this->assertSame('committed', Cache::get(app(EodCacheVersion::class)->publicationKey('activity', 'SPY'))['version']);
    }

    public function test_missing_control_fails_closed_instead_of_using_a_stale_redis_head(): void
    {
        $this->prepare();
        Cache::forever(app(EodCacheVersion::class)->publicationKey('activity', 'SPY'), ['version' => 'stale']);
        DB::table('eod_cache_publication_state')->delete();
        $this->expectException(RuntimeException::class);
        app(EodCacheVersion::class)->current('activity', 'SPY');
    }

    public function test_rollback_mirror_preserves_keys_but_writers_stay_durable(): void
    {
        $this->prepare();
        $versions = app(EodCacheVersion::class);
        $versions->publish(['SPY'], ['activity'], 'v1', 200);
        $key = $versions->key('activity', 'activity', 'SPY', '100');
        Cache::put($key, ['premium' => 123], 3600);
        app(EodPublicationRepository::class)->mirror(['SPY']);
        config()->set('eod_publications.read_enabled', false);
        $this->assertSame($key, $versions->key('activity', 'activity', 'SPY', '100'));
        $this->assertSame(['premium' => 123], Cache::get($key));
        $versions->publish(['SPY'], ['activity'], 'v2', 300);
        $this->assertSame('v2', $versions->current('activity', 'SPY'));
        $this->assertSame('v2', DB::table('eod_cache_publications')->value('version'));
    }

    public function test_mirror_read_rollback_does_not_resurrect_initial_for_missing_heads(): void
    {
        $this->prepare();
        $versions = app(EodCacheVersion::class);
        $before = $versions->currentMany('activity', ['SPY', 'QQQ']);
        Cache::put('activity:SPY:initial:100', ['obsolete' => true], 3600);
        config()->set('eod_publications.read_enabled', false);
        $this->assertSame($before, $versions->currentMany('activity', ['SPY', 'QQQ']));
        $this->assertSame($before['SPY'], $versions->current('activity', 'SPY'));
        $this->assertNull(Cache::get($versions->key('activity', 'activity', 'SPY', '100')));
    }

    public function test_prepare_uses_a_newer_durable_gex_certificate_as_lower_bound(): void
    {
        config()->set('eod_snapshot_health.enabled', true);
        $health = app(EodSnapshotHealth::class);
        $health->complete($health->begin('SPY', 'synthetic-import'));
        $this->assertTrue($health->certify('SPY', 'certificate', 90));
        app(EodPublicationRepository::class)->prepare([$this->publicationHead('SPY', 'redis-old', 50)], 100);
        $this->enable();
        $this->assertSame('certificate', app(EodCacheVersion::class)->current('gex', 'SPY'));
    }

    public function test_generic_heads_and_eligible_certificate_rollback_together(): void
    {
        $this->prepare();
        config()->set('eod_snapshot_health.enabled', true);
        $health = app(EodSnapshotHealth::class);
        $health->complete($health->begin('SPY', 'synthetic-complete'));
        DB::beginTransaction();
        app(EodCacheVersion::class)->publish(['SPY'], EodCacheVersion::ALL_DOMAINS, 'pending', 200);
        $this->assertSame('pending', $health->head('SPY')['cache_version']);
        DB::rollBack();
        $this->assertNull($health->head('SPY'));
        $this->assertSame(0, DB::table('eod_cache_publications')->count());
        app(EodCacheVersion::class)->publish(['SPY'], EodCacheVersion::ALL_DOMAINS, 'committed', 300);
        $this->assertSame('committed', $health->head('SPY')['cache_version']);
        $this->assertSame(['committed'], DB::table('eod_cache_publications')->distinct()->pluck('version')->all());
    }

    public function test_active_or_failed_raw_work_never_gets_a_completed_certificate(): void
    {
        $this->prepare();
        config()->set('eod_snapshot_health.enabled', true);
        $health = app(EodSnapshotHealth::class);
        $mutation = $health->begin('SPY', 'synthetic-active');
        app(EodCacheVersion::class)->publish(['SPY'], ['gex'], 'active', 200);
        $this->assertNull($health->head('SPY'));
        $health->fail($mutation);
        app(EodCacheVersion::class)->publish(['SPY'], ['gex'], 'failed', 300);
        $this->assertNull($health->head('SPY'));
    }

    public function test_command_is_read_only_by_default_and_requires_explicit_prepare_attestation(): void
    {
        $this->artisan('gex:publications')->assertSuccessful();
        $this->assertSame(0, DB::table('eod_cache_publication_state')->count());
        $this->artisan('gex:publications', ['--prepare' => true])->assertFailed();
        $this->assertSame(0, DB::table('eod_cache_publication_state')->count());
    }

    public function test_prepare_command_rejects_a_moving_inventory_and_never_initializes(): void
    {
        $this->mock(EodLegacyPublicationInventory::class, function ($mock): void {
            $mock->shouldReceive('capture')->twice()->andReturn(
                ['heads' => [], 'count' => 0, 'sha256' => hash('sha256', 'first')],
                ['heads' => [], 'count' => 0, 'sha256' => hash('sha256', 'changed')]
            );
        });
        $this->artisan('gex:publications', ['--prepare' => true, '--writers-drained' => true])->assertFailed();
        $this->assertSame(0, DB::table('eod_cache_publication_state')->count());
    }

    public function test_prepare_command_imports_a_stable_inventory_once(): void
    {
        $this->mock(EodLegacyPublicationInventory::class, function ($mock): void {
            $mock->shouldReceive('capture')->twice()->andReturn([
                'heads' => [$this->publicationHead('SPY', 'imported', 50)], 'count' => 1, 'sha256' => hash('sha256', 'same'),
            ]);
        });
        $this->artisan('gex:publications', ['--prepare' => true, '--writers-drained' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('eod_cache_publications')->count());
    }

    public function test_database_failure_cannot_fall_back_to_a_stale_redis_version(): void
    {
        $this->prepare();
        Cache::forever(app(EodCacheVersion::class)->publicationKey('activity', 'SPY'), ['version' => 'stale']);
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if (str_contains($event->sql, 'eod_cache_publication_state')) {
                throw new RuntimeException('synthetic database failure');
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            app(EodCacheVersion::class)->current('activity', 'SPY');
            $this->fail('Database errors must remain visible, without a Redis fallback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic database failure', $exception->getMessage());
        } finally {
            $connection->setEventDispatcher($original);
        }
    }

    public function test_concurrent_processes_cannot_roll_a_committed_head_backwards(): void
    {
        $this->prepare();
        $db = config('database.connections.'.config('database.default'));
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_DATABASE' => $db['database'],
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        $processes = [];
        try {
            foreach ([200, 500, 300, 400] as $order) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/gex026-publication-worker.php'), (string) $order],
                    base_path(), $environment, timeout: 45);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
        $this->assertSame('synthetic-500', app(EodCacheVersion::class)->current('activity', 'SPY'));
        $this->assertSame(1, DB::table('eod_cache_publications')->count());
    }

    private function publicationHead(string $symbol, string $version, int $order, string $domain = 'gex'): array
    {
        return ['domain' => $domain, 'symbol' => $symbol, 'version' => $version, 'issued_at_microseconds' => $order];
    }

    private function prepare(): void
    {
        app(EodPublicationRepository::class)->prepare([], 100);
        $this->enable();
    }

    private function enable(): void
    {
        config()->set('eod_publications.write_enabled', true);
        config()->set('eod_publications.read_enabled', true);
    }
}
