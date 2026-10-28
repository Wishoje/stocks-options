<?php

namespace Tests\Feature;

use App\Models\WallObservation;
use App\Services\WallSetupHistoryService;
use App\Support\WallIntelligence\WallSetupRules;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WallSetupHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_09_25_080000_create_wall_observations_table.php'))->up();
        Http::preventStrayRequests();
    }

    private function caseSeed(array $changes = []): array
    {
        return array_replace(['id' => 'setup', 'side' => 'put', 'strike' => 100.0, 'pattern' => 'reversal',
            'direction' => 'bullish', 'status' => 'conditions_met', 'observed_at' => '2026-09-30T14:00:00Z',
            'confirmed_at' => '2026-09-30T14:00:00Z', 'close' => 100.1, 'evidence_grade' => 'strong',
            'wall_identity' => 'wall', 'entry_zone' => ['low' => 100.05, 'high' => 100.15],
            'invalidation' => ['price' => 99.95], 'target' => ['strike' => 101.0],
            'scope' => ['symbol' => 'SPY', 'session' => '2026-09-30', 'timeframe' => '14d', 'units' => 'USD_per_1pct_move']], $changes);
    }

    private function context(array $seed): array
    {
        return ['symbol' => 'SPY', 'session' => '2026-09-30', 'timeframe' => '14d', 'as_of' => '2026-09-30T14:05:00Z',
            'evaluated_through' => '2026-09-30T14:05:00Z',
            'bars' => [['id' => 'price-one', 't' => strtotime('2026-09-30T14:00:00Z') * 1000, 'c' => 101.1]],
            'readings' => [['side' => 'put', 'observed_at' => '2026-09-30T14:05:00Z', 'wall_identity' => 'wall']],
            'setups' => ['rule_version' => WallSetupRules::VERSION, 'symbol' => 'SPY', 'session' => '2026-09-30', 'timeframe' => '14d', 'readings' => [$seed]]];
    }

    private function record(array $seed, int $sequence = 1, string $scope = '14d', string $version = WallSetupRules::VERSION): void
    {
        $this->recordSeeds([$seed], $sequence, $scope, $version);
    }

    private function recordSeeds(array $seeds, int $sequence = 1, string $scope = '14d', string $version = WallSetupRules::VERSION): void
    {
        $json = json_encode(['timeframe' => $scope, 'price_evidence_hash' => 'price-hash-'.$sequence,
            'setups' => ['rule_version' => $version, 'review_seeds' => $seeds]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        WallObservation::query()->insert(['content_hash' => hash('sha256', $json.$sequence), 'scope_key' => hash('sha256', $scope),
            'symbol' => 'SPY', 'dataset' => 'wall_interactions', 'schema_version' => 'wall-interactions.v1',
            'model_version' => 'completed-5m.v1', 'observation_kind' => 'interaction_assessment',
            'analysis_session' => '2026-09-30', 'source_date' => '2026-09-30',
            'captured_at' => '2026-09-30 14:20:00', 'recorded_at' => '2026-09-30 14:20:00',
            'quality_state' => 'descriptive', 'payload_bytes' => strlen($json), 'payload_json' => $json]);
    }

    public function test_first_saved_eligible_levels_survive_later_revisions_and_reads_are_bounded(): void
    {
        $original = $this->caseSeed();
        $this->record($original);
        $revision = $this->caseSeed(['target' => ['strike' => 105.0]]);
        $this->record($revision, 2);
        $before = WallObservation::count();
        DB::enableQueryLog();
        $result = app(WallSetupHistoryService::class)->read($this->context($revision));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $queries);
        $this->assertStringNotContainsString('select *', strtolower($queries[0]['query']));
        $this->assertStringNotContainsString('json', strtolower($queries[0]['query']));
        $this->assertStringContainsString('where "id" in', strtolower($queries[1]['query']));
        $this->assertSame(101.0, $result['cases'][0]['target']['strike']);
        $this->assertSame('target_reached', $result['cases'][0]['outcome']);
        $this->assertSame('saved_assessment', $result['cases'][0]['review_basis']);
        $this->assertSame('2026-09-30T14:20:00+00:00', $result['cases'][0]['first_recorded_at']);
        $this->assertSame('price-hash-1', $result['cases'][0]['source_price_evidence_hash']);
        $this->assertSame($before, WallObservation::count());
        Http::assertNothingSent();
    }

    public function test_pending_assessments_can_qualify_but_first_qualification_cannot_be_erased(): void
    {
        $pending = $this->caseSeed(['status' => 'watch']);
        $this->record($pending);
        $this->record($this->caseSeed(), 2);
        $this->record($this->caseSeed(['status' => 'no_setup']), 3);
        $result = app(WallSetupHistoryService::class)->read($this->context($pending));
        $this->assertSame(1, $result['summary']['qualified']);
        $this->assertSame('price-hash-2', $result['cases'][0]['source_price_evidence_hash']);
        $this->assertSame('target_reached', $result['cases'][0]['outcome']);
        $this->assertFalse($result['historical_outcome_eligible']);
    }

    public function test_replay_is_labelled_and_wrong_scope_or_rules_cannot_supply_saved_cases(): void
    {
        $seed = $this->caseSeed();
        $this->record($seed, 1, '7d');
        $this->record($seed, 2, '14d', 'another-rule');
        $result = app(WallSetupHistoryService::class)->read($this->context($seed));
        $this->assertSame('session_replay', $result['cases'][0]['review_basis']);
        $this->assertNull($result['cases'][0]['assessment_hash']);
        $this->assertSame(0, $result['summary']['saved_assessments']);
        $context = $this->context($seed);
        $context['symbol'] = 'QQQ';
        $this->assertEmpty(app(WallSetupHistoryService::class)->read($context)['cases']);
    }

    public function test_qualifications_after_the_old_one_hundred_sixty_checkpoint_boundary_are_preserved(): void
    {
        $seed = $this->caseSeed();
        for ($i = 1; $i <= 161; $i++) {
            $this->record($seed, $i);
        }
        $late = $this->caseSeed(['id' => 'later-setup', 'target' => ['strike' => 102.]]);
        $this->record($late, 162);
        $result = app(WallSetupHistoryService::class)->read($this->context($seed));
        $this->assertFalse($result['capture_limit_reached']);
        $this->assertSame(2, $result['summary']['scenarios']);
        $cases = collect($result['cases'])->keyBy('id');
        $this->assertSame('price-hash-1', $cases['setup']['source_price_evidence_hash']);
        $this->assertSame('price-hash-162', $cases['later-setup']['source_price_evidence_hash']);
        $this->assertSame('saved_assessment', $cases['later-setup']['review_basis']);
    }

    public function test_incremental_registry_reads_new_ids_and_cache_loss_rebuilds_original_qualifications(): void
    {
        $seed = $this->caseSeed();
        $this->record($seed);
        app(WallSetupHistoryService::class)->read($this->context($seed));
        $this->record($this->caseSeed(['target' => ['strike' => 105.]]), 2);
        $late = $this->caseSeed(['id' => 'late', 'target' => ['strike' => 102.]]);
        $this->record($late, 3);
        DB::enableQueryLog();
        $result = app(WallSetupHistoryService::class)->read($this->context($seed));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $queries);
        $this->assertEqualsCanonicalizing([2, 3], $queries[1]['bindings']);
        $cases = collect($result['cases'])->keyBy('id');
        $this->assertSame(101., $cases['setup']['target']['strike']);
        $this->assertSame('price-hash-3', $cases['late']['source_price_evidence_hash']);
        Cache::flush();
        $again = app(WallSetupHistoryService::class)->read($this->context($seed));
        $this->assertSame($result['cases'], $again['cases']);
        Http::assertNothingSent();
    }

    public function test_unavailable_cache_falls_back_to_the_original_saved_evidence(): void
    {
        $seed = $this->caseSeed();
        $this->record($seed);
        Cache::shouldReceive('get')->once()->andThrow(new \RuntimeException('cache unavailable'));
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('cache unavailable'));
        $result = app(WallSetupHistoryService::class)->read($this->context($seed));
        $this->assertSame('saved_assessment', $result['cases'][0]['review_basis']);
        $this->assertSame('price-hash-1', $result['cases'][0]['source_price_evidence_hash']);
        $this->assertFalse($result['capture_limit_reached']);
        Http::assertNothingSent();
    }

    public function test_oversized_source_fields_are_not_buffered_as_qualifications_or_silently_treated_as_complete(): void
    {
        $seed = $this->caseSeed();
        $this->record([...$seed, 'extra' => str_repeat('x', 2100000)]);
        $result = app(WallSetupHistoryService::class)->read($this->context($seed));
        $this->assertTrue($result['capture_limit_reached']);
        $this->assertSame('session_replay', $result['cases'][0]['review_basis']);
        $this->assertSame(0, $result['summary']['saved_assessments']);
        Http::assertNothingSent();
    }

    public function test_registry_aggregate_bytes_remain_bounded_without_overwriting_early_qualified_seeds(): void
    {
        $seeds = [];
        for ($i = 0; $i < 400; $i++) {
            $seeds[] = $this->caseSeed(['id' => 'setup-'.$i, 'reason' => str_repeat('r', 3000)]);
        }
        $this->recordSeeds($seeds);
        $result = app(WallSetupHistoryService::class)->read($this->context($seeds[0]));
        $this->assertTrue($result['capture_limit_reached']);
        $this->assertGreaterThan(1, $result['summary']['saved_assessments']);
        $this->assertLessThan(400, $result['summary']['saved_assessments']);
        $key = 'walls:saved-seeds:v2:'.hash('sha256', json_encode(['SPY', '2026-09-30', '14d',
            WallSetupRules::VERSION, \App\Support\WallIntelligence\WallSetupOutcomeEvaluator::VERSION]));
        $cached = Cache::get($key);
        $this->assertIsArray($cached);
        $this->assertLessThanOrEqual(1048576, strlen(json_encode($cached)));
        $this->assertSame('price-hash-1', $cached['seeds']['setup-0']['source_price_evidence_hash']);
    }
}
