<?php

namespace Tests\Feature;

use App\Exceptions\ProviderDeferred;
use App\Models\SymbolBootstrapPhase;
use App\Models\User;
use App\Models\WorkRun;
use App\Models\WorkRunSlot;
use App\Support\SymbolBootstrapCoordinator;
use App\Support\SymbolBootstrapPolicy;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\MySqlTestCase;

class ProviderDurableDeferralTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 16:00:00', 'UTC'));
        Cache::flush();
        Bus::fake();
        config()->set('provider_backpressure.enabled', true);
        config()->set('queue_lanes.isolated', false);
        config()->set('symbol_bootstrap.enabled', false);
        config()->set('work_runs.running_recovery_enabled', true);
        config()->set('work_runs.running_recovery_max_dispatches', 3);
        config()->set('work_runs.pending_ttl_seconds', 43200);
        config()->set('work_runs.abandon_after_seconds', 86400);
        config()->set('symbol_bootstrap.max_phase_attempts', 5);
        config()->set('symbol_bootstrap.pending_lease_seconds', 3600);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_work_deferral_revokes_old_delivery_and_rounds_retry_deadline_up_without_early_dispatch(): void
    {
        $run = $this->createRun();
        $token = $this->startRun($run);
        $deadline = CarbonImmutable::now('UTC')->addSeconds(10)->addMicrosecond();
        $error = new ProviderDeferred(ProviderDeferred::CAPACITY, $deadline);

        $this->assertTrue($this->runs()->deferProvider($run->id, $token, 1, $error, 0));
        $run->refresh();
        $this->assertSame(WorkRun::STATUS_PENDING, $run->status);
        $this->assertNull($run->delivery_token);
        $this->assertSame(1, $run->dispatch_attempts);
        $this->assertSame(1, $run->provider_deferrals);
        $this->assertSame(1, $run->provider_admission_deferrals);
        $this->assertSame(0, $run->effectiveDispatchAttempts());
        $this->assertSame('2026-09-08T16:00:11+00:00', $run->next_dispatch_at->toIso8601String());
        $this->assertTrue($run->lease_expires_at->isAfter($run->next_dispatch_at));
        $this->assertFalse($this->runs()->deferProvider($run->id, $token, 1, $error, 0));
        $this->assertFalse($this->runs()->markCompleted($run->id, $token, 1));
        $this->assertFalse($this->runs()->markFailed($run->id, $token, 1, 'late', 'late'));
        $this->assertFalse($this->runs()->markStarted($run->id, $token, 2));
        $this->travelTo($run->next_dispatch_at->subSecond());
        $this->assertNull($this->runs()->reserveDispatch($run->id));
        $this->travelTo($run->next_dispatch_at);
        $this->assertNotSame($token, $this->startRun($run));
        $this->assertSame(2, $run->fresh()->dispatch_attempts);
        $payload = $this->runs()->payload($run->fresh());
        $this->assertSame(1, $payload['provider_deferrals']);
        $this->assertSame(1, $payload['provider_admission_deferrals']);
        $this->assertSame(1, $payload['effective_dispatch_attempts']);
    }

    public function test_seven_zero_http_admissions_do_not_consume_the_three_actual_failure_budget(): void
    {
        $run = $this->createRun();
        for ($i = 0; $i < 7; $i++) {
            $token = $this->startRun($run);
            $this->assertTrue($this->runs()->deferProvider($run->id, $token, 1, $this->deferred(), 0));
            $run->refresh();
            $this->assertSame(WorkRun::STATUS_PENDING, $run->status);
            $this->assertSame(0, $run->effectiveDispatchAttempts());
            $this->travelTo($run->next_dispatch_at);
        }
        for ($i = 1; $i <= 3; $i++) {
            $token = $this->startRun($run);
            $error = $this->deferred(ProviderDeferred::SERVER_ERROR);
            $this->assertTrue($this->runs()->deferProvider($run->id, $token, 1, $error, 1));
            $run->refresh();
            $this->assertSame($i, $run->effectiveDispatchAttempts());
            $this->assertSame(7, $run->provider_admission_deferrals);
            if ($i < 3) {
                $this->assertSame(WorkRun::STATUS_PENDING, $run->status);
                $this->travelTo($run->next_dispatch_at);
            }
        }
        $this->assertSame(10, $run->dispatch_attempts);
        $this->assertSame(10, $run->provider_deferrals);
        $this->assertSame(WorkRun::STATUS_FAILED, $run->status);
        $this->assertSame('provider_retry_exhausted', $run->error_code);
        $this->assertNull($run->next_dispatch_at);
        $this->assertNull($this->runs()->reserveDispatch($run->id));
    }

    public function test_capacity_miss_after_an_earlier_page_and_a_zero_count_http_error_do_not_receive_admission_credit(): void
    {
        $run = $this->createRun();
        $token = $this->startRun($run);
        $this->assertTrue($this->runs()->deferProvider($run->id, $token, 1, $this->deferred(), 2));
        $run->refresh();
        $this->assertSame(0, $run->provider_admission_deferrals);
        $this->assertSame(1, $run->effectiveDispatchAttempts());
        $this->travelTo($run->next_dispatch_at);
        $token = $this->startRun($run);
        $this->assertTrue($this->runs()->deferProvider($run->id, $token, 1, $this->deferred(ProviderDeferred::RATE_LIMITED), 0));
        $this->assertSame(0, $run->fresh()->provider_admission_deferrals);
        $this->assertSame(2, $run->fresh()->effectiveDispatchAttempts());
    }

    public function test_four_admissions_do_not_hide_three_later_crashes_from_recovery_limits(): void
    {
        $run = $this->createRun();
        for ($i = 0; $i < 4; $i++) {
            $token = $this->startRun($run);
            $this->runs()->deferProvider($run->id, $token, 1, $this->deferred(), 0);
            $this->travelTo($run->fresh()->next_dispatch_at);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->startRun($run);
            $this->travelTo(CarbonImmutable::now('UTC')->addHours(2));
            $this->assertSame($i < 3 ? 'recovered' : 'exhausted', $this->runs()->recoverExpiredRunning($run->id));
        }
        $run->refresh();
        $this->assertSame(7, $run->dispatch_attempts);
        $this->assertSame(4, $run->provider_admission_deferrals);
        $this->assertSame(3, $run->effectiveDispatchAttempts());
        $this->assertSame(WorkRun::STATUS_FAILED, $run->status);
    }

    public function test_long_retry_after_extends_the_lease_and_fixed_lifetime_without_premature_abandonment(): void
    {
        $run = $this->createRun();
        $token = $this->startRun($run);
        $at = CarbonImmutable::now('UTC');
        $header = $at->addDays(3);
        $this->assertTrue($this->runs()->deferProvider($run->id, $token, 1,
            new ProviderDeferred(ProviderDeferred::RATE_LIMITED, $header, 429), 1));
        $run->refresh();
        $this->assertTrue($run->provider_deferral_deadline_at->equalTo($header->addSeconds(43200)));
        $this->travelTo($at->addDays(2));
        $this->assertFalse($this->runs()->markAbandoned($run->id));
        $this->assertNull($this->runs()->reserveDispatch($run->id));
        $this->travelTo($header);
        $token = $this->startRun($run);
        $fixed = $run->fresh()->provider_deferral_deadline_at;
        $laterHeader = $header->addDays(2);
        $this->assertTrue($this->runs()->deferProvider($run->id, $token, 1,
            new ProviderDeferred(ProviderDeferred::RATE_LIMITED, $laterHeader, 429), 1));
        $run->refresh();
        $this->assertTrue($run->provider_deferral_deadline_at->equalTo($fixed));
        $this->assertTrue($run->retry_not_before->greaterThanOrEqualTo($laterHeader));
        $this->assertSame('provider_wait_deadline', $run->error_code);
        $this->assertSame(WorkRun::STATUS_FAILED, $run->status);
    }

    public function test_pending_admission_has_no_attempt_credit_and_expires_at_its_fixed_lifetime(): void
    {
        $run = $this->createRun();
        $this->assertTrue($this->runs()->deferPendingProvider($run->id, $this->deferred(ProviderDeferred::BACKPRESSURE)));
        $run->refresh();
        $this->assertSame(0, $run->dispatch_attempts);
        $this->assertSame(1, $run->provider_deferrals);
        $this->assertSame(0, $run->provider_admission_deferrals);
        $deadline = $run->provider_deferral_deadline_at;
        $this->travelTo($run->next_dispatch_at);
        $this->assertTrue($this->runs()->deferPendingProvider($run->id, $this->deferred(ProviderDeferred::BACKPRESSURE)));
        $this->assertTrue($run->fresh()->provider_deferral_deadline_at->equalTo($deadline));
        $this->travelTo($deadline);
        $this->assertNull($this->runs()->reserveDispatch($run->id));
        $this->assertSame('provider_wait_deadline', $run->fresh()->error_code);
        $this->assertSame(0, $run->fresh()->dispatch_attempts);
    }

    public function test_wrong_token_attempt_or_slot_and_disabled_flag_cannot_defer_current_work(): void
    {
        $run = $this->createRun();
        $token = $this->startRun($run);
        $error = $this->deferred();
        $before = $run->fresh()->toArray();
        $this->assertFalse($this->runs()->deferProvider($run->id, 'wrong', 1, $error, 0));
        $this->assertFalse($this->runs()->deferProvider($run->id, $token, 2, $error, 0));
        $this->assertFalse($this->runs()->deferPendingProvider($run->id, $error));
        config()->set('provider_backpressure.enabled', false);
        $this->assertFalse($this->runs()->deferProvider($run->id, $token, 1, $error, 0));
        config()->set('provider_backpressure.enabled', true);
        WorkRunSlot::query()->whereKey($run->slot_key)->update(['current_run_id' => null]);
        $this->assertFalse($this->runs()->deferProvider($run->id, $token, 1, $error, 0));
        $this->assertSame($before, $run->fresh()->toArray());
    }

    public function test_unrepresentable_retry_time_is_rejected_without_mutating_a_delivery(): void
    {
        $run = $this->createRun();
        $token = $this->startRun($run);
        $before = $run->fresh()->toArray();
        try {
            $this->runs()->deferProvider($run->id, $token, 1,
                new ProviderDeferred(ProviderDeferred::RATE_LIMITED, CarbonImmutable::parse('2040-01-01', 'UTC'), 429), 1);
            $this->fail('An unrepresentable timestamp must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('durable timestamp range', $exception->getMessage());
        }
        $this->assertSame($before, $run->fresh()->toArray());
    }

    public function test_interactive_reuse_promotes_only_unreserved_background_work_and_preserves_provider_deadline(): void
    {
        $run = $this->createRun('calculator_refresh', 'calculator-fill');
        $notBefore = CarbonImmutable::now('UTC')->addMinutes(5);
        $this->runs()->deferPendingProvider($run->id, new ProviderDeferred(ProviderDeferred::COOLDOWN, $notBefore));
        $user = User::factory()->create();
        $claim = $this->runs()->claim('calculator_refresh', 'SPY', ['expiry' => null],
            'calculator-interactive', $user, applyAdmissionLimits: false);
        $this->assertFalse($claim['created']);
        $this->assertSame($run->id, $claim['run']->id);
        $this->assertSame('calculator-interactive', $claim['run']->queue);
        $this->assertSame($user->id, $claim['run']->requested_by_user_id);
        $this->assertTrue($claim['run']->next_dispatch_at->equalTo($notBefore));
        $this->assertSame(0, $claim['run']->dispatch_attempts);

        $reserved = $this->createRun('calculator_refresh', 'calculator-fill', 'QQQ');
        $this->assertNotNull($this->runs()->reserveDispatch($reserved->id));
        $again = $this->runs()->claim('calculator_refresh', 'QQQ', ['expiry' => null],
            'calculator-interactive', $user, applyAdmissionLimits: false);
        $this->assertSame('calculator-fill', $again['run']->queue);
    }

    public function test_phase_deferral_preserves_completed_checkpoints_and_extends_parent_without_shortening_on_heartbeat(): void
    {
        [$parent, $fence] = $this->parentRun();
        $phase = $this->phase($parent);
        $token = $this->startPhase($parent);
        $before = $this->completedPhases($parent);
        $header = CarbonImmutable::now('UTC')->addDays(3);
        $error = new ProviderDeferred(ProviderDeferred::RATE_LIMITED, $header, 429);
        $this->assertTrue($this->phases()->deferProvider($parent->id, 'fill', $token, 1, $error, 1, expectedParentFence: $fence));
        $phase->refresh();
        $parent->refresh();
        $this->assertSame(SymbolBootstrapPhase::STATUS_PENDING, $phase->status);
        $this->assertNull($phase->delivery_token);
        $this->assertSame($before, $this->completedPhases($parent));
        $this->assertTrue($phase->next_dispatch_at->equalTo($header));
        $this->assertTrue($parent->lease_expires_at->greaterThanOrEqualTo($header->addHours(3)));
        $parentLease = $parent->lease_expires_at;
        $this->assertTrue($this->runs()->heartbeatOrchestration($parent->id, $fence['delivery_token'], 1, $fence['orchestration_token']));
        $this->assertTrue($parent->fresh()->lease_expires_at->equalTo($parentLease));
        $this->assertFalse($this->phases()->markPhaseCompleted($parent->id, 'fill', $token, 1));
        $this->assertFalse($this->phases()->isPhaseCurrent($parent->id, 'fill', $token));
        $this->travelTo($header->subSecond());
        $this->assertNull($this->phases()->reservePhase($parent->id, 'fill'));
        $this->assertFalse($this->runs()->markAbandoned($parent->id));
        $this->travelTo($header);
        $this->assertNotSame($token, $this->startPhase($parent));
        $payload = $this->phases()->payload($parent)['phases']['fill'];
        $this->assertSame(1, $payload['provider_deferrals']);
        $this->assertSame(0, $payload['provider_admission_deferrals']);
    }

    public function test_phase_admission_credits_preserve_the_five_actual_http_failure_budget(): void
    {
        [$parent, $fence] = $this->parentRun();
        for ($i = 0; $i < 7; $i++) {
            $token = $this->startPhase($parent);
            $this->assertTrue($this->phases()->deferProvider($parent->id, 'fill', $token, 1,
                $this->deferred(), 0, expectedParentFence: $fence));
            $this->assertSame(0, $this->phase($parent)->effectiveDispatchAttempts());
            $this->travelTo($this->phase($parent)->next_dispatch_at);
        }
        for ($i = 1; $i <= 5; $i++) {
            $token = $this->startPhase($parent);
            $this->assertTrue($this->phases()->deferProvider($parent->id, 'fill', $token, 1,
                $this->deferred(ProviderDeferred::SERVER_ERROR), 1, expectedParentFence: $fence));
            $phase = $this->phase($parent);
            $this->assertSame($i, $phase->effectiveDispatchAttempts());
            if ($i < 5) {
                $this->assertSame(SymbolBootstrapPhase::STATUS_PENDING, $phase->status);
                $this->travelTo($phase->next_dispatch_at);
            }
        }
        $this->assertSame(12, $phase->dispatch_attempts);
        $this->assertSame(7, $phase->provider_admission_deferrals);
        $this->assertSame(SymbolBootstrapPhase::STATUS_FAILED, $phase->status);
        $this->assertSame(WorkRun::STATUS_FAILED, $parent->fresh()->status);
        $this->assertSame('provider_retry_exhausted', $phase->error_code);
        $this->assertCount(3, $this->completedPhases($parent));
    }

    public function test_phase_real_failure_after_many_admissions_uses_first_failure_backoff_and_crashes_still_exhaust(): void
    {
        [$parent, $fence] = $this->parentRun();
        for ($i = 0; $i < 4; $i++) {
            $token = $this->startPhase($parent);
            $this->phases()->deferProvider($parent->id, 'fill', $token, 1, $this->deferred(), 0, expectedParentFence: $fence);
            $this->travelTo($this->phase($parent)->next_dispatch_at);
        }
        $token = $this->startPhase($parent);
        $at = CarbonImmutable::now('UTC');
        $this->assertTrue($this->phases()->markPhaseFailed($parent->id, 'fill', $token, 1, 'timeout', 'fixture'));
        $this->assertTrue($this->phase($parent)->retry_not_before->equalTo($at->addSeconds(15)));
        $this->assertSame(WorkRun::STATUS_RUNNING, $parent->fresh()->status);
        $this->travelTo($this->phase($parent)->next_dispatch_at);
        for ($i = 2; $i <= 5; $i++) {
            $this->startPhase($parent);
            $this->travelTo(CarbonImmutable::now('UTC')->addHours(2));
        }
        $this->assertNull($this->phases()->reservePhase($parent->id, 'fill'));
        $this->assertSame(9, $this->phase($parent)->dispatch_attempts);
        $this->assertSame(5, $this->phase($parent)->effectiveDispatchAttempts());
        $this->assertSame(WorkRun::STATUS_FAILED, $parent->fresh()->status);
    }

    public function test_phase_deferral_requires_current_parent_fence_phase_token_attempt_and_slot(): void
    {
        [$parent, $fence] = $this->parentRun();
        $token = $this->startPhase($parent);
        $error = $this->deferred();
        $before = $this->phase($parent)->toArray();
        $this->assertFalse($this->phases()->deferProvider($parent->id, 'fill', $token, 1, $error, 0));
        foreach (['delivery_token', 'attempt', 'orchestration_token'] as $key) {
            $wrong = $fence;
            $wrong[$key] = $key === 'attempt' ? 99 : 'stale';
            $this->assertFalse($this->phases()->deferProvider($parent->id, 'fill', $token, 1, $error, 0, expectedParentFence: $wrong));
        }
        $this->assertFalse($this->phases()->deferProvider($parent->id, 'fill', 'wrong', 1, $error, 0, expectedParentFence: $fence));
        $this->assertFalse($this->phases()->deferProvider($parent->id, 'fill', $token, 2, $error, 0, expectedParentFence: $fence));
        WorkRunSlot::query()->whereKey($parent->slot_key)->update(['current_run_id' => null]);
        $this->assertFalse($this->phases()->deferProvider($parent->id, 'fill', $token, 1, $error, 0, expectedParentFence: $fence));
        $this->assertSame($before, $this->phase($parent)->toArray());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('expiredPhaseReservations')]
    public function test_stale_parent_fence_cannot_terminalize_an_expired_or_exhausted_phase(array $changes): void
    {
        [$parent, $fence] = $this->parentRun();
        $this->phase($parent)->update($changes);
        $beforeParent = $parent->fresh()->toArray();
        $beforePhase = $this->phase($parent)->toArray();

        foreach (['delivery_token', 'attempt', 'orchestration_token'] as $key) {
            $wrong = $fence;
            $wrong[$key] = $key === 'attempt' ? 99 : 'stale';
            $this->assertNull($this->phases()->reservePhase($parent->id, 'fill', expectedParentFence: $wrong));
            $this->assertSame($beforeParent, $parent->fresh()->toArray());
            $this->assertSame($beforePhase, $this->phase($parent)->toArray());
        }

        $this->assertNull($this->phases()->reservePhase($parent->id, 'fill', expectedParentFence: $fence));
        $this->assertSame(WorkRun::STATUS_FAILED, $parent->fresh()->status);
        $this->assertSame(SymbolBootstrapPhase::STATUS_FAILED, $this->phase($parent)->status);
    }

    public static function expiredPhaseReservations(): array
    {
        return [
            'attempts exhausted' => [['dispatch_attempts' => 5]],
            'provider deadline expired' => [['provider_deferral_deadline_at' => '2026-09-08 15:59:59']],
        ];
    }

    public function test_unreserved_phase_backpressure_consumes_no_delivery_and_has_a_visible_fixed_lifetime(): void
    {
        [$parent, $fence] = $this->parentRun();
        $this->assertTrue($this->phases()->deferPendingPhase($parent->id, 'fill', CarbonImmutable::now('UTC')->addMinute(),
            ProviderDeferred::BACKPRESSURE, expectedParentFence: $fence));
        $phase = $this->phase($parent);
        $this->assertSame(0, $phase->dispatch_attempts);
        $this->assertSame(0, $phase->provider_admission_deferrals);
        $this->assertSame(1, $phase->provider_deferrals);
        $deadline = $phase->provider_deferral_deadline_at;
        $this->travelTo($phase->next_dispatch_at);
        $this->assertTrue($this->phases()->deferPendingPhase($parent->id, 'fill', CarbonImmutable::now('UTC')->addMinute(),
            ProviderDeferred::BACKPRESSURE, expectedParentFence: $fence));
        $this->assertTrue($this->phase($parent)->provider_deferral_deadline_at->equalTo($deadline));
        $this->travelTo($deadline);
        $this->assertNull($this->phases()->reservePhase($parent->id, 'fill'));
        $this->assertSame(0, $this->phase($parent)->dispatch_attempts);
        $this->assertSame('max_attempts:provider_wait_deadline', $this->phase($parent)->error_code);
        $this->assertSame(WorkRun::STATUS_FAILED, $parent->fresh()->status);
        $this->assertCount(3, $this->completedPhases($parent));
    }

    private function runs(): WorkRunCoordinator
    {
        return app(WorkRunCoordinator::class);
    }

    private function phases(): SymbolBootstrapCoordinator
    {
        return app(SymbolBootstrapCoordinator::class);
    }

    private function createRun(string $kind = 'intraday_refresh', string $queue = 'intraday', string $symbol = 'SPY'): WorkRun
    {
        $parameters = match ($kind) {
            'calculator_refresh' => ['expiry' => null],
            'symbol_bootstrap' => ['purpose' => SymbolBootstrapPolicy::PURPOSE, 'session_date' => '2026-09-04'],
            default => ['trade_date' => '2026-09-08'],
        };

        return $this->runs()->claim($kind, $symbol, $parameters, $queue, applyAdmissionLimits: false)['run'];
    }

    private function startRun(WorkRun $run): string
    {
        $reservation = $this->runs()->reserveDispatch($run->id);
        $this->assertNotNull($reservation);
        $token = $reservation['delivery_token'];
        $this->assertTrue($this->runs()->markDispatched($run->id, $token));
        $this->assertTrue($this->runs()->markStarted($run->id, $token, 1));

        return $token;
    }

    private function deferred(string $reason = ProviderDeferred::CAPACITY): ProviderDeferred
    {
        return new ProviderDeferred($reason, CarbonImmutable::now('UTC')->addSeconds(10),
            $reason === ProviderDeferred::RATE_LIMITED ? 429 : ($reason === ProviderDeferred::SERVER_ERROR ? 503 : null));
    }

    private function parentRun(): array
    {
        $parent = $this->createRun('symbol_bootstrap', 'bootstrap');
        $token = $this->startRun($parent);
        $orchestration = $this->runs()->reserveOrchestration($parent->id, $token, 1);
        $this->assertNotNull($orchestration);
        $this->assertTrue($this->runs()->markOrchestrationDispatched($parent->id, $token, 1, $orchestration));
        $this->phases()->initialize($parent);
        DB::table('symbol_bootstrap_phases')->where('work_run_id', $parent->id)
            ->whereIn('phase', ['quote', 'catalog', 'fast_eod'])->update([
                'status' => SymbolBootstrapPhase::STATUS_COMPLETED,
                'completed_at' => now('UTC'),
                'outcome' => json_encode(['checkpoint' => 'preserve-me']),
            ]);
        DB::table('symbol_bootstrap_phases')->where('work_run_id', $parent->id)->where('phase', 'fill')
            ->update(['status' => SymbolBootstrapPhase::STATUS_PENDING, 'next_dispatch_at' => now('UTC')]);

        return [$parent->fresh(), ['delivery_token' => $token, 'attempt' => 1, 'orchestration_token' => $orchestration]];
    }

    private function startPhase(WorkRun $parent): string
    {
        $reservation = $this->phases()->reservePhase($parent->id, 'fill');
        $this->assertNotNull($reservation);
        $token = $reservation['delivery_token'];
        $this->assertTrue($this->phases()->markPhaseDispatched($parent->id, 'fill', $token));
        $this->assertTrue($this->phases()->markPhaseStarted($parent->id, 'fill', $token, 1));

        return $token;
    }

    private function phase(WorkRun $parent): SymbolBootstrapPhase
    {
        return SymbolBootstrapPhase::query()->where('work_run_id', $parent->id)->where('phase', 'fill')->sole();
    }

    private function completedPhases(WorkRun $parent): array
    {
        return DB::table('symbol_bootstrap_phases')->where('work_run_id', $parent->id)
            ->where('status', SymbolBootstrapPhase::STATUS_COMPLETED)->orderBy('phase')
            ->get()->map(static fn (object $row): array => (array) $row)->all();
    }
}
