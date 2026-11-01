<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IntradayResearch\IntradayResearchService;
use App\Services\SetupStudy\IntradayStudySource;
use App\Support\WallIntelligence\WallSetupRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntradayResearchServiceTest extends TestCase
{
    private const OWNER = 20;

    private object $source;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'cache.coordination_enabled' => false,
            'setup_study.owner_email' => 'study.owner@example.test', 'intraday_research.enabled' => true]);
        DB::purge('sqlite');
        Cache::flush();
        foreach (['0001_01_01_000000_create_users_table.php', '2026_10_31_000001_create_intraday_research_tables.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('symbol');
        });
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Other', 'email' => 'someone@example.test', 'password' => 'unused'],
            ['id' => self::OWNER, 'name' => 'Owner', 'email' => 'study.owner@example.test', 'password' => 'unused'],
        ]);
        $this->source = new class
        {
            public array $samples = [];

            public array $bars = [];

            public array $calls = [];

            public int $checkpoint = 10;

            public bool $truncated = false;

            public function readIncremental(array $symbols, string $session, int $afterCheckpointId = 0): array
            {
                $this->calls[] = [$symbols, $session, $afterCheckpointId];

                return ['samples' => $this->samples, 'bars' => $this->bars, 'last_checkpoint_id' => $this->checkpoint,
                    'price_recorded_at' => now('UTC')->toIso8601String(), 'price_hash' => hash('sha256', json_encode($this->bars)),
                    'truncated' => $this->truncated, 'warnings' => []];
            }
        };
        app()->instance(IntradayStudySource::class, $this->source);
        $this->at('10:00');
        $this->source->samples = [$this->sample()];
        $this->source->bars = [$this->bar('09:45', 101.5)];
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_owner_gate_ignores_spoofed_ids_and_read_routes_do_not_collect(): void
    {
        $this->get('/intraday-research')->assertRedirect('/login');
        $this->getJson('/api/intraday-research')->assertUnauthorized();
        $this->getJson('/api/intraday-research/alerts')->assertUnauthorized();
        $this->signIn(1);
        $this->get('/intraday-research')->assertForbidden();
        $this->getJson('/api/intraday-research?owner_id=20')->assertForbidden();
        $this->postJson('/api/intraday-research/refresh', ['owner_id' => 20])->assertForbidden();
        $this->postJson('/api/intraday-research/alerts/'.str_repeat('a', 64).'/read')->assertForbidden();
        foreach (['collect', 'report', 'alerts', 'markAlertRead'] as $method) {
            try {
                $arguments = match ($method) {
                    'report' => [1, $this->filters()], 'markAlertRead' => [1, str_repeat('a', 64)], default => [1],
                };
                (new IntradayResearchService)->{$method}(...$arguments);
                $this->fail('Foreign owner IDs must be rejected.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->signIn(self::OWNER);
        $this->get('/intraday-research')->assertOk()->assertSee('IntradayResearch');
        $this->getJson('/api/intraday-research?owner_id=1')->assertOk()->assertJsonPath('prepared', true);
        $this->postJson('/api/intraday-research/refresh', ['owner_id' => 1])->assertOk()->assertJsonPath('symbols', 0);
        $this->assertSame([], $this->source->calls);
        $this->getJson('/api/intraday-research?from=2025-01-01&to=2026-10-26')->assertUnprocessable();
    }

    public function test_paired_publication_is_frozen_idempotent_and_uses_one_delta_read_per_symbol(): void
    {
        $this->watch();
        $service = new IntradayResearchService;
        $this->assertSame(1, $service->collect(self::OWNER)['groups']);
        $original = DB::table('intraday_research_cases')->pluck('original_plan', 'id')->all();
        $saved = DB::table('intraday_research_groups')->first();
        $this->assertArrayNotHasKey('bars', json_decode($saved->original_sample, true));
        $this->source->samples[0]['available_at'] = $this->instant('10:01');
        $this->source->samples[0]['source_hash'] = str_repeat('c', 64);
        $this->assertSame(0, $service->collect(self::OWNER)['groups']);
        $this->assertSame(1, DB::table('intraday_research_groups')->count());
        $this->assertSame(2, DB::table('intraday_research_cases')->count());
        $this->assertSame($original, DB::table('intraday_research_cases')->pluck('original_plan', 'id')->all());
        $this->assertSame($saved->original_sample, DB::table('intraday_research_groups')->first()->original_sample);
        $this->assertSame([[['SPY'], '2026-10-26', 0], [['SPY'], '2026-10-26', 10]], $this->source->calls);
        $this->assertSame(0, DB::table('intraday_research_cases')->where('status', 'needs_update')->count());
        $this->assertSame([], $service->alerts(self::OWNER)['items']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_causal_retest_notifies_once_only_after_delayed_future_bar_and_mark_read_is_idempotent(): void
    {
        $this->publish();
        $service = new IntradayResearchService;
        $this->source->samples = [];
        $this->followup('10:20');
        $alerts = $service->alerts(self::OWNER);
        $this->assertCount(1, $alerts['items']);
        $alert = $alerts['items'][0];
        $this->assertSame('post_publication_retest', $alert['variant']);
        $this->assertSame('conditions_met', $alert['status']);
        $this->assertSame('conditions_met', $alert['notification_type']);
        $this->assertSame('intraday', $alert['mode']);
        $this->assertSame($this->instant('10:05'), $alert['signal_at']);
        $this->assertSame($this->instant('10:20'), $alert['notification_recorded_at']);
        $this->assertArrayNotHasKey('outcome', $alert);
        $service->collect(self::OWNER);
        $this->assertSame($alerts, $service->alerts(self::OWNER));
        $service->markAlertRead(self::OWNER, $alert['id']);
        $read = DB::table('intraday_research_cases')->where('id', $alert['id'])->value('alert_read_at');
        $this->at('10:21');
        $service->markAlertRead(self::OWNER, $alert['id']);
        $this->assertSame($read, DB::table('intraday_research_cases')->where('id', $alert['id'])->value('alert_read_at'));
        $this->assertSame(0, $service->alerts(self::OWNER)['unread_count']);
        $state = $this->state();
        $this->assertSame($this->instant('10:20'), $state['qualified_recorded_at']);
        $this->assertSame($this->instant('10:20'), $state['signal_available_at']);
        $this->assertCount(3, $this->source->calls);
    }

    public function test_all_known_bars_are_processed_before_notification_and_resolved_same_batch_has_no_alert(): void
    {
        $this->publish();
        $this->source->samples = [];
        $this->followup('10:25', [$this->bar('10:10', 111.)]);
        $state = $this->state();
        $this->assertTrue($state['metrics']['qualified']);
        $this->assertSame('target_reached', $state['outcome']);
        $this->assertSame($this->instant('10:25'), $state['qualified_recorded_at']);
        $this->assertNull(DB::table('intraday_research_cases')->where('variant', 'post_publication_retest')->value('notification_recorded_at'));
        $this->assertSame([], (new IntradayResearchService)->alerts(self::OWNER)['items']);
    }

    public function test_source_and_bar_revisions_preserve_original_evidence_and_exclude_outcomes(): void
    {
        $this->publish();
        $this->followup('10:25', [$this->bar('10:10', 111.)]);
        $group = DB::table('intraday_research_groups')->first();
        $bar = DB::table('intraday_research_bars')->where('bar_at', CarbonImmutable::parse($this->instant('10:05'))->toDateTimeString())->first();
        $this->source->samples[0]['target'] = 112.;
        $this->source->bars[3] = $this->bar('10:05', 99.5);
        (new IntradayResearchService)->collect(self::OWNER);
        $this->assertSame($group->original_sample, DB::table('intraday_research_groups')->first()->original_sample);
        $pinned = DB::table('intraday_research_bars')->where('id', $bar->id)->first();
        $this->assertSame($bar->ohlc, $pinned->ohlc);
        $this->assertNotNull($pinned->revision);
        $report = (new IntradayResearchService)->report(self::OWNER, $this->filters());
        foreach ($report['summary'] as $summary) {
            $this->assertSame(0, $summary['completed']);
            $this->assertSame(0, $summary['target_rate']['eligible']);
            $this->assertSame(1, $summary['needs_review']);
        }
    }

    public function test_gapped_or_too_recent_bars_never_emit_conditions_met(): void
    {
        $this->publish();
        $this->source->samples = [];
        $this->source->bars = [$this->bar('09:50', 101.5), $this->bar('09:55', 101.5), $this->bar('10:05', 100.), $this->bar('10:10', 100.)];
        $this->at('10:20');
        (new IntradayResearchService)->collect(self::OWNER);
        $this->assertTrue($this->state()['metrics']['needs_update']);
        $this->assertSame([], (new IntradayResearchService)->alerts(self::OWNER)['items']);
        $this->assertSame(0, DB::table('intraday_research_bars')->where('bar_at', CarbonImmutable::parse($this->instant('10:10'))->toDateTimeString())->count());
    }

    public function test_missing_delayed_bar_recovers_on_next_collection(): void
    {
        $this->publish();
        $this->source->samples = [];
        $this->source->bars = [$this->bar('09:50', 101.5), $this->bar('09:55', 101.5), $this->bar('10:00', 101.5)];
        $this->at('10:20:05');
        $service = new IntradayResearchService;
        $service->collect(self::OWNER);
        $this->assertSame('needs_update', $this->state()['status']);
        $this->assertSame([], $service->alerts(self::OWNER)['items']);
        $this->assertEmpty($this->state()['correction_detected'] ?? false);
        $pinned = DB::table('intraday_research_bars')->pluck('ohlc', 'bar_at')->all();
        $original = DB::table('intraday_research_cases')->pluck('original_plan', 'id')->all();
        $this->source->bars[] = $this->bar('10:05', 100.);
        $this->at('10:21');
        $result = $service->collect(self::OWNER);
        $this->assertSame(1, $result['symbols']);
        $this->assertSame(0, $result['groups']);
        $this->assertSame('conditions_met', $this->state()['status']);
        $this->assertFalse($this->state()['metrics']['needs_update']);
        $this->assertSame($this->instant('10:21'), $this->state()['qualified_recorded_at']);
        $this->assertCount(1, $service->alerts(self::OWNER)['items']);
        $this->assertSame(0, DB::table('intraday_research_bars')->whereNotNull('revision')->count());
        $this->assertSame($pinned, DB::table('intraday_research_bars')->whereIn('bar_at', array_keys($pinned))->pluck('ohlc', 'bar_at')->all());
        $this->assertSame($original, DB::table('intraday_research_cases')->pluck('original_plan', 'id')->all());
    }

    public function test_removed_symbol_with_non_correction_review_stays_in_rotation_until_late_bar_recovers(): void
    {
        $this->publish();
        $this->source->samples = [];
        $this->source->bars = [$this->bar('09:50', 101.5), $this->bar('09:55', 101.5), $this->bar('10:00', 101.5), $this->bar('10:10', 101.5)];
        $this->at('10:25:05');
        $service = new IntradayResearchService;
        $service->collect(self::OWNER);
        $this->assertSame(['needs_update'], DB::table('intraday_research_cases')->distinct()->pluck('status')->all());
        DB::table('watchlists')->where('user_id', self::OWNER)->delete();
        $this->source->bars[] = $this->bar('10:05', 100.);
        $this->at('10:26');
        $result = $service->collect(self::OWNER);
        $this->assertSame(1, $result['symbols']);
        $this->assertSame(0, $result['groups']);
        $this->assertSame('in_progress', $this->state()['status']);
        $this->assertFalse($this->state()['metrics']['needs_update']);
        $this->assertCount(1, $service->alerts(self::OWNER)['items']);
        $this->assertSame(0, DB::table('intraday_research_bars')->whereNotNull('revision')->count());
    }

    public function test_correction_quarantine_is_not_retried_after_symbol_is_removed(): void
    {
        $this->publish();
        $this->source->samples[0]['target'] = 112.;
        $service = new IntradayResearchService;
        $service->collect(self::OWNER);
        $this->assertTrue($this->state()['correction_detected']);
        $states = DB::table('intraday_research_cases')->pluck('current_state', 'id')->all();
        DB::table('watchlists')->where('user_id', self::OWNER)->delete();
        $calls = count($this->source->calls);
        $this->at('10:21');
        $result = $service->collect(self::OWNER);
        $this->assertSame(0, $result['symbols']);
        $this->assertCount($calls, $this->source->calls);
        $this->assertSame($states, DB::table('intraday_research_cases')->pluck('current_state', 'id')->all());
    }

    public function test_late_first_collection_cannot_retroactively_qualify_and_stale_data_cannot_notify(): void
    {
        $this->watch();
        $this->followup('10:25', [$this->bar('10:10', 111.)]);
        $this->assertFalse($this->state()['metrics']['qualified']);
        $this->assertSame([], (new IntradayResearchService)->alerts(self::OWNER)['items']);
        $this->assertSame($this->instant('10:25'), $this->state()['entry_not_before']);
    }

    public function test_summary_accounts_for_an_entry_window_that_ends_without_qualification(): void
    {
        $this->publish();
        $this->source->samples = [];
        $this->source->bars = array_map(fn ($at) => $this->bar($at, 101.5), ['09:50', '09:55', '10:00', '10:05', '10:10', '10:15']);
        $this->at('10:30');
        $service = new IntradayResearchService;
        $service->collect(self::OWNER);
        $summary = collect($service->report(self::OWNER, $this->filters())['summary'])->keyBy('variant');
        $this->assertSame(1, $summary['post_publication_retest']['not_qualified']);
        $this->assertSame(0, $summary['post_publication_retest']['qualified']);
    }

    public function test_original_signal_bar_revision_quarantines_the_group_while_earlier_bar_revision_does_not(): void
    {
        $this->source->bars = [$this->bar('09:35', 100.), $this->bar('09:40', 100.), $this->bar('09:45', 101.5)];
        $this->publish();
        $original = DB::table('intraday_research_groups')->value('original_sample');
        $signalBar = DB::table('intraday_research_bars')->where('bar_at', CarbonImmutable::parse($this->instant('09:40'))->toDateTimeString())->first();
        $this->source->bars[0] = $this->bar('09:35', 98.);
        $this->at('10:01');
        $service = new IntradayResearchService;
        $service->collect(self::OWNER);
        foreach (DB::table('intraday_research_cases')->pluck('current_state') as $json) {
            $this->assertFalse(json_decode($json, true)['correction_detected'] ?? false);
        }
        $this->source->bars[1] = $this->bar('09:40', 99.5);
        $service->collect(self::OWNER);
        foreach (DB::table('intraday_research_cases')->pluck('current_state') as $json) {
            $state = json_decode($json, true);
            $this->assertTrue($state['correction_detected']);
            $this->assertSame('needs_update', $state['status']);
        }
        $this->assertSame($signalBar->ohlc, DB::table('intraday_research_bars')->where('id', $signalBar->id)->value('ohlc'));
        $this->assertSame($original, DB::table('intraday_research_groups')->value('original_sample'));
        $this->assertSame([], $service->alerts(self::OWNER)['items']);
    }

    public function test_symbol_transaction_rolls_back_samples_bars_and_cursor_together(): void
    {
        $this->publish();
        $this->source->checkpoint = 30;
        $this->source->samples = [[...$this->sample(), 'id' => str_repeat('b', 64)], [...$this->sample(), 'symbol' => 'QQQ']];
        $this->source->bars = [$this->bar('09:50', 102.)];
        $this->at('10:05');
        $result = (new IntradayResearchService)->collect(self::OWNER);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, DB::table('intraday_research_groups')->count());
        $this->assertSame(1, DB::table('intraday_research_bars')->count());
        $this->assertSame(10, DB::table('intraday_research_cursors')->value('last_checkpoint_id'));
    }

    public function test_native_geometry_correction_cannot_create_a_second_group_or_rewrite_original_levels(): void
    {
        $this->source->samples[0]['native_ids'] = ['original-native-assessment'];
        $this->publish();
        $original = DB::table('intraday_research_groups')->first()->original_sample;
        $this->source->samples[0]['id'] = str_repeat('d', 64);
        $this->source->samples[0]['target'] = 112.;
        $result = (new IntradayResearchService)->collect(self::OWNER);
        $this->assertSame(0, $result['groups']);
        $this->assertSame(1, DB::table('intraday_research_groups')->count());
        $this->assertSame(2, DB::table('intraday_research_cases')->where('status', 'needs_update')->count());
        $this->assertSame($original, DB::table('intraday_research_groups')->first()->original_sample);
    }

    public function test_stale_qualification_and_preentry_resolution_never_emit_an_event(): void
    {
        $this->publish();
        $this->followup('10:30');
        $this->assertSame([], (new IntradayResearchService)->alerts(self::OWNER)['items']);
        $this->source->bars = [$this->bar('09:50', 111.), $this->bar('09:55', 100.), $this->bar('10:00', 100.), $this->bar('10:05', 100.)];
        // A revised historical bar also quarantines this group rather than reviving it.
        (new IntradayResearchService)->collect(self::OWNER);
        $this->assertSame([], (new IntradayResearchService)->alerts(self::OWNER)['items']);
        $this->assertSame('needs_update', $this->state()['status']);
    }

    public function test_manual_refresh_is_bounded_to_five_symbols(): void
    {
        $this->source->samples = [];
        $this->source->bars = [];
        foreach (range(1, 6) as $i) {
            $this->watch('X'.$i);
        }
        $this->signIn(self::OWNER);
        $this->postJson('/api/intraday-research/refresh')->assertOk()->assertJsonPath('symbols', 5);
        $this->assertCount(5, $this->source->calls);
        $this->assertCount(5, array_unique(array_map(fn ($call) => $call[0][0], $this->source->calls)));
    }

    public function test_newer_bundle_omission_quarantines_pinned_bar_but_empty_source_does_not_imply_revision(): void
    {
        $this->publish();
        $this->followup('10:20');
        $this->source->samples = [];
        $this->source->bars = [];
        $this->at('10:21');
        (new IntradayResearchService)->collect(self::OWNER);
        $this->assertSame(0, DB::table('intraday_research_bars')->whereNotNull('revision')->count());
        $this->assertSame('conditions_met', $this->state()['status']);
        $this->source->bars = [$this->bar('09:50', 101.5), $this->bar('10:00', 101.5), $this->bar('10:05', 100.)];
        (new IntradayResearchService)->collect(self::OWNER);
        $this->assertSame(1, DB::table('intraday_research_bars')->whereNotNull('revision')->count());
        $this->assertSame('needs_update', $this->state()['status']);
        $this->assertSame(5, DB::table('intraday_research_bars')->count());
    }

    public function test_alert_snapshot_survives_later_state_changes_and_has_fourteen_day_history(): void
    {
        $this->publish();
        $this->followup('10:20');
        $service = new IntradayResearchService;
        $before = $service->alerts(self::OWNER);
        $case = DB::table('intraday_research_cases')->where('variant', 'post_publication_retest')->first();
        $state = json_decode($case->current_state, true, flags: JSON_THROW_ON_ERROR);
        $state = [...$state, 'signal_at' => $this->instant('10:10'), 'signal_price' => 200., 'target' => 250.,
            'status' => 'needs_update', 'outcome' => 'invalidated'];
        DB::table('intraday_research_cases')->where('id', $case->id)->update(['current_state' => json_encode($state), 'status' => 'needs_update']);
        $this->assertNotNull($case->notification_event);
        $this->assertSame($before, $service->alerts(self::OWNER));
        $this->travelTo(CarbonImmutable::parse('2026-11-08 15:20', 'UTC'));
        $this->assertCount(1, $service->alerts(self::OWNER)['items']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:20', 'UTC'));
        $this->assertSame([], $service->alerts(self::OWNER)['items']);
    }

    public function test_report_reads_compact_archive_only_and_owner_deletion_cascades_every_table(): void
    {
        $this->publish();
        $this->followup('10:20');
        DB::enableQueryLog();
        $calls = count($this->source->calls);
        $report = (new IntradayResearchService)->report(self::OWNER, $this->filters());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($calls, count($this->source->calls));
        $this->assertCount(1, $report['groups']);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('from "intraday_research_bars"', $query['query']);
            $this->assertStringNotContainsString('wall_observations', $query['query']);
            $this->assertStringNotContainsString('prices_', $query['query']);
        }
        DB::table('users')->where('id', self::OWNER)->delete();
        foreach (['groups', 'cases', 'bars', 'cursors'] as $table) {
            $this->assertSame(0, DB::table('intraday_research_'.$table)->count());
        }
    }

    public function test_rotation_covers_98_symbols_per_default_batch_and_honors_window_and_disabled_guard(): void
    {
        $this->source->samples = [];
        $this->source->bars = [];
        foreach (range(1, 98) as $i) {
            $this->watch('X'.$i);
        }
        $result = (new IntradayResearchService)->collect(self::OWNER);
        $this->assertSame(98, $result['symbols']);
        $this->assertCount(98, $this->source->calls);
        $this->assertTrue(IntradayResearchService::collectionAllowed(CarbonImmutable::parse('2026-11-27 13:30', 'America/New_York')));
        $this->assertFalse(IntradayResearchService::collectionAllowed(CarbonImmutable::parse('2026-11-27 13:31', 'America/New_York')));
        $this->assertFalse(IntradayResearchService::collectionAllowed(CarbonImmutable::parse('2026-10-26 09:44', 'America/New_York')));
        $this->assertFalse(IntradayResearchService::collectionAllowed(CarbonImmutable::parse('2026-10-31 10:00', 'America/New_York')));
        config(['intraday_research.enabled' => false]);
        $this->assertTrue((new IntradayResearchService)->collect(self::OWNER)['skipped']);
        $this->assertCount(98, $this->source->calls);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    private function publish(): void
    {
        $this->watch();
        $this->assertSame(1, (new IntradayResearchService)->collect(self::OWNER)['groups']);
    }

    private function followup(string $time, array $extra = []): void
    {
        $this->source->bars = [$this->bar('09:50', 101.5), $this->bar('09:55', 101.5), $this->bar('10:00', 101.5), $this->bar('10:05', 100.), ...$extra];
        $this->at($time);
        (new IntradayResearchService)->collect(self::OWNER);
    }

    private function state(): array
    {
        return json_decode(DB::table('intraday_research_cases')->where('variant', 'post_publication_retest')->value('current_state'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function sample(): array
    {
        return ['id' => str_repeat('a', 64), 'mode' => 'intraday', 'horizon' => 'session', 'symbol' => 'SPY', 'session' => '2026-10-26',
            'timeframe' => '7d', 'direction' => 'bullish', 'side' => 'put', 'pattern' => 'reversal', 'rule_version' => WallSetupRules::VERSION,
            'signal_at' => $this->instant('09:40'), 'available_at' => $this->instant('09:55'), 'signal_price' => 100.,
            'entry_low' => 99., 'entry_high' => 101., 'invalidation' => 95., 'invalidation_side' => 'below', 'target' => 110.,
            'review_until' => $this->instant('16:00'), 'entry_expires_at' => $this->instant('10:30'), 'source_hash' => str_repeat('b', 64),
            'native_status' => 'conditions_met', 'bars' => [$this->bar('09:45', 101.5)], 'evidence' => ['grade' => 'supported']];
    }

    private function bar(string $time, float $close): array
    {
        return ['at' => $this->instant($time), 'open' => $close, 'high' => $close + .1, 'low' => $close - .1, 'close' => $close];
    }

    private function instant(string $time): string
    {
        return CarbonImmutable::parse('2026-10-26 '.$time, 'America/New_York')->utc()->toIso8601String();
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($this->instant($time)));
    }

    private function watch(string $symbol = 'SPY'): void
    {
        DB::table('watchlists')->insert(['user_id' => self::OWNER, 'symbol' => $symbol]);
    }

    private function filters(): array
    {
        return ['from' => '2026-10-26', 'to' => '2026-10-26', 'session' => '', 'symbol' => ''];
    }

    private function signIn(int $id): void
    {
        $user = User::findOrFail($id);
        $user->setRelation('subscriptions', collect());
        $user->trial_ends_at = now()->addDays(2);
        Sanctum::actingAs($user);
    }
}
