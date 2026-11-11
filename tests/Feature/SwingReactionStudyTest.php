<?php

namespace Tests\Feature;

use App\Services\SetupStudy\IntradayStudySource;
use App\Services\SetupStudy\SetupStudyService;
use App\Services\SetupStudy\SwingReactionStudySource;
use App\Services\SetupStudy\SwingStudySource;
use App\Support\SetupStudy\SwingReactionStudyMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SwingReactionStudyTest extends TestCase
{
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'cache.coordination_enabled' => false]);
        DB::purge('sqlite');
        Cache::flush();
        Schema::create('swing_reaction_ideas', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('symbol');
            $table->string('horizon');
            $table->string('rule_version');
            $table->date('source_date');
            $table->string('status');
            $table->longText('original_plan');
            $table->longText('current_state');
            $table->timestamps();
        });
        Schema::create('swing_reaction_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('idea_id');
            $table->timestamp('observed_at');
            $table->timestamp('recorded_at');
            $table->longText('state_json');
        });
        $this->travelTo(CarbonImmutable::parse('2026-11-10 22:00', 'UTC'));
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_target_before_entry_is_retained_but_never_counts_as_a_win(): void
    {
        $this->insert(['status' => 'cancelled', 'outcome' => 'target_before_entry',
            'outcome_at' => '2026-11-10T16:00:00Z']);
        $row = $this->measureFirst();

        $this->assertFalse($row['qualified']);
        $this->assertTrue($row['followup_complete']);
        $this->assertSame('not_qualified', $row['status']);
        $this->assertSame('target_before_entry', $row['outcome']);
        $this->assertNull($row['metrics']['strict_outcome']);
        $this->assertNull($row['metrics']['mfe_pct']);
        $summary = (new SetupStudyService)->summarize([$row]);
        $this->assertSame(1, $summary['cancelled_before_entry']);
        $this->assertSame(['hits' => 0, 'eligible' => 0, 'rate' => null], $summary['strict_target']);
    }

    public function test_stop_before_entry_is_not_a_post_entry_loss(): void
    {
        $this->insert(['status' => 'cancelled', 'outcome' => 'stop_before_entry',
            'outcome_at' => '2026-11-10T16:00:00Z']);
        $row = $this->measureFirst();

        $this->assertFalse($row['qualified']);
        $this->assertSame('stop_before_entry', $row['outcome']);
        $this->assertNull($row['metrics']['strict_outcome']);
        $this->assertNull($row['metrics']['mae_pct']);
        $this->assertSame(0, (new SetupStudyService)->summarize([$row])['completed']);
    }

    public function test_a_later_ordered_target_touch_uses_entry_reference_and_touch_measurement(): void
    {
        $this->insert($this->entered(['status' => 'completed', 'outcome' => 'target_reached',
            'outcome_at' => '2026-11-10T17:00:00Z']));
        $row = $this->measureFirst();

        $this->assertTrue($row['qualified']);
        $this->assertTrue($row['followup_complete']);
        $this->assertSame('price-touch-study.v2', $row['metric_version']);
        $this->assertSame('price_touch', $row['metric_basis']);
        $this->assertSame(101., $row['signal_price']);
        $this->assertSame('target_reached', $row['metrics']['strict_outcome']);
        $this->assertSame(3.9604, $row['metrics']['mfe_pct']);
        $this->assertSame(2., $row['metrics']['mfe_r']);
        $this->assertNull($row['metrics']['close_mfe_r']);
        $this->assertTrue($row['metrics']['entry_revisited']);
        $this->assertTrue($row['metrics']['reached_1r']);
        $this->assertSame(['hits' => 1, 'eligible' => 1, 'rate' => 100.],
            (new SetupStudyService)->summarize([$row])['strict_target']);
    }

    public function test_stop_after_entry_maps_to_a_verified_invalidation(): void
    {
        $this->insert($this->entered(['status' => 'completed', 'outcome' => 'stop_reached',
            'outcome_at' => '2026-11-10T17:00:00Z']));
        $row = $this->measureFirst();

        $this->assertSame('invalidated', $row['metrics']['strict_outcome']);
        $this->assertTrue($row['followup_complete']);
        $this->assertSame(['hits' => 0, 'eligible' => 1, 'rate' => 0.],
            (new SetupStudyService)->summarize([$row])['strict_target']);
    }

    public function test_ambiguous_or_gapped_exits_are_excluded_from_verified_rates(): void
    {
        foreach ([['status' => 'ambiguous', 'ambiguous' => true], ['status' => 'completed', 'evidence_gap' => true]] as $problem) {
            $this->insert($this->entered([...$problem, 'outcome' => 'target_reached',
                'outcome_at' => '2026-11-10T17:00:00Z']));
        }
        $rows = array_map(fn ($sample) => (new SwingReactionStudyMetrics)->measure($sample), $this->samples());

        foreach ($rows as $row) {
            $this->assertTrue($row['needs_update']);
            $this->assertFalse($row['followup_complete']);
            $this->assertNull($row['metrics']['strict_outcome']);
        }
        $summary = (new SetupStudyService)->summarize($rows);
        $this->assertSame(2, $summary['needs_update']);
        $this->assertSame(0, $summary['strict_target']['eligible']);
    }

    public function test_no_entry_and_holding_deadlines_are_distinct_outcomes(): void
    {
        $this->insert(['status' => 'expired', 'outcome' => 'no_entry', 'outcome_at' => '2026-11-10T17:00:00Z']);
        $this->insert($this->entered(['status' => 'expired', 'outcome' => 'holding_ended',
            'outcome_at' => '2026-11-10T17:00:00Z']));
        $rows = array_map(fn ($sample) => (new SwingReactionStudyMetrics)->measure($sample), $this->samples());
        $byOutcome = collect($rows)->keyBy('outcome');

        $this->assertFalse($byOutcome['no_entry']['qualified']);
        $this->assertNull($byOutcome['no_entry']['metrics']['strict_outcome']);
        $this->assertTrue($byOutcome['holding_ended']['qualified']);
        $this->assertSame('holding_ended', $byOutcome['holding_ended']['metrics']['strict_outcome']);
        $summary = (new SetupStudyService)->summarize($rows);
        $this->assertSame(1, $summary['no_entry']);
        $this->assertSame(1, $summary['completed']);
    }

    public function test_historical_report_uses_the_latest_state_known_at_its_cutoff(): void
    {
        $id = $this->insert($this->entered(['status' => 'completed', 'outcome' => 'target_reached',
            'outcome_at' => '2026-11-10T17:00:00Z']));
        $state = $this->plan(['status' => 'ready', 'qualified_at' => '2026-11-09T15:00:00Z']);
        DB::table('swing_reaction_reviews')->insert(['idea_id' => $id, 'observed_at' => '2026-11-09 15:00:00',
            'recorded_at' => '2026-11-09 15:15:00', 'state_json' => json_encode($state)]);
        $sample = (new SwingReactionStudySource)->read(['SPY'], '2026-11-06', '2026-11-09')['samples'][0];

        $this->assertSame('ready', $sample['native_status']);
        $this->assertNull($sample['signal_at']);
        $this->assertNull($sample['native_outcome']);
        $this->assertSame('2026-11-09 15:15:00', $sample['source']['state_recorded_at']);
    }

    public function test_prepublication_or_preconfirmation_entry_cannot_become_a_qualified_sample(): void
    {
        $this->insert($this->entered(['entry_at' => '2026-11-06T13:00:00Z', 'status' => 'completed',
            'outcome' => 'target_reached', 'outcome_at' => '2026-11-10T17:00:00Z']));
        $row = $this->measureFirst();

        $this->assertFalse($row['qualified']);
        $this->assertTrue($row['needs_update']);
        $this->assertFalse($row['followup_complete']);
        $this->assertNull($row['metrics']['strict_outcome']);
    }

    public function test_frozen_original_levels_and_symbol_scope_are_preserved_without_writes(): void
    {
        $this->insert($this->entered(['levels' => ['target' => 999.]]));
        $this->insert(['symbol' => 'QQQ']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $samples = $this->samples(['spy']);

        $this->assertCount(1, $samples);
        $this->assertSame(105., $samples[0]['target']);
        $this->assertSame('SPY', $samples[0]['symbol']);
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query['query']);
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_collector_archives_touch_versions_alongside_existing_close_records(): void
    {
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        (require database_path('migrations/2026_10_28_000000_create_setup_study_records_table.php'))->up();
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('symbol');
        });
        DB::table('users')->insert(['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'unused']);
        DB::table('watchlists')->insert(['user_id' => 1, 'symbol' => 'SPY']);
        $empty = new class
        {
            public function read(...$arguments): array
            {
                return ['samples' => [], 'warnings' => [], 'truncated' => false];
            }
        };
        app()->instance(IntradayStudySource::class, $empty);
        app()->instance(SwingStudySource::class, $empty);
        $id = $this->insert($this->entered(['status' => 'completed', 'outcome' => 'target_reached',
            'outcome_at' => '2026-11-10T17:00:00Z',
            'tier' => $this->tier('gold', 'swing-idea-tier.v1', '2026-11-06T14:00:00Z')]));
        $oldId = hash('sha256', 'old-close-plan');
        $old = ['id' => $oldId, 'symbol' => 'SPY', 'direction' => 'bullish', 'pattern' => 'wall_pullback_reclaim',
            'rule_version' => 'swing-wall-reclaim.v1.1', 'metric_version' => 'recorded-close-study.v1',
            'qualified' => false, 'status' => 'watching', 'followup_complete' => false, 'needs_update' => false,
            'metrics' => ['strict_outcome' => null]];
        DB::table('setup_study_records')->insert(['owner_id' => 1, 'sample_id' => $oldId, 'cohort' => 'swing_short',
            'symbol' => 'SPY', 'session' => '2026-11-09', 'rule_version' => $old['rule_version'],
            'payload' => json_encode($old), 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('setup_study_records')->where('sample_id', $oldId)->value('payload');
        $service = new SetupStudyService;
        $collected = $service->collect(1, 20);
        $report = $service->report(1, ['cohort' => 'swing_short', 'from' => '2026-11-06', 'to' => '2026-11-10']);

        $this->assertSame(1, $collected['records']);
        $this->assertSame(2, $report['summary']['total']);
        $this->assertSame(1, $report['summary']['strict_target']['hits']);
        $this->assertSame(['swing-wall-reaction.v2', 'swing-wall-reclaim.v1.1'], array_column($report['breakdowns']['rule_versions'], 'label'));
        $this->assertSame(['price-touch-study.v2', 'recorded-close-study.v1'], array_column($report['breakdowns']['measurement_methods'], 'label'));
        $this->assertSame([['label' => 'Gold · swing-idea-tier.v1', 'hits' => 1]],
            array_map(fn ($row) => ['label' => $row['label'], 'hits' => $row['strict_target']['hits']], $report['breakdowns']['tiers']));
        $this->assertSame($before, DB::table('setup_study_records')->where('sample_id', $oldId)->value('payload'));
        $this->assertNotNull(DB::table('setup_study_records')->where('sample_id', $id)->first());
        $filters = ['cohort' => 'swing_short', 'from' => '2026-11-06', 'to' => '2026-11-10'];
        $this->assertSame(1, $service->report(1, [...$filters, 'rule_version' => 'swing-wall-reaction.v2'])['summary']['total']);
        $this->assertSame(1, $service->report(1, [...$filters, 'rule_version' => 'swing-wall-reclaim.v1.1'])['summary']['total']);
        $this->assertSame(2, $service->report(1, [...$filters, 'rule_version' => 'all'])['summary']['total']);
        $stored = json_decode(DB::table('setup_study_records')->where('sample_id', $id)->value('payload'), true);
        $stored['tier']['assigned_at'] = '2026-11-11T14:00:00Z';
        DB::table('setup_study_records')->where('sample_id', $id)->update(['payload' => json_encode($stored)]);
        $earlier = $service->report(1, $filters);
        $this->assertSame('Not assigned', $earlier['breakdowns']['tiers'][0]['label']);
        $this->assertNull(collect($earlier['rows'])->firstWhere('id', $id)['tier']);
        $this->assertSame('gold', json_decode(DB::table('setup_study_records')->where('sample_id', $id)->value('payload'), true)['tier']['key']);
    }

    public function test_incremental_archive_skips_unchanged_records_and_never_replays_legacy_history(): void
    {
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        (require database_path('migrations/2026_10_28_000000_create_setup_study_records_table.php'))->up();
        DB::table('users')->insert(['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'unused']);
        $this->insert(['status' => 'cancelled', 'outcome' => 'target_before_entry', 'outcome_at' => '2026-11-10T17:00:00Z']);
        $forbidden = new class
        {
            public function read(...$arguments): array
            {
                throw new \RuntimeException('Legacy history must not be read by the incremental archive.');
            }
        };
        app()->instance(IntradayStudySource::class, $forbidden);
        app()->instance(SwingStudySource::class, $forbidden);
        $archive = new \App\Services\SetupStudy\SwingReactionStudyArchive;
        $this->assertSame(1, $archive->collect(1));
        $this->assertSame(0, $archive->collect(1));
        $payload = json_decode(DB::table('setup_study_records')->value('payload'), true);
        $this->assertFalse($payload['qualified']);
        $this->assertNull($payload['metrics']['strict_outcome']);
        $this->assertSame('target_before_entry', $payload['outcome']);
        $this->assertSame('swing_short', DB::table('setup_study_records')->value('cohort'));
        Http::assertNothingSent();
    }

    public function test_tier_metadata_is_frozen_and_only_appears_after_its_recorded_assignment(): void
    {
        $gold = $this->tier('gold', 'swing-idea-tier.v1', '2026-11-10T16:00:00Z');
        $this->insert(['tier' => $gold]);
        $sample = $this->samples()[0];
        $this->assertSame($gold, $sample['tier']);
        $this->assertSame($gold, (new SwingReactionStudyMetrics)->measure($sample)['tier']);

        $historical = (new SwingReactionStudySource)->read(['SPY'], '2026-11-06', '2026-11-09');
        $this->assertNull($historical['samples'][0]['tier']);
        $this->assertNull($historical['samples'][0]['native_outcome']);
    }

    public function test_a_publication_tier_is_not_replaced_by_later_state_metadata_or_outcomes(): void
    {
        $id = $this->insert($this->entered(['status' => 'completed', 'outcome' => 'stop_reached',
            'outcome_at' => '2026-11-10T17:00:00Z', 'tier' => $this->tier('bronze', 'future.v2', '2026-11-10T16:00:00Z')]));
        $original = $this->plan(['tier' => $this->tier('gold', 'swing-idea-tier.v1', '2026-11-06T14:00:00Z')]);
        DB::table('swing_reaction_ideas')->where('id', $id)->update(['original_plan' => json_encode($original)]);

        $sample = $this->samples()[0];
        $this->assertSame('gold', $sample['tier']['key']);
        $this->assertSame('swing-idea-tier.v1', $sample['tier']['version']);
        $this->assertSame('invalidated', (new SwingReactionStudyMetrics)->measure($sample)['metrics']['strict_outcome']);
    }

    public function test_incremental_archive_backfills_missing_tiers_but_preserves_a_previously_recorded_version(): void
    {
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        (require database_path('migrations/2026_10_28_000000_create_setup_study_records_table.php'))->up();
        DB::table('users')->insert(['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'unused']);
        $id = $this->insert(['status' => 'cancelled', 'outcome' => 'target_before_entry', 'outcome_at' => '2026-11-10T17:00:00Z']);
        $archive = new \App\Services\SetupStudy\SwingReactionStudyArchive;
        $this->assertSame(1, $archive->collect(1));
        $before = json_decode(DB::table('setup_study_records')->value('payload'), true);
        $this->assertNull($before['tier']);

        $this->travel(1)->minutes();
        $state = json_decode(DB::table('swing_reaction_ideas')->where('id', $id)->value('current_state'), true);
        $gold = $this->tier('gold', 'swing-idea-tier.v1', now('UTC')->toIso8601String());
        $state['tier'] = $gold;
        DB::table('swing_reaction_ideas')->where('id', $id)->update(['current_state' => json_encode($state), 'updated_at' => now()]);
        $this->assertSame(1, $archive->collect(1));
        $ranked = json_decode(DB::table('setup_study_records')->value('payload'), true);
        $this->assertSame($gold, $ranked['tier']);
        $this->assertSame($before['metrics'], $ranked['metrics']);
        $this->assertSame($before['outcome'], $ranked['outcome']);

        $this->travel(1)->minutes();
        $state['tier'] = $this->tier('bronze', 'future.v2', now('UTC')->toIso8601String());
        DB::table('swing_reaction_ideas')->where('id', $id)->update(['current_state' => json_encode($state), 'updated_at' => now()]);
        $this->assertSame(1, $archive->collect(1));
        $this->assertSame($gold, json_decode(DB::table('setup_study_records')->value('payload'), true)['tier']);
    }

    private function tier(string $key, string $version, string $assigned): array
    {
        return ['key' => $key, 'label' => ucfirst($key), 'rank' => $key === 'gold' ? 3 : 1,
            'version' => $version, 'assigned_at' => $assigned, 'reasons' => ['Recorded publication geometry'],
            'tradeoffs' => [], 'metrics' => ['reward_risk' => 2]];
    }

    private function samples(array $symbols = ['SPY']): array
    {
        return (new SwingReactionStudySource)->read($symbols, '2026-11-06', '2026-11-10')['samples'];
    }

    private function measureFirst(): array
    {
        return (new SwingReactionStudyMetrics)->measure($this->samples()[0]);
    }

    private function insert(array $state): string
    {
        $id = hash('sha256', 'reaction-study-'.++$this->sequence);
        $original = $this->plan(['symbol' => $state['symbol'] ?? 'SPY']);
        $current = array_replace($original, $state);
        DB::table('swing_reaction_ideas')->insert(['id' => $id, 'symbol' => $original['symbol'],
            'horizon' => 'short', 'rule_version' => 'swing-wall-reaction.v2', 'source_date' => '2026-11-05',
            'status' => $current['status'], 'original_plan' => json_encode($original), 'current_state' => json_encode($current),
            'created_at' => '2026-11-06 14:00:00', 'updated_at' => '2026-11-10 18:00:00']);

        return $id;
    }

    private function entered(array $overrides = []): array
    {
        return [...$this->plan(), 'status' => 'entry_touched', 'qualified_at' => '2026-11-09T14:30:00Z',
            'ready_recorded_at' => '2026-11-09T14:45:00Z',
            'entry_at' => '2026-11-09T15:00:00Z', 'entry_price' => 101., 'holding_deadline' => '2026-11-16T21:00:00Z',
            'observations' => 15, 'last_at' => '2026-11-10T17:00:00Z',
            'metrics' => ['mfe_pct' => 3.9604, 'mae_pct' => .495, 'mfe_r' => 2., 'mae_r' => .25], ...$overrides];
    }

    private function plan(array $overrides = []): array
    {
        return ['symbol' => 'SPY', 'horizon' => 'short', 'pattern' => 'hold_reclaim', 'direction' => 'bullish',
            'side' => 'put', 'status' => 'watching', 'available_at' => '2026-11-06T14:00:00Z',
            'entry_deadline' => '2026-11-11T21:00:00Z', 'entry_at' => null, 'qualified_at' => null,
            'outcome' => null, 'levels' => ['wall' => 100., 'entry_low' => 100.5, 'entry_high' => 101.,
                'invalidation' => 99., 'target' => 105.], 'evidence' => ['atr14' => 2.], ...$overrides];
    }
}
