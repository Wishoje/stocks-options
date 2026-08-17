<?php

namespace Tests\Feature;

use App\Exceptions\WorkRunRateLimited;
use App\Jobs\BootstrapUserSymbolJob;
use App\Jobs\CompleteWorkRunJob;
use App\Jobs\ConfirmWorkRunOrchestrationJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\Middleware\EnsureWorkRunOrchestrationCurrent;
use App\Jobs\QueueSymbolEnrichmentJob;
use App\Models\WorkRun;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class WorkRunCoordinatorTest extends TestCase
{
    private WorkRunCoordinator $runs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSqliteTables();
        DB::table('work_run_slots')->delete();
        DB::table('work_runs')->delete();
        Cache::flush();
        RateLimiter::clear('accepted-work:provider:massive');

        config()->set('queue.default', 'redis');
        config()->set('work_runs.pending_ttl_seconds', 3600);
        config()->set('work_runs.running_ttl_seconds.calculator_refresh', 300);
        config()->set('work_runs.failure_cooldown_seconds', 300);
        config()->set('work_runs.dispatch_reservation_seconds', 30);
        config()->set('work_runs.rate_limits.accepted_symbol_per_minute', 1000);
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 1000);

        $this->runs = app(WorkRunCoordinator::class);
    }

    public function test_identical_claims_share_one_run_and_generation(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');

        $first = $this->runs->claim(
            'calculator_refresh',
            'spy',
            ['expiry' => '2026-08-21'],
            'calculator-interactive',
            at: $at
        );
        $second = $this->runs->claim(
            'calculator_refresh',
            'SPY',
            ['expiry' => '2026-08-21'],
            'calculator-interactive',
            at: $at->addSecond()
        );

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['run']->id, $second['run']->id);
        $this->assertSame(1, $second['run']->generation);
        $this->assertSame(1, WorkRun::query()->count());
    }

    public function test_reconciler_safely_skips_during_the_additive_schema_rollout_window(): void
    {
        Schema::partialMock()
            ->shouldReceive('hasTable')
            ->once()
            ->with('work_runs')
            ->andReturnFalse();

        $this->assertSame(0, Artisan::call('work-runs:reconcile'));
        $this->assertStringContainsString(
            'work_run_schema_unavailable',
            Artisan::output()
        );
    }

    public function test_coalescing_bypasses_admission_but_a_new_scope_is_rejected_without_partial_rows(): void
    {
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 1);
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');

        $first = $this->claimResult($at);
        $same = $this->claimResult($at->addSecond());

        $this->assertTrue($first['created']);
        $this->assertFalse($same['created']);
        $this->assertSame($first['run']->id, $same['run']->id);

        try {
            $this->runs->claim(
                'calculator_refresh',
                'QQQ',
                ['expiry' => '2026-08-21'],
                'calculator-interactive',
                at: $at->addSeconds(2)
            );
            $this->fail('A second provider run should have been rate limited.');
        } catch (WorkRunRateLimited $exception) {
            $this->assertGreaterThanOrEqual(1, $exception->retryAfterSeconds);
        }

        $this->assertSame(1, DB::table('work_runs')->count());
        $this->assertSame(1, DB::table('work_run_slots')->count());
    }

    public function test_admission_limited_durable_run_is_deferred_then_dispatched_by_the_reconciler(): void
    {
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 1);
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $this->travelTo($at);
        $this->claimResult($at);
        Bus::fake();

        $claim = $this->runs->claim(
            'symbol_bootstrap',
            'MSFT',
            [],
            'bootstrap-fast',
            at: $at->addSecond(),
            deferWhenRateLimited: true
        );

        $this->assertTrue($claim['created']);
        $this->assertTrue($claim['deferred']);
        $this->assertSame(WorkRun::STATUS_PENDING, $claim['run']->status);
        $this->assertTrue($claim['run']->next_dispatch_at->isAfter($at));
        $this->assertSame('admission_deferred', $claim['run']->error_category);
        Bus::assertNothingDispatched();

        $this->travelTo($claim['run']->next_dispatch_at->addSecond());
        $this->assertSame(0, Artisan::call('work-runs:reconcile', ['--limit' => 100]));

        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 1);
        Bus::assertDispatched(
            BootstrapUserSymbolJob::class,
            fn (BootstrapUserSymbolJob $job): bool => $job->workRunId === $claim['run']->id
                && $job->workRunDeliveryToken !== null
        );
        $this->assertNotNull($claim['run']->fresh()->dispatched_at);
    }

    public function test_expired_pending_delivery_is_redispatched_with_a_new_token_on_the_same_run(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claim($at);
        $first = $this->runs->reserveDispatch($run->id, $at);

        $this->assertNotNull($first);
        $this->assertTrue($this->runs->markDispatched($run->id, $first['delivery_token'], $at));
        $this->assertNull($this->runs->reserveDispatch($run->id, $at->addMinutes(59)));

        $second = $this->runs->reserveDispatch($run->id, $at->addHour());

        $this->assertNotNull($second);
        $this->assertSame($run->id, $second['run']->id);
        $this->assertSame(1, $second['run']->generation);
        $this->assertNotSame($first['delivery_token'], $second['delivery_token']);
        $this->assertSame(2, $second['run']->dispatch_attempts);
    }

    public function test_newer_attempt_fences_stale_start_and_terminal_transitions(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claim($at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $token = $reservation['delivery_token'];

        $this->assertTrue($this->runs->markDispatched($run->id, $token, $at));
        $this->assertTrue($this->runs->markStarted($run->id, $token, 1, $at->addSecond()));
        $this->assertFalse($this->runs->markStarted($run->id, $token, 1, $at->addSeconds(2)));
        $this->assertTrue($this->runs->markStarted($run->id, $token, 2, $at->addSeconds(3)));
        $this->assertFalse($this->runs->markCompleted($run->id, $token, 1, $at->addSeconds(4)));
        $this->assertTrue($this->runs->markCompleted($run->id, $token, 2, $at->addSeconds(5)));
        $this->assertFalse($this->runs->markFailed(
            $run->id,
            $token,
            2,
            'unexpected',
            'late_failure',
            $at->addSeconds(6)
        ));
        $this->assertSame(WorkRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_newer_attempt_fences_the_previous_orchestration_token(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claim($at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $deliveryToken = $reservation['delivery_token'];
        $this->runs->markDispatched($run->id, $deliveryToken, $at);
        $this->runs->markStarted($run->id, $deliveryToken, 1, $at);

        $first = $this->runs->reserveOrchestration($run->id, $deliveryToken, 1, $at);
        $this->assertNotNull($first);
        $this->assertTrue($this->runs->isOrchestrationCurrent($run->id, $deliveryToken, 1, $first));
        $this->assertNull($this->runs->reserveOrchestration(
            $run->id,
            $deliveryToken,
            1,
            $at->addSeconds(29)
        ));

        $this->assertTrue($this->runs->markStarted($run->id, $deliveryToken, 2, $at->addSeconds(30)));
        $this->assertFalse($this->runs->isOrchestrationCurrent($run->id, $deliveryToken, 1, $first));

        $second = $this->runs->reserveOrchestration($run->id, $deliveryToken, 2, $at->addSeconds(30));
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertTrue($this->runs->isOrchestrationCurrent($run->id, $deliveryToken, 2, $second));

        $this->assertTrue($this->runs->markCompleted($run->id, $deliveryToken, 2, $at->addSeconds(31)));
        $this->assertFalse($this->runs->isOrchestrationCurrent($run->id, $deliveryToken, 2, $second));
    }

    public function test_confirmed_orchestration_short_circuits_root_retry_and_cannot_be_re_reserved(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claim($at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $deliveryToken = $reservation['delivery_token'];
        $this->runs->markDispatched($run->id, $deliveryToken, $at);
        $this->runs->markStarted($run->id, $deliveryToken, 1, $at);
        $orchestrationToken = $this->runs->reserveOrchestration($run->id, $deliveryToken, 1, $at);

        $this->assertTrue($this->runs->markOrchestrationDispatched(
            $run->id,
            $deliveryToken,
            1,
            $orchestrationToken,
            $at->addSecond()
        ));
        $this->assertTrue($this->runs->hasDispatchedOrchestration($run->id, $deliveryToken));
        $this->assertNull($this->runs->reserveOrchestration(
            $run->id,
            $deliveryToken,
            1,
            $at->addMinutes(10)
        ));
        $this->assertTrue($this->runs->isOrchestrationCurrent(
            $run->id,
            $deliveryToken,
            1,
            $orchestrationToken
        ));
    }

    public function test_confirmed_orchestration_cannot_be_fenced_by_a_racing_root_retry(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claimBootstrap($at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $deliveryToken = $reservation['delivery_token'];
        $this->runs->markDispatched($run->id, $deliveryToken, $at);
        $this->runs->markStarted($run->id, $deliveryToken, 1, $at);
        $orchestrationToken = $this->runs->reserveOrchestration($run->id, $deliveryToken, 1, $at);
        $this->runs->markOrchestrationDispatched(
            $run->id,
            $deliveryToken,
            1,
            $orchestrationToken,
            $at->addSecond()
        );

        // Models confirmation winning immediately after a retry's optimistic
        // hasDispatchedOrchestration() read but before its start transition.
        $this->assertFalse($this->runs->markStarted(
            $run->id,
            $deliveryToken,
            2,
            $at->addSeconds(2)
        ));
        $this->assertSame(1, $run->fresh()->attempt);
        $this->assertTrue($this->runs->isOrchestrationCurrent(
            $run->id,
            $deliveryToken,
            1,
            $orchestrationToken
        ));
    }

    public function test_failed_orchestration_push_releases_reservation_for_immediate_retry(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claim($at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $deliveryToken = $reservation['delivery_token'];
        $this->runs->markDispatched($run->id, $deliveryToken, $at);
        $this->runs->markStarted($run->id, $deliveryToken, 1, $at);
        $first = $this->runs->reserveOrchestration($run->id, $deliveryToken, 1, $at);

        $this->assertTrue($this->runs->markOrchestrationDispatchFailed(
            $run->id,
            $deliveryToken,
            1,
            $first
        ));
        $this->assertFalse($this->runs->isOrchestrationCurrent($run->id, $deliveryToken, 1, $first));

        $second = $this->runs->reserveOrchestration($run->id, $deliveryToken, 1, $at->addSecond());
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertTrue($this->runs->isOrchestrationCurrent($run->id, $deliveryToken, 1, $second));
    }

    public function test_bootstrap_releases_orchestration_reservation_when_chain_push_fails(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claimBootstrap($at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $deliveryToken = $reservation['delivery_token'];
        $this->runs->markDispatched($run->id, $deliveryToken, $at);

        $pendingChain = Mockery::mock();
        $pendingChain->shouldReceive('catch')->once()->andReturnSelf();
        $pendingChain->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('redis unavailable'));
        Bus::shouldReceive('chain')->once()->andReturn($pendingChain);

        $job = new BootstrapUserSymbolJob('SPY', 'api_prime', $run->id, $deliveryToken);

        try {
            $job->handle();
            $this->fail('The simulated chain push should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('redis unavailable', $exception->getMessage());
        }

        $fresh = $run->fresh();
        $this->assertSame(WorkRun::STATUS_RUNNING, $fresh->status);
        $this->assertNull($fresh->orchestration_token);
        $this->assertNull($fresh->orchestration_reserved_at);
        $this->assertNull($fresh->orchestration_dispatched_at);
        $this->assertNotNull($this->runs->reserveOrchestration(
            $run->id,
            $deliveryToken,
            1,
            $at->addSecond()
        ));
    }

    public function test_bootstrap_redelivery_recovers_a_crash_before_the_chain_push(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claimBootstrap($at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $deliveryToken = $reservation['delivery_token'];
        $this->runs->markDispatched($run->id, $deliveryToken, $at);
        $this->runs->markStarted($run->id, $deliveryToken, 1, $at);
        $abandonedToken = $this->runs->reserveOrchestration($run->id, $deliveryToken, 1, $at);

        $this->travelTo($at->addSeconds(30));
        Bus::fake();

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->zeroOrMoreTimes()->andReturn(2);
        $job = new BootstrapUserSymbolJob('SPY', 'api_prime', $run->id, $deliveryToken);
        $job->setJob($queueJob);
        $job->handle();

        Bus::assertDispatchedTimes(ConfirmWorkRunOrchestrationJob::class, 1);
        /** @var ConfirmWorkRunOrchestrationJob $confirmation */
        $confirmation = Bus::dispatched(ConfirmWorkRunOrchestrationJob::class)->first();
        $fresh = $run->fresh();

        $this->assertSame(2, $fresh->attempt);
        $this->assertSame(2, $fresh->orchestration_attempt);
        $this->assertNotSame($abandonedToken, $confirmation->orchestrationToken);
        $this->assertSame($fresh->orchestration_token, $confirmation->orchestrationToken);
        $this->assertFalse($this->runs->isOrchestrationCurrent(
            $run->id,
            $deliveryToken,
            1,
            $abandonedToken
        ));
    }

    public function test_confirmed_bootstrap_chain_short_circuits_redelivered_root_job(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claimBootstrap($at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $deliveryToken = $reservation['delivery_token'];
        $this->runs->markDispatched($run->id, $deliveryToken, $at);
        Bus::fake();

        $job = new BootstrapUserSymbolJob('SPY', 'api_prime', $run->id, $deliveryToken);
        $job->handle();

        Bus::assertDispatchedTimes(ConfirmWorkRunOrchestrationJob::class, 1);
        /** @var ConfirmWorkRunOrchestrationJob $confirmation */
        $confirmation = Bus::dispatched(ConfirmWorkRunOrchestrationJob::class)->first();
        $rootChain = collect($confirmation->chained)
            ->map(static fn (string $serialized): object => unserialize($serialized))
            ->values();
        $enrichment = $rootChain->last();

        $this->assertFalse($rootChain->contains(
            static fn (object $child): bool => $child instanceof CompleteWorkRunJob
        ));
        $this->assertInstanceOf(QueueSymbolEnrichmentJob::class, $enrichment);
        $this->assertSame($run->id, $enrichment->workRunId);
        $this->assertSame($deliveryToken, $enrichment->workRunDeliveryToken);
        $this->assertSame(1, $enrichment->workRunAttempt);
        $this->assertSame($confirmation->orchestrationToken, $enrichment->workRunOrchestrationToken);
        $confirmation->handle($this->runs);
        $this->assertTrue($this->runs->hasDispatchedOrchestration($run->id, $deliveryToken));

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->zeroOrMoreTimes()->andReturn(2);
        $job->setJob($queueJob);
        $job->handle();

        Bus::assertDispatchedTimes(ConfirmWorkRunOrchestrationJob::class, 1);
        $this->assertSame(1, $run->fresh()->attempt);
    }

    public function test_durable_enrichment_appends_completion_after_every_missing_enrichment_job(): void
    {
        $this->createEnrichmentTables();

        $job = new QueueSymbolEnrichmentJob(
            'SPY',
            'api_prime',
            'run-id',
            'delivery-token',
            2,
            'orchestration-token'
        );
        $job->handle();

        $children = collect($job->chained)
            ->map(static fn (string $serialized): object => unserialize($serialized))
            ->values();

        $this->assertGreaterThan(1, $children->count());
        $this->assertInstanceOf(CompleteWorkRunJob::class, $children->last());
        $this->assertFalse($children->slice(0, -1)->contains(
            static fn (object $child): bool => $child instanceof CompleteWorkRunJob
        ));
        foreach ($children as $child) {
            $this->assertCount(1, $child->middleware);
            $this->assertInstanceOf(EnsureWorkRunOrchestrationCurrent::class, $child->middleware[0]);
        }

        /** @var CompleteWorkRunJob $completion */
        $completion = $children->last();
        $this->assertSame('run-id', $completion->workRunId);
        $this->assertSame('delivery-token', $completion->workRunDeliveryToken);
        $this->assertSame(2, $completion->workRunAttempt);
    }

    public function test_durable_intraday_no_expiries_hands_off_to_one_owned_bootstrap_without_unowned_retry(): void
    {
        $this->createEnrichmentTables();
        if (! Schema::hasTable('option_snapshots')) {
            Schema::create('option_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol');
                $table->date('expiry');
            });
        }

        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $this->travelTo($at);
        $claim = $this->runs->claim(
            'intraday_refresh',
            'SPY',
            ['trade_date' => '2026-08-14'],
            'intraday-heavy',
            at: $at,
            applyAdmissionLimits: false
        );
        $reservation = $this->runs->reserveDispatch($claim['run']->id, $at);
        $this->runs->markDispatched($claim['run']->id, $reservation['delivery_token'], $at);
        Bus::fake();

        (new FetchPolygonIntradayOptionsJob(
            ['SPY'],
            tradeDate: '2026-08-14',
            workRunId: $claim['run']->id,
            workRunDeliveryToken: $reservation['delivery_token']
        ))->handle();

        $intraday = $claim['run']->fresh();
        $bootstrap = WorkRun::query()->where('kind', 'symbol_bootstrap')->sole();
        $this->assertSame(WorkRun::STATUS_FAILED, $intraday->status);
        $this->assertSame('intraday_incomplete', $intraday->error_category);
        $this->assertSame('no_expiries', $intraday->error_code);
        $this->assertSame('SPY', $bootstrap->symbol);
        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 1);
        Bus::assertDispatched(
            BootstrapUserSymbolJob::class,
            fn (BootstrapUserSymbolJob $job): bool => $job->workRunId === $bootstrap->id
                && $job->workRunDeliveryToken !== null
        );
        Bus::assertNotDispatched(FetchPolygonIntradayOptionsJob::class);
        $this->assertSame(2, WorkRun::query()->count());
    }

    public function test_stale_orchestration_middleware_skips_child_after_token_rotation(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claim($at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $deliveryToken = $reservation['delivery_token'];
        $this->runs->markDispatched($run->id, $deliveryToken, $at);
        $this->runs->markStarted($run->id, $deliveryToken, 1, $at);
        $oldToken = $this->runs->reserveOrchestration($run->id, $deliveryToken, 1, $at);
        $this->runs->markStarted($run->id, $deliveryToken, 2, $at->addSeconds(30));
        $newToken = $this->runs->reserveOrchestration($run->id, $deliveryToken, 2, $at->addSeconds(30));

        $called = false;
        (new EnsureWorkRunOrchestrationCurrent($run->id, $deliveryToken, 1, $oldToken))
            ->handle(new stdClass, function () use (&$called): void {
                $called = true;
            });
        $this->assertFalse($called);

        (new EnsureWorkRunOrchestrationCurrent($run->id, $deliveryToken, 2, $newToken))
            ->handle(new stdClass, function () use (&$called): void {
                $called = true;
            });
        $this->assertTrue($called);
    }

    public function test_failed_run_is_reused_during_cooldown_then_advances_generation(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claim($at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $token = $reservation['delivery_token'];
        $this->runs->markDispatched($run->id, $token, $at);
        $this->runs->markStarted($run->id, $token, 1, $at);
        $this->runs->markFailed($run->id, $token, 1, 'provider', 'incomplete', $at);

        $duringCooldown = $this->claimResult($at->addSeconds(299));
        $afterCooldown = $this->claimResult($at->addSeconds(300));

        $this->assertFalse($duringCooldown['created']);
        $this->assertSame($run->id, $duringCooldown['run']->id);
        $this->assertTrue($afterCooldown['created']);
        $this->assertNotSame($run->id, $afterCooldown['run']->id);
        $this->assertSame(2, $afterCooldown['run']->generation);
    }

    public function test_force_bypasses_only_completed_reuse_and_never_active_or_failed_cooldown(): void
    {
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $run = $this->claim($at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $token = $reservation['delivery_token'];
        $this->runs->markDispatched($run->id, $token, $at);
        $this->runs->markStarted($run->id, $token, 1, $at);

        $activeForce = $this->runs->claim(
            'calculator_refresh',
            'SPY',
            ['expiry' => '2026-08-21'],
            'calculator-interactive',
            at: $at->addSecond(),
            reuseCompleted: false
        );
        $this->assertFalse($activeForce['created']);
        $this->assertSame($run->id, $activeForce['run']->id);

        $this->runs->markCompleted($run->id, $token, 1, $at->addSeconds(2));
        $normalCompleted = $this->claimResult($at->addSeconds(3));
        $this->assertFalse($normalCompleted['created']);
        $this->assertSame($run->id, $normalCompleted['run']->id);

        $forcedCompleted = $this->runs->claim(
            'calculator_refresh',
            'SPY',
            ['expiry' => '2026-08-21'],
            'calculator-interactive',
            at: $at->addSeconds(4),
            reuseCompleted: false
        );
        $this->assertTrue($forcedCompleted['created']);
        $this->assertNotSame($run->id, $forcedCompleted['run']->id);
        $this->assertSame(2, $forcedCompleted['run']->generation);

        $forcedReservation = $this->runs->reserveDispatch($forcedCompleted['run']->id, $at->addSeconds(4));
        $forcedToken = $forcedReservation['delivery_token'];
        $this->runs->markDispatched($forcedCompleted['run']->id, $forcedToken, $at->addSeconds(4));
        $this->runs->markStarted($forcedCompleted['run']->id, $forcedToken, 1, $at->addSeconds(4));
        $this->runs->markFailed(
            $forcedCompleted['run']->id,
            $forcedToken,
            1,
            'provider',
            'incomplete',
            $at->addSeconds(5)
        );

        $failedForce = $this->runs->claim(
            'calculator_refresh',
            'SPY',
            ['expiry' => '2026-08-21'],
            'calculator-interactive',
            at: $at->addSeconds(6),
            reuseCompleted: false
        );
        $this->assertFalse($failedForce['created']);
        $this->assertSame($forcedCompleted['run']->id, $failedForce['run']->id);
        $this->assertSame(2, WorkRun::query()->count());
    }

    public function test_supplied_timestamp_is_persisted_as_the_same_utc_instant(): void
    {
        $local = CarbonImmutable::parse('2026-08-16 10:00:00', 'America/New_York');

        $run = $this->claim($local)->fresh();

        $this->assertSame('2026-08-16T14:00:00+00:00', $run->requested_at->utc()->toIso8601String());
        $this->assertSame('2026-08-16T15:00:00+00:00', $run->lease_expires_at->utc()->toIso8601String());
    }

    private function claim(CarbonImmutable $at): WorkRun
    {
        return $this->claimResult($at)['run'];
    }

    private function claimBootstrap(CarbonImmutable $at): WorkRun
    {
        return $this->runs->claim(
            'symbol_bootstrap',
            'SPY',
            [],
            'bootstrap-fast',
            at: $at
        )['run'];
    }

    /** @return array{run: WorkRun, created: bool} */
    private function claimResult(CarbonImmutable $at): array
    {
        return $this->runs->claim(
            'calculator_refresh',
            'SPY',
            ['expiry' => '2026-08-21'],
            'calculator-interactive',
            at: $at
        );
    }

    private function createSqliteTables(): void
    {
        if (DB::getDriverName() !== 'sqlite' || Schema::hasTable('work_runs')) {
            return;
        }

        Schema::create('work_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->char('slot_key', 64);
            $table->unsignedBigInteger('generation');
            $table->string('kind', 48);
            $table->string('provider', 32)->nullable();
            $table->string('symbol', 32)->nullable();
            $table->char('scope_hash', 64);
            $table->string('status', 24);
            $table->string('queue_connection', 32)->default('redis');
            $table->string('queue', 64)->nullable();
            $table->json('parameters')->nullable();
            $table->uuid('delivery_token')->nullable();
            $table->uuid('orchestration_token')->nullable();
            $table->timestamp('orchestration_reserved_at')->nullable();
            $table->timestamp('orchestration_dispatched_at')->nullable();
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('orchestration_attempt')->default(0);
            $table->timestamp('requested_at');
            $table->timestamp('dispatching_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('next_dispatch_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('reusable_until')->nullable();
            $table->timestamp('retry_not_before')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_category', 64)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->timestamps();
            $table->unique(['slot_key', 'generation']);
        });

        Schema::create('work_run_slots', function (Blueprint $table): void {
            $table->char('key', 64)->primary();
            $table->string('kind', 48);
            $table->string('provider', 32)->nullable();
            $table->string('symbol', 32)->nullable();
            $table->json('parameters')->nullable();
            $table->unsignedBigInteger('generation')->default(0);
            $table->uuid('current_run_id')->nullable()->index();
            $table->timestamps();
        });
    }

    private function createEnrichmentTables(): void
    {
        if (! Schema::hasTable('prices_daily')) {
            Schema::create('prices_daily', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol');
                $table->date('trade_date');
            });
        }
        if (! Schema::hasTable('option_expirations')) {
            Schema::create('option_expirations', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol');
                $table->date('expiration_date')->nullable();
            });
        }
        if (! Schema::hasTable('option_chain_data')) {
            Schema::create('option_chain_data', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('expiration_id');
                $table->date('data_date');
            });
        }

        foreach (['seasonality_5d', 'expiry_pressure', 'dex_by_expiry', 'iv_term', 'unusual_activity'] as $name) {
            if (! Schema::hasTable($name)) {
                Schema::create($name, function (Blueprint $table): void {
                    $table->id();
                    $table->string('symbol');
                    $table->date('data_date');
                });
            }
        }
    }
}
