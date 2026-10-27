<?php

namespace Tests\Feature;

use App\Services\SetupStudy\IntradayStudySource;
use App\Support\WallIntelligence\WallSetupRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntradayStudySourceTest extends TestCase
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

    public function test_original_levels_and_availability_survive_repeated_confirmations_and_revisions(): void
    {
        $this->checkpoint([$this->caseSeed(['status' => 'watch'])], '2026-09-30 14:01:00');
        $hash = $this->checkpoint([$this->caseSeed()], '2026-09-30 14:20:00');
        $this->checkpoint([$this->caseSeed(['observed_at' => '2026-09-30T14:05:00Z'])], '2026-09-30 14:25:00');
        $this->checkpoint([$this->caseSeed(['target' => ['strike' => 105.0]])], '2026-09-30 14:30:00');
        $this->checkpoint([$this->caseSeed(['id' => 'repeat', 'wall_identity' => 'new-model-basis',
            'observed_at' => '2026-09-30T14:10:00Z'])], '2026-09-30 14:35:00');
        $this->checkpoint([$this->caseSeed(['status' => 'watch'])], '2026-09-30 14:40:00');

        $samples = $this->read()['samples'];
        $this->assertCount(1, $samples);
        $sample = $samples[0];
        $this->assertSame(101.0, $sample['target']);
        $this->assertSame('2026-09-30T14:00:00+00:00', $sample['signal_at']);
        $this->assertSame('2026-09-30T14:20:00+00:00', $sample['available_at']);
        $this->assertSame($hash, $sample['source_hash']);
        $this->assertSame(['2026-09-30T14:00:00+00:00', '2026-09-30T14:05:00+00:00', '2026-09-30T14:10:00+00:00'], $sample['confirmations']);
        $this->assertSame('below', $sample['invalidation_side']);
        $this->assertSame('2026-09-30T20:00:00+00:00', $sample['review_until']);
        $this->assertSame($sample['id'], $this->read()['samples'][0]['id']);
    }

    public function test_later_new_qualifications_survive_many_checkpoints_and_reads_only_projected_saved_evidence(): void
    {
        for ($i = 0; $i < 170; $i++) {
            $this->checkpoint([$this->caseSeed()]);
        }
        $this->checkpoint([$this->caseSeed(['id' => 'later', 'target' => ['strike' => 102.0]])]);
        $this->insert(['strike_data' => array_fill(0, 100, ['unrelated' => true])], ['dataset' => 'intraday_capture']);
        $before = DB::table('wall_observations')->orderBy('id')->pluck('payload_json', 'content_hash')->all();
        DB::enableQueryLog();
        $result = $this->read();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $result['samples']);
        $this->assertFalse($result['truncated']);
        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('select *', strtolower($query['query']));
            $this->assertNotContains('intraday_capture', $query['bindings']);
        }
        $this->assertSame($before, DB::table('wall_observations')->orderBy('id')->pluck('payload_json', 'content_hash')->all());
        Http::assertNothingSent();
    }

    public function test_nonqualifying_demo_wrong_scope_schema_symbol_and_dates_are_excluded(): void
    {
        $this->checkpoint([$this->caseSeed(['status' => 'watch'])]);
        $this->checkpoint([$this->caseSeed(['provenance' => ['quote_source' => 'synthetic_review']])]);
        $this->checkpoint([$this->caseSeed(['scope' => ['symbol' => 'QQQ', 'session' => '2026-09-30', 'timeframe' => '14d', 'units' => 'USD_per_1pct_move']])]);
        $this->checkpoint([$this->caseSeed()], extra: ['state' => 'demonstration']);
        $this->checkpoint([$this->caseSeed()], extra: ['demonstration' => true]);
        $this->checkpoint([$this->caseSeed()], row: ['schema_version' => 'unknown']);
        $this->checkpoint([$this->caseSeed()], row: ['symbol' => 'QQQ']);
        $this->checkpoint([$this->caseSeed()], row: ['analysis_session' => '2026-09-29']);
        $this->assertSame([], $this->read()['samples']);

        $this->checkpoint([$this->caseSeed(['status' => 'invalidated', 'ever_eligible' => true, 'terminal_at' => '2026-09-30T14:05:00Z'])]);
        $this->assertCount(1, $this->read()['samples']);
    }

    public function test_latest_completed_price_bundle_is_used_and_bar_ends_are_normalized(): void
    {
        $this->checkpoint([$this->caseSeed()]);
        $this->prices([$this->bar('2026-09-30T14:00:00Z', 100.2)]);
        $hash = $this->prices([
            $this->bar('2026-09-30T14:05:00Z', 100.4),
            $this->bar('2026-09-30T14:00:00Z', 100.3),
            $this->bar('2026-09-30T14:10:00Z', 100.5), // Ends after recording.
            $this->bar('2026-09-30T13:20:00Z', 100.5), // Before regular hours.
            $this->bar('2026-09-30T14:15:00Z', 100.5),
            $this->bar('2026-09-30T14:15:00Z', 100.5), // Ambiguous duplicate.
        ], '2026-09-30 14:12:00');
        $this->insert(['bars' => [$this->bar('2026-09-30T14:00:00Z', 999)]],
            ['dataset' => 'intraday_price_bars', 'schema_version' => 'unsupported']);

        $sample = $this->read()['samples'][0];
        $this->assertSame($hash, $sample['price_evidence_hash']);
        $this->assertSame(['2026-09-30T14:05:00+00:00', '2026-09-30T14:10:00+00:00'], array_column($sample['bars'], 'at'));
        $this->assertSame([100.3, 100.4], array_column($sample['bars'], 'close'));
        $this->assertSame(100.0, $sample['bars'][0]['open']);
    }

    public function test_saved_terminal_outcome_separates_a_later_episode_with_identical_levels(): void
    {
        $this->checkpoint([$this->caseSeed()], extra: ['setup_lifecycle' => ['items' => [
            ['id' => 'setup', 'outcome' => 'target_reached', 'outcome_at' => '2026-09-30T14:10:00Z'],
        ]]]);
        $this->checkpoint([$this->caseSeed(['id' => 'repeat', 'observed_at' => '2026-09-30T14:05:00Z'])]);
        $this->checkpoint([$this->caseSeed(['id' => 'new-episode', 'observed_at' => '2026-09-30T14:15:00Z'])]);

        $samples = $this->read()['samples'];
        $this->assertCount(2, $samples);
        $this->assertCount(2, $samples[0]['confirmations']);
        $this->assertSame('target_reached', $samples[0]['native_outcome']);
        $this->assertSame('2026-09-30T14:15:00+00:00', $samples[1]['signal_at']);
        $this->assertNotSame($samples[0]['id'], $samples[1]['id']);
    }

    public function test_early_close_uses_the_market_calendar_and_rejects_later_bars(): void
    {
        $session = '2026-11-27';
        $seed = $this->caseSeed(['observed_at' => $session.'T17:00:00Z', 'confirmed_at' => $session.'T17:00:00Z',
            'scope' => ['symbol' => 'SPY', 'session' => $session, 'timeframe' => '14d', 'units' => 'USD_per_1pct_move']]);
        $this->checkpoint([$seed], $session.' 19:00:00', row: ['analysis_session' => $session]);
        $this->prices([$this->bar($session.'T17:55:00Z', 100.5), $this->bar($session.'T18:00:00Z', 100.6)],
            $session.' 19:00:00', $session);
        $sample = app(IntradayStudySource::class)->read(['SPY'], $session, $session)['samples'][0];
        $this->assertSame($session.'T18:00:00+00:00', $sample['review_until']);
        $this->assertCount(1, $sample['bars']);
        $this->assertSame($session.'T18:00:00+00:00', $sample['bars'][0]['at']);
    }

    private function read(): array
    {
        return app(IntradayStudySource::class)->read(['spy', 'SPY'], '2026-09-30', '2026-09-30');
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

    private function checkpoint(array $seeds, string $recorded = '2026-09-30 15:00:00', array $extra = [], array $row = []): string
    {
        return $this->insert(array_replace(['timeframe' => '14d', 'price_evidence_hash' => 'original-price',
            'setups' => ['rule_version' => WallSetupRules::VERSION, 'review_seeds' => $seeds]], $extra),
            array_replace(['recorded_at' => $recorded], $row));
    }

    private function prices(array $bars, string $recorded = '2026-09-30 15:00:00', string $session = '2026-09-30'): string
    {
        return $this->insert(['bars' => $bars], ['dataset' => 'intraday_price_bars', 'schema_version' => 'wall-price-bars.v1',
            'recorded_at' => $recorded, 'analysis_session' => $session]);
    }

    private function bar(string $start, float $close): array
    {
        return ['t' => strtotime($start) * 1000, 'o' => 100.0, 'h' => max(100.0, $close) + .1, 'l' => 99.9, 'c' => $close];
    }

    private function insert(array $payload, array $row = []): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $hash = hash('sha256', $json.++$this->sequence);
        DB::table('wall_observations')->insert(array_replace([
            'content_hash' => $hash, 'scope_key' => hash('sha256', 'study'), 'symbol' => 'SPY',
            'dataset' => 'wall_interactions', 'schema_version' => 'wall-interactions.v1', 'model_version' => 'completed-5m.v1',
            'observation_kind' => 'interaction_assessment', 'analysis_session' => '2026-09-30', 'source_date' => '2026-09-30',
            'captured_at' => '2026-09-30 15:00:00', 'recorded_at' => '2026-09-30 15:00:00',
            'quality_state' => 'descriptive', 'payload_bytes' => strlen($json), 'payload_json' => $json,
        ], $row));

        return $hash;
    }
}
