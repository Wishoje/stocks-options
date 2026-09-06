<?php

namespace Tests\Feature;

use App\Exceptions\ProviderDeferred;
use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\Middleware\DeferProviderWork;
use App\Models\WorkRun;
use App\Support\CalculatorExecutionFreshness;
use App\Support\CalculatorPrimeScheduler;
use App\Support\CalculatorPublicationRepository;
use App\Support\ProviderRequestReplay;
use App\Support\ScheduledFillBackpressure;
use App\Support\WorkRunCoordinator;
use App\Support\WorkRunDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\MySqlTestCase;

class ProviderQueueIntegrationTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 16:00:00', 'UTC'));
        config()->set('provider_backpressure.enabled', true);
        config()->set('provider_backpressure.replay.enabled', false);
        config()->set('cache.default', 'array');
        config()->set('queue_lanes.isolated', false);
        config()->set('services.massive.concurrency.enabled', false);
        config()->set('calculator.scheduler.fallback_symbols', ['SPY', 'QQQ']);
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 1000);
        config()->set('work_runs.rate_limits.accepted_symbol_per_minute', 1000);
        Cache::flush();
        Bus::fake();
        Http::preventStrayRequests();
        $this->pressure(false);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_repeated_scheduler_buckets_share_durable_symbol_intent(): void
    {
        $scheduler = app(CalculatorPrimeScheduler::class);
        $first = $scheduler->dispatchDue();
        $this->assertCount(2, $first['dispatched']);
        $this->travel(5)->minutes();
        $second = $scheduler->dispatchDue();
        $this->assertCount(2, $second['coalesced']);
        $this->assertSame([], $second['dispatched']);
        $this->assertDatabaseCount('work_runs', 2);
        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 2);
        Bus::assertDispatched(FetchCalculatorChainJob::class, fn ($job): bool => $job->workRunId !== null && $job->schedulerGeneration === null);
    }

    public function test_pressure_preserves_undispatched_intent_then_recovers_when_clear(): void
    {
        $this->pressure(true);
        $result = app(CalculatorPrimeScheduler::class)->dispatchDue();
        $this->assertCount(2, $result['deferred']);
        Bus::assertNothingDispatched();
        $this->assertSame(0, (int) DB::table('work_runs')->sum('dispatch_attempts'));
        Cache::flush();
        $this->pressure(false);
        $this->travel(21)->seconds();
        foreach (WorkRun::all() as $run) {
            $this->assertTrue(app(WorkRunDispatcher::class)->dispatch($run));
        }
        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 2);
        $this->assertDatabaseCount('work_runs', 2);
    }

    public function test_execution_reuses_fresh_complete_catalog_without_provider_or_new_publication(): void
    {
        $this->publishCatalog('SPY', now('UTC')->toImmutable()->subMinute());
        $this->assertTrue(app(CalculatorExecutionFreshness::class)->isFresh('SPY'));
        $this->assertTrue(app(CalculatorExecutionFreshness::class)->isFresh('SPY', '2026-09-18'));
        $before = DB::table('calculator_publication_runs')->count();
        (new FetchCalculatorChainJob('SPY'))->handle();
        $this->assertSame($before, DB::table('calculator_publication_runs')->count());
        $result = app(CalculatorPrimeScheduler::class)->dispatchDue();
        $this->assertSame(['QQQ'], $result['dispatched']);
        $this->travel(9)->minutes();
        $this->assertFalse(app(CalculatorExecutionFreshness::class)->isFresh('SPY'));
    }

    public function test_middleware_persists_durable_wait_without_releasing_old_token_payload(): void
    {
        $runs = app(WorkRunCoordinator::class);
        $run = $runs->claim('intraday_refresh', 'SPY', ['trade_date' => '2026-09-08'], 'intraday')['run'];
        $reservation = $runs->reserveDispatch($run->id);
        $token = $reservation['delivery_token'];
        $runs->markDispatched($run->id, $token);
        $job = new FetchPolygonIntradayOptionsJob(['SPY'], tradeDate: '2026-09-08', workRunId: $run->id, workRunDeliveryToken: $token);
        $transport = Mockery::mock(Job::class);
        $transport->shouldReceive('attempts')->andReturn(1);
        $transport->shouldNotReceive('release');
        $job->setJob($transport);
        $deadline = now('UTC')->toImmutable()->addMinutes(2);
        (new DeferProviderWork)->handle($job, function () use ($runs, $run, $token, $deadline): void {
            $this->assertTrue($runs->markStarted($run->id, $token, 1));
            throw new ProviderDeferred(ProviderDeferred::CAPACITY, $deadline);
        });
        $run->refresh();
        $this->assertSame('pending', $run->status);
        $this->assertNull($run->delivery_token);
        $this->assertSame(1, $run->provider_admission_deferrals);
        $this->assertTrue($run->next_dispatch_at->equalTo($deadline));
        $this->assertFalse($runs->markCompleted($run->id, $token, 1));
    }

    public function test_legacy_capacity_waits_do_not_consume_provider_failure_budget(): void
    {
        $job = new FetchCalculatorChainJob('SPY');
        $transport = Mockery::mock(Job::class);
        $transport->shouldReceive('uuid')->andReturn('fixture-legacy');
        $transport->shouldReceive('attempts')->andReturn(1);
        $transport->shouldReceive('release')->times(5)->with(60);
        $transport->shouldNotReceive('fail');
        $job->setJob($transport);
        $this->assertNotNull($job->retryUntil());
        for ($i = 0; $i < 5; $i++) {
            (new DeferProviderWork)->handle($job, function (): void {
                throw new ProviderDeferred(ProviderDeferred::COOLDOWN, now('UTC')->toImmutable()->addMinute());
            });
        }
        $this->assertSame(3, $job->maxExceptions);
        Bus::assertNothingDispatched();
    }

    public function test_three_real_provider_failures_terminalize_legacy_work(): void
    {
        $job = new FetchCalculatorChainJob('SPY');
        $transport = Mockery::mock(Job::class);
        $transport->shouldReceive('uuid')->andReturn('fixture-failures');
        $transport->shouldReceive('attempts')->andReturn(1);
        $transport->shouldReceive('release')->twice()->with(60);
        $transport->shouldReceive('fail')->once()->with(Mockery::type(ProviderDeferred::class));
        $job->setJob($transport);
        for ($i = 0; $i < 3; $i++) {
            (new DeferProviderWork)->handle($job, function (): void {
                app(ProviderRequestReplay::class)->recordPhysicalRequest();
                throw new ProviderDeferred(ProviderDeferred::RATE_LIMITED, now('UTC')->toImmutable()->addMinute(), 429);
            });
        }
        Bus::assertNothingDispatched();
    }

    private function pressure(bool $blocked): void
    {
        $mock = Mockery::mock(ScheduledFillBackpressure::class);
        $mock->shouldReceive('deferral')->andReturnUsing(fn (): ?ProviderDeferred => $blocked
            ? new ProviderDeferred(ProviderDeferred::BACKPRESSURE, now('UTC')->toImmutable()->addSeconds(20)) : null);
        $this->app->instance(ScheduledFillBackpressure::class, $mock);
    }

    private function publishCatalog(string $symbol, CarbonImmutable $at): void
    {
        $repository = app(CalculatorPublicationRepository::class);
        $run = $repository->startCatalogRun($symbol, ownerKey: 'fixture:'.$symbol, at: $at);
        $repository->freezeCatalog($run['id'], ['2026-09-18'], 'massive-contracts', $at, true, '2026-12-18', at: $at);
        $repository->stageAndPublishExpiry($run['id'], '2026-09-18', 'massive-snapshot', $at, $at, [[
            'ticker' => 'O:SPY260918C00600000', 'type' => 'call', 'strike' => 600,
            'bid' => 1.0, 'ask' => 1.2, 'mid' => 1.1, 'implied_volatility' => 0.2,
        ]], $at);
        $this->assertTrue($repository->completeCatalog($run['id'], $at)['advanced']);
    }
}
