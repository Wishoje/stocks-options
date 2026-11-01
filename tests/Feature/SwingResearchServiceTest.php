<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SwingDataSource;
use App\Services\SwingResearch\SwingResearchService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\SwingInput;
use Tests\TestCase;

class SwingResearchServiceTest extends TestCase
{
    private const OWNER = 20;

    private SwingDataSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'cache.coordination_enabled' => false,
            'setup_study.owner_email' => 'study.owner@example.test', 'swing_research.enabled' => true]);
        DB::purge('sqlite');
        Cache::flush();
        foreach (['0001_01_01_000000_create_users_table.php', '2025_09_26_045222_create_prices_daily.php',
            '2026_10_31_000000_create_swing_research_tables.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('symbol');
        });
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Other account', 'email' => 'someone@example.test', 'password' => 'unused'],
            ['id' => self::OWNER, 'name' => 'Study owner', 'email' => 'study.owner@example.test', 'password' => 'unused'],
        ]);
        $this->source = new class extends SwingDataSource
        {
            public array $input = [];

            public int $calls = 0;

            public array $symbols = [];

            public bool $fail = false;

            public function read(string $symbol): array
            {
                $this->calls++;
                $this->symbols[] = $symbol;
                if ($this->fail) {
                    throw new \DomainException('Sensitive provider and database details must not be exposed.');
                }

                return [...$this->input, 'symbol' => $symbol];
            }
        };
        $this->source->input = SwingInput::make();
        app()->instance(SwingDataSource::class, $this->source);
        $this->travelTo(CarbonImmutable::parse('2026-10-23 21:00:00', 'UTC'));
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_owner_routes_and_service_methods_reject_non_owner_and_ignore_spoofed_owner_input(): void
    {
        $this->get('/swing-research')->assertRedirect('/login');
        $this->getJson('/api/swing-research')->assertUnauthorized();
        $this->getJson('/api/swing-research/alerts')->assertUnauthorized();
        $this->signIn(1);
        $this->get('/swing-research')->assertForbidden();
        $this->getJson('/api/swing-research?owner_id=20')->assertForbidden();
        $this->postJson('/api/swing-research/refresh', ['owner_id' => 20])->assertForbidden();
        $this->getJson('/api/swing-research/alerts?owner_id=20')->assertForbidden();
        foreach (['collect', 'report', 'alerts', 'markAlertRead'] as $method) {
            try {
                $args = match ($method) {
                    'report' => [1, $this->filters()], 'markAlertRead' => [1, str_repeat('a', 64)], default => [1],
                };
                (new SwingResearchService)->{$method}(...$args);
                $this->fail('Direct service use must reject a foreign owner ID.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->signIn(self::OWNER);
        $this->get('/swing-research')->assertOk()->assertSee('SwingResearch');
        $this->getJson('/api/swing-research?owner_id=1')->assertOk()->assertJsonPath('prepared', true);
        $this->postJson('/api/swing-research/refresh', ['owner_id' => 1])->assertOk()->assertJsonPath('symbols', 0);
        $this->assertSame(0, $this->source->calls);
    }

    public function test_publication_is_paired_idempotent_and_includes_no_idea_arms(): void
    {
        $this->watch('SPY');
        $service = new SwingResearchService;
        $this->assertSame(2, $service->collect(self::OWNER)['groups']);
        $original = DB::table('swing_research_cases')->orderBy('id')->pluck('original_plan')->all();
        $this->assertSame(0, $service->collect(self::OWNER)['groups']);
        $this->assertSame(2, DB::table('swing_research_groups')->count());
        $this->assertSame(6, DB::table('swing_research_cases')->count());
        $this->assertSame($original, DB::table('swing_research_cases')->orderBy('id')->pluck('original_plan')->all());
        $this->assertSame(2, $this->source->calls);
        $this->assertSame(70, DB::table('swing_research_daily_bars')->count());
        foreach (DB::table('swing_research_groups')->get() as $group) {
            $cases = DB::table('swing_research_cases')->where('group_id', $group->id)->get();
            $this->assertCount(3, $cases);
            $this->assertEqualsCanonicalizing(['baseline', 'nearer_entry', 'earlier_exit'], $cases->pluck('variant')->all());
            $this->assertSame('2026-10-23', $group->source_date);
            $this->assertStringNotContainsString('expirations', $group->context);
        }
        $this->source->input['strikes'] = [];
        $this->watch('QQQ');
        $service->collect(self::OWNER, 2);
        $ids = DB::table('swing_research_groups')->where('symbol', 'QQQ')->pluck('id');
        $this->assertSame(6, DB::table('swing_research_cases')->whereIn('group_id', $ids)->where('status', 'no_idea')->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_first_qualification_is_recorded_once_and_levels_and_paired_cadence_stay_frozen(): void
    {
        $this->publishInitial();
        $original = DB::table('swing_research_cases')->pluck('original_plan', 'id')->all();
        $this->day('2026-10-26', 106.5, 107., 105.9);
        $service = new SwingResearchService;
        $alerts = $service->alerts(self::OWNER);
        $this->assertCount(6, $alerts['items']);
        $this->assertSame(6, $alerts['unread_count']);
        $recorded = DB::table('swing_research_cases')->pluck('signal_recorded_at', 'id')->all();
        $service->collect(self::OWNER);
        $this->assertSame($recorded, DB::table('swing_research_cases')->pluck('signal_recorded_at', 'id')->all());
        $this->day('2026-10-27', 109., 109.2, 108.);
        $this->assertSame(2, DB::table('swing_research_cases')->where('variant', 'earlier_exit')->where('status', 'finished')->count());
        $this->assertSame(2, DB::table('swing_research_groups')->count());
        $this->assertSame('finished', collect($service->alerts(self::OWNER)['items'])->firstWhere('variant', 'earlier_exit')['status']);
        $this->day('2026-10-28', 111.3, 112., 110.);
        $this->assertSame(4, DB::table('swing_research_groups')->count());
        foreach ($original as $id => $plan) {
            $row = DB::table('swing_research_cases')->where('id', $id)->first();
            $this->assertSame($plan, $row->original_plan);
            $this->assertSame(json_decode($plan, true)['levels'], json_decode($row->current_state, true)['levels']);
        }
        $summary = collect($service->report(self::OWNER, $this->filters())['summary'])->keyBy('variant');
        $this->assertSame(['hits' => 1, 'eligible' => 1, 'rate' => 100.], $summary['baseline']['target_rate']);
        $this->assertSame(['hits' => 1, 'eligible' => 1, 'rate' => 100.], $summary['earlier_exit']['target_rate']);
        $this->assertSame(4.507, $summary['baseline']['avg_outcome_move_pct']);
        $this->assertSame(2.347, $summary['earlier_exit']['avg_outcome_move_pct']);
        $this->assertSame(0., $summary['baseline']['avg_initial_entry_distance_pct']);
        $this->assertSame(6, $service->alerts(self::OWNER)['unread_count']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_completed_correction_preserves_original_prices_and_excludes_all_affected_rates(): void
    {
        $this->publishInitial();
        $this->day('2026-10-26', 106.5, 107., 105.9);
        $this->day('2026-10-27', 111.3, 112., 110.);
        $service = new SwingResearchService;
        $before = DB::table('swing_research_daily_bars')->where('trade_date', '2026-10-26')->first();
        $this->source->input['prices'][70]['close'] = 106.6;
        $service->collect(self::OWNER);
        $after = DB::table('swing_research_daily_bars')->where('trade_date', '2026-10-26')->first();
        $this->assertSame($before->ohlc, $after->ohlc);
        $this->assertSame($before->evidence_hash, $after->evidence_hash);
        $this->assertSame(106.6, json_decode($after->revision, true)['latest_ohlc']['close']);
        $groupIds = DB::table('swing_research_groups')->where('source_date', '2026-10-23')->pluck('id');
        $this->assertSame(6, DB::table('swing_research_cases')->whereIn('group_id', $groupIds)->where('status', 'needs_update')->count());
        foreach ($service->report(self::OWNER, $this->filters())['summary'] as $summary) {
            $this->assertSame(0, $summary['target_rate']['eligible']);
            $this->assertNull($summary['avg_mfe_pct']);
        }
        $this->source->input['prices'][70]['close'] = 106.5;
        $service->collect(self::OWNER);
        $this->assertSame(6, DB::table('swing_research_cases')->whereIn('group_id', $groupIds)->where('status', 'needs_update')->count());
    }

    public function test_gap_quarantines_old_group_and_allows_a_new_paired_group_on_a_later_date(): void
    {
        $this->publishInitial();
        $this->day('2026-10-27', 106.5, 107., 105.9);
        $this->assertSame(6, DB::table('swing_research_cases')->where('status', 'needs_update')->count());
        $this->assertSame(4, DB::table('swing_research_groups')->count());
        $this->assertSame(0, DB::table('swing_research_cases')->whereNotNull('signal_recorded_at')->count());
    }

    public function test_report_and_alert_gets_are_read_only_and_filters_and_read_actions_are_owner_scoped(): void
    {
        $this->publishInitial();
        $this->day('2026-10-26', 106.5, 107., 105.9);
        $this->signIn(self::OWNER);
        $before = DB::table('swing_research_cases')->orderBy('id')->get()->toJson();
        $calls = $this->source->calls;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/swing-research?'.http_build_query([...$this->filters(), 'symbol' => 'spy', 'owner_id' => 1]))
            ->assertOk()->assertJsonCount(1, 'groups')->assertJsonPath('groups.0.symbol', 'SPY');
        $alerts = $this->getJson('/api/swing-research/alerts?owner_id=1')->assertOk()->assertJsonCount(6, 'items')->json();
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertSame($calls, $this->source->calls);
        $this->assertSame($before, DB::table('swing_research_cases')->orderBy('id')->get()->toJson());
        $id = $alerts['items'][0]['id'];
        $this->postJson('/api/swing-research/alerts/'.$id.'/read', ['owner_id' => 1])->assertOk();
        $readAt = DB::table('swing_research_cases')->where('id', $id)->value('alert_read_at');
        $this->travel(1)->hours();
        $this->postJson('/api/swing-research/alerts/'.$id.'/read')->assertOk();
        $this->assertSame($readAt, DB::table('swing_research_cases')->where('id', $id)->value('alert_read_at'));
        $groupId = DB::table('swing_research_cases')->where('id', $id)->value('group_id');
        DB::table('swing_research_groups')->where('id', $groupId)->update(['owner_id' => 1]);
        $this->postJson('/api/swing-research/alerts/'.$id.'/read', ['owner_id' => 1])->assertNotFound();
        foreach ([['horizon' => 'bad'], ['from' => '2026-02-31'], ['from' => '2026-01-01'],
            ['from' => '2026-10-27', 'to' => '2026-10-26'], ['symbol' => "SPY' OR 1=1"]] as $invalid) {
            $this->getJson('/api/swing-research?'.http_build_query([...$this->filters(), ...$invalid]))->assertUnprocessable();
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_stale_sources_do_not_seed_and_failures_use_a_date_scoped_cooldown_without_details(): void
    {
        $this->watch('SPY');
        $this->travelTo(CarbonImmutable::parse('2026-10-26 21:00:00', 'UTC'));
        $service = new SwingResearchService;
        $this->assertSame(1, $service->collect(self::OWNER)['skipped']);
        $this->assertSame(0, DB::table('swing_research_groups')->count());
        $this->assertSame(0, DB::table('swing_research_daily_bars')->count());
        $service->collect(self::OWNER);
        $this->assertSame(1, $this->source->calls);
        $this->travel(31)->minutes();
        $this->source->fail = true;
        $result = $service->collect(self::OWNER);
        $this->assertSame(1, $result['failed']);
        $this->assertStringNotContainsString('Sensitive', json_encode($result));
        $service->collect(self::OWNER);
        $this->assertSame(2, $this->source->calls);
    }

    public function test_collection_rotates_at_twenty_and_manual_collection_is_capped_at_two(): void
    {
        foreach (range(1, 25) as $i) {
            $this->watch(sprintf('SYM%02d', $i));
        }
        $this->source->input['strikes'] = [];
        $service = new SwingResearchService;
        $this->assertSame(20, $service->collect(self::OWNER, 100)['symbols']);
        $this->assertSame(5, $service->collect(self::OWNER, 5)['symbols']);
        $this->assertCount(25, array_unique($this->source->symbols));
        $this->assertSame(0, Cache::get('swing-research:cursor:'.self::OWNER));
        $this->signIn(self::OWNER);
        config(['swing_research.batch_symbols' => 20]);
        $this->postJson('/api/swing-research/refresh')->assertOk()->assertJsonPath('symbols', 2);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_collection_switch_and_demo_are_enforced_without_source_reads(): void
    {
        $this->signIn(self::OWNER);
        config(['swing_research.enabled' => false]);
        $this->postJson('/api/swing-research/refresh')->assertForbidden();
        $this->artisan('swing-research:collect')->expectsOutput('Swing research collection is disabled.')->assertSuccessful();
        $this->getJson('/api/swing-research?demo=1')->assertForbidden();
        $this->postJson('/api/swing-research/refresh', ['demo' => true])->assertUnprocessable();
        $this->assertSame(0, $this->source->calls);
    }

    public function test_source_failure_and_watchlist_removal_do_not_abandon_existing_daily_followup(): void
    {
        $this->publishInitial();
        DB::table('watchlists')->delete();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 21:00:00', 'UTC'));
        DB::table('prices_daily')->insert(['symbol' => 'SPY', 'trade_date' => '2026-10-26',
            'open' => 106.5, 'high' => 107., 'low' => 105.9, 'close' => 106.5]);
        $this->source->fail = true;
        $service = new SwingResearchService;
        $result = $service->collect(self::OWNER);
        $this->assertSame(1, $result['symbols']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['groups']);
        $this->assertCount(6, $service->alerts(self::OWNER)['items']);
        $this->assertSame(6, DB::table('swing_research_cases')->where('status', 'conditions_met')->count());
        $this->assertSame(2, $this->source->calls);
        $this->travelTo(CarbonImmutable::parse('2026-10-27 21:00:00', 'UTC'));
        DB::table('prices_daily')->insert(['symbol' => 'SPY', 'trade_date' => '2026-10-27',
            'open' => 111.3, 'high' => 112., 'low' => 110., 'close' => 111.3]);
        $service->collect(self::OWNER);
        $this->assertSame(6, DB::table('swing_research_cases')->where('status', 'finished')->count());
        $this->assertSame(2, DB::table('swing_research_groups')->count());
        $this->assertSame('target_reached', $service->alerts(self::OWNER)['items'][0]['outcome']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_new_cohorts_may_use_revised_initial_evidence_without_repeating_old_quarantine(): void
    {
        $this->publishInitial();
        $initialIds = DB::table('swing_research_groups')->pluck('id');
        $this->day('2026-10-26', 106.5, 107., 105.9);
        $this->source->input['prices'][69]['close'] = 106.95;
        $this->day('2026-10-27', 106.5, 107., 105.9);
        $this->assertSame(6, DB::table('swing_research_cases')->whereIn('group_id', $initialIds)->where('status', 'needs_update')->count());
        $newIds = DB::table('swing_research_groups')->where('source_date', '2026-10-27')->pluck('id');
        $this->assertCount(2, $newIds);
        (new SwingResearchService)->collect(self::OWNER);
        $this->assertSame(0, DB::table('swing_research_cases')->whereIn('group_id', $newIds)->where('status', 'needs_update')->count());
        $this->assertSame(6, DB::table('swing_research_cases')->whereIn('group_id', $initialIds)->where('status', 'needs_update')->count());
    }

    public function test_same_day_historical_prices_never_qualify_a_newly_published_group(): void
    {
        $this->watch('SPY');
        $this->source->input['prices'][69] = ['date' => '2026-10-23', 'open' => 106.5, 'high' => 107., 'low' => 105.9, 'close' => 106.5];
        $service = new SwingResearchService;
        $service->collect(self::OWNER);
        $service->collect(self::OWNER);
        $this->assertSame(0, DB::table('swing_research_cases')->whereNotNull('signal_recorded_at')->count());
        $this->assertSame([], $service->alerts(self::OWNER)['items']);
        foreach (DB::table('swing_research_cases')->get() as $case) {
            $state = json_decode($case->current_state, true);
            $this->assertSame('2026-10-26', $state['activation_session']);
            $this->assertSame('2026-10-23', $state['reviewed_through']);
            $this->assertEmpty($state['qualified_at'] ?? null);
        }
    }

    public function test_busy_collection_and_disabled_scheduler_do_not_read_sources(): void
    {
        $this->watch('SPY');
        $lock = Cache::lock('swing-research:collect:'.self::OWNER, 180);
        $this->assertTrue($lock->get());
        $this->assertTrue((new SwingResearchService)->collect(self::OWNER)['busy']);
        $lock->release();
        $this->assertSame(0, $this->source->calls);
        $this->assertSame(0, DB::table('swing_research_groups')->count());
    }

    public function test_removed_saved_symbol_finishes_existing_cohort_without_starting_another(): void
    {
        $this->publishInitial();
        DB::table('watchlists')->delete();
        $this->day('2026-10-26', 106.5, 107., 105.9);
        $this->day('2026-10-27', 111.3, 112., 110.);
        $this->assertSame(6, DB::table('swing_research_cases')->where('status', 'finished')->count());
        $this->assertSame(2, DB::table('swing_research_groups')->count());
        $this->assertSame(0, (new SwingResearchService)->collect(self::OWNER)['symbols']);
    }

    public function test_intraday_publication_skips_the_prepublication_session_without_creating_a_false_gap(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 14:00:00', 'UTC'));
        $this->watch('SPY');
        (new SwingResearchService)->collect(self::OWNER);
        foreach (DB::table('swing_research_cases')->get() as $case) {
            $this->assertSame('2026-10-23', $case->reviewed_through);
            $this->assertSame('2026-10-27', json_decode($case->current_state, true)['activation_session']);
        }
        $this->day('2026-10-26', 106.5, 107., 105.9);
        $this->assertSame(0, DB::table('swing_research_cases')->whereNotNull('signal_recorded_at')->count());
        $this->day('2026-10-27', 106.5, 107., 105.9);
        $this->assertSame(6, DB::table('swing_research_cases')->where('status', 'conditions_met')->count());
        foreach (DB::table('swing_research_cases')->get() as $case) {
            $state = json_decode($case->current_state, true);
            $this->assertSame('2026-10-27', $case->reviewed_through);
            $this->assertSame('2026-10-27', $state['touched_at']);
            $this->assertSame('2026-10-27', $state['qualified_at']);
        }
    }

    public function test_many_newer_no_plan_groups_do_not_hide_an_older_qualified_plan_or_disappear_from_totals(): void
    {
        $this->publishInitial();
        $this->day('2026-10-26', 106.5, 107., 105.9);
        $template = (array) DB::table('swing_research_groups')->where('horizon', 'short')->first();
        $caseTemplate = (array) DB::table('swing_research_cases')->where('group_id', $template['id'])->first();
        $groups = $cases = [];
        $noPlan = json_encode(['status' => 'no_idea', 'levels' => null]);
        foreach (range(1, 1005) as $number) {
            $id = hash('sha256', 'no-plan-group-'.$number);
            $groups[] = [...$template, 'id' => $id, 'symbol' => 'EMPTY'.$number, 'source_date' => '2026-10-26', 'context' => '{}'];
            foreach (['baseline', 'nearer_entry', 'earlier_exit'] as $variant) {
                $cases[] = [...$caseTemplate, 'id' => hash('sha256', $id.':'.$variant), 'group_id' => $id, 'variant' => $variant,
                    'status' => 'no_idea', 'original_plan' => $noPlan, 'current_state' => $noPlan, 'signal_recorded_at' => null];
            }
        }
        foreach (array_chunk($groups, 50) as $chunk) {
            DB::table('swing_research_groups')->insert($chunk);
        }
        foreach (array_chunk($cases, 50) as $chunk) {
            DB::table('swing_research_cases')->insert($chunk);
        }
        $report = (new SwingResearchService)->report(self::OWNER, $this->filters());
        $this->assertCount(1, $report['groups']);
        $this->assertSame($template['id'], $report['groups'][0]['id']);
        $this->assertSame(1006, $report['reviewed_groups']);
        $this->assertSame(1005, $report['without_plan_groups']);
        $this->assertSame([], $report['warnings']);
        foreach ($report['summary'] as $summary) {
            $this->assertSame(1006, $summary['total']);
            $this->assertSame(1, $summary['qualified']);
            $this->assertSame(0, $summary['target_rate']['eligible']);
        }
    }

    public function test_alerts_are_bounded_but_unread_count_and_latest_status_remain_accurate_and_owner_delete_cascades(): void
    {
        $this->publishInitial();
        $this->day('2026-10-26', 106.5, 107., 105.9);
        $case = (array) DB::table('swing_research_cases')->first();
        $inserts = [];
        foreach (range(1, 200) as $number) {
            $inserts[] = [...$case, 'id' => hash('sha256', 'extra-alert-'.$number), 'variant' => 'baseline',
                'group_id' => hash('sha256', 'extra-group-'.$number)];
            $group = (array) DB::table('swing_research_groups')->where('id', $case['group_id'])->first();
            DB::table('swing_research_groups')->insert([...$group, 'id' => hash('sha256', 'extra-group-'.$number), 'symbol' => 'X'.$number]);
        }
        foreach (array_chunk($inserts, 50) as $chunk) {
            DB::table('swing_research_cases')->insert($chunk);
        }
        $alerts = (new SwingResearchService)->alerts(self::OWNER);
        $this->assertCount(200, $alerts['items']);
        $this->assertSame(206, $alerts['unread_count']);
        $this->assertTrue($alerts['truncated']);
        $this->assertTrue($alerts['items'][0]['experimental']);
        DB::table('users')->where('id', self::OWNER)->delete();
        $this->assertSame(0, DB::table('swing_research_groups')->count());
        $this->assertSame(0, DB::table('swing_research_cases')->count());
        $this->assertSame(0, DB::table('swing_research_daily_bars')->count());
    }

    private function publishInitial(): void
    {
        $this->watch('SPY');
        (new SwingResearchService)->collect(self::OWNER);
    }

    private function day(string $date, float $close, float $high, float $low): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 21:00:00', 'UTC'));
        $this->source->input['data_date'] = $date;
        $this->source->input['prices'][] = ['date' => $date, 'open' => $close, 'high' => $high, 'low' => $low, 'close' => $close];
        foreach ($this->source->input['strikes'] as &$strike) {
            foreach ($strike['expirations'] as &$cell) {
                $cell['data_date'] = $date;
            }
        }
        (new SwingResearchService)->collect(self::OWNER);
    }

    private function watch(string $symbol): void
    {
        DB::table('watchlists')->insert(['user_id' => self::OWNER, 'symbol' => $symbol]);
    }

    private function filters(): array
    {
        return ['horizon' => 'short', 'from' => '2026-10-20', 'to' => now('America/New_York')->toDateString(), 'symbol' => ''];
    }

    private function signIn(int $id): void
    {
        $user = User::findOrFail($id);
        $user->setRelation('subscriptions', collect());
        $user->trial_ends_at = now()->addDays(2);
        Sanctum::actingAs($user);
    }
}
