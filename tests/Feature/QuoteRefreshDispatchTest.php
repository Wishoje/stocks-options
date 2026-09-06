<?php

namespace Tests\Feature;

use App\Jobs\FetchUnderlyingQuotesJob;
use App\Models\User;
use App\Models\WorkRun;
use App\Support\QuoteRefreshDispatcher;
use App\Support\WorkRunCoordinator;
use App\Support\WorkRunDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\MySqlTestCase;

class QuoteRefreshDispatchTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 16:00:00', 'UTC'));
        config()->set('quote_refresh.enabled', true);
        config()->set('quote_refresh.completed_ttl_seconds', 300);
        config()->set('quote_refresh.final_delay_minutes', 15);
        config()->set('quote_refresh.final_window_minutes', 15);
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.retry_after', 1080);
        config()->set('queue_lanes.isolated', false);
        config()->set('work_runs.reusable_seconds.quote_refresh', 300);
        config()->set('work_runs.running_ttl_seconds.quote_refresh', 300);
        config()->set('work_runs.running_recovery_enabled', true);
        config()->set('work_runs.running_recovery_max_dispatches', 3);
        config()->set('work_runs.rate_limits.accepted_symbol_per_minute', 1000);
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 1000);
        Cache::flush();
        Bus::fake();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_every_canonical_symbol_has_a_durable_intent_before_bounded_enqueue_and_pending_ticks_coalesce(): void
    {
        $sizes = [];
        Bus::shouldReceive('dispatch')->times(3)->andReturnUsing(function (FetchUnderlyingQuotesJob $job) use (&$sizes): string {
            $sizes[] = count($job->symbols);
            $this->assertTrue($job->scheduled);
            $this->assertSame('2026-09-08', $job->sessionDate);
            $this->assertSame('regular', $job->phase);
            $this->assertSame('quotes', $job->queue);
            $this->assertSame('redis', $job->connection);
            $this->assertSame($job->symbols, array_keys($job->workRunDeliveries));
            foreach ($job->workRunDeliveries as $symbol => $delivery) {
                $run = WorkRun::query()->findOrFail($delivery['run_id']);
                $this->assertSame('quote_refresh', $run->kind);
                $this->assertSame($symbol, $run->symbol);
                $this->assertSame(WorkRun::STATUS_PENDING, $run->status);
                $this->assertSame($delivery['delivery_token'], $run->delivery_token);
                $this->assertSame(['phase' => 'regular', 'session_date' => '2026-09-08'], $run->parameters);
                $this->assertNull($run->dispatched_at);
            }

            return 'fake-delivery';
        });
        $symbols = ['SPY', 'QQQ', 'IWM', 'AAPL', 'MSFT', 'NVDA', 'TSLA', 'AMZN', 'META', ' spy ', 'aapl', ''];
        $result = $this->dispatcher()->dispatch($symbols);
        $this->travel(5)->minutes();
        $again = $this->dispatcher()->dispatch($symbols);

        $this->assertSame([4, 4, 1], $sizes);
        $this->assertSame(9, $result['created']);
        $this->assertSame(9, $result['dispatched']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(9, $again['reused']);
        $this->assertSame(0, $again['dispatched']);
        $this->assertDatabaseCount('work_runs', 9);
        $this->assertDatabaseCount('work_run_slots', 9);
    }

    public function test_due_filter_reads_receipt_states_once_and_does_not_repoll_delayed_source_timestamps(): void
    {
        $this->state('AAPL', ['captured_at' => now('UTC'), 'ingestion_completed_at' => now('UTC'), 'source_asof' => now('UTC')->subMinutes(15)]);
        $this->state('MSFT', ['captured_at' => now('UTC')->subMinutes(5), 'ingestion_completed_at' => now('UTC')->subSeconds(298), 'source_asof' => now('UTC')]);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'quote_refresh_states')) {
                $queries[] = $query->sql;
            }
        });

        $result = $this->dispatcher()->dispatch(['AAPL', 'MSFT']);

        $this->assertSame(1, $result['fresh']);
        $this->assertSame(1, $result['dispatched']);
        $this->assertCount(1, $queries);
        Bus::assertDispatched(FetchUnderlyingQuotesJob::class, fn ($job): bool => $job->symbols === ['MSFT']);
        $this->assertDatabaseMissing('work_runs', ['symbol' => 'AAPL']);
    }

    public function test_successful_capture_keeps_five_minute_cadence_despite_later_completion_without_a_moving_scope_key(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:30:00', 'America/New_York'));
        $this->dispatcher()->dispatch(['SPY']);
        $run = WorkRun::query()->sole();
        $capturedAt = now('UTC')->toImmutable();
        $this->travel(2)->seconds();
        $this->complete($run);
        $this->state('SPY', ['captured_at' => $capturedAt, 'received_at' => now('UTC'), 'ingestion_completed_at' => now('UTC')]);
        $this->travelTo($capturedAt->addSeconds(299));
        $this->assertSame(0, $this->dispatcher()->dispatch(['SPY'])['dispatched']);
        $this->travel(1)->seconds();
        $this->assertSame(1, $this->dispatcher()->dispatch(['SPY'])['dispatched']);

        $replacement = WorkRun::query()->where('generation', 2)->sole();
        $this->assertSame($run->slot_key, $replacement->slot_key);
        $this->assertSame($run->parameters, $replacement->parameters);
        $this->assertDatabaseCount('work_run_slots', 1);
    }

    public function test_final_refresh_is_not_suppressed_by_regular_reuse_and_a_final_receipt_suppresses_later_final_ticks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 15:59:00', 'America/New_York'));
        $this->dispatcher()->dispatch(['SPY']);
        $regular = WorkRun::query()->sole();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 16:14:00', 'America/New_York'));
        $this->complete($regular);
        $this->travel(1)->minutes();
        $this->state('SPY', ['ingestion_completed_at' => now('UTC')->subMinute()]);

        $result = $this->dispatcher()->dispatch(['SPY']);
        $this->assertSame('final', $result['phase']);
        $this->assertSame(1, $result['created']);
        $final = WorkRun::query()->where('id', '!=', $regular->id)->sole();
        $this->assertNotSame($regular->slot_key, $final->slot_key);
        $this->assertSame('final', $final->parameters['phase']);
        $this->complete($final);
        $this->state('SPY', ['final_captured_at' => now('UTC'), 'final_received_at' => now('UTC'), 'final_completed_at' => now('UTC')]);
        $this->travel(5)->minutes();
        $this->assertSame(1, $this->dispatcher()->dispatch(['SPY'])['fresh']);
        $this->assertDatabaseCount('work_runs', 2);
        Bus::assertDispatchedTimes(FetchUnderlyingQuotesJob::class, 2);
    }

    public function test_transport_failure_retains_every_reserved_intent_and_reconciliation_uses_singleton_metadata(): void
    {
        $originalBus = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->twice()->andReturnUsing(function (FetchUnderlyingQuotesJob $job): string {
            if (count($job->symbols) === 4) {
                throw new RuntimeException('Simulated transport failure');
            }

            return 'fake-delivery';
        });
        $result = $this->dispatcher()->dispatch(['SPY', 'QQQ', 'IWM', 'AAPL', 'MSFT']);
        $this->assertSame(4, $result['failed']);
        $this->assertSame(1, $result['dispatched']);
        $run = WorkRun::query()->where('symbol', 'SPY')->sole();
        $this->assertSame(WorkRun::STATUS_PENDING, $run->status);
        $this->assertNull($run->delivery_token);
        $this->assertSame('dispatch_failed', $run->error_category);
        $this->assertSame(4, WorkRun::query()->where('error_category', 'dispatch_failed')->count());
        Bus::swap($originalBus);
        $this->assertFalse(app(WorkRunDispatcher::class)->dispatch($run));
        $this->travelTo($run->next_dispatch_at);
        $this->assertTrue(app(WorkRunDispatcher::class)->dispatch($run));
        $this->assertSingleton($run);
    }

    public function test_admission_pressure_keeps_due_intents_and_does_not_create_new_runs_on_later_ticks(): void
    {
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 1);
        $result = $this->dispatcher()->dispatch(['SPY', 'QQQ', 'AAPL']);
        $this->assertSame(3, $result['created']);
        $this->assertSame(2, $result['deferred']);
        $this->assertSame(1, $result['dispatched']);
        $this->assertSame(3, $this->dispatcher()->dispatch(['SPY', 'QQQ', 'AAPL'])['reused']);
        $this->assertDatabaseCount('work_runs', 3);
        $this->assertSame(2, WorkRun::query()->where('error_category', 'admission_deferred')->count());
    }

    public function test_lost_running_quote_respects_transport_safety_and_recovers_with_a_new_token(): void
    {
        $this->dispatcher()->dispatch(['SPY']);
        $run = WorkRun::query()->sole();
        $token = $run->delivery_token;
        $at = now('UTC')->toImmutable();
        $this->assertTrue($this->runs()->markStarted($run->id, $token, 1));
        $this->assertNull($this->runs()->recoverExpiredRunning($run->id, $at->addSeconds(301)));
        $this->travelTo($at->addSeconds(1140));
        $this->assertSame('recovered', $this->runs()->recoverExpiredRunning($run->id));
        $this->assertFalse($this->runs()->markCompleted($run->id, $token, 1));
        Bus::fake();
        $this->assertSame(0, Artisan::call('work-runs:reconcile'));
        $this->assertSingleton($run);
        $this->assertNotSame($token, $run->fresh()->delivery_token);
        $this->assertSame(2, $run->fresh()->dispatch_attempts);
    }

    public function test_repeated_quote_worker_losses_exhaust_the_existing_finite_delivery_budget(): void
    {
        $this->dispatcher()->dispatch(['SPY']);
        $run = WorkRun::query()->sole();
        for ($delivery = 1; $delivery <= 3; $delivery++) {
            $run->refresh();
            $this->assertSame($delivery, $run->dispatch_attempts);
            $this->assertTrue($this->runs()->markStarted($run->id, $run->delivery_token, 1));
            $this->travel(1140)->seconds();
            $this->assertSame($delivery < 3 ? 'recovered' : 'exhausted', $this->runs()->recoverExpiredRunning($run->id));
            if ($delivery < 3) {
                $this->assertTrue(app(WorkRunDispatcher::class)->dispatch($run->id));
            }
        }
        $this->assertSame(WorkRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame('recovery_exhausted', $run->fresh()->error_category);
        $this->assertFalse(app(WorkRunDispatcher::class)->dispatch($run->id));
        Bus::assertDispatchedTimes(FetchUnderlyingQuotesJob::class, 3);
    }

    public function test_failed_quote_cooldown_is_preserved_across_scheduler_ticks(): void
    {
        $this->dispatcher()->dispatch(['SPY']);
        $run = WorkRun::query()->sole();
        $this->runs()->markStarted($run->id, $run->delivery_token, 1);
        $this->runs()->markFailed($run->id, $run->delivery_token, 1, 'provider_authentication', 'unauthorized');
        $this->travel(299)->seconds();
        $this->assertSame(0, $this->dispatcher()->dispatch(['SPY'])['created']);
        $this->assertDatabaseCount('work_runs', 1);
        $this->assertSame(WorkRun::STATUS_FAILED, $run->fresh()->status);
    }

    #[DataProvider('closedWindows')]
    public function test_closed_command_guards_before_universe_queries_and_dispatcher_does_not_materialize_symbols(string $at): void
    {
        $this->travelTo(CarbonImmutable::parse($at, 'America/New_York'));
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (preg_match('/watchlists|hot_option_symbols|work_runs|quote_refresh_states/', $query->sql)) {
                $queries[] = $query->sql;
            }
        });
        $this->assertSame(0, Artisan::call('prices:refresh', ['--source' => 'both']));
        $symbols = (static function (): \Generator {
            throw new RuntimeException('Closed dispatch must not materialize the universe.');
            yield 'SPY';
        })();
        $this->assertFalse($this->dispatcher()->dispatch($symbols)['eligible']);
        $this->assertSame([], $queries);
        Bus::assertNothingDispatched();
    }

    public static function closedWindows(): array
    {
        return [
            ['2026-09-06 12:00:00'], ['2026-09-07 12:00:00'], ['2026-09-08 09:29:59'],
            ['2026-09-08 16:00:00'], ['2026-09-08 16:14:59'], ['2026-09-08 16:30:00'],
            ['2026-11-27 13:00:00'], ['2026-11-27 13:30:00'],
        ];
    }

    public function test_watchlist_command_deduplicates_canonical_symbols_in_sql(): void
    {
        foreach ([' spy ', 'SPY', 'aapl', ' AAPL ', 'QQQ', ''] as $symbol) {
            DB::table('watchlists')->insert([
                'user_id' => User::factory()->create()->id, 'symbol' => $symbol, 'timeframe' => '14d',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'watchlists')) {
                $queries[] = strtolower($query->sql);
            }
        });
        $this->assertSame(0, Artisan::call('prices:refresh', ['--source' => 'watchlist']));
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('select distinct upper(trim(symbol))', $queries[0]);
        Bus::assertDispatched(FetchUnderlyingQuotesJob::class, fn ($job): bool => $job->symbols === ['AAPL', 'QQQ', 'SPY']);
        $this->assertDatabaseCount('work_runs', 3);
    }

    public function test_disabled_flag_preserves_legacy_command_batches_without_durable_claims(): void
    {
        config()->set('quote_refresh.enabled', false);
        $this->travelTo(CarbonImmutable::parse('2026-09-06 16:00:00', 'UTC'));
        $this->assertSame(0, Artisan::call('prices:refresh'));
        Bus::assertDispatchedTimes(FetchUnderlyingQuotesJob::class, 2);
        foreach (Bus::dispatched(FetchUnderlyingQuotesJob::class) as $job) {
            $this->assertCount(4, $job->symbols);
            $this->assertSame([], $job->workRunDeliveries);
        }
        $this->assertDatabaseCount('work_runs', 0);
    }

    #[DataProvider('schedulerWindows')]
    public function test_scheduler_extends_only_the_enabled_window(bool $enabled, string $time, bool $expected): void
    {
        config()->set('quote_refresh.enabled', $enabled);
        $this->travelTo(CarbonImmutable::parse('2026-09-08 '.$time, 'America/New_York'));
        \Illuminate\Support\Facades\Schedule::swap(new Schedule('UTC'));
        require base_path('routes/console.php');
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'prices:refresh:intraday');
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * 1-5', $event->expression);
        $this->assertSame('America/New_York', $event->timezone);
        $this->assertSame($expected, $event->filtersPass($this->app));
    }

    public static function schedulerWindows(): array
    {
        return [[true, '09:30:00', true], [false, '09:30:00', false], [true, '16:15:00', true],
            [false, '16:15:00', false], [false, '15:55:00', true], [true, '16:35:00', false]];
    }

    private function state(string $symbol, array $attributes): void
    {
        DB::table('quote_refresh_states')->updateOrInsert(
            ['symbol' => $symbol, 'session_date' => now('America/New_York')->toDateString()],
            $attributes,
        );
    }

    private function complete(WorkRun $run): void
    {
        $this->assertTrue($this->runs()->markStarted($run->id, $run->delivery_token, 1));
        $this->assertTrue($this->runs()->markCompleted($run->id, $run->delivery_token, 1));
    }

    private function assertSingleton(WorkRun $run): void
    {
        $fresh = $run->fresh();
        Bus::assertDispatched(FetchUnderlyingQuotesJob::class, fn ($job): bool => $job->symbols === [$run->symbol]
            && $job->scheduled && $job->sessionDate === $run->parameters['session_date']
            && $job->phase === $run->parameters['phase'] && $job->queue === $run->queue
            && $job->workRunDeliveries === [$run->symbol => ['run_id' => $run->id, 'delivery_token' => $fresh->delivery_token]]);
    }

    private function dispatcher(): QuoteRefreshDispatcher
    {
        return app(QuoteRefreshDispatcher::class);
    }

    private function runs(): WorkRunCoordinator
    {
        return app(WorkRunCoordinator::class);
    }
}
