<?php

namespace Tests\Feature;

use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Models\User;
use App\Models\WorkRun;
use App\Support\IntradayRefreshDispatcher;
use App\Support\PolygonClient;
use App\Support\QueueLanes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\MySqlTestCase;

class IntradaySingletonDispatchTest extends MySqlTestCase
{
    use RefreshDatabase;

    private const SYMBOLS = ['SPY', 'QQQ', 'IWM', 'AAPL', 'MSFT', 'NVDA', 'TSLA', 'AMZN', 'META', 'GOOG', 'MU', 'BBY', 'V', 'AMD', 'CVX'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-04 16:00:00', 'UTC'));
        Cache::flush();
        Bus::fake();
        config()->set('intraday_dispatch.singleton_enabled', true);
        config()->set('intraday_dispatch.rollout_validated', true);
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.connection', 'queue');
        config()->set('queue_lanes.isolated', true);
        config()->set('queue_lanes.intraday_heavy_symbols', ['SPY', 'QQQ', 'IWM']);
        config()->set('services.massive.concurrency.enabled', true);
        config()->set('services.massive.concurrency.limit', 2);
        config()->set('work_runs.rate_limits.accepted_symbol_per_minute', 1000);
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 1000);
        config()->set('option_live_totals.dual_write', true);
        config()->set('option_live_totals.compare_writes', false);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_disabled_singletons_do_not_require_new_runtime_gates(): void
    {
        config()->set('intraday_dispatch.singleton_enabled', false);
        config()->set('intraday_dispatch.rollout_validated', false);
        config()->set('queue_lanes.isolated', false);
        config()->set('services.massive.concurrency.enabled', false);
        config()->set('queue.default', 'database');

        $this->assertFalse($this->dispatcher()->enabled());
        $this->assertDatabaseCount('work_runs', 0);
        Bus::assertNothingDispatched();
    }

    public function test_unsafe_activation_fails_before_creating_or_dispatching_work(): void
    {
        $cases = [
            ['intraday_dispatch.rollout_validated', false],
            ['queue_lanes.isolated', false],
            ['services.massive.concurrency.enabled', false],
            ['services.massive.concurrency.limit', 1],
            ['queue.default', 'database'],
            ['queue.connections.redis.connection', 'default'],
            ['queue_lanes.queues.intraday_heavy', 'intraday'],
        ];
        foreach ($cases as [$key, $invalid]) {
            $original = config($key);
            config()->set($key, $invalid);
            try {
                $this->dispatcher()->dispatch(['AAPL']);
                $this->fail('Unsafe singleton routing must fail before accepting work: '.$key);
            } catch (LogicException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            } finally {
                config()->set($key, $original);
            }
        }

        $this->assertDatabaseCount('work_runs', 0);
        Bus::assertNothingDispatched();
    }

    public function test_every_canonical_symbol_has_one_durable_intent_before_enqueue(): void
    {
        $seen = [];
        Bus::shouldReceive('dispatch')->twice()->andReturnUsing(function (FetchPolygonIntradayOptionsJob $job) use (&$seen): string {
            $this->assertCount(1, $job->symbols);
            $run = WorkRun::query()->findOrFail($job->workRunId);
            $this->assertSame(WorkRun::STATUS_PENDING, $run->status);
            $this->assertSame($run->delivery_token, $job->workRunDeliveryToken);
            $this->assertSame('2026-09-03', $run->parameters['trade_date']);
            $this->assertSame('2026-09-03', $job->tradeDate);
            $this->assertSame(QueueLanes::intraday($run->symbol), $job->queue);
            $seen[] = $run->symbol;

            return 'fake-delivery';
        });

        $result = $this->dispatcher()->dispatch([' aapl ', 'AAPL', ' spy ', 'SPY', ''], '2026-09-03');
        $again = $this->dispatcher()->dispatch(['AAPL', 'SPY'], '2026-09-03');

        $this->assertSame(['AAPL', 'SPY'], $seen);
        $this->assertSame(2, $result['created']);
        $this->assertSame(2, $result['dispatched']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(2, $again['reused']);
        $this->assertSame(0, $again['dispatched']);
        $this->assertDatabaseCount('work_runs', 2);
        $this->assertDatabaseCount('work_run_slots', 2);
    }

    public function test_one_enqueue_failure_retains_its_intent_and_does_not_skip_later_symbols(): void
    {
        Bus::shouldReceive('dispatch')->twice()->andReturnUsing(function (FetchPolygonIntradayOptionsJob $job): string {
            $this->assertDatabaseHas('work_runs', ['id' => $job->workRunId, 'symbol' => $job->symbols[0]]);
            if ($job->symbols === ['SPY']) {
                throw new RuntimeException('Simulated queue transport failure');
            }

            return 'fake-delivery';
        });

        $result = $this->dispatcher()->dispatch(['SPY', 'AAPL']);

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['dispatched']);
        $this->assertSame(1, $result['failed']);
        $failed = WorkRun::query()->where('symbol', 'SPY')->firstOrFail();
        $this->assertSame(WorkRun::STATUS_PENDING, $failed->status);
        $this->assertSame('dispatch_failed', $failed->error_category);
        $this->assertNull($failed->delivery_token);
        $this->assertTrue($failed->next_dispatch_at->isAfter(now('UTC')));
        $this->assertNotNull(WorkRun::query()->where('symbol', 'AAPL')->firstOrFail()->dispatched_at);
    }

    public function test_admission_deferral_still_persists_every_singleton_for_reconciliation(): void
    {
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 1);

        $result = $this->dispatcher()->dispatch(['AAPL', 'MSFT', 'QQQ']);

        $this->assertSame(3, $result['created']);
        $this->assertSame(1, $result['dispatched']);
        $this->assertSame(2, $result['deferred']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(2, WorkRun::query()->where('error_category', 'admission_deferred')->count());
        $this->assertDatabaseCount('work_runs', 3);
        Bus::assertDispatchedTimes(FetchPolygonIntradayOptionsJob::class, 1);
    }

    public function test_watchlist_deduplication_happens_in_the_database_before_materialization(): void
    {
        $this->seedWatchlist([' spy ', 'SPY', 'aapl', ' AAPL ', 'QQQ', '']);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'watchlists')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $this->assertSame(['AAPL', 'QQQ', 'SPY'], $this->dispatcher()->watchlistSymbols());
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('select distinct upper(trim(symbol))', $queries[0]);
    }

    public function test_the_scheduler_queues_one_job_per_symbol_on_the_shared_normal_or_heavy_lane(): void
    {
        $this->seedWatchlist(array_merge(self::SYMBOLS, [' spy ', 'aapl']));
        $event = collect(app(Schedule::class)->events())
            ->first(fn (object $event): bool => $event->description === 'intraday:polygon:pull');
        $this->assertNotNull($event);

        $event->run($this->app);

        Bus::assertDispatchedTimes(FetchPolygonIntradayOptionsJob::class, 15);
        foreach ($this->queuedJobs() as $job) {
            $this->assertCount(1, $job->symbols);
            $this->assertSame(QueueLanes::intraday($job->symbols[0]), $job->queue);
            $this->assertSame('2026-09-04', $job->tradeDate);
            $this->assertNotNull($job->workRunId);
        }
        $this->assertDatabaseCount('work_runs', 15);
    }

    public function test_warmup_deduplicates_ranked_symbols_before_limit_and_preserves_current_session(): void
    {
        $this->seedHotSymbols(['AAPL', ' aapl', 'SPY', 'MSFT'], '2026-09-03');

        $this->assertSame(0, Artisan::call('intraday:warmup', ['--limit' => 2]));

        Bus::assertDispatchedTimes(FetchPolygonIntradayOptionsJob::class, 2);
        $this->assertSame(['AAPL', 'SPY'], array_map(fn ($job): string => $job->symbols[0], $this->queuedJobs()));
        foreach ($this->queuedJobs() as $job) {
            $this->assertSame('2026-09-04', $job->tradeDate);
        }
    }

    public function test_cli_singletons_preserve_preopen_session_and_heavy_isolation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07 08:00:00', 'America/New_York'));

        $this->assertSame(0, Artisan::call('intraday:pull', ['symbols' => [' aapl ', 'AAPL', 'SPY']]));

        Bus::assertDispatchedTimes(FetchPolygonIntradayOptionsJob::class, 2);
        Bus::assertNotDispatchedSync(FetchPolygonIntradayOptionsJob::class);
        foreach ($this->queuedJobs() as $job) {
            $this->assertSame('2026-09-04', $job->tradeDate);
            $this->assertSame(QueueLanes::intraday($job->symbols[0], true), $job->queue);
        }
    }

    public function test_disabled_flag_preserves_legacy_warmup_batches_and_cli_sync_path(): void
    {
        config()->set('intraday_dispatch.singleton_enabled', false);
        config()->set('queue_lanes.isolated', false);
        $this->seedHotSymbols(['SPY', 'QQQ', 'AAPL', 'MSFT']);

        $this->assertSame(0, Artisan::call('intraday:warmup'));

        $this->assertSame([['SPY'], ['QQQ'], ['AAPL', 'MSFT']], array_map(fn ($job): array => $job->symbols, $this->queuedJobs()));
        $this->assertDatabaseCount('work_runs', 0);
        $this->assertSame(0, Artisan::call('intraday:pull', ['symbols' => ['AAPL', 'MSFT']]));
        Bus::assertDispatchedSync(FetchPolygonIntradayOptionsJob::class, fn ($job): bool => $job->symbols === ['AAPL', 'MSFT']);
    }

    public function test_fifteen_singletons_match_all_stored_data_from_one_legacy_batch(): void
    {
        $this->seedExpirations(self::SYMBOLS);
        $client = Mockery::mock(PolygonClient::class);
        foreach (self::SYMBOLS as $index => $symbol) {
            $client->shouldReceive('intradayOptionVolumes')->twice()->with($symbol, '2026-09-11')
                ->andReturn($this->payload($symbol, $index));
        }
        $this->app->instance(PolygonClient::class, $client);
        (new FetchPolygonIntradayOptionsJob(self::SYMBOLS, tradeDate: '2026-09-04'))->execute();
        $expected = $this->storedData();
        foreach (['option_live_totals', 'option_live_counters', 'intraday_option_volumes'] as $table) {
            DB::table($table)->delete();
        }

        $result = $this->dispatcher()->dispatch(self::SYMBOLS, '2026-09-04');
        $this->assertSame(15, $result['dispatched']);
        foreach ($this->queuedJobs() as $job) {
            $job->handle();
        }

        $this->assertSame($expected, $this->storedData());
        $this->assertSame(15, WorkRun::query()->where('status', WorkRun::STATUS_COMPLETED)->count());
    }

    public function test_a_failed_symbol_and_a_waiting_heavy_symbol_do_not_block_the_other_singletons(): void
    {
        $this->seedExpirations(self::SYMBOLS);
        $client = Mockery::mock(PolygonClient::class);
        foreach (self::SYMBOLS as $index => $symbol) {
            $response = $this->payload($symbol, $index);
            if ($symbol === 'NVDA') {
                $response['complete'] = false;
            }
            $client->shouldReceive('intradayOptionVolumes')->once()->with($symbol, '2026-09-11')->andReturn($response);
        }
        $this->app->instance(PolygonClient::class, $client);
        $this->dispatcher()->dispatch(self::SYMBOLS, '2026-09-04');
        $heldHeavy = null;
        foreach ($this->queuedJobs() as $job) {
            if ($job->symbols === ['SPY']) {
                $heldHeavy = $job;
                continue;
            }
            try {
                $job->handle();
                $this->assertNotSame(['NVDA'], $job->symbols);
            } catch (RuntimeException $exception) {
                $this->assertSame(['NVDA'], $job->symbols);
                $this->assertStringContainsString('Intraday refresh incomplete', $exception->getMessage());
            }
        }

        $this->assertSame(13, WorkRun::query()->where('status', WorkRun::STATUS_COMPLETED)->count());
        $this->assertDatabaseHas('work_runs', ['symbol' => 'SPY', 'status' => WorkRun::STATUS_PENDING]);
        $this->assertDatabaseHas('work_runs', ['symbol' => 'NVDA', 'status' => WorkRun::STATUS_RUNNING]);
        $this->assertDatabaseMissing('option_live_totals', ['symbol' => 'NVDA']);
        $this->assertDatabaseHas('option_live_totals', ['symbol' => 'AAPL']);
        $this->assertNotNull($heldHeavy);
        $heldHeavy->handle();
        $this->assertSame(14, WorkRun::query()->where('status', WorkRun::STATUS_COMPLETED)->count());
    }

    private function dispatcher(): IntradayRefreshDispatcher
    {
        return app(IntradayRefreshDispatcher::class);
    }

    /** @return list<FetchPolygonIntradayOptionsJob> */
    private function queuedJobs(): array
    {
        return Bus::dispatched(FetchPolygonIntradayOptionsJob::class)->all();
    }

    private function seedWatchlist(array $symbols): void
    {
        foreach ($symbols as $symbol) {
            DB::table('watchlists')->insert([
                'user_id' => User::factory()->create()->id,
                'symbol' => $symbol,
                'timeframe' => '14d',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedHotSymbols(array $symbols, string $tradeDate = '2026-09-04'): void
    {
        foreach ($symbols as $index => $symbol) {
            DB::table('hot_option_symbols')->insert([
                'trade_date' => $tradeDate,
                'symbol' => $symbol,
                'rank' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedExpirations(array $symbols): void
    {
        foreach ($symbols as $symbol) {
            DB::table('option_expirations')->insert([
                'symbol' => $symbol,
                'expiration_date' => '2026-09-11',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function payload(string $symbol, int $index): array
    {
        $callVolume = $index + 1;
        $putVolume = 2 * ($index + 1);
        $strike = 100 + $index;
        $contracts = [];
        foreach (['call' => $callVolume, 'put' => $putVolume] as $side => $volume) {
            $contracts[] = [
                'underlying_asset' => ['ticker' => $symbol],
                'details' => [
                    'ticker' => 'O:'.$symbol.'260911'.($side === 'call' ? 'C' : 'P').str_pad((string) ($strike * 1000), 8, '0', STR_PAD_LEFT),
                    'contract_type' => $side,
                    'expiration_date' => '2026-09-11',
                    'strike_price' => $strike,
                ],
                'day' => ['volume' => $volume, 'close' => $side === 'call' ? 2.5 : 1.25],
                'open_interest' => 100 + $index,
                'greeks' => ['delta' => $side === 'call' ? 0.5 : -0.5, 'gamma' => $index === 0 ? 0 : null],
            ];
        }

        return [
            'complete' => true,
            'asof' => '2026-09-04 15:59:00',
            'request_id' => 'singleton-fixture-'.$symbol,
            'totals' => ['call_vol' => $callVolume, 'put_vol' => $putVolume, 'premium' => $callVolume * 250 + $putVolume * 125],
            'by_strike' => [[
                'exp_date' => '2026-09-11', 'strike' => $strike,
                'call_vol' => $callVolume, 'put_vol' => $putVolume,
                'call_prem' => $callVolume * 250, 'put_prem' => $putVolume * 125,
            ]],
            'contracts' => $contracts,
        ];
    }

    /** Normalize only surrogate IDs while checking every other stored field. */
    private function storedData(): array
    {
        $result = [];
        $sourceIds = [];
        $ordering = [
            'intraday_option_volumes' => ['symbol', 'contract_symbol', 'captured_at'],
            'option_live_counters' => ['symbol', 'trade_date', 'exp_date', 'strike', 'option_type'],
            'option_live_totals' => ['symbol', 'trade_date'],
        ];
        foreach ($ordering as $table => $columns) {
            $query = DB::table($table);
            foreach ($columns as $column) {
                $query->orderBy($column);
            }
            $result[$table] = [];
            foreach ($query->get() as $index => $row) {
                $data = (array) $row;
                if ($table === 'option_live_counters') {
                    $sourceIds[$data['id']] = $index + 1;
                }
                if ($table === 'option_live_totals') {
                    $this->assertArrayHasKey($data['source_row_id'], $sourceIds);
                    $data['source_row_id'] = $sourceIds[$data['source_row_id']];
                    $data['freshness_key'] = substr($data['freshness_key'], 0, -20).sprintf('%020d', $data['source_row_id']);
                }
                unset($data['id']);
                $result[$table][] = $data;
            }
        }

        return $result;
    }
}
