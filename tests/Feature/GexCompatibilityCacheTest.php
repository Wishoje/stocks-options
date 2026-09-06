<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Jobs\RebuildEodSnapshotManifestJob;
use App\Support\EodCacheVersion;
use App\Support\EodSnapshotHealth;
use App\Support\EodSnapshotManifestBuilder;
use App\Support\GexSnapshotCache;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MySqlTestCase;

class GexCompatibilityCacheTest extends MySqlTestCase
{
    use RefreshDatabase;

    private CompatibilityAuditArrayStore $cacheStore;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'symbol_bootstrap.enabled' => false, 'cache.default' => 'array',
            'queue_lanes.isolated' => false, 'provider_backpressure.enabled' => false,
            'services.massive.eod_min_side_strike_ratio' => 0.5,
            'services.massive.eod_force_data_date' => '',
            'gex_performance.expiration_universe_enabled' => true,
            'gex_performance.expiration_shadow_enabled' => false,
            'eod_snapshot_health.enabled' => true, 'eod_snapshot_health.read_enabled' => true,
        ]);
        $this->cacheStore = new CompatibilityAuditArrayStore;
        $store = $this->cacheStore;
        Cache::extend('array', fn () => new Repository($store));
        Cache::purge('array');
        $this->travelTo(CarbonImmutable::parse('2026-09-04 21:00:00', 'UTC'));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->travelBack();
        parent::tearDown();
    }

    public static function fallbackCases(): array
    {
        $cases = [];
        foreach (['absent', 'missing', 'corrupt'] as $state) {
            foreach (['0d', '1d', '7d', '14d', '30d', '90d'] as $timeframe) {
                $cases[$state.' '.$timeframe] = [$state, $timeframe];
            }
        }

        return $cases;
    }

    #[DataProvider('fallbackCases')]
    public function test_uncovered_and_missing_manifest_fallbacks_cache_exact_payload_without_new_certificates(string $state, string $timeframe): void
    {
        $this->seedFixture($state);
        $health = app(EodSnapshotHealth::class);
        $before = $this->durableCounts();
        $beforeHead = $health->head('SPY');
        config()->set('eod_snapshot_health.read_enabled', false);
        $legacy = $this->response($timeframe, true);
        $legacyKey = 'gex:levels:v4:SPY:'.$timeframe.':'.app(EodCacheVersion::class)->current('gex', 'SPY');
        $v4 = Cache::get($legacyKey);
        config()->set('eod_snapshot_health.read_enabled', true);
        $context = $this->context($timeframe);
        $this->assertNotNull($context);
        $cache = app(GexSnapshotCache::class);

        [$cold, $coldQueries] = $this->queries(fn () => $this->response($timeframe));
        $this->assertSame(200, $cold->getStatusCode());
        $this->assertSame($legacy->getContent(), $cold->getContent());
        $this->assertNotEmpty($this->marketQueries($coldQueries));
        $this->assertNotNull($cache->get($context['key']));
        [$warm, $warmQueries] = $this->queries(fn () => $this->response($timeframe));
        $this->assertSame($cold->getContent(), $warm->getContent());
        $this->assertSame([], $this->marketQueries($warmQueries));
        $this->assertSame($v4, Cache::get($legacyKey), 'Enabled fallback never overwrites or migrates a v4 payload.');
        $this->assertSame($beforeHead, $health->head('SPY'));
        $this->assertSame($before, $this->durableCounts());
        if ($state === 'absent') {
            $this->assertDatabaseMissing('eod_snapshot_states', ['symbol' => 'SPY']);
            Bus::assertNotDispatched(RebuildEodSnapshotManifestJob::class);
        } else {
            Bus::assertDispatchedTimes(RebuildEodSnapshotManifestJob::class, 1);
        }
    }

    public function test_first_tracked_mutation_invalidates_an_absent_state_warm_entry(): void
    {
        $this->seedFixture('absent');
        $before = $this->response('30d');
        $key = $this->context('30d')['key'];
        app(EodSnapshotHealth::class)->begin('SPY', 'fixture:new-tracked-write');
        DB::table('option_chain_data')->where('data_date', '2026-09-04')->where('option_type', 'call')->increment('open_interest', 10);

        [$after, $queries] = $this->queries(fn () => $this->response('30d'));
        $this->assertNotEmpty($this->marketQueries($queries));
        $this->assertGreaterThan($this->payload($before)['call_open_interest_total'], $this->payload($after)['call_open_interest_total']);
        $this->assertNull($this->context('30d'));
        $this->assertSame($before->getContent(), response()->json(app(GexSnapshotCache::class)->get($key))->getContent());
    }

    public function test_warm_eligibility_is_checked_again_after_the_cache_get(): void
    {
        $this->seedFixture('absent');
        $before = $this->response('30d');
        $raced = false;
        $this->cacheStore->afterCompatibilityGet = function () use (&$raced): void {
            if (! $raced) {
                $raced = true;
                app(EodSnapshotHealth::class)->begin('SPY', 'fixture:between-state-and-cache');
                DB::table('option_chain_data')->where('data_date', '2026-09-04')->where('option_type', 'call')->increment('open_interest', 10);
            }
        };
        [$after, $queries] = $this->queries(fn () => $this->response('30d'));

        $this->assertTrue($raced);
        $this->assertNotEmpty($this->marketQueries($queries));
        $this->assertGreaterThan($this->payload($before)['call_open_interest_total'], $this->payload($after)['call_open_interest_total']);
        $this->assertNull($this->context('30d'));
    }

    public static function ineligibleStates(): array
    {
        return [['active'], ['failed'], ['complete_uncertified'], ['dirty'], ['publication_gap']];
    }

    #[DataProvider('ineligibleStates')]
    public function test_present_uncertified_dirty_and_publication_gap_states_never_cache(string $state): void
    {
        $this->seedFixture(in_array($state, ['dirty', 'publication_gap'], true) ? 'missing' : 'absent');
        $health = app(EodSnapshotHealth::class);
        if ($state === 'publication_gap') {
            Cache::put(app(EodCacheVersion::class)->publicationKey('gex', 'SPY'), ['version' => 'not-yet-certified']);
        } else {
            $token = $health->begin('SPY', 'fixture:ineligible');
            if ($state === 'failed') {
                $health->fail($token);
            } elseif ($state === 'complete_uncertified') {
                $health->complete($token);
            }
        }
        $this->assertNull($this->context('30d'));
        for ($index = 0; $index < 2; $index++) {
            [$response, $queries] = $this->queries(fn () => $this->response('30d'));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertNotEmpty($this->marketQueries($queries));
        }
        $this->assertSame([], $this->compatibilityKeys());
    }

    public static function coldRaces(): array
    {
        return [['absent', 'first_mutation'], ['missing', 'first_mutation'], ['absent', 'publication'],
            ['missing', 'new_certificate'], ['absent', 'policy'], ['absent', 'ny_day'], ['absent', 'app_day']];
    }

    #[DataProvider('coldRaces')]
    public function test_a_change_after_cold_calculation_prevents_any_compatibility_write(string $state, string $race): void
    {
        $this->seedFixture($state);
        if ($race === 'app_day') {
            $this->travelTo(CarbonImmutable::parse('2026-09-04T23:59:59Z'));
        }
        $context = $this->context('30d');
        $this->assertNotNull($context);
        $controller = new RacingCompatibilityController;
        $controller->afterBuild = function () use ($race): void {
            $health = app(EodSnapshotHealth::class);
            if ($race === 'first_mutation') {
                $health->begin('SPY', 'fixture:cold-race');
            } elseif ($race === 'publication') {
                Cache::put(app(EodCacheVersion::class)->publicationKey('gex', 'SPY'), ['version' => 'changed']);
            } elseif ($race === 'new_certificate') {
                $token = $health->begin('SPY', 'fixture:new-complete-generation');
                $health->complete($token);
                app(EodCacheVersion::class)->publish(['SPY'], ['gex'], 'new-complete', 2000000);
            } elseif ($race === 'policy') {
                config()->set('services.massive.eod_min_side_strike_ratio', 1.0);
            } elseif ($race === 'ny_day') {
                $this->travelTo(CarbonImmutable::parse('2026-09-05T15:00:00Z'));
            } else {
                $this->travelTo(CarbonImmutable::parse('2026-09-05T00:00:01Z'));
            }
        };
        $this->app->instance(GexController::class, $controller);
        $this->assertSame(200, $this->response('30d')->getStatusCode());
        $this->assertTrue($controller->ran);
        $this->assertNull(app(GexSnapshotCache::class)->get($context['key']));
        $this->assertSame([], $this->compatibilityKeys());
    }

    public function test_a_valid_manifest_takes_priority_over_a_compatibility_payload(): void
    {
        $manifest = $this->seedFixture('missing');
        $expected = $this->response('30d');
        $context = $this->context('30d');
        $wrong = $this->payload($expected);
        $wrong['call_open_interest_total'] = 999999;
        Cache::put($context['key'], [
            'schema_version' => 1, 'payload' => $wrong,
            'sha256' => hash('sha256', EodSnapshotManifestBuilder::canonicalJson($wrong)),
        ]);
        $health = app(EodSnapshotHealth::class);
        $this->assertNotNull($health->rebuild('SPY', $manifest['revision'], $manifest['cache_version'], $manifest['policy']));

        [$cold, $queries] = $this->queries(fn () => $this->response('30d'));
        $this->assertSame($expected->getContent(), $cold->getContent());
        $this->assertNotEmpty($this->marketQueries($queries));
        $this->assertNotNull(app(GexSnapshotCache::class)->get(app(GexSnapshotCache::class)->key('SPY', '30d', $manifest)));
        [$warm, $queries] = $this->queries(fn () => $this->response('30d'));
        $this->assertSame($expected->getContent(), $warm->getContent());
        $this->assertSame([], $this->marketQueries($queries));
        $this->assertCount(3, $queries, 'The valid manifest path must not add compatibility metadata probes.');
    }

    public function test_compatibility_entries_expire_quickly_and_manual_refresh_still_rebuilds(): void
    {
        $this->seedFixture('absent');
        $expected = $this->response('30d');
        [$forced, $queries] = $this->queries(fn () => $this->response('30d', true));
        $this->assertSame($expected->getContent(), $forced->getContent());
        $this->assertNotEmpty($this->marketQueries($queries));
        $this->travel(121)->seconds();
        [$expired, $queries] = $this->queries(fn () => $this->response('30d'));
        $this->assertSame($expected->getContent(), $expired->getContent());
        $this->assertNotEmpty($this->marketQueries($queries));
    }

    private function seedFixture(string $state): ?array
    {
        $health = app(EodSnapshotHealth::class);
        $token = $state === 'absent' ? null : $health->begin('SPY', 'fixture:completed');
        foreach (['2026-09-04', '2026-09-07', '2026-09-11', '2026-09-18', '2026-10-05', '2026-12-03'] as $date) {
            $id = DB::table('option_expirations')->insertGetId([
                'symbol' => 'SPY', 'expiration_date' => $date, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['2026-08-28', '2026-09-03', '2026-09-04'] as $snapshot) {
                foreach ([['100.25', 'call', 20, 8, 0.01, 100], ['100.25', 'put', 10, 6, 0.01, 100],
                    ['102.50', 'call', 0, 1, null, null], ['102.50', 'put', 6, 0, 0.0, 0]] as $row) {
                    DB::table('option_chain_data')->insert([
                        'expiration_id' => $id, 'data_date' => $snapshot, 'strike' => $row[0], 'option_type' => $row[1],
                        'open_interest' => $row[2], 'volume' => $row[3], 'gamma' => $row[4], 'underlying_price' => $row[5],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
        if ($state === 'absent') {
            return null;
        }
        $health->complete($token);
        $this->assertTrue($health->certify('SPY', 'compat-fixture-v1', 1000000));
        $this->assertNotNull($health->rebuild('SPY', 1, 'compat-fixture-v1', $health->policy()));
        app(EodCacheVersion::class)->publish(['SPY'], ['gex'], 'compat-fixture-v1', 1000000);
        $manifest = $health->read('SPY', $health->policy());
        $this->assertNotNull($manifest);
        if ($state === 'missing') {
            DB::table('eod_snapshot_manifests')->where('id', $manifest['manifest_id'])->delete();
        } else {
            DB::table('eod_snapshot_manifests')->where('id', $manifest['manifest_id'])->update(['facts_sha256' => str_repeat('0', 64)]);
        }

        return $manifest;
    }

    private function context(string $timeframe): ?array
    {
        return app(GexSnapshotCache::class)->compatibilityContext('SPY', $timeframe, app(EodSnapshotHealth::class)->policy());
    }

    private function durableCounts(): array
    {
        return array_map(static fn (string $table): int => DB::table($table)->count(), [
            'eod_snapshot_states', 'eod_snapshot_mutations', 'eod_snapshot_manifests',
        ]);
    }

    private function compatibilityKeys(): array
    {
        return array_values(array_filter($this->cacheStore->keys(),
            static fn (string $key): bool => str_starts_with($key, 'gex:levels:compat:v1:')));
    }

    private function response(string $timeframe, bool $refresh = false)
    {
        return app(GexController::class)->getGexLevels(Request::create('/api/gex-levels', 'GET', [
            'symbol' => 'SPY', 'timeframe' => $timeframe, 'refresh' => $refresh,
        ]));
    }

    private function payload($response): array
    {
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function queries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $callback();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$result, $queries];
    }

    private function marketQueries(array $queries): array
    {
        return array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], '`option_expirations`') || str_contains($query['query'], '`option_chain_data`')));
    }
}

class CompatibilityAuditArrayStore extends ArrayStore
{
    public ?\Closure $afterCompatibilityGet = null;

    public function get($key)
    {
        $value = parent::get($key);
        if ($this->afterCompatibilityGet !== null && str_starts_with($key, 'gex:levels:compat:v1:')) {
            ($this->afterCompatibilityGet)();
        }

        return $value;
    }

    public function keys(): array
    {
        return array_keys($this->storage);
    }
}

class RacingCompatibilityController extends GexController
{
    public ?\Closure $afterBuild = null;

    public bool $ran = false;

    protected function buildGexPayload(string $symbol, string $timeframe, array $dates, array $timeframeExpirations,
        array $expirationIds, ?string $anchorDate = null, ?array $manifest = null): ?array
    {
        $payload = parent::buildGexPayload($symbol, $timeframe, $dates, $timeframeExpirations, $expirationIds, $anchorDate, $manifest);
        if (! $this->ran && $this->afterBuild !== null) {
            $this->ran = true;
            ($this->afterBuild)();
        }

        return $payload;
    }
}
