<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Jobs\RebuildEodSnapshotManifestJob;
use App\Models\WorkRun;
use App\Support\EodCacheVersion;
use App\Support\EodSnapshotHealth;
use App\Support\EodSnapshotSelector;
use App\Support\GexSnapshotCache;
use App\Support\Regression\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MySqlTestCase;

class GexSnapshotManifestReadTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('symbol_bootstrap.enabled', false);
        config()->set('cache.default', 'array');
        config()->set('queue_lanes.isolated', false);
        config()->set('provider_backpressure.enabled', false);
        config()->set('services.massive.eod_min_side_strike_ratio', 0.5);
        config()->set('services.massive.eod_force_data_date', '');
        config()->set('gex_performance.expiration_universe_enabled', true);
        config()->set('gex_performance.expiration_shadow_enabled', false);
        config()->set('eod_snapshot_health.enabled', true);
        config()->set('eod_snapshot_health.read_enabled', true);
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

    public static function payloadCases(): array
    {
        $cases = [];
        foreach (['complete', 'partial', 'missing_greeks', 'stale'] as $shape) {
            foreach (['0d', '1d', '7d', '14d', '30d', '90d'] as $timeframe) {
                $cases[$shape.' '.$timeframe] = [$shape, $timeframe];
            }
        }

        return $cases;
    }

    #[DataProvider('payloadCases')]
    public function test_cold_warm_and_flag_off_payloads_match_for_every_timeframe_and_snapshot_shape(string $shape, string $timeframe): void
    {
        $manifest = $this->seedManifest($shape);
        config()->set('eod_snapshot_health.enabled', false);
        $legacy = $this->response($timeframe, true);
        $this->assertSame(200, $legacy->getStatusCode(), $legacy->getContent());

        config()->set('eod_snapshot_health.enabled', true);
        [$cold, $coldQueries] = $this->queries(fn () => $this->response($timeframe));
        $this->assertSame($legacy->getContent(), $cold->getContent());
        $this->assertNotEmpty($this->marketQueries($coldQueries), 'Cold construction still reads selected raw option rows.');
        $this->assertSame([], $this->catalogQueries($coldQueries), 'A valid manifest supplies its catalog without SQL discovery.');
        $cache = app(GexSnapshotCache::class);
        $key = $cache->key('SPY', $timeframe, $manifest);
        $this->assertNotNull($cache->get($key));

        [$warm, $warmQueries] = $this->queries(fn () => $this->response($timeframe));
        $this->assertSame($cold->getContent(), $warm->getContent());
        $this->assertSame([], $this->marketQueries($warmQueries), 'Valid warm hits must not query either market table.');

        config()->set('eod_snapshot_health.enabled', false);
        [$rollback, $rollbackQueries] = $this->queries(fn () => $this->response($timeframe));
        $this->assertSame($legacy->getContent(), $rollback->getContent());
        $this->assertNotEmpty($this->marketQueries($rollbackQueries), 'Flag-off retains the legacy v4 discovery path.');
    }

    public function test_dirty_warm_keeps_last_good_but_dirty_cold_reads_new_catalog_and_never_seeds_old_generation(): void
    {
        $manifest = $this->seedManifest('complete', ['2026-09-04', '2026-09-11']);
        $lastGood = $this->response('7d');
        $this->assertSame(200, $lastGood->getStatusCode());
        $health = app(EodSnapshotHealth::class);
        $health->begin('SPY', 'test:next-catalog');
        $newId = $this->expiration('2026-09-18');
        $this->rows($newId, '2026-09-04', [['120.25', 'call', 500, 50, 0.01, 100], ['120.25', 'put', 200, 20, 0.01, 100]]);
        $this->assertTrue($health->head('SPY')['dirty']);

        [$warm, $warmQueries] = $this->queries(fn () => $this->response('7d'));
        $this->assertSame($lastGood->getContent(), $warm->getContent());
        $this->assertSame([], $this->marketQueries($warmQueries));

        $oldKey = app(GexSnapshotCache::class)->key('SPY', '14d', $manifest);
        $this->assertNull(app(GexSnapshotCache::class)->get($oldKey));
        [$firstCold, $firstQueries] = $this->queries(fn () => $this->response('14d'));
        $firstPayload = $this->payload($firstCold);
        $this->assertContains('2026-09-18', $firstPayload['expiration_dates']);
        $this->assertNotEmpty($this->catalogQueries($firstQueries), 'Dirty discovery cannot use the old catalog.');
        $this->assertNull(app(GexSnapshotCache::class)->get($oldKey));

        DB::table('option_chain_data')->where('expiration_id', $newId)->where('option_type', 'call')->update(['open_interest' => 900]);
        $secondCold = $this->payload($this->response('14d'));
        $this->assertSame($firstPayload['call_open_interest_total'] + 400, $secondCold['call_open_interest_total']);
        $this->assertNull(app(GexSnapshotCache::class)->get($oldKey));
        $this->assertSame($lastGood->getContent(), $this->response('7d')->getContent());
    }

    public static function damagedManifests(): array
    {
        return [['missing'], ['corrupt']];
    }

    #[DataProvider('damagedManifests')]
    public function test_missing_or_corrupt_certified_manifest_falls_back_and_coalesces_one_rebuild(string $damage): void
    {
        $manifest = $this->seedManifest();
        config()->set('eod_snapshot_health.enabled', false);
        $legacy = $this->response('30d', true);
        config()->set('eod_snapshot_health.enabled', true);
        if ($damage === 'missing') {
            DB::table('eod_snapshot_manifests')->where('id', $manifest['manifest_id'])->delete();
        } else {
            DB::table('eod_snapshot_manifests')->where('id', $manifest['manifest_id'])->update(['facts_sha256' => str_repeat('0', 64)]);
        }
        $this->assertNull(app(EodSnapshotHealth::class)->read('SPY', $manifest['policy']));
        Bus::fake();

        for ($index = 0; $index < 3; $index++) {
            $this->assertSame($legacy->getContent(), $this->response('30d')->getContent());
        }
        $runs = WorkRun::query()->where('kind', 'eod_manifest_rebuild')->where('symbol', 'SPY')->get();
        $this->assertCount(1, $runs);
        $this->assertSame($manifest['revision'], $runs[0]->parameters['revision']);
        Bus::assertDispatchedTimes(RebuildEodSnapshotManifestJob::class, 1);
    }

    public function test_unobserved_legacy_data_falls_back_without_inventing_a_certificate_or_rebuild(): void
    {
        $id = $this->expiration('2026-09-04');
        $this->rows($id, '2026-09-04', [['100.25', 'call', 20, 8, 0.01, 100], ['100.25', 'put', 10, 6, 0.01, 100]]);
        [$response, $queries] = $this->queries(fn () => $this->response('0d'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($this->marketQueries($queries));
        $this->assertNull(app(EodSnapshotHealth::class)->head('SPY'));
        $this->assertSame(0, WorkRun::query()->where('kind', 'eod_manifest_rebuild')->count());
        Bus::assertNotDispatched(RebuildEodSnapshotManifestJob::class);
    }

    public function test_corrupt_response_envelope_is_rebuilt_from_valid_manifest_without_corrupting_its_head(): void
    {
        $manifest = $this->seedManifest();
        $cache = app(GexSnapshotCache::class);
        $key = $cache->key('SPY', '30d', $manifest);
        $expected = $this->response('30d');
        Cache::put($key, ['schema_version' => 1, 'payload' => ['strike_data' => [], 'symbol' => 'WRONG'], 'sha256' => 'invalid'], 3600);

        [$repaired, $queries] = $this->queries(fn () => $this->response('30d'));
        $this->assertSame($expected->getContent(), $repaired->getContent());
        $this->assertNotEmpty($this->marketQueries($queries));
        $this->assertNotNull($cache->get($key));
        $this->assertSame($manifest['revision'], app(EodSnapshotHealth::class)->head('SPY')['revision']);
        Bus::assertNotDispatched(RebuildEodSnapshotManifestJob::class);
    }

    public function test_changed_anchor_does_not_reuse_a_later_cached_payload_or_manifest_selection(): void
    {
        $this->seedManifest();
        $this->assertSame('2026-09-04', $this->payload($this->response('30d'))['data_date']);
        config()->set('services.massive.eod_force_data_date', '2026-09-03');
        config()->set('eod_snapshot_health.enabled', false);
        $legacy = $this->response('30d', true);
        config()->set('eod_snapshot_health.enabled', true);

        [$earlier, $queries] = $this->queries(fn () => $this->response('30d'));
        $this->assertSame($legacy->getContent(), $earlier->getContent());
        $this->assertSame('2026-09-03', $this->payload($earlier)['data_date']);
        $this->assertNotEmpty($this->marketQueries($queries));
    }

    public function test_enabled_fallback_ignores_existing_policy_blind_v4_payload_from_before_anchor_change(): void
    {
        $manifest = $this->seedManifest();
        config()->set('eod_snapshot_health.enabled', false);
        $this->payload($this->response('30d', true));
        $legacyKey = 'gex:levels:v4:SPY:30d:'.$manifest['cache_version'];
        $oldCachedPayload = Cache::get($legacyKey);
        $this->assertSame('2026-09-04', Cache::get($legacyKey)['data_date']);
        config()->set('eod_snapshot_health.enabled', true);
        config()->set('services.massive.eod_force_data_date', '2026-09-03');

        $candidate = $this->payload($this->response('30d'));

        $this->assertSame('2026-09-03', $candidate['data_date']);
        $this->assertSame($oldCachedPayload, Cache::get($legacyKey), 'Enabled fallback must neither use nor overwrite a policy-blind v4 entry.');
    }

    public function test_tracking_only_keeps_legacy_v4_reads_and_cache_writes_without_activating_manifest_readers(): void
    {
        $manifest = $this->seedManifest();
        config()->set('eod_snapshot_health.read_enabled', false);
        [$cold, $coldQueries] = $this->queries(fn () => $this->response('30d'));
        $this->payload($cold);
        $legacyKey = 'gex:levels:v4:SPY:30d:'.$manifest['cache_version'];
        $this->assertNotEmpty($this->catalogQueries($coldQueries));
        $this->assertSame($cold->getContent(), response()->json(Cache::get($legacyKey))->getContent());
        $this->assertNull(app(GexSnapshotCache::class)->get(app(GexSnapshotCache::class)->key('SPY', '30d', $manifest)));

        [$warm, $warmQueries] = $this->queries(fn () => $this->response('30d'));
        $this->assertSame($cold->getContent(), $warm->getContent());
        $this->assertNotEmpty($this->catalogQueries($warmQueries), 'Tracking-only rollout retains the existing discovery/cache path.');
        $this->assertNotNull(app(EodSnapshotHealth::class)->read('SPY', $manifest['policy']));
    }

    public function test_changed_side_ratio_does_not_reuse_a_less_strict_cached_selection(): void
    {
        $health = app(EodSnapshotHealth::class);
        $token = $health->begin('SPY', 'fixture:ratio');
        $id = $this->expiration('2026-09-04');
        $this->rows($id, '2026-09-03', [['100.25', 'call', 20, 8, 0.01, 100], ['100.25', 'put', 10, 6, 0.01, 100]]);
        $this->rows($id, '2026-09-04', [['100.25', 'call', 25, 9, 0.01, 100], ['100.25', 'put', 15, 7, 0.01, 100], ['102.50', 'call', 30, 10, 0.01, 100]]);
        $this->certify($token);
        $this->assertSame('2026-09-04', $this->payload($this->response('0d'))['data_date']);

        config()->set('services.massive.eod_min_side_strike_ratio', 1.0);
        config()->set('eod_snapshot_health.enabled', false);
        $legacy = $this->response('0d', true);
        config()->set('eod_snapshot_health.enabled', true);
        $candidate = $this->response('0d');
        $this->assertSame($legacy->getContent(), $candidate->getContent());
        $this->assertSame('2026-09-03', $this->payload($candidate)['data_date']);
    }

    public function test_weekend_calendar_rollover_refreshes_data_age_even_when_anchor_and_publication_stay_the_same(): void
    {
        $manifest = $this->seedManifest();
        $cache = app(GexSnapshotCache::class);
        $fridayKey = $cache->key('SPY', '30d', $manifest);
        $this->assertSame(0, $this->payload($this->response('30d'))['data_age_days']);
        $this->travelTo(CarbonImmutable::parse('2026-09-05 15:00:00', 'UTC'));
        $this->assertNotSame($fridayKey, $cache->key('SPY', '30d', $manifest));
        config()->set('eod_snapshot_health.enabled', false);
        $legacy = $this->response('30d', true);
        config()->set('eod_snapshot_health.enabled', true);

        $saturday = $this->response('30d');
        $this->assertSame($legacy->getContent(), $saturday->getContent());
        $this->assertSame(1, $this->payload($saturday)['data_age_days']);
    }

    public function test_monthly_app_midnight_changes_selection_before_the_new_york_day_rolls_over(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-18 23:59:59', 'UTC'));
        $health = app(EodSnapshotHealth::class);
        $token = $health->begin('SPY', 'fixture:monthly');
        foreach (['2026-09-18', '2026-10-16'] as $date) {
            $id = $this->expiration($date);
            $this->rows($id, '2026-09-18', [['100.25', 'call', 20, 8, 0.01, 100], ['100.25', 'put', 10, 6, 0.01, 100]]);
        }
        $manifest = $this->certify($token);
        $key = app(GexSnapshotCache::class)->key('SPY', 'monthly', $manifest);
        $this->assertSame(['2026-09-18'], $this->payload($this->response('monthly'))['expiration_dates']);
        $this->travelTo(CarbonImmutable::parse('2026-09-19 00:00:01', 'UTC'));
        $this->assertSame('2026-09-18', now('America/New_York')->toDateString());
        $this->assertNotSame($key, app(GexSnapshotCache::class)->key('SPY', 'monthly', $manifest));
        config()->set('eod_snapshot_health.enabled', false);
        $legacy = $this->response('monthly', true);
        config()->set('eod_snapshot_health.enabled', true);

        $nextMonth = $this->response('monthly');
        $this->assertSame($legacy->getContent(), $nextMonth->getContent());
        $this->assertSame(['2026-10-16'], $this->payload($nextMonth)['expiration_dates']);
    }

    public function test_manifest_selector_matches_sql_selection_rows_and_summary_at_a_ratio_rounding_boundary(): void
    {
        config()->set('services.massive.eod_min_side_strike_ratio', 0.35);
        $health = app(EodSnapshotHealth::class);
        $token = $health->begin('SPY', 'fixture:division-boundary');
        $id = $this->expiration('2026-09-04');
        $missingId = $this->expiration('2026-09-07');
        $this->rows($id, '2026-09-03', [['100.25', 'call', 20, 8, 0.01, 100], ['100.25', 'put', 10, 6, 0.01, 100]]);
        $rows = [];
        foreach (['call' => 351, 'put' => 1003] as $side => $count) {
            foreach (range(1, $count) as $index) {
                $rows[] = [(100 + $index).'.25', $side, 10, 5, 0.01, 100];
            }
        }
        $this->rows($id, '2026-09-04', $rows);
        $manifest = $this->certify($token);
        $selector = app(EodSnapshotSelector::class);
        $ids = [$id, $missingId];
        $legacyDates = $selector->selectedDateRows($ids, '2026-09-04', 0.35)->toArray();
        [$dates, $queries] = $this->queries(fn () => $selector->selectedDateRows($ids, '2026-09-04', 0.35, $manifest)->toArray());
        $this->assertEquals($legacyDates, $dates);
        $this->assertCount(1, $dates, 'Catalog-only expirations must not create null-date selected rows.');
        $this->assertSame([], $this->marketQueries($queries));

        $legacySummary = $selector->summary($ids, '2026-09-04', 0.35)->toArray();
        [$summary, $queries] = $this->queries(fn () => $selector->summary($ids, '2026-09-04', 0.35, $manifest)->toArray());
        $this->assertSame(CanonicalJson::normalize($legacySummary), CanonicalJson::normalize($summary));
        $this->assertSame([], $this->marketQueries($queries));
        $legacyRows = $selector->selectedRows($ids, ['option_chain_data.*'], '2026-09-04', 0.35)->sortBy('id')->values()->toArray();
        $candidateRows = $selector->selectedRows($ids, ['option_chain_data.*'], '2026-09-04', 0.35, $manifest)->sortBy('id')->values()->toArray();
        $this->assertSame($legacyRows, $candidateRows);
    }

    public function test_mutation_starting_after_payload_build_cannot_seed_the_old_manifest_generation(): void
    {
        $manifest = $this->seedManifest();
        $cache = app(GexSnapshotCache::class);
        $oldKey = $cache->key('SPY', '30d', $manifest);
        $controller = new MutatingManifestReadController;
        $this->app->instance(GexController::class, $controller);

        $payload = $this->payload($this->response('30d'));

        $this->assertTrue($controller->mutated);
        $this->assertTrue(app(EodSnapshotHealth::class)->head('SPY')['dirty']);
        $this->assertNull($cache->get($oldKey));
        // Five expirations are inside 30d. Each originally has call OI20+4;
        // the concurrent fixture adds10 to each of those two contracts.
        $this->assertSame(220, $payload['call_open_interest_total']);
        $this->assertNull($cache->get($oldKey));
    }

    public static function unsuitableManifestPolicies(): array
    {
        return [['different_anchor'], ['different_ratio'], ['null_anchor'], ['dirty'], ['missing_id'], ['bad_date'], ['disabled']];
    }

    #[DataProvider('unsuitableManifestPolicies')]
    public function test_selector_falls_back_for_wrong_policy_dirty_missing_or_disabled_manifest(string $case): void
    {
        $manifest = $this->seedManifest();
        $ids = array_column($manifest['catalog'], 'expiration_id');
        $anchor = $case === 'null_anchor' ? null : ($case === 'different_anchor' ? '2026-09-03' : '2026-09-04');
        $ratio = $case === 'different_ratio' ? 1.0 : 0.5;
        if ($case === 'dirty') {
            $manifest['dirty'] = true;
        } elseif ($case === 'missing_id') {
            unset($manifest['expirations'][$ids[0]]);
        } elseif ($case === 'bad_date') {
            $manifest['expirations'][$ids[0]]['selected_date'] = 'not-a-date';
        } elseif ($case === 'disabled') {
            config()->set('eod_snapshot_health.enabled', false);
        }
        $selector = app(EodSnapshotSelector::class);
        $legacy = $selector->selectedDateRows($ids, $anchor, $ratio)->toArray();

        [$actual, $queries] = $this->queries(fn () => $selector->selectedDateRows($ids, $anchor, $ratio, $manifest)->toArray());

        $this->assertEquals($legacy, $actual);
        $this->assertNotEmpty($this->marketQueries($queries));
    }

    private function seedManifest(string $shape = 'complete', ?array $dates = null): array
    {
        $token = app(EodSnapshotHealth::class)->begin('SPY', 'fixture:'.$shape);
        $latest = $shape === 'stale' ? '2026-08-31' : '2026-09-04';
        $prior = $shape === 'stale' ? '2026-08-28' : '2026-09-03';
        $week = $shape === 'stale' ? '2026-08-24' : '2026-08-28';
        foreach ($dates ?? ['2026-09-04', '2026-09-07', '2026-09-11', '2026-09-18', '2026-10-05', '2026-12-03'] as $date) {
            $id = $this->expiration($date);
            foreach ([$week, $prior, $latest] as $snapshot) {
                $gamma = $shape === 'missing_greeks' && $snapshot === $latest ? null : 0.01;
                $rows = [['100.25', 'call', 20, 8, $gamma, 100], ['102.50', 'call', 4, 1, $gamma, null]];
                if ($shape !== 'partial' || $snapshot !== $latest) {
                    $rows[] = ['100.25', 'put', 10, 6, $gamma, 100];
                    $rows[] = ['102.50', 'put', 6, 3, $gamma, 0];
                }
                $this->rows($id, $snapshot, $rows);
            }
        }

        return $this->certify($token);
    }

    private function certify(string $token): array
    {
        $health = app(EodSnapshotHealth::class);
        $health->complete($token);
        $version = 'manifest-fixture-v1';
        $issuedAt = 1000000;
        $this->assertTrue($health->certify('SPY', $version, $issuedAt));
        $head = $health->head('SPY');
        $policy = $health->policy();
        $this->assertNotNull($health->rebuild('SPY', $head['revision'], $version, $policy));
        // Materialize first so the normal publication hook finds no repair
        // work to dispatch; subsequent damage tests start with a clean slot.
        app(EodCacheVersion::class)->publish(['SPY'], [EodCacheVersion::DOMAIN_GEX], $version, $issuedAt);
        $manifest = $health->read('SPY', $policy);
        $this->assertNotNull($manifest);

        return $manifest;
    }

    private function expiration(string $date): int
    {
        return DB::table('option_expirations')->insertGetId([
            'symbol' => 'SPY', 'expiration_date' => $date, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rows(int $id, string $date, array $tuples): void
    {
        $rows = array_map(static fn (array $row): array => [
            'expiration_id' => $id, 'data_date' => $date, 'strike' => $row[0], 'option_type' => $row[1],
            'open_interest' => $row[2], 'volume' => $row[3], 'gamma' => $row[4], 'underlying_price' => $row[5],
            'created_at' => now(), 'updated_at' => now(),
        ], $tuples);
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('option_chain_data')->insert($chunk);
        }
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

    private function catalogQueries(array $queries): array
    {
        return array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], '`option_expirations`')));
    }
}

class MutatingManifestReadController extends GexController
{
    public bool $mutated = false;

    protected function buildGexPayload(
        string $symbol,
        string $timeframe,
        array $dates,
        array $timeframeExpirations,
        array $expirationIds,
        ?string $anchorDate = null,
        ?array $manifest = null,
    ): ?array {
        $payload = parent::buildGexPayload($symbol, $timeframe, $dates, $timeframeExpirations, $expirationIds, $anchorDate, $manifest);
        if (! $this->mutated) {
            $this->mutated = true;
            app(EodSnapshotHealth::class)->begin($symbol, 'fixture:during-build');
            DB::table('option_chain_data')->where('data_date', '2026-09-04')->where('option_type', 'call')->increment('open_interest', 10);
        }

        return $payload;
    }
}
