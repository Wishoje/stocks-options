<?php

namespace Tests\Feature;

use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Models\WorkRun;
use App\Models\WorkRunSlot;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MySqlTestCase;

class WorkRunLostDeliveryRecoveryTest extends MySqlTestCase
{
    use RefreshDatabase;

    private WorkRunCoordinator $runs;

    protected function setUp(): void
    {
        parent::setUp();

        // Older coordinator tests use explicit schema setup outside the
        // RefreshDatabase transaction. Isolate this command-wide scan.
        WorkRunSlot::query()->delete();
        WorkRun::query()->delete();

        config()->set('queue.default', 'redis');
        config()->set('work_runs.running_recovery_enabled', true);
        config()->set('work_runs.running_recovery_max_dispatches', 3);
        config()->set('work_runs.running_ttl_seconds.intraday_refresh', 1800);
        config()->set('work_runs.running_ttl_seconds.calculator_refresh', 1800);
        config()->set('work_runs.pending_ttl_seconds', 3600);
        config()->set('work_runs.dispatch_reservation_seconds', 30);
        $this->runs = app(WorkRunCoordinator::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-04 16:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public static function refreshKinds(): array
    {
        return [
            'intraday' => ['intraday_refresh', FetchPolygonIntradayOptionsJob::class],
            'calculator' => ['calculator_refresh', FetchCalculatorChainJob::class],
        ];
    }

    #[DataProvider('refreshKinds')]
    public function test_a_lost_running_delivery_is_requeued_on_the_same_generation_with_a_new_token(string $kind, string $jobClass): void
    {
        $at = now('UTC')->toImmutable();
        [$run, $oldToken] = $this->startedRun($kind, $at, 4);
        $expiredAt = $at->addSeconds(1801);

        $this->assertSame('recovered', $this->runs->recoverExpiredRunning($run->id, $expiredAt));
        $pending = $run->fresh();
        $this->assertSame(WorkRun::STATUS_PENDING, $pending->status);
        $this->assertNull($pending->delivery_token);
        $this->assertNull($pending->dispatched_at);
        $this->assertSame(0, $pending->attempt);
        $this->assertSame($run->generation, $pending->generation);
        $this->assertSame($run->id, WorkRunSlot::query()->findOrFail($run->slot_key)->current_run_id);
        $this->assertNull($this->runs->recoverExpiredRunning($run->id, $expiredAt));
        $this->assertFalse($this->runs->markStarted($run->id, $oldToken, 5, $expiredAt));
        $this->assertFalse($this->runs->markCompleted($run->id, $oldToken, 4, $expiredAt));

        Bus::fake();
        $this->travelTo($expiredAt);
        $this->assertSame(0, Artisan::call('work-runs:reconcile'));
        Bus::assertDispatchedTimes($jobClass, 1);
        Bus::assertDispatched($jobClass, fn (object $job): bool => $job->workRunId === $run->id
            && $job->workRunDeliveryToken !== $oldToken);
        $replacement = $run->fresh();
        $this->assertNotNull($replacement->delivery_token);
        $this->assertSame(2, $replacement->dispatch_attempts);
        $this->assertTrue($this->runs->markStarted($run->id, $replacement->delivery_token, 1, $expiredAt));
        $this->assertFalse($this->runs->markFailed($run->id, $oldToken, 4, 'timeout', 'late', $expiredAt));
        $this->assertTrue($this->runs->markCompleted($run->id, $replacement->delivery_token, 1, $expiredAt->addSecond()));
    }

    public function test_reconciliation_recovers_and_dispatches_an_expired_running_refresh_in_one_pass(): void
    {
        $at = now('UTC')->toImmutable();
        [$run, $oldToken] = $this->startedRun('intraday_refresh', $at);
        Bus::fake();
        $this->travelTo($at->addSeconds(1801));

        $this->assertSame(0, Artisan::call('work-runs:reconcile'));
        $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['recovered_running']);
        $this->assertSame(0, $report['recovery_exhausted']);
        $this->assertSame(1, $report['dispatched']);
        $this->assertSame(0, $report['abandoned']);
        Bus::assertDispatchedTimes(FetchPolygonIntradayOptionsJob::class, 1);
        $this->assertNotSame($oldToken, $run->fresh()->delivery_token);
        $this->assertSame(1, WorkRun::query()->count());
    }

    public function test_recovery_requires_both_an_expired_lease_and_an_expired_heartbeat(): void
    {
        $at = now('UTC')->toImmutable();
        [$run, $token] = $this->startedRun('calculator_refresh', $at);
        $later = $at->addSeconds(1801);
        $run->refresh()->forceFill(['heartbeat_at' => $later->subSecond()])->save();
        $this->assertNull($this->runs->recoverExpiredRunning($run->id, $later));
        $this->assertSame($token, $run->fresh()->delivery_token);

        $run->refresh()->forceFill(['heartbeat_at' => $at, 'lease_expires_at' => $later->addSecond()])->save();
        $this->assertNull($this->runs->recoverExpiredRunning($run->id, $later));
        $run->refresh()->forceFill(['lease_expires_at' => null])->save();
        $this->assertNull($this->runs->recoverExpiredRunning($run->id, $later));
        $run->refresh()->forceFill(['lease_expires_at' => $later])->save();
        $this->assertSame('recovered', $this->runs->recoverExpiredRunning($run->id, $later));
    }

    public function test_short_running_ttl_cannot_replay_before_the_transport_safety_floor(): void
    {
        config()->set('work_runs.running_ttl_seconds.intraday_refresh', 300);
        $at = now('UTC')->toImmutable();
        [$run] = $this->startedRun('intraday_refresh', $at);
        $this->assertNull($this->runs->recoverExpiredRunning($run->id, $at->addSeconds(301)));
        $this->assertNull($this->runs->recoverExpiredRunning($run->id, $at->addSeconds(1139)));
        $this->assertSame('recovered', $this->runs->recoverExpiredRunning($run->id, $at->addSeconds(1141)));
    }

    public function test_repeated_worker_loss_stops_after_the_finite_delivery_cap(): void
    {
        $at = now('UTC')->toImmutable();
        [$run, $token] = $this->startedRun('intraday_refresh', $at);

        for ($delivery = 1; $delivery < 3; $delivery++) {
            $at = $at->addSeconds(1801);
            $this->assertSame('recovered', $this->runs->recoverExpiredRunning($run->id, $at));
            $reservation = $this->runs->reserveDispatch($run->id, $at);
            $this->assertNotNull($reservation);
            $token = $reservation['delivery_token'];
            $this->assertTrue($this->runs->markDispatched($run->id, $token, $at));
            $this->assertTrue($this->runs->markStarted($run->id, $token, 1, $at));
        }

        $at = $at->addSeconds(1801);
        $this->assertSame('exhausted', $this->runs->recoverExpiredRunning($run->id, $at));
        $failed = $run->fresh();
        $this->assertSame(WorkRun::STATUS_FAILED, $failed->status);
        $this->assertSame(3, $failed->dispatch_attempts);
        $this->assertSame('recovery_exhausted', $failed->error_category);
        $this->assertSame('running_delivery_limit', $failed->error_code);
        $this->assertNull($failed->delivery_token);
        $this->assertNull($failed->lease_expires_at);
        $this->assertNotNull($failed->failed_at);
        $this->assertTrue($failed->retry_not_before->isAfter($at));
        $this->assertNull($this->runs->reserveDispatch($run->id, $at));
        $this->assertFalse($this->runs->markCompleted($run->id, $token, 1, $at));
        $this->assertNull($this->runs->recoverExpiredRunning($run->id, $at));
    }

    public function test_exhausted_recovery_is_reported_without_redispatch(): void
    {
        config()->set('work_runs.running_recovery_max_dispatches', 1);
        $at = now('UTC')->toImmutable();
        $this->startedRun('calculator_refresh', $at);
        Bus::fake();
        $this->travelTo($at->addSeconds(1801));

        $this->assertSame(0, Artisan::call('work-runs:reconcile'));
        $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $report['recovered_running']);
        $this->assertSame(1, $report['recovery_exhausted']);
        $this->assertSame(0, $report['dispatched']);
        Bus::assertNothingDispatched();
    }

    public function test_bootstrap_orchestration_and_noncurrent_generations_are_not_replayed(): void
    {
        $at = now('UTC')->toImmutable();
        [$bootstrap, $bootstrapToken] = $this->startedRun('symbol_bootstrap', $at);
        $orchestrationToken = $this->runs->reserveOrchestration($bootstrap->id, $bootstrapToken, 1, $at);
        $this->assertNotNull($orchestrationToken);
        $this->assertTrue($this->runs->markOrchestrationDispatched($bootstrap->id, $bootstrapToken, 1, $orchestrationToken, $at));
        $this->assertNull($this->runs->recoverExpiredRunning($bootstrap->id, $at->addDay()));
        $this->assertTrue($this->runs->isOrchestrationCurrent($bootstrap->id, $bootstrapToken, 1, $orchestrationToken));

        [$refresh, $refreshToken] = $this->startedRun('calculator_refresh', $at);
        WorkRunSlot::query()->whereKey($refresh->slot_key)->update(['current_run_id' => null]);
        $this->assertNull($this->runs->recoverExpiredRunning($refresh->id, $at->addSeconds(1801)));
        $this->assertSame($refreshToken, $refresh->fresh()->delivery_token);
    }

    public function test_completed_work_and_disabled_recovery_do_not_requeue(): void
    {
        $at = now('UTC')->toImmutable();
        [$run, $token] = $this->startedRun('calculator_refresh', $at);
        config()->set('work_runs.running_recovery_enabled', false);
        $this->assertNull($this->runs->recoverExpiredRunning($run->id, $at->addSeconds(1801)));
        $this->assertSame($token, $run->fresh()->delivery_token);
        config()->set('work_runs.running_recovery_enabled', true);
        $this->assertTrue($this->runs->markCompleted($run->id, $token, 1, $at->addSecond()));
        $this->assertNull($this->runs->recoverExpiredRunning($run->id, $at->addDay()));
    }

    public function test_existing_lost_pending_delivery_recovery_fences_the_old_payload(): void
    {
        $at = now('UTC')->toImmutable();
        $run = $this->claim('intraday_refresh', $at);
        $first = $this->runs->reserveDispatch($run->id, $at);
        $this->assertTrue($this->runs->markDispatched($run->id, $first['delivery_token'], $at));
        $this->assertNull($this->runs->reserveDispatch($run->id, $at->addSeconds(3599)));

        $second = $this->runs->reserveDispatch($run->id, $at->addHour());
        $this->assertNotNull($second);
        $this->assertSame($run->id, $second['run']->id);
        $this->assertSame($run->generation, $second['run']->generation);
        $this->assertNotSame($first['delivery_token'], $second['delivery_token']);
        $this->assertFalse($this->runs->markStarted($run->id, $first['delivery_token'], 1, $at->addHour()));
        $this->assertTrue($this->runs->markStarted($run->id, $second['delivery_token'], 1, $at->addHour()));
    }

    public function test_a_lost_dispatch_reservation_waits_for_its_expiration_and_fences_late_acknowledgement(): void
    {
        $at = now('UTC')->toImmutable();
        $run = $this->claim('calculator_refresh', $at);
        $first = $this->runs->reserveDispatch($run->id, $at);
        $this->assertNotNull($first);
        $this->assertNull($this->runs->reserveDispatch($run->id, $at->addSeconds(29)));

        $second = $this->runs->reserveDispatch($run->id, $at->addSeconds(30));
        $this->assertNotNull($second);
        $this->assertFalse($this->runs->markDispatched($run->id, $first['delivery_token'], $at->addSeconds(31)));
        $this->assertTrue($this->runs->markDispatched($run->id, $second['delivery_token'], $at->addSeconds(31)));
        $this->assertFalse($this->runs->markStarted($run->id, $first['delivery_token'], 1, $at->addSeconds(31)));
        $this->assertTrue($this->runs->markStarted($run->id, $second['delivery_token'], 1, $at->addSeconds(31)));
    }

    /** @return array{WorkRun, string} */
    private function startedRun(string $kind, CarbonImmutable $at, int $attempt = 1): array
    {
        $run = $this->claim($kind, $at);
        $reservation = $this->runs->reserveDispatch($run->id, $at);
        $this->assertNotNull($reservation);
        $token = $reservation['delivery_token'];
        $this->assertTrue($this->runs->markDispatched($run->id, $token, $at));
        $this->assertTrue($this->runs->markStarted($run->id, $token, $attempt, $at));

        return [$run->fresh(), $token];
    }

    private function claim(string $kind, CarbonImmutable $at): WorkRun
    {
        return $this->runs->claim(
            $kind,
            'AAPL',
            $kind === 'intraday_refresh' ? ['trade_date' => '2026-09-04'] : [],
            $kind === 'intraday_refresh' ? 'intraday' : 'calculator',
            at: $at,
            applyAdmissionLimits: false
        )['run'];
    }
}
