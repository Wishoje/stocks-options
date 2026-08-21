<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Models\SymbolBootstrapExpiration;
use App\Models\SymbolBootstrapHead;
use App\Models\SymbolBootstrapPhase;
use App\Models\SymbolBootstrapRun;
use App\Models\WorkRun;
use App\Support\SymbolBootstrapCoordinator;
use App\Support\SymbolBootstrapPolicy;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SymbolBootstrapCoordinatorTest extends TestCase
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
        config()->set('symbol_bootstrap.fast_horizon_days', 14);
        config()->set('symbol_bootstrap.fill_horizon_days', 90);
        config()->set('symbol_bootstrap.pending_lease_seconds', 3600);
        config()->set('symbol_bootstrap.running_lease_seconds.quote', 300);
        config()->set('symbol_bootstrap.max_phase_attempts', 5);
        config()->set('symbol_bootstrap.failure_cooldown_seconds', 300);
        config()->set('work_runs.pending_ttl_seconds', 3600);
        config()->set('work_runs.running_ttl_seconds.symbol_bootstrap', 10800);
        config()->set('work_runs.reusable_seconds.symbol_bootstrap', 600);

        $this->workRuns = app(WorkRunCoordinator::class);
        $this->bootstraps = app(SymbolBootstrapCoordinator::class);
    }

    public function test_catalog_fast_and_fill_cannot_complete_before_their_durable_checkpoints(): void
    {
        [$run] = $this->runningParent();
        $this->bootstraps->initialize($run, $this->at());

        [$quote, $quoteToken] = $this->startPhase($run, SymbolBootstrapCoordinator::PHASE_QUOTE);
        $this->assertTrue($this->bootstraps->markPhaseCompleted(
            $run->id,
            $quote->phase,
            $quoteToken,
            1,
            [],
            $this->at()
        ));

        [$catalog, $catalogToken] = $this->startPhase($run, SymbolBootstrapCoordinator::PHASE_CATALOG);
        $this->assertFalse($this->bootstraps->markPhaseCompleted(
            $run->id,
            $catalog->phase,
            $catalogToken,
            1,
            [],
            $this->at()
        ));
        $this->assertSame(SymbolBootstrapPhase::STATUS_RUNNING, $catalog->fresh()->status);

        $this->bootstraps->freezeCatalog(
            $run->id,
            $catalogToken,
            ['2026-08-21', '2026-09-18'],
            ['complete' => true, 'capped' => false, 'source' => 'test'],
            $this->at()
        );
        $this->assertTrue($this->bootstraps->markPhaseCompleted(
            $run->id,
            $catalog->phase,
            $catalogToken,
            1,
            [],
            $this->at()
        ));

        [$fast, $fastToken] = $this->startPhase($run, SymbolBootstrapCoordinator::PHASE_FAST_EOD);
        $this->assertFalse($this->bootstraps->markPhaseCompleted(
            $run->id,
            $fast->phase,
            $fastToken,
            1,
            [],
            $this->at()
        ));
        SymbolBootstrapRun::query()->whereKey($run->id)->update(['fast_ready_count' => 1]);
        $this->assertTrue($this->bootstraps->markPhaseCompleted(
            $run->id,
            $fast->phase,
            $fastToken,
            1,
            [],
            $this->at()
        ));

        [$fill, $fillToken] = $this->startPhase($run, SymbolBootstrapCoordinator::PHASE_FILL);
        $this->assertFalse($this->bootstraps->markPhaseCompleted(
            $run->id,
            $fill->phase,
            $fillToken,
            1,
            [],
            $this->at()
        ));
        SymbolBootstrapRun::query()->whereKey($run->id)->update(['fill_ready_count' => 2]);
        $this->assertTrue($this->bootstraps->markPhaseCompleted(
            $run->id,
            $fill->phase,
            $fillToken,
            1,
            [],
            $this->at()
        ));
    }

    public function test_expired_running_phase_is_reclaimed_with_a_new_token_and_stale_delivery_is_fenced(): void
    {
        $startedAt = $this->at();
        [$run] = $this->runningParent($startedAt);
        $this->bootstraps->initialize($run, $startedAt);

        [$phase, $oldToken] = $this->startPhase(
            $run,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $startedAt
        );
        $expiredAt = $startedAt->addSeconds(301);

        $this->assertTrue(
            $this->bootstraps->dispatchable(100, $expiredAt)
                ->contains(fn (SymbolBootstrapPhase $candidate): bool => $candidate->is($phase))
        );

        $replacement = $this->bootstraps->reservePhase($run->id, $phase->phase, $expiredAt);
        $this->assertNotNull($replacement);
        $newToken = $replacement['delivery_token'];
        $this->assertNotSame($oldToken, $newToken);
        $this->assertFalse($this->bootstraps->isPhaseCurrent($run->id, $phase->phase, $oldToken));
        $this->assertFalse($this->bootstraps->markPhaseCompleted(
            $run->id,
            $phase->phase,
            $oldToken,
            1,
            [],
            $expiredAt
        ));

        $this->assertTrue($this->bootstraps->markPhaseDispatched(
            $run->id,
            $phase->phase,
            $newToken,
            $expiredAt
        ));
        $this->assertTrue($this->bootstraps->markPhaseStarted(
            $run->id,
            $phase->phase,
            $newToken,
            1,
            $expiredAt
        ));
        $this->assertSame(2, $phase->fresh()->dispatch_attempts);
    }

    public function test_abandoned_pending_dispatch_reservation_is_reclaimed_after_the_short_reservation_window(): void
    {
        $startedAt = $this->at();
        [$run] = $this->runningParent($startedAt);
        $this->bootstraps->initialize($run, $startedAt);

        $first = $this->bootstraps->reservePhase(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $startedAt
        );
        $this->assertNotNull($first);
        $oldToken = $first['delivery_token'];

        $this->assertFalse(
            $this->bootstraps->dispatchable(100, $startedAt->addSeconds(119))
                ->contains('work_run_id', $run->id)
        );
        $this->assertTrue(
            $this->bootstraps->dispatchable(100, $startedAt->addSeconds(120))
                ->contains('work_run_id', $run->id)
        );

        $replacement = $this->bootstraps->reservePhase(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $startedAt->addSeconds(120)
        );
        $this->assertNotNull($replacement);
        $this->assertNotSame($oldToken, $replacement['delivery_token']);
        $this->assertFalse($this->bootstraps->markPhaseDispatched(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $oldToken,
            $startedAt->addSeconds(120)
        ));
        $this->assertSame(2, $replacement['phase']->fresh()->dispatch_attempts);
    }

    public function test_dispatched_pending_phase_keeps_its_live_delivery_after_the_reservation_window(): void
    {
        $startedAt = $this->at();
        [$run] = $this->runningParent($startedAt);
        $this->bootstraps->initialize($run, $startedAt);

        $first = $this->bootstraps->reservePhase(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $startedAt
        );
        $this->assertNotNull($first);
        $token = $first['delivery_token'];
        $this->assertTrue($this->bootstraps->markPhaseDispatched(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $token,
            $startedAt
        ));

        $afterReservationWindow = $startedAt->addSeconds(120);
        $this->assertFalse(
            $this->bootstraps->dispatchable(100, $afterReservationWindow)
                ->contains('work_run_id', $run->id)
        );
        $this->assertNull($this->bootstraps->reservePhase(
            $run->id,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $afterReservationWindow
        ));

        $phase = $first['phase']->fresh();
        $this->assertSame($token, $phase->delivery_token);
        $this->assertSame(1, $phase->dispatch_attempts);
        $this->assertNotNull($phase->dispatched_at);
    }

    public function test_expired_phase_at_max_dispatch_attempts_terminalizes_the_parent_instead_of_reclaiming(): void
    {
        config()->set('symbol_bootstrap.max_phase_attempts', 2);
        $firstAt = $this->at();
        [$run] = $this->runningParent($firstAt);
        $this->bootstraps->initialize($run, $firstAt);

        [$first, $firstToken] = $this->startPhase(
            $run,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $firstAt
        );
        $secondAt = $firstAt->addSeconds(301);
        $second = $this->bootstraps->reservePhase($run->id, $first->phase, $secondAt);
        $this->assertNotNull($second);
        $this->assertNotSame($firstToken, $second['delivery_token']);
        $this->assertTrue($this->bootstraps->markPhaseDispatched(
            $run->id,
            $first->phase,
            $second['delivery_token'],
            $secondAt
        ));
        $this->assertTrue($this->bootstraps->markPhaseStarted(
            $run->id,
            $first->phase,
            $second['delivery_token'],
            1,
            $secondAt
        ));

        $terminalAt = $secondAt->addSeconds(301);
        $this->assertNull($this->bootstraps->reservePhase($run->id, $first->phase, $terminalAt));

        $parent = $run->fresh();
        $phase = $first->fresh();
        $payload = $this->bootstraps->payload($parent);
        $this->assertSame(WorkRun::STATUS_FAILED, $parent->status);
        $this->assertSame('bootstrap_abandoned', $parent->error_category);
        $this->assertSame('quote:running_lease_expired', $parent->error_code);
        $this->assertSame($terminalAt->addSeconds(300)->toIso8601String(), $parent->retry_not_before?->toIso8601String());
        $this->assertSame(SymbolBootstrapPhase::STATUS_FAILED, $phase->status);
        $this->assertSame(2, $phase->dispatch_attempts);
        $this->assertNull($phase->delivery_token);
        $this->assertNull($phase->next_dispatch_at);
        $this->assertSame('abandoned', $phase->error_category);
        $this->assertSame('max_attempts:running_lease_expired', $phase->error_code);
        $this->assertTrue($payload['terminal']);
        $this->assertFalse($payload['retryable']);
        $this->assertFalse(
            $this->bootstraps->dispatchable(100, $terminalAt)
                ->contains('work_run_id', $run->id)
        );
    }

    public function test_monday_run_keeps_friday_eod_session_but_freezes_a_monday_forward_catalog(): void
    {
        $monday = CarbonImmutable::parse('2026-08-17 14:00:00', 'UTC');
        [$run] = $this->runningParent($monday);
        $manifest = $this->bootstraps->initialize($run, $monday);

        $this->assertSame('2026-08-14', $manifest->session_date->toDateString());
        $this->assertSame('2026-08-17', $manifest->catalog_horizon_start->toDateString());
        $this->assertSame('2026-11-15', $manifest->catalog_horizon_end->toDateString());

        [$quote, $quoteToken] = $this->startPhase(
            $run,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $monday
        );
        $this->assertTrue($this->bootstraps->markPhaseCompleted(
            $run->id,
            $quote->phase,
            $quoteToken,
            1,
            [],
            $monday
        ));
        [$catalog, $catalogToken] = $this->startPhase(
            $run,
            SymbolBootstrapCoordinator::PHASE_CATALOG,
            $monday
        );
        $this->bootstraps->freezeCatalog(
            $run->id,
            $catalogToken,
            ['2026-08-31', '2026-09-01'],
            ['complete' => true, 'capped' => false, 'source' => 'test'],
            $monday
        );

        $scopes = SymbolBootstrapExpiration::query()
            ->where('work_run_id', $run->id)
            ->orderBy('expiration_date')
            ->pluck('fast_scope', 'expiration_date')
            ->mapWithKeys(static fn ($fast, $date): array => [substr((string) $date, 0, 10) => (bool) $fast])
            ->all();
        $this->assertSame([
            '2026-08-31' => true,
            '2026-09-01' => false,
        ], $scopes);
    }

    public function test_terminal_parent_fences_every_phase_mutation_and_redispatch(): void
    {
        [$run, $parentToken] = $this->runningParent();
        $this->bootstraps->initialize($run, $this->at());
        [$phase, $phaseToken] = $this->startPhase($run, SymbolBootstrapCoordinator::PHASE_QUOTE);

        $this->assertTrue($this->workRuns->markFailed(
            $run->id,
            $parentToken,
            1,
            'test',
            'terminal_parent',
            $this->at()
        ));

        $this->assertFalse($this->bootstraps->isPhaseCurrent($run->id, $phase->phase, $phaseToken));
        $this->assertFalse($this->bootstraps->markPhaseCompleted(
            $run->id,
            $phase->phase,
            $phaseToken,
            1,
            [],
            $this->at()
        ));
        $this->assertFalse($this->bootstraps->markPhaseFailed(
            $run->id,
            $phase->phase,
            $phaseToken,
            1,
            'test',
            'stale_failure',
            $this->at()
        ));
        $this->assertNull($this->bootstraps->reservePhase($run->id, $phase->phase, $this->at()));
        $this->assertFalse(
            $this->bootstraps->dispatchable(100, $this->at())
                ->contains('work_run_id', $run->id)
        );
        $this->assertFalse($this->bootstraps->completeIfReady($run->id, $this->at()));
        $this->assertSame(SymbolBootstrapPhase::STATUS_RUNNING, $phase->fresh()->status);
    }

    public function test_permanent_phase_categories_terminalize_on_first_failure_with_cooldown(): void
    {
        $at = $this->at();
        foreach ([
            'AUTH' => 'provider_authentication',
            'CONF' => 'configuration',
            'VALID' => 'validation',
        ] as $symbol => $category) {
            [$run] = $this->runningParent($at, symbol: $symbol);
            $this->bootstraps->initialize($run, $at);
            [$phase, $token] = $this->startPhase(
                $run,
                SymbolBootstrapCoordinator::PHASE_QUOTE,
                $at
            );

            $this->assertTrue($this->bootstraps->markPhaseFailed(
                $run->id,
                $phase->phase,
                $token,
                1,
                $category,
                'permanent_failure',
                $at
            ));

            $parent = $run->fresh();
            $failedPhase = $phase->fresh();
            $payload = $this->bootstraps->payload($parent);
            $this->assertSame(WorkRun::STATUS_FAILED, $parent->status);
            $this->assertSame($at->addSeconds(300)->toIso8601String(), $parent->retry_not_before?->toIso8601String());
            $this->assertSame(SymbolBootstrapPhase::STATUS_FAILED, $failedPhase->status);
            $this->assertNull($failedPhase->next_dispatch_at);
            $this->assertTrue($payload['terminal']);
            $this->assertFalse($payload['retryable']);
            $this->assertNull($payload['retry_after_seconds']);
        }
    }

    public function test_transient_phase_failure_retries_until_max_attempt_then_terminalizes(): void
    {
        config()->set('symbol_bootstrap.max_phase_attempts', 3);
        $firstAt = $this->at();
        [$run] = $this->runningParent($firstAt);
        $this->bootstraps->initialize($run, $firstAt);

        [$first, $firstToken] = $this->startPhase(
            $run,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $firstAt
        );
        $this->assertTrue($this->bootstraps->markPhaseFailed(
            $run->id,
            $first->phase,
            $firstToken,
            1,
            'timeout',
            'attempt_1',
            $firstAt
        ));
        $this->assertSame(WorkRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertTrue($this->bootstraps->payload($run->fresh())['retryable']);

        $secondAt = $firstAt->addSeconds(15);
        [$second, $secondToken] = $this->startPhase(
            $run,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $secondAt
        );
        $this->assertTrue($this->bootstraps->markPhaseFailed(
            $run->id,
            $second->phase,
            $secondToken,
            1,
            'timeout',
            'attempt_2',
            $secondAt
        ));
        $this->assertSame(WorkRun::STATUS_RUNNING, $run->fresh()->status);

        $thirdAt = $secondAt->addSeconds(60);
        [$third, $thirdToken] = $this->startPhase(
            $run,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $thirdAt
        );
        $this->assertTrue($this->bootstraps->markPhaseFailed(
            $run->id,
            $third->phase,
            $thirdToken,
            1,
            'timeout',
            'attempt_3',
            $thirdAt
        ));

        $parent = $run->fresh();
        $payload = $this->bootstraps->payload($parent);
        $this->assertSame(WorkRun::STATUS_FAILED, $parent->status);
        $this->assertSame($thirdAt->addSeconds(300)->toIso8601String(), $parent->retry_not_before?->toIso8601String());
        $this->assertNull($third->fresh()->next_dispatch_at);
        $this->assertTrue($payload['terminal']);
        $this->assertFalse($payload['retryable']);
        $this->assertNull($payload['retry_after_seconds']);
    }

    public function test_gex_read_returns_truthful_terminal_failure_without_polling_header(): void
    {
        $at = $this->at();
        $this->travelTo($at);
        config()->set('queue_lanes.isolated', true);
        config()->set('services.massive.concurrency.enabled', true);
        config()->set('services.massive.concurrency.limit', 4);
        config()->set('symbol_bootstrap.enabled', true);

        [$run] = $this->runningParent($at);
        $this->bootstraps->initialize($run, $at);
        [$phase, $token] = $this->startPhase(
            $run,
            SymbolBootstrapCoordinator::PHASE_QUOTE,
            $at
        );
        $this->assertTrue($this->bootstraps->markPhaseFailed(
            $run->id,
            $phase->phase,
            $token,
            1,
            'provider_authentication',
            'catalog_unauthorized',
            $at
        ));

        $response = app(GexController::class)->getGexLevels(
            Request::create('/api/gex-levels', 'GET', [
                'symbol' => 'SPY',
                'timeframe' => '14d',
            ])
        );
        $payload = $response->getData(true);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('failed', $payload['status']);
        $this->assertSame(WorkRun::STATUS_FAILED, $payload['run']['status']);
        $this->assertTrue($payload['bootstrap']['terminal']);
        $this->assertFalse($payload['bootstrap']['retryable']);
        $this->assertFalse($response->headers->has('Retry-After'));
    }

    public function test_completed_generations_publish_one_monotonic_head_and_preserve_previous_head(): void
    {
        [$first] = $this->runningParent();
        $this->bootstraps->initialize($first, $this->at());
        $this->completeNoOptionsRun($first);

        $firstHead = SymbolBootstrapHead::query()->sole();
        $this->assertSame($first->id, $firstHead->current_work_run_id);
        $this->assertSame(1, $firstHead->current_generation);
        $this->assertNull($firstHead->previous_work_run_id);
        $this->assertSame(WorkRun::STATUS_COMPLETED, $first->fresh()->status);

        [$second] = $this->runningParent($this->at()->addMinute(), reuseCompleted: false);
        $this->bootstraps->initialize($second, $this->at()->addMinute());
        $this->completeNoOptionsRun($second, $this->at()->addMinute());

        $head = SymbolBootstrapHead::query()->sole();
        $this->assertSame(2, $second->generation);
        $this->assertSame($second->generation, SymbolBootstrapRun::query()->findOrFail($second->id)->generation);
        $this->assertSame($second->id, $head->current_work_run_id);
        $this->assertSame(2, $head->current_generation);
        $this->assertSame($first->id, $head->previous_work_run_id);
        $this->assertSame(1, $head->previous_generation);
        $this->assertSame($second->id, $this->bootstraps->authoritativeWorkRun(
            'SPY',
            '2026-08-14',
            SymbolBootstrapPolicy::PURPOSE
        )?->id);
    }

    public function test_reconcile_publishes_a_completion_ready_run_after_the_last_phase_checkpoint(): void
    {
        [$run] = $this->runningParent();
        $this->bootstraps->initialize($run, $this->at());

        SymbolBootstrapRun::query()->whereKey($run->id)->update([
            'catalog_frozen_at' => $this->at(),
            'expected_expirations_hash' => hash('sha256', ''),
            'expected_count' => 0,
            'fast_expected_count' => 0,
            'fast_ready_count' => 0,
            'fill_ready_count' => 0,
        ]);
        SymbolBootstrapPhase::query()->where('work_run_id', $run->id)->update([
            'status' => SymbolBootstrapPhase::STATUS_COMPLETED,
            'completed_at' => $this->at(),
        ]);

        $this->assertFalse(SymbolBootstrapHead::query()->exists());
        $this->assertSame(WorkRun::STATUS_RUNNING, $run->fresh()->status);

        $this->bootstraps->reconcileRun($run->id, $this->at()->addSecond());

        $this->assertSame(WorkRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertNotNull(SymbolBootstrapRun::query()->findOrFail($run->id)->full_ready_at);
        $this->assertSame($run->id, SymbolBootstrapHead::query()->sole()->current_work_run_id);

        $this->bootstraps->reconcileRun($run->id, $this->at()->addSeconds(2));
        $this->assertSame(1, SymbolBootstrapHead::query()->count());
    }

    /** @return array{WorkRun,string} */
    private function runningParent(
        ?CarbonImmutable $at = null,
        bool $reuseCompleted = true,
        string $symbol = 'SPY'
    ): array {
        $at ??= $this->at();
        $claim = $this->workRuns->claim(
            'symbol_bootstrap',
            $symbol,
            $this->parameters(),
            'bootstrap-fast',
            at: $at,
            applyAdmissionLimits: false,
            reuseCompleted: $reuseCompleted
        );
        $reservation = $this->workRuns->reserveDispatch($claim['run']->id, $at);
        $this->assertNotNull($reservation);
        $token = $reservation['delivery_token'];
        $this->assertTrue($this->workRuns->markDispatched($claim['run']->id, $token, $at));
        $this->assertTrue($this->workRuns->markStarted($claim['run']->id, $token, 1, $at));
        $orchestrationToken = $this->workRuns->reserveOrchestration($claim['run']->id, $token, 1, $at);
        $this->assertNotNull($orchestrationToken);
        $this->assertTrue($this->workRuns->markOrchestrationDispatched(
            $claim['run']->id,
            $token,
            1,
            $orchestrationToken,
            $at
        ));

        return [$claim['run'], $token];
    }

    /** @return array{SymbolBootstrapPhase,string} */
    private function startPhase(
        WorkRun $run,
        string $phaseName,
        ?CarbonImmutable $at = null
    ): array {
        $at ??= $this->at();
        $reservation = $this->bootstraps->reservePhase($run->id, $phaseName, $at);
        $this->assertNotNull($reservation, "Phase [{$phaseName}] was not dispatchable.");
        $token = $reservation['delivery_token'];
        $this->assertTrue($this->bootstraps->markPhaseDispatched($run->id, $phaseName, $token, $at));
        $this->assertTrue($this->bootstraps->markPhaseStarted($run->id, $phaseName, $token, 1, $at));

        return [$reservation['phase'], $token];
    }

    private function completeNoOptionsRun(WorkRun $run, ?CarbonImmutable $at = null): void
    {
        $at ??= $this->at();
        foreach (SymbolBootstrapCoordinator::PHASES as $phaseName) {
            [$phase, $token] = $this->startPhase($run, $phaseName, $at);
            if ($phaseName === SymbolBootstrapCoordinator::PHASE_CATALOG) {
                $this->bootstraps->freezeCatalog(
                    $run->id,
                    $token,
                    [],
                    ['complete' => true, 'capped' => false, 'source' => 'test'],
                    $at
                );
            }
            if ($phaseName === SymbolBootstrapCoordinator::PHASE_FAST_EOD) {
                $this->bootstraps->publishCoverage($run->id, 'fast', $token, 1, $at);
            }
            if ($phaseName === SymbolBootstrapCoordinator::PHASE_FILL) {
                $this->bootstraps->publishCoverage($run->id, 'fill', $token, 1, $at);
            }
            $this->assertTrue($this->bootstraps->markPhaseCompleted(
                $run->id,
                $phaseName,
                $token,
                1,
                [],
                $at
            ));
        }
    }

    /** @return array<string,string> */
    private function parameters(): array
    {
        return [
            'purpose' => SymbolBootstrapPolicy::PURPOSE,
            'session_date' => '2026-08-14',
        ];
    }

    private function at(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-17 14:00:00', 'UTC');
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
}
