<?php

namespace Tests\Feature;

use App\Services\SetupStudy\IntradayStudySource;
use App\Support\WallIntelligence\WallSetupRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntradayResearchSourceTest extends TestCase
{
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_09_25_080000_create_wall_observations_table.php'))->up();
        Http::preventStrayRequests();
    }

    public function test_cursor_reads_only_new_scoped_checkpoints_and_advances_past_rejected_records(): void
    {
        $old = $this->checkpoint([$this->caseSeed()]);
        $current = $this->checkpoint([$this->caseSeed(['id' => 'new', 'target' => ['strike' => 102.0]])]);
        $this->checkpoint([$this->caseSeed(['id' => 'wrong-symbol'])], row: ['symbol' => 'QQQ']);
        $this->checkpoint([$this->caseSeed(['id' => 'wrong-session'])], row: ['analysis_session' => '2026-09-29']);
        $this->checkpoint([$this->caseSeed(['id' => 'unsupported'])], row: ['schema_version' => 'unsupported']);
        $this->checkpoint([$this->caseSeed(['id' => 'watching', 'status' => 'watch'])]);
        $last = $this->checkpoint([$this->caseSeed()], extra: ['provenance' => ['quote_source' => 'synthetic_review']]);
        DB::enableQueryLog();
        $result = $this->source()->readIncremental(['spy', 'SPY'], '2026-09-30', $old);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $result['samples']);
        $this->assertSame($current, $result['samples'][0]['source_id']);
        $this->assertSame(102.0, $result['samples'][0]['target']);
        $this->assertSame($last, $result['last_checkpoint_id']);
        $this->assertFalse($result['truncated']);
        $this->assertSame([], $result['warnings']);
        $this->assertStringContainsString('"id" > ?', $queries[0]['query']);
        $this->assertStringContainsString('order by "id" asc limit 20001', $queries[0]['query']);
        $this->assertStringNotContainsString('payload_json', $queries[0]['query']);
        $this->assertContains($old, $queries[0]['bindings']);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
        }
        Http::assertNothingSent();
    }

    public function test_prices_continue_without_new_checkpoints_and_latest_completed_bars_are_normalized(): void
    {
        $checkpoint = $this->checkpoint([$this->caseSeed()]);
        $this->prices([$this->bar('2026-09-30T14:00:00Z', 100.2)]);
        $first = $this->source()->readIncremental(['SPY'], '2026-09-30');
        $latest = $this->prices([
            $this->bar('2026-09-30T14:05:00Z', 100.4),
            $this->bar('2026-09-30T14:00:00Z', 100.3),
            $this->bar('2026-09-30T14:10:00Z', 100.5), // Incomplete at recording.
            $this->bar('2026-09-30T13:20:00Z', 100.5), // Outside the regular session.
            $this->bar('2026-09-30T14:15:00Z', 100.6),
            $this->bar('2026-09-30T14:15:00Z', 100.6), // Duplicate evidence is ambiguous.
        ], '2026-09-30 14:12:00');
        $result = $this->source()->readIncremental(['SPY'], '2026-09-30', $first['last_checkpoint_id']);

        $this->assertSame([], $result['samples']);
        $this->assertSame($checkpoint, $result['last_checkpoint_id']);
        $this->assertSame($latest['hash'], $result['price_hash']);
        $this->assertSame('2026-09-30T14:12:00+00:00', $result['price_recorded_at']);
        $this->assertSame(['2026-09-30T14:05:00+00:00', '2026-09-30T14:10:00+00:00'], array_column($result['bars'], 'at'));
        $this->assertSame([100.3, 100.4], array_column($result['bars'], 'close'));
        $this->assertSame(['at', 'open', 'high', 'low', 'close'], array_keys($result['bars'][0]));
        $this->assertCount(2, $result['bars']);
        Http::assertNothingSent();
    }

    public function test_new_checkpoints_preserve_receipt_precedence_frozen_levels_and_episode_identity(): void
    {
        $this->checkpoint([$this->caseSeed(['target' => ['strike' => 105.0]])], '2026-09-30 14:30:00');
        $original = $this->checkpoint([$this->caseSeed()], '2026-09-30 14:20:00');
        $result = $this->source()->readIncremental(['SPY'], '2026-09-30');
        $sample = $result['samples'][0];

        $this->assertCount(1, $result['samples']);
        $this->assertSame($original, $sample['source_id']);
        $this->assertSame('2026-09-30T14:20:00+00:00', $sample['available_at']);
        $this->assertSame('2026-09-30T14:15:00+00:00', $sample['entry_expires_at']);
        $this->assertSame(101.0, $sample['target']);
        $this->assertNull($sample['native_outcome_at']);
        $this->assertSame($sample['id'], $this->source()->read(['SPY'], '2026-09-30', '2026-09-30')['samples'][0]['id']);

        $this->checkpoint([$this->caseSeed()]);
        $repeat = $this->source()->readIncremental(['SPY'], '2026-09-30', $result['last_checkpoint_id']);
        $this->assertSame($sample['id'], $repeat['samples'][0]['id']);
        $this->assertSame('2026-09-30T15:00:00+00:00', $repeat['samples'][0]['available_at']);
    }

    public function test_terminal_saved_qualifications_expose_original_expiry_and_terminal_time_without_reconstruction(): void
    {
        $this->checkpoint([$this->caseSeed(['status' => 'invalidated', 'ever_eligible' => true,
            'terminal_at' => '2026-09-30T14:10:00Z'])]);
        $this->checkpoint([$this->caseSeed(['status' => 'invalidated', 'ever_eligible' => true])], extra: [
            'setup_lifecycle' => ['items' => [['id' => 'setup', 'outcome' => 'invalidated', 'outcome_at' => '2026-09-30T14:05:00Z']]],
        ]);
        $sample = $this->source()->readIncremental(['SPY'], '2026-09-30')['samples'][0];

        $this->assertSame('invalidated', $sample['native_status']);
        $this->assertSame('invalidated', $sample['native_outcome']);
        $this->assertSame('2026-09-30T14:05:00+00:00', $sample['native_outcome_at']);
        $this->assertSame('2026-09-30T14:15:00+00:00', $sample['entry_expires_at']);
        $this->assertSame('2026-09-30T14:00:00+00:00', $sample['signal_at']);
        Http::assertNothingSent();
    }

    public function test_nonqualifying_or_synthetic_evidence_cannot_supply_samples_or_price_bars(): void
    {
        $this->checkpoint([$this->caseSeed(['status' => 'watch'])]);
        $this->checkpoint([$this->caseSeed(['provenance' => ['quote_source' => 'synthetic_review']])]);
        $this->checkpoint([$this->caseSeed(['scope' => ['symbol' => 'QQQ', 'session' => '2026-09-30',
            'timeframe' => '7d', 'units' => 'USD_per_1pct_move']])]);
        $last = $this->checkpoint([$this->caseSeed()], extra: ['demonstration' => true]);
        $this->prices([$this->bar('2026-09-30T14:00:00Z', 100.3)], extra: ['provenance' => ['quote_source' => 'synthetic_review']]);
        $result = $this->source()->readIncremental(['SPY'], '2026-09-30');

        $this->assertSame([], $result['samples']);
        $this->assertSame([], $result['bars']);
        $this->assertNull($result['price_recorded_at']);
        $this->assertNull($result['price_hash']);
        $this->assertSame($last, $result['last_checkpoint_id']);
    }

    public function test_empty_intake_keeps_the_cursor_and_avoids_database_reads(): void
    {
        DB::enableQueryLog();
        $result = $this->source()->readIncremental(['', null], '2026-09-30', 42);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(['samples' => [], 'truncated' => false, 'warnings' => [], 'bars' => [],
            'price_recorded_at' => null, 'price_hash' => null, 'last_checkpoint_id' => 42], $result);
        $this->assertSame([], $queries);
    }

    public function test_incremental_reads_reject_multiple_symbols(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->source()->readIncremental(['SPY', 'QQQ'], '2026-09-30');
    }

    public function test_incremental_reads_reject_negative_cursors(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->source()->readIncremental(['SPY'], '2026-09-30', -1);
    }

    private function source(): IntradayStudySource
    {
        return app(IntradayStudySource::class);
    }

    private function caseSeed(array $changes = []): array
    {
        return array_replace(['id' => 'setup', 'side' => 'put', 'strike' => 100.0, 'pattern' => 'reversal',
            'direction' => 'bullish', 'status' => 'conditions_met', 'observed_at' => '2026-09-30T14:00:00Z',
            'confirmed_at' => '2026-09-30T14:00:00Z', 'expires_at' => '2026-09-30T14:15:00Z',
            'close' => 100.1, 'evidence_grade' => 'strong', 'wall_identity' => 'wall',
            'entry_zone' => ['low' => 100.05, 'high' => 100.15], 'invalidation' => ['price' => 99.95],
            'target' => ['strike' => 101.0],
            'scope' => ['symbol' => 'SPY', 'session' => '2026-09-30', 'timeframe' => '7d', 'units' => 'USD_per_1pct_move']], $changes);
    }

    private function checkpoint(array $seeds, string $recorded = '2026-09-30 15:00:00', array $extra = [], array $row = []): int
    {
        return $this->insert(array_replace(['timeframe' => '7d', 'price_evidence_hash' => 'original-price',
            'setups' => ['rule_version' => WallSetupRules::VERSION, 'review_seeds' => $seeds]], $extra),
            array_replace(['recorded_at' => $recorded], $row))['id'];
    }

    private function prices(array $bars, string $recorded = '2026-09-30 15:00:00', array $extra = []): array
    {
        return $this->insert(array_replace(['bars' => $bars], $extra), ['dataset' => 'intraday_price_bars',
            'schema_version' => 'wall-price-bars.v1', 'recorded_at' => $recorded]);
    }

    private function bar(string $start, float $close): array
    {
        return ['t' => strtotime($start) * 1000, 'o' => 100.0, 'h' => max(100.0, $close) + .1, 'l' => 99.9, 'c' => $close];
    }

    private function insert(array $payload, array $row = []): array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $hash = hash('sha256', $json.++$this->sequence);
        $id = DB::table('wall_observations')->insertGetId(array_replace([
            'content_hash' => $hash, 'scope_key' => hash('sha256', 'research'), 'symbol' => 'SPY',
            'dataset' => 'wall_interactions', 'schema_version' => 'wall-interactions.v1', 'model_version' => 'completed-5m.v1',
            'observation_kind' => 'interaction_assessment', 'analysis_session' => '2026-09-30', 'source_date' => '2026-09-30',
            'captured_at' => '2026-09-30 15:00:00', 'recorded_at' => '2026-09-30 15:00:00',
            'quality_state' => 'descriptive', 'payload_bytes' => strlen($json), 'payload_json' => $json,
        ], $row));

        return ['id' => $id, 'hash' => $hash];
    }
}
