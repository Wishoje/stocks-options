<?php

namespace Tests\Feature;

use App\Jobs\FetchCalculatorChainJob;
use App\Models\User;
use App\Support\CalculatorPrimeScheduler;
use App\Support\CalculatorRefreshState;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CalculatorPrimeSchedulerTest extends TestCase
{
    use DatabaseTransactions;

    private CarbonImmutable $now;

    private CalculatorRefreshState $states;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-12 10:00:00', 'America/New_York');
        Carbon::setTestNow($this->now);
        Cache::flush();
        Bus::fake();

        if (DB::getDriverName() === 'sqlite' && ! Schema::hasTable('watchlists')) {
            Schema::create('watchlists', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('symbol', 10);
                $table->string('timeframe')->nullable();
                $table->timestamps();
            });
        }

        config()->set('queue_lanes.isolated', false);
        config()->set('calculator.scheduler.max_symbols', 75);
        config()->set('calculator.scheduler.fresh_minutes', 10);
        config()->set('calculator.scheduler.failure_cooldown_seconds', 300);
        config()->set('calculator.scheduler.fallback_symbols', ['SPY', 'QQQ', 'IWM']);

        $this->states = app(CalculatorRefreshState::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_empty_watchlist_uses_the_documented_fallback_once(): void
    {
        $result = $this->scheduler()->dispatchDue($this->now);

        $this->assertSame('fallback', $result['source']);
        $this->assertSame('scheduled:20260812T1400Z', $result['generation']);
        $this->assertSame(0, $result['configured_count']);
        $this->assertEqualsCanonicalizing(['SPY', 'QQQ', 'IWM'], $result['dispatched']);
        $this->assertEqualsCanonicalizing(['SPY', 'QQQ', 'IWM'], $this->dispatchedSymbols());
        $this->assertCount(3, $this->dispatchedSymbols());
        $this->assertCount(1, collect($this->dispatchedJobs())->pluck('schedulerGeneration')->unique());
    }

    public function test_all_fresh_watchlist_dispatches_nothing_and_never_falls_back(): void
    {
        $this->watchlist(['AAPL', 'MSFT', 'NVDA']);
        foreach (['AAPL', 'MSFT', 'NVDA'] as $symbol) {
            $this->complete($symbol, $this->now->subMinutes(9));
        }

        $result = $this->scheduler()->dispatchDue($this->now, 'generation-fresh');

        $this->assertSame('watchlist', $result['source']);
        $this->assertSame([], $result['dispatched']);
        $this->assertSame([], $this->dispatchedSymbols());
    }

    public function test_partly_stale_watchlist_dispatches_the_exact_due_set_in_fair_order(): void
    {
        $this->watchlist(['AAPL', 'MSFT', 'NVDA']);
        $this->complete('AAPL', $this->now->subMinutes(11));
        $this->complete('MSFT', $this->now->subMinutes(10));

        $result = $this->scheduler()->dispatchDue($this->now, 'generation-partly-stale');

        $this->assertSame(['NVDA', 'AAPL'], $result['dispatched']);
        $this->assertSame(['NVDA', 'AAPL'], $this->dispatchedSymbols());
    }

    public function test_fully_stale_completed_symbols_are_ordered_by_oldest_publication(): void
    {
        $this->watchlist(['AAPL', 'MSFT', 'NVDA']);
        $this->complete('AAPL', $this->now->subMinutes(30));
        $this->complete('MSFT', $this->now->subMinutes(20));
        $this->complete('NVDA', $this->now->subMinutes(40));

        $result = $this->scheduler()->dispatchDue($this->now, 'generation-fully-stale');

        $this->assertSame(['NVDA', 'AAPL', 'MSFT'], $result['dispatched']);
        $this->assertSame(['NVDA', 'AAPL', 'MSFT'], $this->dispatchedSymbols());
    }

    public function test_pending_and_started_symbols_are_not_dispatched_again(): void
    {
        $this->watchlist(['AAPL', 'MSFT', 'NVDA']);
        $this->pending('AAPL', 'active-aapl', $this->now->subMinutes(5));
        [$generation, $token] = $this->pending('MSFT', 'active-msft', $this->now->subMinutes(5));
        $this->states->markStarted('MSFT', $generation, $token, 1, $this->now->subMinutes(4));

        $first = $this->scheduler()->dispatchDue($this->now, 'generation-one');
        $second = $this->scheduler()->dispatchDue($this->now->addMinutes(5), 'generation-two');

        $this->assertSame(['NVDA'], $first['dispatched']);
        $this->assertSame([], $second['dispatched']);
        $this->assertSame(['NVDA'], $this->dispatchedSymbols());
        $this->assertSame(CalculatorRefreshState::STATUS_PENDING, $this->states->get('AAPL')['status']);
        $this->assertSame(CalculatorRefreshState::STATUS_STARTED, $this->states->get('MSFT')['status']);
    }

    public function test_failed_symbol_is_not_fresh_and_recovers_after_cooldown(): void
    {
        $this->watchlist(['AAPL']);
        [$failedGeneration, $failedToken] = $this->pending(
            'AAPL',
            'failed-generation',
            $this->now->subMinute()
        );
        $this->states->markStarted(
            'AAPL',
            $failedGeneration,
            $failedToken,
            1,
            $this->now->subMinute()
        );
        $this->states->markFailed(
            'AAPL',
            $failedGeneration,
            $failedToken,
            'no_contracts',
            $this->now->subMinute()
        );

        $coolingDown = $this->scheduler()->dispatchDue($this->now, 'generation-cooldown');
        $recovered = $this->scheduler()->dispatchDue($this->now->addMinutes(4), 'generation-recovery');

        $this->assertSame([], $coolingDown['dispatched']);
        $this->assertSame(['AAPL'], $recovered['dispatched']);

        $job = $this->dispatchedJobs()[0];
        $this->states->markStarted(
            $job->symbol,
            (string) $job->schedulerGeneration,
            (string) $job->schedulerClaimToken,
            1,
            $this->now->addMinutes(4)
        );
        $this->states->markCompleted(
            $job->symbol,
            (string) $job->schedulerGeneration,
            (string) $job->schedulerClaimToken,
            $this->now->addMinutes(4)
        );

        $this->assertFalse($this->states->markFailed(
            'AAPL',
            $failedGeneration,
            $failedToken,
            'late_failure',
            $this->now->addMinutes(5)
        ));

        Bus::fake();
        $afterRecovery = $this->scheduler()->dispatchDue($this->now->addMinutes(9), 'generation-after-recovery');

        $this->assertSame([], $afterRecovery['dispatched']);
        $this->assertSame(CalculatorRefreshState::STATUS_COMPLETED, $this->states->get('AAPL')['status']);
    }

    public function test_dispatch_cap_rotates_never_completed_symbols_instead_of_starving_them(): void
    {
        $symbols = collect(range(1, 80))
            ->map(static fn (int $number): string => sprintf('S%03d', $number))
            ->all();
        $this->watchlist($symbols);

        $first = $this->scheduler()->dispatchDue($this->now, 'generation-cap-one');
        $second = $this->scheduler()->dispatchDue($this->now->addMinutes(5), 'generation-cap-two');

        $this->assertSame(array_slice($symbols, 0, 75), $first['dispatched']);
        $this->assertSame(array_slice($symbols, 75), $second['dispatched']);
        $this->assertCount(80, array_unique($this->dispatchedSymbols()));
    }

    public function test_recent_never_successful_failures_do_not_starve_older_successful_work(): void
    {
        $failedSymbols = collect(range(1, 75))
            ->map(static fn (int $number): string => sprintf('N%03d', $number))
            ->all();
        $this->watchlist(array_merge($failedSymbols, ['STALE']));
        $failureTime = $this->now->subMinutes(5);

        foreach ($failedSymbols as $symbol) {
            [$generation, $token] = $this->pending($symbol, 'failed-'.$symbol, $failureTime);
            $this->states->markStarted($symbol, $generation, $token, 1, $failureTime);
            $this->states->markFailed($symbol, $generation, $token, 'no_contracts', $failureTime);
        }
        $this->complete('STALE', $this->now->subMinutes(30));

        $result = $this->scheduler()->dispatchDue($this->now, 'generation-mixed-fairness');

        $this->assertSame('STALE', $result['dispatched'][0]);
        $this->assertSame(
            array_merge(['STALE'], array_slice($failedSymbols, 0, 74)),
            $result['dispatched']
        );
    }

    public function test_dispatch_failures_release_claims_and_still_respect_the_cap(): void
    {
        $symbols = collect(range(1, 80))
            ->map(static fn (int $number): string => sprintf('F%03d', $number))
            ->all();
        $this->watchlist($symbols);
        Bus::shouldReceive('dispatch')
            ->times(75)
            ->andThrow(new RuntimeException('queue unavailable'));

        $result = $this->scheduler()->dispatchDue($this->now, 'generation-dispatch-failure');

        $this->assertSame('dispatch_failed', $result['status']);
        $this->assertSame([], $result['dispatched']);
        $this->assertSame(array_slice($symbols, 0, 75), array_keys($result['dispatch_failures']));
        $this->assertSame(CalculatorRefreshState::STATUS_FAILED, $this->states->get('F001')['status']);
        $this->assertNull($this->states->get('F076'));
    }

    public function test_expired_active_state_can_be_reclaimed_but_live_claim_is_atomic(): void
    {
        $this->watchlist(['AAPL']);
        $oldTime = $this->now->subHours(13);
        Carbon::setTestNow($oldTime);
        $this->pending('AAPL', 'old-generation', $oldTime);
        Carbon::setTestNow($this->now);

        $first = $this->scheduler()->dispatchDue($this->now, 'new-generation');
        $second = $this->scheduler()->dispatchDue($this->now, 'new-generation');

        $this->assertSame(['AAPL'], $first['dispatched']);
        $this->assertSame([], $second['dispatched']);
        $this->assertCount(1, $this->dispatchedSymbols());
    }

    private function scheduler(): CalculatorPrimeScheduler
    {
        return app(CalculatorPrimeScheduler::class);
    }

    /** @param string[] $symbols */
    private function watchlist(array $symbols): void
    {
        $userId = DB::getDriverName() === 'sqlite'
            ? 1
            : User::factory()->create()->id;
        $now = now();

        DB::table('watchlists')->insert(array_map(static fn (string $symbol): array => [
            'user_id' => $userId,
            'symbol' => $symbol,
            'timeframe' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $symbols));
    }

    /** @return array{string, string} */
    private function pending(string $symbol, string $generation, CarbonInterface $at): array
    {
        $token = $this->states->claim($symbol, $generation, 'calculator', $at);
        $this->assertNotNull($token);

        return [$generation, $token];
    }

    private function complete(string $symbol, CarbonInterface $at): void
    {
        [$generation, $token] = $this->pending($symbol, 'completed-'.$symbol.'-'.$at->timestamp, $at);
        $this->assertTrue($this->states->markStarted($symbol, $generation, $token, 1, $at));
        $this->assertTrue($this->states->markCompleted($symbol, $generation, $token, $at));
    }

    /** @return FetchCalculatorChainJob[] */
    private function dispatchedJobs(): array
    {
        return Bus::dispatched(FetchCalculatorChainJob::class)->values()->all();
    }

    /** @return string[] */
    private function dispatchedSymbols(): array
    {
        return array_map(
            static fn (FetchCalculatorChainJob $job): string => $job->symbol,
            $this->dispatchedJobs()
        );
    }
}
