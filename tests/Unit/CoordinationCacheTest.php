<?php

namespace Tests\Unit;

use App\Exceptions\ProviderDeferred;
use App\Http\Controllers\GexController;
use App\Http\Controllers\PositioningController;
use App\Jobs\ComputePositioningJob;
use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\Middleware\DeferProviderWork;
use App\Models\OptionChainData;
use App\Support\CalculatorRefreshState;
use App\Support\CoordinationCache;
use App\Support\EodSnapshotSelector;
use App\Support\ProviderRequestReplay;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** Executable cache routing tests. All stores/queries/transports are local fakes. */
class CoordinationCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-04 21:00:00', 'UTC'));
        config()->set('cache.default', 'array');
        config()->set('cache.coordination_enabled', false);
        config()->set('cache.stores.coordination', ['driver' => 'array', 'serialize' => false]);
        config()->set('provider_backpressure.enabled', false);
        config()->set('queue_lanes.isolated', false);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_disabled_selector_preserves_direct_facade_method_mocks(): void
    {
        Cache::shouldReceive('get')->once()->with('legacy-key')->andReturn(['legacy' => true]);
        Cache::shouldNotReceive('store');
        $this->assertSame(['legacy' => true], CoordinationCache::store()->get('legacy-key'));
    }

    public function test_enabled_store_uses_original_connection_and_unchanged_key_prefix(): void
    {
        config()->set('cache.coordination_enabled', true);
        config()->set('cache.prefix', 'existing-cache:');
        config()->set('cache.stores.coordination', [
            'driver' => 'redis', 'connection' => 'coordination', 'lock_connection' => 'default',
        ]);
        config()->set('cache.stores.redis', [
            'driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default',
        ]);
        $factory = Mockery::mock(Factory::class);
        $original = Mockery::mock(RedisConnection::class);
        $payload = Mockery::mock(RedisConnection::class);
        $locks = Mockery::mock(RedisConnection::class);
        $factory->shouldReceive('connection')->with('coordination')->twice()->andReturn($original);
        $factory->shouldReceive('connection')->with('cache')->once()->andReturn($payload);
        $factory->shouldReceive('connection')->with('default')->twice()->andReturn($locks);
        $facts = $this->gammaFacts();
        $original->shouldReceive('get')->with('existing-cache:gamma_strength:SPY:2026-09-04')
            ->once()->andReturn(serialize($facts));
        $this->app->instance('redis', $factory);

        $store = CoordinationCache::store();
        $this->assertSame($original, $store->getStore()->connection());
        $this->assertSame($payload, Cache::store('redis')->getStore()->connection());
        $this->assertSame($locks, $store->getStore()->lockConnection());
        $this->assertSame($locks, Cache::store('redis')->getStore()->lockConnection());
        $this->assertSame('existing-cache:', $store->getStore()->getPrefix());
        $this->assertSame($facts, $store->get('gamma_strength:SPY:2026-09-04'));
    }

    public function test_enabled_store_failure_never_falls_back_to_payload_state(): void
    {
        config()->set('cache.coordination_enabled', true);
        Cache::shouldReceive('store')->once()->with('coordination')
            ->andThrow(new RuntimeException('fixture coordination unavailable'));
        Cache::shouldNotReceive('get');
        $this->expectException(RuntimeException::class);
        CoordinationCache::store()->get('gamma_strength:SPY:2026-09-04');
    }

    public function test_legacy_claim_generation_survives_payload_clear_and_still_coalesces(): void
    {
        config()->set('cache.coordination_enabled', true);
        $states = new CalculatorRefreshState;
        $at = now()->toImmutable();
        $token = $states->claim('SPY', 'original-generation', 'calculator', $at);
        $this->assertNotNull($token);
        $this->assertSame(CalculatorRefreshState::STATUS_PENDING, $states->get('SPY')['status']);
        Cache::put('disposable-payload', ['old' => true], 60);
        Cache::flush(); // This is the per-test in-memory payload store only.
        $this->assertNull(Cache::get('disposable-payload'));
        $this->assertSame($token, $states->many(['SPY'])['SPY']['claim_token']);
        $this->assertNull($states->claim('SPY', 'duplicate-generation', 'calculator', $at));
        $this->assertTrue($states->markStarted('SPY', 'original-generation', $token, 1, $at));
        $this->assertTrue($states->markCompleted('SPY', 'original-generation', $token, $at));
        $this->assertSame(CalculatorRefreshState::STATUS_COMPLETED, $states->get('SPY')['status']);
    }

    public function test_provider_retry_budget_survives_payload_clear_and_terminalizes_on_third_failure(): void
    {
        config()->set('cache.coordination_enabled', true);
        config()->set('provider_backpressure.enabled', true);
        config()->set('provider_backpressure.replay.enabled', false);
        $job = new FetchCalculatorChainJob('SPY');
        $transport = Mockery::mock(Job::class);
        $transport->shouldReceive('uuid')->andReturn('fixture-coordination-retry');
        $transport->shouldReceive('attempts')->andReturn(1);
        $transport->shouldReceive('release')->twice()->with(60);
        $transport->shouldReceive('fail')->once()->with(Mockery::type(ProviderDeferred::class));
        $job->setJob($transport);
        Log::shouldReceive('channel')->with('queue_monitor')->andReturnSelf();
        Log::shouldReceive('info')->times(3);
        $key = 'provider-backpressure:failures:'.hash('sha256', 'queue:fixture-coordination-retry');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            (new DeferProviderWork)->handle($job, function (): void {
                app(ProviderRequestReplay::class)->recordPhysicalRequest();
                throw new ProviderDeferred(ProviderDeferred::RATE_LIMITED, now()->toImmutable()->addMinute(), 429);
            });
            Cache::flush();
            $this->assertSame($attempt, CoordinationCache::store()->get($key));
            $this->assertNull(Cache::get($key));
        }
    }

    public function test_framework_rate_limiter_retains_counter_after_payload_clear(): void
    {
        config()->set('cache.coordination_enabled', true);
        config()->set('cache.limiter', 'coordination');
        $this->app->forgetInstance(RateLimiter::class);
        $limiter = app(RateLimiter::class);
        $limiter->hit('fixture-admission', 60);
        Cache::flush();
        $this->assertTrue($limiter->tooManyAttempts('fixture-admission', 1));
        $this->assertSame(1, CoordinationCache::store()->get('fixture-admission'));
        $this->assertNull(Cache::get('fixture-admission'));
    }

    public function test_worker_restart_signal_stays_on_default_cache_for_explicit_supervisor_cutover(): void
    {
        config()->set('cache.coordination_enabled', true);
        $this->artisan('queue:restart')->assertSuccessful();
        $this->assertSame(now()->getTimestamp(), Cache::get('illuminate:queue:restart'));
        $this->assertNull(CoordinationCache::store()->get('illuminate:queue:restart'));
    }

    public function test_positioning_writer_keeps_exact_gamma_fields_outside_payload_cache(): void
    {
        config()->set('cache.coordination_enabled', true);
        $selector = Mockery::mock(EodSnapshotSelector::class);
        $selector->shouldReceive('resolvedAnchorDate')->once()->with('2026-09-04')->andReturn('2026-09-04');
        $this->app->instance(EodSnapshotSelector::class, $selector);
        $job = Mockery::mock(ComputePositioningJob::class, [['SPY'], '2026-09-04'])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('dexExpiryMap')->once()->andReturn(collect(['2026-09-18' => 1]));
        $job->shouldReceive('forwardExpiryMap')->once()->andReturn(collect(['2026-09-18' => 1]));
        $job->shouldReceive('selectedChainContext')->twice()->andReturn([
            collect([1 => (object) ['max_date' => '2026-09-04']]),
            collect([
                (object) ['expiration_id' => 1, 'open_interest' => 10, 'delta' => 0.5, 'gamma' => 0.01, 'underlying_price' => 100],
                (object) ['expiration_id' => 1, 'open_interest' => 5, 'delta' => -0.5, 'gamma' => -0.01, 'underlying_price' => 100],
            ]),
        ]);
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('table')->twice()->with('dex_by_expiry')->andReturn($query);
        $query->shouldReceive('where')->with('symbol', 'SPY')->once()->andReturnSelf();
        $query->shouldReceive('where')->with('data_date', '2026-09-04')->once()->andReturnSelf();
        $query->shouldReceive('delete')->once()->andReturn(0);
        $query->shouldReceive('insert')->once()->with(Mockery::on(fn ($rows): bool => $rows[0]['dex_total'] === 250.0 && $rows[0]['source_chain_date'] === '2026-09-04'))->andReturnTrue();

        $job->handle();
        $expected = [
            'date' => '2026-09-04', 'strength' => 1 / 3, 'sign' => 1,
            'source_meta' => ['anchor_date' => '2026-09-04', 'selected_snapshot_dates' => [1 => '2026-09-04']],
        ];
        Cache::flush();
        $this->assertSame($expected, CoordinationCache::store()->get('gamma_strength:SPY:2026-09-04'));
        $this->assertNull(Cache::get('gamma_strength:SPY:2026-09-04'));
    }

    public function test_positioning_endpoint_reads_original_gamma_fields_after_payload_clear(): void
    {
        config()->set('cache.coordination_enabled', true);
        CoordinationCache::store()->put('gamma_strength:SPY:2026-09-04', $this->gammaFacts(), 3600);
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->andReturn($query);
        DB::shouldReceive('query')->andReturn($query);
        DB::shouldReceive('raw')->andReturnUsing(fn ($value) => new Expression($value));
        foreach (['where', 'whereDate', 'select', 'join', 'whereBetween', 'distinct', 'union', 'fromSub', 'rightJoinSub', 'orderBy'] as $method) {
            $query->shouldReceive($method)->andReturnSelf();
        }
        $query->shouldReceive('max')->once()->with('data_date')->andReturn('2026-09-04');
        $query->shouldReceive('get')->once()->andReturn(collect());
        $query->shouldReceive('sum')->once()->with('dex_total')->andReturn(250);
        $controller = Mockery::mock(PositioningController::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('hasUsableDex')->once()->andReturnTrue();
        Cache::flush();
        $payload = $controller->dex(Request::create('/positioning', 'GET', ['symbol' => 'SPY']))->getData(true);
        $this->assertGammaFacts($payload);
    }

    public function test_gex_cold_builder_reads_original_gamma_fields_after_payload_clear(): void
    {
        config()->set('cache.coordination_enabled', true);
        CoordinationCache::store()->put('gamma_strength:SPY:2026-09-04', $this->gammaFacts(), 3600);
        $originalResolver = Model::getConnectionResolver();
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $connection = Mockery::mock(Connection::class);
        $query = Mockery::mock(Builder::class);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);
        $connection->shouldReceive('query')->andReturn($query);
        foreach (['from', 'whereIn', 'where'] as $method) {
            $query->shouldReceive($method)->andReturnSelf();
        }
        $query->shouldReceive('max')->with('data_date')->twice()->andReturnNull();
        $selector = Mockery::mock(EodSnapshotSelector::class);
        $rows = [];
        foreach (['call', 'put'] as $side) {
            $row = new OptionChainData;
            $row->setRawAttributes([
                'expiration_id' => 1, 'data_date' => '2026-09-04', 'strike' => '100.00',
                'option_type' => $side, 'open_interest' => 10, 'volume' => 2,
                'gamma' => '0.010000', 'underlying_price' => '100.000000',
            ]);
            $rows[] = $row;
        }
        $selector->shouldReceive('selectedRows')->once()
            ->with([1], ['option_chain_data.*'], '2026-09-04')->andReturn(new Collection($rows));
        $this->app->instance(EodSnapshotSelector::class, $selector);
        try {
            Model::setConnectionResolver($resolver);
            Cache::flush();
            $payload = (new ReflectionMethod(GexController::class, 'buildGexPayload'))->invoke(
                new GexController, 'SPY', '30d', ['2026-09-18'],
                ['30d' => ['2026-09-18']], [1], '2026-09-04',
            );
            $this->assertGammaFacts($payload);
            $this->assertCount(1, $payload['strike_data']);
        } finally {
            Model::setConnectionResolver($originalResolver);
        }
    }

    private function gammaFacts(): array
    {
        return ['strength' => 0.75, 'sign' => -1, 'source_meta' => ['selected_snapshot_dates' => [1 => '2026-09-04']]];
    }

    private function assertGammaFacts(array $payload): void
    {
        $this->assertSame($this->gammaFacts()['strength'], $payload['regime_strength']);
        $this->assertSame($this->gammaFacts()['sign'], $payload['gamma_sign']);
        $this->assertSame($this->gammaFacts()['source_meta'], $payload['regime_source_meta']);
    }
}
