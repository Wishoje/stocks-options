<?php

namespace Tests\Feature;

use App\Jobs\BootstrapUserSymbolJob;
use App\Jobs\ComputeExpiryPressureJob;
use App\Jobs\ComputePositioningJob;
use App\Jobs\ComputeUAJob;
use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\ConfirmWorkRunOrchestrationJob;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\PricesBackfillJob;
use App\Jobs\PricesDailyJob;
use App\Jobs\PrimeSymbolJob;
use App\Jobs\RunSymbolBootstrapPhaseJob;
use App\Jobs\Seasonality5DJob;
use App\Models\SymbolBootstrapPhase;
use App\Models\SymbolBootstrapRun;
use App\Models\WorkRun;
use App\Support\ProviderConcurrencyLimiter;
use App\Support\SymbolBootstrapCoordinator;
use App\Support\SymbolBootstrapPhaseDispatcher;
use App\Support\SymbolBootstrapPolicy;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class SymbolBootstrapPhaseDispatchTest extends TestCase
{
    private WorkRunCoordinator $workRuns;

    private SymbolBootstrapCoordinator $bootstraps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        DB::table('symbol_bootstrap_heads')->delete();
        DB::table('symbol_bootstrap_phases')->delete();
        DB::table('symbol_bootstrap_expirations')->delete();
        DB::table('symbol_bootstrap_runs')->delete();
        DB::table('work_run_slots')->delete();
        DB::table('work_runs')->delete();

        config()->set('queue.default', 'redis');
        config()->set('queue_lanes.isolated', true);
        config()->set('services.massive.concurrency.enabled', true);
        config()->set('services.massive.concurrency.limit', 4);
        config()->set('services.massive.key', 'test-key');
        config()->set('services.massive.base', 'https://api.massive.test');
        config()->set('services.massive.mode', 'bearer');
        config()->set('symbol_bootstrap.enabled', true);
        config()->set('work_runs.pending_ttl_seconds', 3600);
        config()->set('work_runs.running_ttl_seconds.symbol_bootstrap', 10800);
        config()->set('work_runs.dispatch_reservation_seconds', 30);
        config()->set('work_runs.rate_limits.accepted_symbol_per_minute', 1000);
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 1000);

        $this->workRuns = app(WorkRunCoordinator::class);
        $this->bootstraps = app(SymbolBootstrapCoordinator::class);
    }

    public function test_phased_root_dispatches_one_confirmed_quote_phase_with_single_attempt_contract(): void
    {
        [$run, $deliveryToken] = $this->runningParent($this->phasedParameters());
        Bus::fake();

        (new BootstrapUserSymbolJob('SPY', 'api_prime', $run->id, $deliveryToken))->handle();

        Bus::assertDispatchedTimes(ConfirmWorkRunOrchestrationJob::class, 1);
        /** @var ConfirmWorkRunOrchestrationJob $confirmation */
        $confirmation = Bus::dispatched(ConfirmWorkRunOrchestrationJob::class)->sole();
        $children = collect($confirmation->chained)
            ->map(static fn (string $serialized): object => unserialize($serialized));

        $this->assertCount(1, $children);
        /** @var RunSymbolBootstrapPhaseJob $phaseJob */
        $phaseJob = $children->sole();
        $this->assertInstanceOf(RunSymbolBootstrapPhaseJob::class, $phaseJob);
        $this->assertSame(SymbolBootstrapCoordinator::PHASE_QUOTE, $phaseJob->phase);
        $this->assertSame('bootstrap-fast', $phaseJob->queue);
        $this->assertSame(120, $phaseJob->timeout);
        $this->assertSame(1, $phaseJob->tries);
        $this->assertCount(2, $phaseJob->middleware());

        $manifest = SymbolBootstrapRun::query()->findOrFail($run->id);
        $this->assertSame('2026-08-14', $manifest->session_date->toDateString());
        $this->assertSame(6, SymbolBootstrapPhase::query()->where('work_run_id', $run->id)->count());
        $this->assertSame(
            SymbolBootstrapPhase::STATUS_PENDING,
            SymbolBootstrapPhase::query()
                ->where('work_run_id', $run->id)
                ->where('phase', SymbolBootstrapCoordinator::PHASE_QUOTE)
                ->value('status')
        );
        $this->assertSame(
            5,
            SymbolBootstrapPhase::query()
                ->where('work_run_id', $run->id)
                ->where('status', SymbolBootstrapPhase::STATUS_BLOCKED)
                ->count()
        );
    }

    public function test_phase_reservation_rejects_a_stale_parent_fence_and_dispatches_with_the_locked_current_fence(): void
    {
        $firstAt = CarbonImmutable::parse('2026-08-17 14:00:00', 'UTC');
        $this->travelTo($firstAt);
        [$run, $deliveryToken] = $this->runningParent($this->phasedParameters());
        $this->assertTrue($this->workRuns->markStarted($run->id, $deliveryToken, 1, $firstAt));
        $firstOrchestrationToken = $this->workRuns->reserveOrchestration(
            $run->id,
            $deliveryToken,
            1,
            $firstAt
        );
        $this->assertNotNull($firstOrchestrationToken);
        $this->bootstraps->initialize($run, $firstAt);

        $secondAt = $firstAt->addSeconds(31);
        $this->travelTo($secondAt);
        $this->assertTrue($this->workRuns->markStarted($run->id, $deliveryToken, 2, $secondAt));
        $secondOrchestrationToken = $this->workRuns->reserveOrchestration(
            $run->id,
            $deliveryToken,
            2,
            $secondAt
        );
        $this->assertNotNull($secondOrchestrationToken);

        Bus::fake();
        $staleConfirmation = new ConfirmWorkRunOrchestrationJob(
            $run->id,
            $deliveryToken,
            1,
            $firstOrchestrationToken
        );
        $this->assertSame(0, app(SymbolBootstrapPhaseDispatcher::class)->dispatchReady(
            $run->id,
            $staleConfirmation
        ));
        Bus::assertNothingDispatched();
        $this->assertSame(0, SymbolBootstrapPhase::query()
            ->where('work_run_id', $run->id)
            ->where('phase', SymbolBootstrapCoordinator::PHASE_QUOTE)
            ->value('dispatch_attempts'));

        $this->assertSame(1, app(SymbolBootstrapPhaseDispatcher::class)->dispatchReady($run->id));
        Bus::assertDispatched(
            RunSymbolBootstrapPhaseJob::class,
            fn (RunSymbolBootstrapPhaseJob $job): bool => $job->workRunId === $run->id
                && $job->workRunDeliveryToken === $deliveryToken
                && $job->workRunAttempt === 2
                && $job->workRunOrchestrationToken === $secondOrchestrationToken
        );

        $reservedParent = $run->fresh();
        $this->assertNotNull($reservedParent->orchestration_dispatched_at);
        $this->assertFalse($this->workRuns->markStarted(
            $run->id,
            $deliveryToken,
            3,
            $secondAt->addSeconds(31)
        ));
        $this->assertSame(2, $run->fresh()->attempt);
    }

    public function test_work_run_parameters_freeze_legacy_and_phased_routing_across_flag_changes(): void
    {
        [$legacy, $legacyToken] = $this->runningParent([], 'bootstrap');
        Bus::fake();

        (new BootstrapUserSymbolJob('SPY', 'old_release', $legacy->id, $legacyToken))->handle();

        $this->assertFalse(SymbolBootstrapRun::query()->whereKey($legacy->id)->exists());
        Bus::assertDispatched(ConfirmWorkRunOrchestrationJob::class);
        Bus::assertNotDispatched(RunSymbolBootstrapPhaseJob::class);

        Bus::fake();
        [$phased, $phasedToken] = $this->runningParent($this->phasedParameters());
        config()->set('symbol_bootstrap.enabled', false);
        config()->set('queue_lanes.isolated', false);
        config()->set('services.massive.concurrency.enabled', false);

        (new BootstrapUserSymbolJob('QQQ', 'rolled_back_release', $phased->id, $phasedToken))->handle();

        $this->assertTrue(SymbolBootstrapRun::query()->whereKey($phased->id)->exists());
        Bus::assertDispatched(ConfirmWorkRunOrchestrationJob::class);
    }

    public function test_complete_empty_catalog_is_frozen_as_no_options_and_advances_to_fast_phase(): void
    {
        [$run, $deliveryToken] = $this->runningParent($this->phasedParameters());
        Bus::fake();
        (new BootstrapUserSymbolJob('SPY', 'api_prime', $run->id, $deliveryToken))->handle();
        /** @var ConfirmWorkRunOrchestrationJob $parentConfirmation */
        $parentConfirmation = Bus::dispatched(ConfirmWorkRunOrchestrationJob::class)->sole();
        $parentConfirmation->handle($this->workRuns);

        $quote = SymbolBootstrapPhase::query()
            ->where('work_run_id', $run->id)
            ->where('phase', SymbolBootstrapCoordinator::PHASE_QUOTE)
            ->sole();
        $this->assertTrue($this->bootstraps->markPhaseStarted(
            $run->id,
            $quote->phase,
            $quote->delivery_token,
            1
        ));
        $this->assertTrue($this->bootstraps->markPhaseCompleted(
            $run->id,
            $quote->phase,
            $quote->delivery_token,
            1
        ));

        $catalogReservation = $this->bootstraps->reservePhase(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_CATALOG
        );
        $this->assertNotNull($catalogReservation);
        $catalog = $catalogReservation['phase'];
        $catalogToken = $catalogReservation['delivery_token'];
        $this->bootstraps->markPhaseDispatched($run->id, $catalog->phase, $catalogToken);

        $passthroughLimiter = new class extends ProviderConcurrencyLimiter
        {
            public function withPriority(string $priority, callable $callback, ?int $blockForSeconds = null): mixed
            {
                return $callback();
            }

            public function massive(callable $callback, ?int $blockForSeconds = null): mixed
            {
                return $callback();
            }
        };
        $this->app->instance(ProviderConcurrencyLimiter::class, $passthroughLimiter);
        Http::fake(['*' => Http::response(['status' => 'OK', 'results' => []], 200)]);
        $parent = $run->fresh();

        (new RunSymbolBootstrapPhaseJob(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_CATALOG,
            $catalogToken,
            $deliveryToken,
            $parent->attempt,
            $parent->orchestration_token
        ))->onConnection('redis')->onQueue('bootstrap-fast')->handle(
            $this->bootstraps,
            app(SymbolBootstrapPhaseDispatcher::class),
            $this->workRuns
        );

        $manifest = SymbolBootstrapRun::query()->findOrFail($run->id);
        $this->assertNotNull($manifest->catalog_frozen_at);
        $this->assertSame(0, $manifest->expected_count);
        $this->assertSame(
            SymbolBootstrapPhase::STATUS_COMPLETED,
            SymbolBootstrapPhase::query()
                ->where('work_run_id', $run->id)
                ->where('phase', SymbolBootstrapCoordinator::PHASE_CATALOG)
                ->value('status')
        );
        Bus::assertDispatched(
            RunSymbolBootstrapPhaseJob::class,
            fn (RunSymbolBootstrapPhaseJob $job): bool => $job->workRunId === $run->id
                && $job->phase === SymbolBootstrapCoordinator::PHASE_FAST_EOD
        );
    }

    public function test_delayed_missing_expiry_fallback_does_not_replace_the_authoritative_full_run(): void
    {
        $at = CarbonImmutable::parse('2026-08-17 14:00:00', 'UTC');
        $this->travelTo($at);
        [$run, $deliveryToken] = $this->runningParent($this->phasedParameters());
        $this->workRuns->markStarted($run->id, $deliveryToken, 1, $at);
        $this->workRuns->markCompleted($run->id, $deliveryToken, 1, $at);
        DB::table('symbol_bootstrap_heads')->insert([
            'symbol' => 'SPY',
            'session_date' => '2026-08-14',
            'purpose' => SymbolBootstrapPolicy::PURPOSE,
            'current_work_run_id' => $run->id,
            'current_generation' => $run->generation,
            'current_full_ready_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        config()->set('work_runs.reusable_seconds.symbol_bootstrap', 600);
        $this->travelTo($at->addSeconds(601));
        Bus::fake();

        $this->assertSame('2026-08-14', app(SymbolBootstrapPolicy::class)->sessionDate());
        $this->assertNotNull($this->bootstraps->authoritativeWorkRun(
            'SPY',
            '2026-08-14',
            SymbolBootstrapPolicy::PURPOSE
        ));

        $this->assertTrue(BootstrapUserSymbolJob::dispatchIfNeeded(
            'SPY',
            'intraday_no_expiries',
            300
        ));

        $this->assertSame(1, WorkRun::query()->where('kind', 'symbol_bootstrap')->count());
        Bus::assertNothingDispatched();
    }

    public function test_failed_spy_interactive_intraday_retries_once_on_the_heavy_lane(): void
    {
        $at = CarbonImmutable::parse('2026-08-17 14:00:00', 'UTC');
        $this->travelTo($at);
        [$run, $deliveryToken] = $this->runningParent($this->phasedParameters());
        Bus::fake();
        (new BootstrapUserSymbolJob('SPY', 'api_prime', $run->id, $deliveryToken))->handle();
        /** @var ConfirmWorkRunOrchestrationJob $confirmation */
        $confirmation = Bus::dispatched(ConfirmWorkRunOrchestrationJob::class)->sole();
        $confirmation->handle($this->workRuns);

        SymbolBootstrapPhase::query()
            ->where('work_run_id', $run->id)
            ->where('phase', SymbolBootstrapCoordinator::PHASE_INTRADAY)
            ->update([
                'status' => SymbolBootstrapPhase::STATUS_PENDING,
                'next_dispatch_at' => $at,
            ]);
        $reservation = $this->bootstraps->reservePhase(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_INTRADAY,
            $at
        );
        $this->assertNotNull($reservation);
        $token = $reservation['delivery_token'];
        $this->bootstraps->markPhaseDispatched(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_INTRADAY,
            $token,
            $at
        );
        $this->bootstraps->markPhaseStarted(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_INTRADAY,
            $token,
            1,
            $at
        );
        $this->assertTrue($this->bootstraps->markPhaseFailed(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_INTRADAY,
            $token,
            1,
            'timeout',
            'terminal_exception:timeout',
            $at
        ));

        $failed = SymbolBootstrapPhase::query()
            ->where('work_run_id', $run->id)
            ->where('phase', SymbolBootstrapCoordinator::PHASE_INTRADAY)
            ->sole();
        $this->assertSame('intraday-heavy', $failed->queue);
        $this->travelTo($failed->retry_not_before->addSecond());
        Bus::fake();

        app(SymbolBootstrapPhaseDispatcher::class)->dispatchReady($run->id);

        Bus::assertDispatched(
            RunSymbolBootstrapPhaseJob::class,
            fn (RunSymbolBootstrapPhaseJob $job): bool => $job->workRunId === $run->id
                && $job->phase === SymbolBootstrapCoordinator::PHASE_INTRADAY
                && $job->queue === 'intraday-heavy'
                && $job->timeout === 540
                && $job->tries === 1
        );
    }

    public function test_first_use_intraday_uses_monday_live_date_and_skips_closed_windows(): void
    {
        $job = new RunSymbolBootstrapPhaseJob(
            'run-id',
            SymbolBootstrapCoordinator::PHASE_INTRADAY,
            'phase-token',
            'parent-token',
            1,
            'orchestration-token'
        );
        $liveDate = new ReflectionMethod($job, 'liveTradingDate');
        $eodTimeout = new ReflectionMethod($job, 'eodTimeout');

        $this->assertSame(270, $eodTimeout->invoke($job, 'fast'));
        $this->assertSame(540, $eodTimeout->invoke($job, 'fill'));

        $this->travelTo(CarbonImmutable::parse('2026-08-17 14:00:00', 'UTC'));
        $this->assertSame('2026-08-17', $liveDate->invoke($job));

        $this->travelTo(CarbonImmutable::parse('2026-08-17 12:00:00', 'UTC'));
        $this->assertNull($liveDate->invoke($job));

        $this->travelTo(CarbonImmutable::parse('2026-08-16 16:00:00', 'UTC'));
        $this->assertNull($liveDate->invoke($job));
    }

    public function test_phase_failure_classifier_separates_terminal_configuration_and_validation(): void
    {
        $job = new RunSymbolBootstrapPhaseJob(
            'run-id',
            SymbolBootstrapCoordinator::PHASE_CATALOG,
            'phase-token',
            'parent-token',
            1,
            'orchestration-token'
        );
        $category = new ReflectionMethod($job, 'phaseErrorCategory');

        $this->assertSame(
            'provider_authentication',
            $category->invoke($job, new RuntimeException('Option expiration catalog incomplete: unauthorized'))
        );
        $this->assertSame(
            'configuration',
            $category->invoke($job, new RuntimeException('Option expiration catalog incomplete: invalid_configuration'))
        );
        $this->assertSame(
            'validation',
            $category->invoke($job, new RuntimeException('Option expiration catalog incomplete: invalid_request'))
        );
        $this->assertSame(
            'validation',
            $category->invoke($job, new InvalidArgumentException('bad phase input'))
        );
        $this->assertSame(
            'provider_validation',
            $category->invoke($job, new RuntimeException('Option expiration catalog incomplete: scope_violation'))
        );
        $this->assertSame(
            'provider_rate_limited',
            $category->invoke($job, new RuntimeException('Option expiration catalog incomplete: rate_limited'))
        );
    }

    public function test_quote_fetch_preserves_safe_auth_and_rate_limit_categories(): void
    {
        // This test owns the HTTP boundary. Disable the Redis-backed provider
        // funnel so a developer machine without Redis cannot mask the HTTP
        // category with a local Redis connection failure.
        config()->set('queue_lanes.isolated', false);
        config()->set('services.massive.concurrency.enabled', false);

        $responseStatus = 401;
        Http::fake(static function () use (&$responseStatus) {
            return Http::response([], $responseStatus);
        });

        foreach ([401 => 'unauthorized', 429 => 'rate_limited'] as $status => $category) {
            $responseStatus = $status;

            try {
                (new \App\Jobs\FetchUnderlyingQuotesJob(['SPY']))->handle();
                $this->fail("HTTP {$status} must remain a categorized provider failure.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString(
                    $category,
                    $exception->getMessage(),
                    "HTTP {$status} must preserve its safe provider category."
                );
            }

            Http::assertSent(fn ($request): bool => $request->url()
                === 'https://api.massive.test/v2/snapshot/locale/us/markets/stocks/tickers/SPY');
        }
    }

    public function test_frozen_fast_scope_guard_expires_after_its_worker_budget(): void
    {
        $scoped = new BootstrapScopedFetchOptionChainDataJob(
            ['SPY'],
            90,
            '2026-08-14',
            270,
            ['2026-08-17']
        );
        $legacy = new BootstrapScopedFetchOptionChainDataJob(
            ['SPY'],
            90,
            '2026-08-14',
            270
        );

        $this->assertSame(330, $scoped->guardSecondsForTest());
        $this->assertSame(600, $legacy->guardSecondsForTest());
    }

    public function test_prime_planner_passes_the_frozen_session_to_every_date_bearing_job(): void
    {
        $this->createPlannerTables();
        $date = '2026-08-14';
        $symbol = 'PLNR';
        foreach (['prices_daily', 'iv_term', 'seasonality_5d', 'expiry_pressure', 'dex_by_expiry', 'unusual_activity'] as $table) {
            DB::table($table)->where('symbol', $symbol)->delete();
        }
        foreach (range(1, 30) as $offset) {
            DB::table('prices_daily')->insert([
                'symbol' => $symbol,
                'trade_date' => CarbonImmutable::parse($date)->subDays($offset)->toDateString(),
            ]);
        }
        DB::table('iv_term')->insert([
            'symbol' => $symbol,
            'data_date' => $date,
            'exp_date' => '2026-08-21',
            'iv' => 0.2,
        ]);
        DB::table('seasonality_5d')->insert([
            'symbol' => $symbol,
            'data_date' => $date,
        ]);
        DB::table('expiry_pressure')->insert([
            'symbol' => $symbol,
            'data_date' => $date,
            'exp_date' => '2026-08-21',
            'pin_score' => 50,
            'clusters_json' => '[]',
        ]);
        DB::table('dex_by_expiry')->insert([
            'symbol' => $symbol,
            'data_date' => $date,
            'exp_date' => '2026-08-21',
            'dex_total' => 1,
        ]);
        DB::table('unusual_activity')->insert([
            'symbol' => $symbol,
            'data_date' => $date,
            'exp_date' => '2026-08-21',
            'strike' => 500,
            'z_score' => 2,
            'vol_oi' => 1,
        ]);
        $jobs = collect((new PrimeSymbolJob($symbol))->plannedJobs($date))->keyBy(fn (object $job): string => $job::class);

        $this->assertSame($date, $jobs->get(PricesBackfillJob::class)->endDate);
        $this->assertSame($date, $jobs->get(PricesDailyJob::class)->targetDate);
        $chainDate = new ReflectionProperty(FetchOptionChainDataJob::class, 'targetDate');
        $this->assertSame($date, $chainDate->getValue($jobs->get(FetchOptionChainDataJob::class)));
        $this->assertSame($date, $jobs->get(ComputeVolMetricsJob::class)->anchorDate);
        $this->assertSame($date, $jobs->get(Seasonality5DJob::class)->asOfDate);
        $this->assertSame($date, $jobs->get(ComputeExpiryPressureJob::class)->anchorDate);
        $this->assertSame($date, $jobs->get(ComputePositioningJob::class)->anchorDate);
        $this->assertSame($date, $jobs->get(ComputeUAJob::class)->anchorDate);
    }

    /** @return array{WorkRun,string} */
    private function runningParent(array $parameters, string $queue = 'bootstrap-fast', string $symbol = 'SPY'): array
    {
        $claim = $this->workRuns->claim(
            'symbol_bootstrap',
            $symbol,
            $parameters,
            $queue,
            applyAdmissionLimits: false
        );
        $reservation = $this->workRuns->reserveDispatch($claim['run']->id);
        $this->workRuns->markDispatched($claim['run']->id, $reservation['delivery_token']);

        return [$claim['run'], $reservation['delivery_token']];
    }

    /** @return array<string,string> */
    private function phasedParameters(): array
    {
        return [
            'purpose' => SymbolBootstrapPolicy::PURPOSE,
            'session_date' => '2026-08-14',
        ];
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
            });
        }
        if (! Schema::hasTable('work_runs')) {
            $migration = require database_path('migrations/2026_08_16_000001_create_work_runs_table.php');
            $migration->up();
        }
        if (! Schema::hasTable('symbol_bootstrap_runs')) {
            $migration = require database_path('migrations/2026_08_17_000005_create_symbol_bootstrap_tables.php');
            $migration->up();
        }
    }

    private function createPlannerTables(): void
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
                $table->date('expiration_date');
            });
        }
        if (! Schema::hasTable('option_chain_data')) {
            Schema::create('option_chain_data', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('expiration_id');
                $table->date('data_date');
                $table->string('option_type')->nullable();
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

class BootstrapScopedFetchOptionChainDataJob extends FetchOptionChainDataJob
{
    public function guardSecondsForTest(): int
    {
        return $this->guardSeconds();
    }
}
