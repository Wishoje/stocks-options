<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Support\GexExpirationUniverse;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MySqlTestCase;

class GexExpirationUniverseShadowTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('symbol_bootstrap.enabled', false);
        config()->set('cache.default', 'array');
        config()->set('services.massive.eod_force_data_date', '');
        config()->set('gex_performance.expiration_universe_enabled', false);
        config()->set('gex_performance.expiration_shadow_enabled', false);
        $this->travelTo(CarbonImmutable::parse('2026-09-04 21:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->travelBack();

        parent::tearDown();
    }

    public static function uiTimeframes(): array
    {
        return [['0d'], ['1d'], ['7d'], ['14d'], ['30d'], ['90d']];
    }

    #[DataProvider('uiTimeframes')]
    public function test_cold_and_warm_payloads_are_identical_with_seven_catalog_queries_replaced_by_one(string $timeframe): void
    {
        $ids = [$this->expiration('SPY', '2026-09-04'), $this->expiration('SPY', '2026-09-11')];
        foreach ($ids as $index => $id) {
            foreach (['2026-08-28', '2026-09-03', '2026-09-04'] as $date) {
                foreach (['call', 'put'] as $side) {
                    DB::table('option_chain_data')->insert([
                        'expiration_id' => $id,
                        'data_date' => $date,
                        'strike' => $index === 0 ? '100.25' : '102.50',
                        'option_type' => $side,
                        'open_interest' => $side === 'call' ? 20 : 10,
                        'volume' => $side === 'call' ? 8 : 6,
                        'gamma' => 0.01,
                        'underlying_price' => 100,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
        // A different symbol must not contaminate the shared universe.
        $this->expiration('QQQ', '2026-09-07');
        [$legacy, $legacyQueries] = $this->catalogQueries(fn () => $this->response($timeframe, true));
        $this->assertSame(200, $legacy->getStatusCode());
        $this->assertCount(7, $legacyQueries);

        config()->set('gex_performance.expiration_universe_enabled', true);
        [$candidate, $candidateQueries] = $this->catalogQueries(fn () => $this->response($timeframe, true));
        $this->assertSame($legacy->getContent(), $candidate->getContent());
        $this->assertCount(1, $candidateQueries);
        $this->assertStringContainsString('`id`, `expiration_date`', $candidateQueries[0]['query']);

        [$warm, $warmQueries] = $this->catalogQueries(fn () => $this->response($timeframe, false));
        $this->assertSame($candidate->getContent(), $warm->getContent());
        $this->assertCount(1, $warmQueries);

        config()->set('gex_performance.expiration_universe_enabled', false);
        [$rolledBack, $rollbackQueries] = $this->catalogQueries(fn () => $this->response($timeframe, false));
        $this->assertSame($legacy->getContent(), $rolledBack->getContent());
        $this->assertCount(7, $rollbackQueries);
    }

    public function test_missing_requested_timeframe_keeps_catalog_only_availability_and_404_contract(): void
    {
        $this->expiration('SPY', '2026-09-18');
        $legacy = $this->response('0d', false);
        config()->set('gex_performance.expiration_universe_enabled', true);
        [$candidate, $queries] = $this->catalogQueries(fn () => $this->response('0d', false));

        $this->assertSame(404, $candidate->getStatusCode());
        $this->assertSame($legacy->getContent(), $candidate->getContent());
        $this->assertSame(['14d', '30d', '90d'], json_decode($candidate->getContent(), true)['available_timeframes']);
        $this->assertCount(1, $queries);
    }

    public static function snapshotShapes(): array
    {
        $cases = [];
        foreach (['sparse', 'zero', 'missing_greeks', 'stale', 'heavy'] as $shape) {
            foreach (['0d', '1d', '7d', '14d', '30d', '90d'] as $timeframe) {
                $cases[$shape.' '.$timeframe] = [$shape, $timeframe];
            }
        }
        foreach (['21d', '45d', '60d', 'monthly', 'unrecognized', '0'] as $timeframe) {
            $cases['alias '.$timeframe] = ['complete', $timeframe];
        }

        return $cases;
    }

    #[DataProvider('snapshotShapes')]
    public function test_full_payload_shadow_matches_for_sparse_zero_missing_greek_stale_heavy_and_alias_fixtures(string $shape, string $timeframe): void
    {
        $this->snapshotFixture($shape);
        [$legacy, $legacyQueries] = $this->catalogQueries(fn () => $this->response($timeframe, true));
        $this->assertSame(200, $legacy->getStatusCode(), $legacy->getContent());
        $expectedCatalogQueries = in_array($timeframe, ['0d', '1d', '7d', '14d', '30d', '90d'], true) ? 7 : 8;
        $this->assertCount($expectedCatalogQueries, $legacyQueries);

        config()->set('gex_performance.expiration_universe_enabled', true);
        [$candidate, $candidateQueries] = $this->catalogQueries(fn () => $this->response($timeframe, true));
        $this->assertSame($legacy->getContent(), $candidate->getContent());
        $this->assertCount(1, $candidateQueries);

        $payload = json_decode($candidate->getContent(), true, flags: JSON_THROW_ON_ERROR);
        if ($shape === 'sparse') {
            $this->assertSame('2026-09-03', $payload['data_date'], 'The newer one-sided snapshot must not replace balanced data.');
        } elseif ($shape === 'stale') {
            $this->assertSame('2026-08-31', $payload['data_date']);
        } elseif ($shape === 'zero') {
            $this->assertSame(0, $payload['call_open_interest_total']);
            $this->assertSame(0, $payload['put_open_interest_total']);
            $this->assertNull($payload['pcr_volume']);
        } elseif ($shape === 'missing_greeks') {
            $this->assertSame('2026-09-04', $payload['data_date']);
            foreach ($payload['strike_data'] as $strike) {
                $this->assertEquals(0, $strike['net_gex']);
            }
        } elseif ($shape === 'heavy') {
            $this->assertCount(80, $payload['strike_data']);
        }
    }

    public function test_shadow_flag_has_no_extra_queries_or_service_calls_when_feature_is_disabled(): void
    {
        $this->expiration('SPY', '2026-09-04');
        config()->set('gex_performance.expiration_shadow_enabled', true);
        $resolver = Mockery::mock(GexExpirationUniverse::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(GexExpirationUniverse::class, $resolver);

        [$selection, $queries] = $this->catalogQueries(fn () => (new ExposedGexExpirationController)->selection('SPY', '0d'));
        $this->assertCount(6, $queries);
        $this->assertNull($selection['expiration_ids']);
    }

    public function test_shadow_compares_actual_mysql_sets_and_ids_and_records_only_bounded_metadata(): void
    {
        $id = $this->expiration('SPY', '2026-09-04');
        config()->set('gex_performance.expiration_universe_enabled', true);
        config()->set('gex_performance.expiration_shadow_enabled', true);
        Log::shouldReceive('info')->once()->with('gex.expiration_shadow.match', Mockery::on(
            static fn (array $context): bool => $context['matches'] === true
                && $context['legacy_hash'] === $context['candidate_hash']
                && $context['legacy_expiration_count'] === 1
                && $context['candidate_expiration_count'] === 1
                && count($context) === 7,
        ));
        Log::shouldNotReceive('warning');

        [$selection, $queries] = $this->catalogQueries(fn () => (new ExposedGexExpirationController)->selection('SPY', '0d'));
        $this->assertSame([$id], $selection['expiration_ids']);
        $this->assertCount(8, $queries, 'Shadow overhead is measured separately from the one-query active path.');
    }

    public static function mismatchKinds(): array
    {
        return [['dates'], ['ids'], ['order']];
    }

    #[DataProvider('mismatchKinds')]
    public function test_shadow_discrepancy_serves_the_legacy_selection_instead_of_changing_results(string $kind): void
    {
        $id = $this->expiration('SPY', '2026-09-04');
        $controller = new ExposedGexExpirationController;
        $expectedDates = $controller->selection('SPY', '0d')['timeframe_expirations'];
        config()->set('gex_performance.expiration_universe_enabled', true);
        config()->set('gex_performance.expiration_shadow_enabled', true);
        $resolver = new MutatingGexExpirationUniverse;
        $resolver->kind = $kind;
        $this->app->instance(GexExpirationUniverse::class, $resolver);
        Log::shouldReceive('warning')->once()->with('gex.expiration_shadow.mismatch', Mockery::on(
            static fn (array $context): bool => $context['matches'] === false
                && strlen($context['legacy_hash']) === 64
                && strlen($context['candidate_hash']) === 64
                && $context['legacy_hash'] !== $context['candidate_hash']
                && count($context) === 7,
        ));
        Log::shouldNotReceive('info');

        $selection = $controller->selection('SPY', '0d');
        $this->assertSame(['timeframe_expirations' => $expectedDates, 'expiration_ids' => [$id]], $selection);
    }

    public function test_shadow_freezes_legacy_clock_when_new_york_midnight_passes_between_paths(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 03:59:59', 'UTC'));
        $id = $this->expiration('SPY', '2026-09-07');
        $this->expiration('SPY', '2026-09-08');
        config()->set('gex_performance.expiration_universe_enabled', true);
        config()->set('gex_performance.expiration_shadow_enabled', true);
        $resolver = new MutatingGexExpirationUniverse;
        $resolver->kind = 'advance_clock';
        $this->app->instance(GexExpirationUniverse::class, $resolver);
        Log::shouldReceive('info')->once()->with('gex.expiration_shadow.match', Mockery::on(
            static fn (array $context): bool => $context['matches'] === true,
        ));
        Log::shouldNotReceive('warning');

        $selection = (new ExposedGexExpirationController)->selection('SPY', '0d');
        $this->assertSame(['2026-09-07'], $selection['timeframe_expirations']['0d']);
        $this->assertSame([$id], $selection['expiration_ids']);
        $this->assertSame('2026-09-08', Carbon::now('America/New_York')->toDateString());
    }

    private function snapshotFixture(string $shape): void
    {
        $latestDate = $shape === 'stale' ? '2026-08-31' : '2026-09-04';
        $priorDate = $shape === 'stale' ? '2026-08-28' : '2026-09-03';
        $weekDate = $shape === 'stale' ? '2026-08-24' : '2026-08-28';
        $rows = [];
        foreach (['2026-09-04', '2026-09-07', '2026-09-11', '2026-09-18', '2026-10-05', '2026-12-03'] as $expiration) {
            $id = $this->expiration('SPY', $expiration);
            foreach ([$weekDate, $priorDate, $latestDate] as $date) {
                $isCurrent = $date === $latestDate;
                $strikeCount = $shape === 'heavy' && $isCurrent ? 80 : 2;
                $sides = $shape === 'sparse' && $isCurrent ? ['call'] : ['call', 'put'];
                foreach (range(0, $strikeCount - 1) as $strikeIndex) {
                    foreach ($sides as $side) {
                        $rows[] = [
                            'expiration_id' => $id,
                            'data_date' => $date,
                            'strike' => number_format(100.25 + $strikeIndex * 0.5, 2, '.', ''),
                            'option_type' => $side,
                            'open_interest' => $shape === 'zero' ? ($side === 'call' ? null : 0) : ($side === 'call' ? 20 : 10),
                            'volume' => $shape === 'zero' ? ($side === 'call' ? 0 : null) : ($side === 'call' ? 8 : 6),
                            'gamma' => $shape === 'missing_greeks' && $isCurrent ? null : 0.01,
                            'underlying_price' => $strikeIndex % 2 === 0 ? 100 : null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('option_chain_data')->insert($chunk);
        }
    }

    private function response(string $timeframe, bool $refresh)
    {
        return app(GexController::class)->getGexLevels(Request::create('/api/gex-levels', 'GET', [
            'symbol' => 'SPY', 'timeframe' => $timeframe, 'refresh' => $refresh,
        ]));
    }

    private function expiration(string $symbol, string $date): int
    {
        return DB::table('option_expirations')->insertGetId([
            'symbol' => $symbol, 'expiration_date' => $date, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function catalogQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $callback();
            $queries = array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], '`option_expirations`')));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$result, $queries];
    }
}

class ExposedGexExpirationController extends GexController
{
    public function selection(string $symbol, string $timeframe): array
    {
        return $this->resolveGexExpirationSelection($symbol, $timeframe);
    }
}

class MutatingGexExpirationUniverse extends GexExpirationUniverse
{
    public string $kind = '';

    public function resolve(string $symbol, string $requestedTimeframe, array $uiTimeframes = ['0d', '1d', '7d', '14d', '30d', '90d'], ?CarbonInterface $at = null): array
    {
        $result = parent::resolve($symbol, $requestedTimeframe, $uiTimeframes, $at);
        match ($this->kind) {
            'dates' => $result['timeframe_expirations'][$requestedTimeframe] = ['2026-09-08'],
            'ids' => $result['expiration_ids'] = [999999],
            'order' => $result['timeframe_expirations'] = array_reverse($result['timeframe_expirations'], true),
            'advance_clock' => Carbon::setTestNow(Carbon::instance($at)->addSeconds(2)),
            default => null,
        };

        return $result;
    }
}
