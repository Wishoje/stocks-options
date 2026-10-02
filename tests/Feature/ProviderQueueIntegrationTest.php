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

    public function test_an_old_pending_calculator_refresh_dispatches_once_when_transport_is_empty(): void
    {
        $run = app(WorkRunCoordinator::class)->claim('calculator_refresh', 'SPY', ['expiry' => null],
            'calculator-fill-heavy', at: now('UTC')->toImmutable()->subHour())['run'];
        // Production telemetry: an old durable intent, but no queued deliveries.
        Cache::put('provider-backpressure:queue-sample:v2', [[
            'queue' => $run->queue, 'interactive' => false, 'ready' => 0,
            'ready_head_age_seconds' => null, 'oldest_due_intent_age_seconds' => 3600,
        ]], 2);
        $this->app->instance(ScheduledFillBackpressure::class, new ScheduledFillBackpressure);
        $dispatcher = app(WorkRunDispatcher::class);
        $this->assertTrue($dispatcher->dispatch($run));
        $this->assertFalse($dispatcher->dispatch($run->fresh()));
        $this->assertSame(1, $run->fresh()->dispatch_attempts);
        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 1);
        Bus::assertDispatched(FetchCalculatorChainJob::class, fn ($job): bool => $job->workRunId === $run->id && $job->expiry === null);
        Http::assertNothingSent();
    }

    public function test_pending_live_quotes_defer_calculator_admission_without_delaying_the_quote(): void
    {
        $runs = app(WorkRunCoordinator::class);
        $calculator = $runs->claim('calculator_refresh', 'SPY', ['expiry' => null], 'calculator-fill-heavy')['run'];
        $quote = $runs->claim('quote_refresh', 'SPY', ['session_date' => '2026-09-08', 'phase' => 'regular'], 'quotes')['run'];
        Cache::put('provider-backpressure:queue-sample:v2', [[
            'queue' => 'quotes', 'interactive' => false, 'market_data' => true, 'ready' => 0,
            'reserved' => 0, 'oldest_due_intent_age_seconds' => 0,
        ]], 2);
        $this->app->instance(ScheduledFillBackpressure::class, new ScheduledFillBackpressure);
        $dispatcher = app(WorkRunDispatcher::class);
        $this->assertFalse($dispatcher->dispatch($calculator));
        $this->assertTrue($dispatcher->dispatch($quote));
        $this->assertSame(0, $calculator->fresh()->dispatch_attempts);
        Bus::assertNotDispatched(FetchCalculatorChainJob::class);
        $this->travel(21)->seconds();
        Cache::put('provider-backpressure:queue-sample:v2', [], 2);
        $this->assertTrue($dispatcher->dispatch($calculator->fresh()));
        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_an_already_queued_background_calculator_yields_before_http_when_intraday_work_is_busy(): void
    {
        Cache::put('provider-backpressure:queue-sample:v2', [[
            'queue' => 'intraday-heavy', 'interactive' => false, 'market_data' => true, 'ready' => 0,
            'reserved' => 1, 'oldest_due_intent_age_seconds' => null,
        ]], 2);
        $this->app->instance(ScheduledFillBackpressure::class, new ScheduledFillBackpressure);
        try {
            (new FetchCalculatorChainJob('SPY'))->onQueue('calculator-fill-heavy')->handle();
            $this->fail('Background calculator work must yield before fetching a catalog.');
        } catch (ProviderDeferred $exception) {
            $this->assertSame(ProviderDeferred::BACKPRESSURE, $exception->reason);
        }
        Http::assertNothingSent();
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

    public function test_bounded_scan_prioritizes_live_and_interactive_work_before_older_background_calculators(): void
    {
        $runs = app(WorkRunCoordinator::class);
        $at = now('UTC')->toImmutable();
        $fill = $runs->claim('calculator_refresh', 'SPY', [], 'calculator-fill', at: $at->subHour(), applyAdmissionLimits: false)['run'];
        $quote = $runs->claim('quote_refresh', 'SPY', [], 'quotes', at: $at->subMinutes(3), applyAdmissionLimits: false)['run'];
        $interactive = $runs->claim('calculator_refresh', 'QQQ', [], 'calculator-interactive', at: $at->subMinutes(2), applyAdmissionLimits: false)['run'];
        $intraday = $runs->claim('intraday_refresh', 'SPY', [], 'intraday-heavy', at: $at->subMinute(), applyAdmissionLimits: false)['run'];

        $this->assertSame([$quote->id, $interactive->id, $intraday->id], $runs->dispatchable(3, $at)->modelKeys());
        $this->assertSame([$quote->id, $interactive->id, $intraday->id, $fill->id], $runs->dispatchable(4, $at)->modelKeys());
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
                app(ProviderRequestReplay::class)->recordPhysicalRequest();
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
