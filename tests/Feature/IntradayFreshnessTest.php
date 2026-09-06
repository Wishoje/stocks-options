<?php

namespace Tests\Feature;

use App\Http\Controllers\IntradayController;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\RunSymbolBootstrapPhaseJob;
use App\Models\WorkRun;
use App\Support\IntradayFreshness;
use App\Support\OptionLiveTotalsRepository;
use App\Support\PolygonClient;
use App\Support\SymbolBootstrapCoordinator;
use App\Support\SymbolBootstrapPolicy;
use App\Support\WorkRunCoordinator;
use App\Support\WorkRunDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\MySqlTestCase;

class IntradayFreshnessTest extends MySqlTestCase
{
    use RefreshDatabase;

    private const TRADE_DATE = '2026-09-08';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-08 16:00:00', 'UTC'));
        Cache::flush();
        Bus::fake();
        Http::preventStrayRequests();
        config()->set('intraday_freshness.enabled', true);
        config()->set('intraday_freshness.completed_ttl_seconds', 90);
        config()->set('intraday_ingestion.bulk_enabled', true);
        config()->set('queue_lanes.isolated', false);
        config()->set('symbol_bootstrap.enabled', false);
        config()->set('services.massive.concurrency.enabled', false);
        config()->set('option_live_totals.dual_write', true);
        config()->set('option_live_totals.read_from_canonical', true);
        config()->set('option_live_totals.compare_writes', false);
        config()->set('work_runs.failure_cooldown_seconds', 300);
        config()->set('work_runs.rate_limits.accepted_symbol_per_minute', 1000);
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 1000);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_delayed_provider_data_keeps_true_source_time_but_recent_completion_prevents_immediate_refetch(): void
    {
        $started = CarbonImmutable::now('UTC');
        $received = $started->addSeconds(2);
        $source = $started->subMinutes(20);
        $this->seedExpiry();
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('intradayOptionVolumes')->once()->with('SPY', '2026-09-11')
            ->andReturnUsing(function () use ($received, $source): array {
                $this->travelTo($received);

                return $this->snapshot($received, $source);
            });
        $this->app->instance(PolygonClient::class, $client);

        $this->assertSame('ok', (new FetchPolygonIntradayOptionsJob(['SPY']))->execute());
        $state = $this->freshness()->metadata('SPY', self::TRADE_DATE, true);
        $this->assertSame($source->toIso8601String(), $state['asof']);
        $this->assertSame($state['asof'], $state['source_asof']);
        $this->assertSame('provider', $state['source_timestamp_status']);
        $this->assertSame($started->toIso8601String(), $state['captured_at']);
        $this->assertSame($received->toIso8601String(), $state['received_at']);
        $this->assertSame($received->toIso8601String(), $state['ingestion_completed_at']);
        $this->assertSame(1202, $state['stale_seconds']);
        $this->assertFalse($state['refresh_eligible']);
        $this->assertSame('recently_completed', $state['refresh_reason']);
        $this->assertDatabaseHas('intraday_option_volumes', ['symbol' => 'SPY', 'captured_at' => $received->format('Y-m-d H:i:s')]);
        $this->assertSame(30, app(OptionLiveTotalsRepository::class)->read('SPY', self::TRADE_DATE)['volume']);

        $summary = app(IntradayController::class)->summary(
            Request::create('/api/intraday/summary', 'GET', ['symbol' => 'SPY']),
            app(OptionLiveTotalsRepository::class)
        )->getData(true);
        $this->assertSame($source->toIso8601String(), $summary['source_asof']);
        $this->assertSame($received->toIso8601String(), $summary['ingestion_completed_at']);
        $this->assertTrue($summary['snapshot_available']);
        $this->assertFalse($summary['refresh_eligible']);
        $this->assertSame('recently_completed', (new FetchPolygonIntradayOptionsJob(['SPY']))->execute());
        $this->assertDatabaseCount('intraday_option_volumes', 2);
    }

    public function test_completion_window_is_exclusive_at_exactly_ninety_seconds_and_force_only_bypasses_completion(): void
    {
        $completed = CarbonImmutable::now('UTC');
        $this->publishValues($completed, $completed->subMinutes(20));
        $this->assertTrue($this->freshness()->decision('SPY', self::TRADE_DATE, force: true)['eligible']);

        $this->travelTo($completed->addSeconds(89));
        $this->assertSame('recently_completed', $this->freshness()->decision('SPY', self::TRADE_DATE)['reason']);
        $this->travelTo($completed->addSeconds(90));
        $this->assertTrue($this->freshness()->decision('SPY', self::TRADE_DATE)['eligible']);
        $this->travelTo($completed->addSeconds(91));
        $this->assertSame('refresh_due', $this->freshness()->decision('SPY', self::TRADE_DATE)['reason']);
    }

    public function test_pending_and_failed_work_are_reused_even_when_forced_until_exact_safe_backoff_boundary(): void
    {
        $runs = app(WorkRunCoordinator::class);
        $at = CarbonImmutable::now('UTC');
        $first = $this->claim();
        $run = $first['run'];
        $this->assertSame('pending', $this->freshness()->decision('SPY', self::TRADE_DATE, force: true)['reason']);
        $this->assertTrue($this->freshness()->decision('SPY', self::TRADE_DATE, ignoreRunId: $run->id)['eligible']);
        $this->assertSame($run->id, $this->claim(force: true)['run']->id);

        $reservation = $runs->reserveDispatch($run->id);
        $this->assertNotNull($reservation);
        $token = $reservation['delivery_token'];
        $this->assertTrue($runs->markStarted($run->id, $token, 1));
        $this->assertSame('pending', $this->freshness()->decision('SPY', self::TRADE_DATE)['reason']);
        $this->assertTrue($runs->markFailed($run->id, $token, 1, 'fixture', 'provider_timeout'));

        $this->travelTo($at->addSeconds(299));
        $this->assertSame('failure_backoff', $this->freshness()->decision('SPY', self::TRADE_DATE, force: true)['reason']);
        $this->assertSame($run->id, $this->claim(force: true)['run']->id);
        $this->travelTo($at->addSeconds(300));
        $this->assertTrue($this->freshness()->decision('SPY', self::TRADE_DATE)['eligible']);
        $retry = $this->claim();
        $this->assertTrue($retry['created']);
        $this->assertSame(2, $retry['run']->generation);
        $this->assertNotSame($run->id, $retry['run']->id);
    }

    #[DataProvider('blockedSessions')]
    public function test_closed_or_stale_session_jobs_and_forced_api_pulls_never_rewrite_friday(
        string $instant,
        bool $apiShouldSkip
    ): void {
        $friday = CarbonImmutable::parse('2026-09-04 19:55:00', 'UTC');
        $this->travelTo($friday);
        $this->publishValues($friday, $friday->subMinute(), tradeDate: '2026-09-04');
        $this->seedExpiry('2026-09-04');
        $before = $this->publishedSnapshot();
        $this->travelTo(CarbonImmutable::parse($instant, 'America/New_York'));
        Http::fake();

        $decision = $this->freshness()->decision('SPY', '2026-09-04', force: true);
        $this->assertFalse($decision['eligible']);
        $this->assertSame('market_closed', $decision['reason']);
        $this->assertSame('market_closed', (new FetchPolygonIntradayOptionsJob(['SPY'], tradeDate: '2026-09-04'))->execute());

        if ($apiShouldSkip) {
            $response = app(IntradayController::class)->pull(
                Request::create('/api/intraday/pull', 'POST', ['symbols' => ['SPY', 'COLD'], 'force' => true]),
                app(WorkRunCoordinator::class),
                app(WorkRunDispatcher::class)
            );
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['SPY', 'COLD'], $response->getData(true)['skipped_symbols']);
            $this->assertDatabaseCount('work_runs', 0);
            Bus::assertNothingDispatched();
        }

        Http::assertNothingSent();
        $this->assertSame($before, $this->publishedSnapshot());
        $this->assertDatabaseCount('intraday_option_volumes', 0);
    }

    public static function blockedSessions(): array
    {
        return [
            'Sunday' => ['2026-09-06 12:00:00', true],
            'Labor Day holiday' => ['2026-09-07 12:00:00', true],
            'Tuesday preopen' => ['2026-09-08 09:29:59', true],
            'frozen Friday job at Tuesday open' => ['2026-09-08 09:30:00', false],
            'post finalization' => ['2026-09-08 16:15:00', true],
        ];
    }

    public function test_closed_bootstrap_intraday_unit_returns_an_explicit_deferred_outcome_without_failing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'America/New_York'));
        $run = app(WorkRunCoordinator::class)->claim(
            'symbol_bootstrap', 'SPY',
            ['purpose' => SymbolBootstrapPolicy::PURPOSE, 'session_date' => '2026-09-04'],
            'bootstrap', applyAdmissionLimits: false
        )['run'];
        $manifest = app(SymbolBootstrapCoordinator::class)->initialize($run);
        $manifest->update(['expected_count' => 1]);
        Http::fake();
        $job = (new RunSymbolBootstrapPhaseJob(
            $run->id, SymbolBootstrapCoordinator::PHASE_INTRADAY,
            'phase-fixture', 'parent-fixture', 1, 'orchestration-fixture'
        ))->onConnection('sync')->onQueue('intraday-interactive');
        $result = (new ReflectionMethod($job, 'runIntraday'))->invoke($job);

        $this->assertSame('market_closed', $result['status']);
        $this->assertFalse($result['intraday_ready']);
        $this->assertSame('2026-09-04', $result['trade_date']);
        $this->assertSame('2026-09-08T13:30:00+00:00', $result['next_open_at']);
        $this->assertDatabaseCount('intraday_refresh_states', 0);
        $this->assertDatabaseCount('option_live_counters', 0);
        Http::assertNothingSent();
    }

    public function test_failed_publication_rolls_back_counter_changes_and_does_not_advance_completion(): void
    {
        $at = CarbonImmutable::now('UTC');
        $this->publishValues($at, $at->subMinute());
        $before = $this->publishedSnapshot();
        $this->travelTo($at->addMinutes(2));

        try {
            $this->freshness()->publish(
                'SPY', self::TRADE_DATE, $at->addMinutes(2), $at->addMinutes(2), $at,
                null, 1, function (): void {
                    DB::table('option_live_counters')->where('symbol', 'SPY')->update(['volume' => 999]);
                    throw new RuntimeException('fixture publication failed');
                }
            );
            $this->fail('A publication failure must escape after rolling back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fixture publication failed', $exception->getMessage());
        }

        $this->assertSame($before, $this->publishedSnapshot());
        $this->assertTrue($this->freshness()->decision('SPY', self::TRADE_DATE)['eligible']);
    }

    public function test_failed_first_publication_does_not_leave_a_successful_freshness_record(): void
    {
        $at = CarbonImmutable::now('UTC');
        try {
            $this->freshness()->publish('SPY', self::TRADE_DATE, $at, $at, null, null, 1, static function (): void {
                throw new RuntimeException('first publication failed');
            });
            $this->fail('The failed first publication must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('first publication failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('intraday_refresh_states', 0);
        $this->assertTrue($this->freshness()->decision('SPY', self::TRADE_DATE)['eligible']);
    }

    #[DataProvider('stalePublications')]
    public function test_late_older_capture_or_older_source_cannot_replace_the_newer_complete_generation(
        int $captureOffset,
        int $sourceOffset
    ): void {
        $at = CarbonImmutable::now('UTC');
        $this->publishValues($at, $at->subMinute());
        $before = $this->publishedSnapshot();
        $invoked = false;
        $this->travelTo($at->addMinutes(3));
        $accepted = $this->freshness()->publish(
            'SPY', self::TRADE_DATE, $at->addSeconds($captureOffset), $at->addMinutes(3),
            $at->subMinute()->addSeconds($sourceOffset), (string) Str::uuid(), 1,
            function () use (&$invoked): void {
                $invoked = true;
                DB::table('option_live_counters')->where('symbol', 'SPY')->update(['volume' => 999]);
            }
        );

        $this->assertFalse($accepted);
        $this->assertFalse($invoked);
        $this->assertSame($before, $this->publishedSnapshot());
    }

    public static function stalePublications(): array
    {
        return ['older capture, newer source' => [-1, 1], 'newer capture, older source' => [1, -1]];
    }

    public function test_unknown_source_is_exposed_as_unknown_without_using_receipt_time_as_market_time(): void
    {
        $at = CarbonImmutable::now('UTC');
        $this->publishValues($at, null);
        $metadata = $this->freshness()->metadata('SPY', self::TRADE_DATE, true);

        $this->assertNull($metadata['asof']);
        $this->assertNull($metadata['source_asof']);
        $this->assertNull($metadata['stale_seconds']);
        $this->assertSame('unknown', $metadata['source_timestamp_status']);
        $this->assertNotNull($metadata['ingestion_completed_at']);
        $this->assertTrue($metadata['snapshot_available']);
        $this->assertFalse($metadata['refresh_eligible']);
    }

    private function freshness(): IntradayFreshness
    {
        return app(IntradayFreshness::class);
    }

    private function claim(bool $force = false): array
    {
        return app(WorkRunCoordinator::class)->claim(
            'intraday_refresh', 'SPY', ['trade_date' => self::TRADE_DATE], 'intraday',
            applyAdmissionLimits: false, reuseCompleted: ! $force
        );
    }

    private function seedExpiry(string $expiry = '2026-09-11'): void
    {
        DB::table('option_expirations')->insert([
            'symbol' => 'SPY', 'expiration_date' => $expiry, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function publishValues(CarbonImmutable $at, ?CarbonImmutable $source, string $tradeDate = self::TRADE_DATE): void
    {
        $this->assertTrue($this->freshness()->publish(
            'SPY', $tradeDate, $at, $at, $source, null, 1,
            static function () use ($at, $tradeDate): void {
                app(OptionLiveTotalsRepository::class)->publish([
                    'symbol' => 'SPY', 'trade_date' => $tradeDate,
                    'call_volume' => 10, 'put_volume' => 20, 'volume' => 30,
                    'premium_usd' => 7500, 'asof' => $at, 'source_updated_at' => $at,
                ]);
            }
        ));
    }

    private function publishedSnapshot(): array
    {
        return [
            'counters' => DB::table('option_live_counters')->orderBy('id')->get()->toJson(),
            'totals' => DB::table('option_live_totals')->orderBy('id')->get()->toJson(),
            'freshness' => DB::table('intraday_refresh_states')->orderBy('symbol')->orderBy('trade_date')->get()->toJson(),
        ];
    }

    private function snapshot(CarbonImmutable $received, ?CarbonImmutable $source): array
    {
        $contracts = [];
        foreach (['call' => 10, 'put' => 20] as $side => $volume) {
            $contracts[] = [
                'underlying_asset' => ['ticker' => 'SPY'],
                'details' => [
                    'ticker' => 'O:SPY260911'.($side === 'call' ? 'C' : 'P').'00600000',
                    'contract_type' => $side, 'expiration_date' => '2026-09-11', 'strike_price' => 600,
                ],
                'day' => ['volume' => $volume, 'close' => 2.5],
                'open_interest' => 100,
            ];
        }

        return [
            'complete' => true,
            'asof' => $received->toIso8601String(),
            'received_at' => $received->toIso8601String(),
            'source_asof' => $source?->toIso8601String(),
            'source_timestamp_complete' => $source !== null,
            'request_id' => 'gex020-fixture',
            'contracts' => $contracts,
            'totals' => ['call_vol' => 10, 'put_vol' => 20, 'premium' => 7500],
            'by_strike' => [[
                'exp_date' => '2026-09-11', 'strike' => 600,
                'call_vol' => 10, 'put_vol' => 20, 'call_prem' => 2500, 'put_prem' => 5000,
            ]],
        ];
    }
}
