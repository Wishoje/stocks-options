<?php

namespace Tests\Feature;

use App\Models\WallObservation;
use App\Services\WallSetupHistoryService;
use App\Support\WallIntelligence\WallSetupRules;
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
        $json = json_encode(['timeframe' => $scope, 'price_evidence_hash' => 'price-hash-'.$sequence,
            'setups' => ['rule_version' => $version, 'review_seeds' => [$seed]]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
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
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('select *', strtolower($queries[0]['query']));
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

    public function test_capture_limit_is_explicit_and_preserves_the_earliest_qualifying_case(): void
    {
        $seed = $this->caseSeed();
        for ($i = 1; $i <= 161; $i++) {
            $this->record($seed, $i);
        }
        $result = app(WallSetupHistoryService::class)->read($this->context($seed));
        $this->assertTrue($result['capture_limit_reached']);
        $this->assertSame(1, $result['summary']['scenarios']);
        $this->assertSame('price-hash-1', $result['cases'][0]['source_price_evidence_hash']);
    }
}
