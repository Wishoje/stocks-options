<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SetupStudy\SetupStudyService;
use App\Services\SwingIdeaService;
use App\Support\SetupStudy\SetupStudyAccess;
use App\Support\SetupStudy\SetupStudyMetrics;
use App\Support\WallIntelligence\WallSetupRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\SwingInput;
use Tests\TestCase;

class SetupStudyServiceTest extends TestCase
{
    private const OWNER = 20;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'cache.coordination_enabled' => false,
            'setup_study.owner_email' => 'study.owner@example.test', 'setup_study.enabled' => true]);
        DB::purge('sqlite');
        Cache::flush();
        foreach (['0001_01_01_000000_create_users_table.php', '2026_09_25_080000_create_wall_observations_table.php',
            '2026_10_26_180000_create_swing_ideas_tables.php', '2025_09_26_045222_create_prices_daily.php',
            '2026_10_28_000000_create_setup_study_records_table.php'] as $migration) {
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
        $this->travelTo(CarbonImmutable::parse('2026-10-28 22:00:00', 'UTC'));
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_all_study_routes_require_the_owner_email_and_authentication(): void
    {
        $this->get('/setup-study')->assertRedirect('/login');
        $this->getJson('/api/setup-study')->assertUnauthorized();
        $this->postJson('/api/setup-study/refresh')->assertUnauthorized();
        $this->signIn(1);
        $this->get('/setup-study')->assertForbidden();
        $this->getJson('/api/setup-study?owner_id='.self::OWNER)->assertForbidden();
        $this->postJson('/api/setup-study/refresh', ['owner_id' => self::OWNER])->assertForbidden();

        $owner = $this->signIn(self::OWNER);
        $this->assertTrue(SetupStudyAccess::allows($owner));
        $this->assertFalse(SetupStudyAccess::allows(new User(['email' => 'study.owner@example.test.attacker.test'])));
        $this->get('/setup-study')->assertOk()->assertSee('SetupStudy');
        $this->getJson('/api/setup-study')->assertOk()->assertJsonPath('prepared', true);
        $this->postJson('/api/setup-study/refresh')->assertOk()->assertJsonPath('symbols', 0);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_synthetic_preview_is_local_only_and_cannot_be_collected(): void
    {
        $this->signIn(self::OWNER);
        $this->getJson('/api/setup-study?demo=1')->assertForbidden();
        app()['env'] = 'local';
        $this->getJson('/api/setup-study?demo=1')->assertOk()->assertJsonPath('demonstration', true)
            ->assertJsonCount(5, 'rows')->assertJsonPath('summary.completed', 3);
        app()['env'] = 'testing';
        $this->postJson('/api/setup-study/refresh', ['demo' => true])->assertStatus(422);
        $this->assertSame(0, DB::table('setup_study_records')->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_report_is_private_filtered_and_read_only_even_with_another_owner_id_supplied(): void
    {
        $expected = $this->archive();
        $this->archive(['direction' => 'bearish']);
        $this->archive(['symbol' => 'QQQ']);
        $this->archive(['session' => '2026-10-01']);
        $this->archive([], 'swing_short');
        $this->archive([], 'intraday', 1);
        $this->signIn(self::OWNER);
        $before = DB::table('setup_study_records')->orderBy('id')->get()->toJson();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson('/api/setup-study?'.http_build_query([...$this->filters(), 'symbol' => 'spy',
            'direction' => 'bullish', 'owner_id' => 1]))->assertOk()
            ->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', $expected)
            ->assertJsonPath('summary.total', 1)->assertJsonPath('summary.strict_target.eligible', 1)
            ->assertJsonPath('summary.strict_target.hits', 1);

        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertSame($before, DB::table('setup_study_records')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_report_rejects_invalid_filter_windows_and_values(): void
    {
        $this->signIn(self::OWNER);
        foreach ([['cohort' => 'someone_else'], ['from' => '2026-02-31'], ['to' => '2026-10-29'],
            ['from' => '2026-10-25', 'to' => '2026-10-24'], ['from' => '2026-01-01'],
            ['direction' => 'sideways'], ['symbol' => "SPY' OR 1=1"]] as $invalid) {
            $this->getJson('/api/setup-study?'.http_build_query([...$this->filters(), ...$invalid]))->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('setup_study_records')->count());
    }

    public function test_pending_and_incomplete_followups_do_not_create_zero_denominator_rates(): void
    {
        $this->archive(['status' => 'watching', 'qualified' => false, 'followup_complete' => false,
            'metrics' => ['strict_outcome' => null]]);
        $this->archive(['status' => 'in_progress', 'followup_complete' => false,
            'metrics' => ['strict_outcome' => null, 'reached_05r' => true]]);
        $this->archive(['status' => 'needs_update', 'needs_update' => true, 'followup_complete' => false]);

        $report = (new SetupStudyService)->report(self::OWNER, $this->filters());
        $this->assertSame(3, $report['summary']['total']);
        $this->assertSame(2, $report['summary']['qualified']);
        $this->assertSame(0, $report['summary']['completed']);
        foreach (['strict_target', 'favorable_05r', 'favorable_1r', 'favorable_05pct', 'favorable_1pct', 'entry_revisit'] as $rate) {
            $this->assertSame(['hits' => 0, 'eligible' => 0, 'rate' => null], $report['summary'][$rate]);
        }
        $this->assertNull($report['summary']['avg_mfe_r']);
        $this->assertNull($report['summary']['avg_mfe_pct']);
        $this->assertNull($report['summary']['avg_mae_pct']);
        $this->archive();
        $summary = (new SetupStudyService)->report(self::OWNER, $this->filters())['summary'];
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(['hits' => 1, 'eligible' => 1, 'rate' => 100.], $summary['strict_target']);
    }

    public function test_stock_percentage_summaries_use_each_stored_move_instead_of_relabelling_risk_multiples(): void
    {
        foreach ([['HIGH', 'bullish', 'reclaim', 1000., 999.8, 999.9, 1001.],
            ['LOW', 'bearish', 'reversal', 50., 51., 50.5, 49.]] as [$symbol, $direction, $pattern, $price, $invalid, $adverse, $target]) {
            $sample = ['id' => $symbol, 'mode' => 'intraday', 'horizon' => 'session', 'symbol' => $symbol,
                'session' => '2026-10-23', 'direction' => $direction, 'pattern' => $pattern, 'rule_version' => 'fixture.v1',
                'signal_at' => '2026-10-23T14:00:00Z', 'available_at' => '2026-10-23T14:00:00Z',
                'signal_price' => $price, 'invalidation' => $invalid, 'target' => $target,
                'entry_low' => $price - .01, 'entry_high' => $price + .01, 'review_until' => '2026-10-23T20:00:00Z',
                'confirmations' => [], 'bars' => [
                    ['at' => '2026-10-23T14:05:00Z', 'open' => $price, 'high' => max($price, $adverse),
                        'low' => min($price, $adverse), 'close' => $adverse],
                    ['at' => '2026-10-23T14:10:00Z', 'open' => $adverse, 'high' => max($adverse, $target),
                        'low' => min($adverse, $target), 'close' => $target],
                ]];
            $measurement = (new SetupStudyMetrics)->measure($sample);
            unset($measurement['id']);
            $this->archive($measurement);
        }
        $before = DB::table('setup_study_records')->orderBy('id')->pluck('payload')->all();
        $report = (new SetupStudyService)->report(self::OWNER, $this->filters());
        $summary = $report['summary'];
        $this->assertSame(3., $summary['avg_mfe_r']);
        $this->assertSame(1.05, $summary['avg_mfe_pct']);
        $this->assertSame(.505, $summary['avg_mae_pct']);
        $this->assertSame(['hits' => 2, 'eligible' => 2, 'rate' => 100.], $summary['favorable_1r']);
        $this->assertSame(['hits' => 1, 'eligible' => 2, 'rate' => 50.], $summary['favorable_05pct']);
        $this->assertSame(['hits' => 1, 'eligible' => 2, 'rate' => 50.], $summary['favorable_1pct']);
        $symbols = collect($report['breakdowns']['symbols'])->keyBy('label');
        $this->assertSame(5., $symbols['HIGH']['avg_mfe_r']);
        $this->assertSame(.1, $symbols['HIGH']['avg_mfe_pct']);
        $this->assertSame(0, $symbols['HIGH']['favorable_1pct']['hits']);
        $this->assertSame(1., $symbols['LOW']['avg_mfe_r']);
        $this->assertSame(2., $symbols['LOW']['avg_mfe_pct']);
        $this->assertSame(1, $symbols['LOW']['favorable_1pct']['hits']);
        $patterns = collect($report['breakdowns']['patterns'])->keyBy('label');
        $this->assertSame(.1, $patterns['reclaim']['avg_mfe_pct']);
        $this->assertSame(2., $patterns['reversal']['avg_mfe_pct']);
        $this->assertSame($before, DB::table('setup_study_records')->orderBy('id')->pluck('payload')->all());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_percentage_denominators_exclude_unknown_nonfinite_and_unfinished_moves_but_include_zero(): void
    {
        $this->archive(['metrics' => ['mfe_pct' => 0., 'mae_pct' => 0.]]);
        $this->archive(['metrics' => ['mfe_pct' => .5, 'mae_pct' => .2]]);
        $this->archive(['metrics' => ['mfe_pct' => null, 'mae_pct' => null]]);
        $this->archive(['metrics' => ['mfe_pct' => '1e999', 'mae_pct' => 'unknown']]);
        $this->archive(['status' => 'in_progress', 'followup_complete' => false, 'metrics' => ['mfe_pct' => 100., 'mae_pct' => 100.]]);
        $this->archive(['needs_update' => true, 'metrics' => ['mfe_pct' => 100., 'mae_pct' => 100.]]);

        $summary = (new SetupStudyService)->report(self::OWNER, $this->filters())['summary'];
        $this->assertSame(4, $summary['completed']);
        $this->assertSame(.25, $summary['avg_mfe_pct']);
        $this->assertSame(.1, $summary['avg_mae_pct']);
        $this->assertSame(['hits' => 1, 'eligible' => 2, 'rate' => 50.], $summary['favorable_05pct']);
        $this->assertSame(['hits' => 0, 'eligible' => 2, 'rate' => 0.], $summary['favorable_1pct']);
    }

    public function test_collection_is_idempotent_provider_free_and_leaves_source_records_unchanged(): void
    {
        $this->completedSwing();
        DB::table('watchlists')->insert(['user_id' => 1, 'symbol' => 'QQQ']);
        $before = $this->rawSnapshot();
        $service = new SetupStudyService;
        $first = $service->collect(self::OWNER, 1);
        $payloads = DB::table('setup_study_records')->orderBy('id')->pluck('payload', 'sample_id')->all();
        $second = $service->collect(self::OWNER, 1);

        $this->assertSame(1, $first['symbols']);
        $this->assertSame(2, $first['records']);
        $this->assertSame(2, $second['records']);
        $this->assertSame(2, DB::table('setup_study_records')->count());
        $this->assertSame($payloads, DB::table('setup_study_records')->orderBy('id')->pluck('payload', 'sample_id')->all());
        $this->assertSame($before, $this->rawSnapshot());
        $this->assertSame(['SPY' => '2026-10-28'], Cache::get('setup-study:covered:'.self::OWNER));
        $report = $service->report(self::OWNER, [...$this->filters(), 'cohort' => 'swing_short']);
        $this->assertSame(1, $report['summary']['completed']);
        $this->assertSame('target_reached', $report['rows'][0]['metrics']['strict_outcome']);
        $this->assertTrue($report['rows'][0]['source']['retrospective']);
        $this->assertArrayNotHasKey('bars', $report['rows'][0]);
        $signalDate = CarbonImmutable::parse($report['rows'][0]['signal_at'])->setTimezone('America/New_York')->toDateString();
        $this->assertSame($signalDate, DB::table('setup_study_records')->where('cohort', 'swing_short')->value('session'));
        $this->assertSame(1, $service->report(self::OWNER, [...$this->filters(), 'cohort' => 'swing_short',
            'from' => $signalDate, 'to' => $signalDate])['summary']['completed']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_complete_archived_metrics_survive_raw_price_retention(): void
    {
        $this->completedSwing();
        $service = new SetupStudyService;
        $service->collect(self::OWNER);
        $before = $service->report(self::OWNER, [...$this->filters(), 'cohort' => 'swing_short'])['rows'][0];
        DB::table('prices_daily')->delete();
        $service->collect(self::OWNER);
        $after = $service->report(self::OWNER, [...$this->filters(), 'cohort' => 'swing_short']);
        $this->assertSame($before['metrics'], $after['rows'][0]['metrics']);
        $this->assertTrue($after['rows'][0]['followup_complete']);
        $this->assertSame(1, $after['summary']['strict_target']['eligible']);
    }

    public function test_detected_source_correction_stays_quarantined_after_later_valid_collections(): void
    {
        $this->completedSwing();
        $service = new SetupStudyService;
        $service->collect(self::OWNER);
        $row = DB::table('swing_ideas')->where('horizon', 'short')->where('status', 'finished')->first();
        $saved = $row->current_state;
        $state = json_decode($saved, true, flags: JSON_THROW_ON_ERROR);
        DB::table('swing_ideas')->where('id', $row->id)->update(['status' => 'needs_update',
            'current_state' => json_encode([...$state, 'status' => 'needs_update']), 'updated_at' => now()]);
        $service->collect(self::OWNER);
        $quarantined = $service->report(self::OWNER, [...$this->filters(), 'cohort' => 'swing_short']);
        $this->assertTrue($quarantined['rows'][0]['source']['correction_detected']);
        $this->assertTrue($quarantined['rows'][0]['needs_update']);
        $this->assertSame(0, $quarantined['summary']['strict_target']['eligible']);

        DB::table('swing_ideas')->where('id', $row->id)->update(['status' => 'finished', 'current_state' => $saved]);
        $service->collect(self::OWNER);
        $service->collect(self::OWNER);
        $after = $service->report(self::OWNER, [...$this->filters(), 'cohort' => 'swing_short']);
        $this->assertTrue($after['rows'][0]['source']['correction_detected']);
        $this->assertTrue($after['rows'][0]['needs_update']);
        $this->assertFalse($after['rows'][0]['followup_complete']);
        $this->assertSame(0, $after['summary']['strict_target']['eligible']);
    }

    public function test_collection_rotates_symbols_and_caps_each_batch_at_twenty(): void
    {
        foreach (range(1, 25) as $i) {
            DB::table('watchlists')->insert(['user_id' => self::OWNER, 'symbol' => sprintf('SYM%02d', $i)]);
        }
        $service = new SetupStudyService;
        $first = $service->collect(self::OWNER, 100);
        $this->assertSame(20, $first['symbols']);
        $this->assertSame(25, $first['total_symbols']);
        $this->assertCount(20, Cache::get('setup-study:covered:'.self::OWNER));
        $service->collect(self::OWNER, 5);
        $this->assertCount(25, Cache::get('setup-study:covered:'.self::OWNER));
        $this->assertSame(0, Cache::get('setup-study:cursor:'.self::OWNER));
        $this->assertSame(1, $service->collect(self::OWNER, 0)['symbols']);
        $this->assertSame(0, DB::table('setup_study_records')->count());
    }

    public function test_first_pass_progress_counts_scanned_symbols_even_when_they_have_no_qualifying_records(): void
    {
        foreach (['AAA', 'BBB', 'CCC'] as $symbol) {
            DB::table('watchlists')->insert(['user_id' => self::OWNER, 'symbol' => $symbol]);
        }
        $service = new SetupStudyService;
        $this->assertSame(['collected_symbols' => 0, 'total_symbols' => 3, 'first_pass_complete' => false],
            $service->report(self::OWNER, $this->filters())['coverage']);
        $service->collect(self::OWNER, 1);
        $partial = $service->report(self::OWNER, $this->filters());
        $this->assertSame(['collected_symbols' => 1, 'total_symbols' => 3, 'first_pass_complete' => false], $partial['coverage']);
        $this->assertSame(0, $partial['summary']['total']);
        $this->assertStringContainsString('selected sample', $partial['methodology']['scope']);
        $service->collect(self::OWNER, 2);
        $this->assertSame(['collected_symbols' => 3, 'total_symbols' => 3, 'first_pass_complete' => true],
            $service->report(self::OWNER, $this->filters())['coverage']);
        $this->assertSame(0, DB::table('setup_study_records')->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_coverage_uses_only_the_current_owner_universe_and_stays_read_only_with_bounded_queries(): void
    {
        DB::table('watchlists')->insert([
            ['user_id' => self::OWNER, 'symbol' => 'SPY'], ['user_id' => self::OWNER, 'symbol' => 'SPY'],
            ['user_id' => self::OWNER, 'symbol' => 'QQQ'], ['user_id' => 1, 'symbol' => 'OTHER'],
        ]);
        $this->archive(['symbol' => 'IWM']);
        $this->archive(['symbol' => 'IWM'], 'swing_short');
        $this->archive(['symbol' => 'OLD', 'session' => '2026-08-01']);
        $this->archive(['symbol' => 'FOREIGN'], owner: 1);
        Cache::put('setup-study:covered:'.self::OWNER, array_fill_keys(['SPY', 'IWM', 'OLD', 'FOREIGN', 'REMOVED'], '2026-10-28'));
        DB::enableQueryLog();
        DB::flushQueryLog();

        $report = (new SetupStudyService)->report(self::OWNER, [...$this->filters(), 'symbol' => 'NO-MATCH']);
        $this->assertSame(['collected_symbols' => 2, 'total_symbols' => 3, 'first_pass_complete' => false], $report['coverage']);
        $this->assertSame([], $report['rows']);
        $this->assertLessThanOrEqual(5, count(DB::getQueryLog()));
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
        }
        DB::disableQueryLog();
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_empty_saved_universe_has_no_first_pass_work(): void
    {
        Cache::put('setup-study:covered:'.self::OWNER, ['REMOVED' => '2026-10-28']);
        $this->assertSame(['collected_symbols' => 0, 'total_symbols' => 0, 'first_pass_complete' => true],
            (new SetupStudyService)->report(self::OWNER, $this->filters())['coverage']);
    }

    public function test_collection_catches_up_retained_days_after_an_outage_without_unbounded_backfill(): void
    {
        DB::table('watchlists')->insert(['user_id' => self::OWNER, 'symbol' => 'SPY']);
        Cache::put('setup-study:covered:'.self::OWNER, ['SPY' => '2026-10-01']);
        $this->intraday('2026-10-21');
        $this->intraday('2026-10-01');
        (new SetupStudyService)->collect(self::OWNER);
        $this->assertSame(['2026-10-21'], DB::table('setup_study_records')->pluck('session')->all());
        $this->assertSame('2026-10-28', Cache::get('setup-study:covered:'.self::OWNER)['SPY']);
        $this->assertSame(4, DB::table('wall_observations')->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    private function signIn(int $id): User
    {
        $user = User::findOrFail($id);
        $user->setRelation('subscriptions', collect());
        $user->trial_ends_at = now()->addDays(2);
        Sanctum::actingAs($user);

        return $user;
    }

    private function filters(): array
    {
        return ['cohort' => 'intraday', 'from' => '2026-10-20', 'to' => '2026-10-28', 'symbol' => '', 'direction' => ''];
    }

    private function archive(array $changes = [], string $cohort = 'intraday', int $owner = self::OWNER): string
    {
        $id = hash('sha256', 'archive-'.++$this->sequence);
        $payload = (new SetupStudyMetrics)->measure(['id' => $id, 'mode' => 'intraday', 'horizon' => 'session',
            'symbol' => 'SPY', 'session' => '2026-10-23', 'direction' => 'bullish', 'pattern' => 'reclaim',
            'rule_version' => 'fixture.v1', 'signal_at' => '2026-10-23T14:00:00Z', 'available_at' => '2026-10-23T14:00:00Z',
            'signal_price' => 100., 'invalidation' => 99., 'target' => 101., 'entry_low' => 99.9, 'entry_high' => 100.1,
            'review_until' => '2026-10-23T20:00:00Z', 'confirmations' => [],
            'bars' => [['at' => '2026-10-23T14:05:00Z', 'open' => 100., 'high' => 101.1, 'low' => 100., 'close' => 101.]]]);
        $payload = array_replace_recursive($payload, $changes);
        DB::table('setup_study_records')->insert(['owner_id' => $owner, 'sample_id' => $id, 'cohort' => $cohort,
            'symbol' => $payload['symbol'], 'session' => $payload['session'], 'rule_version' => $payload['rule_version'],
            'payload' => json_encode($payload, JSON_PRESERVE_ZERO_FRACTION), 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function completedSwing(): void
    {
        DB::table('watchlists')->insert(['user_id' => self::OWNER, 'symbol' => 'SPY']);
        $service = new SwingIdeaService;
        $input = SwingInput::make();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 21:00:00', 'UTC'));
        $service->refresh($input);
        foreach ([['2026-10-26', 106.5, 107., 105.9], ['2026-10-27', 111., 112., 110.]] as [$date, $close, $high, $low]) {
            $this->travelTo(CarbonImmutable::parse($date.' 21:00:00', 'UTC'));
            $input['data_date'] = $date;
            $bar = ['date' => $date, 'open' => $close, 'high' => $high, 'low' => $low, 'close' => $close];
            $input['prices'][] = $bar;
            $service->refresh($input);
            DB::table('prices_daily')->insert(['symbol' => 'SPY', 'trade_date' => $date,
                'open' => $close, 'high' => $high, 'low' => $low, 'close' => $close]);
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-28 22:00:00', 'UTC'));
    }

    private function rawSnapshot(): array
    {
        $snapshot = [];
        foreach (['swing_ideas', 'swing_idea_reviews', 'prices_daily', 'wall_observations'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    private function intraday(string $date): void
    {
        $seed = ['id' => 'source-'.$date, 'status' => 'conditions_met', 'direction' => 'bullish', 'side' => 'put',
            'pattern' => 'reclaim', 'strike' => 100., 'close' => 100., 'wall_identity' => 'wall-'.$date,
            'observed_at' => $date.'T14:00:00Z', 'confirmed_at' => $date.'T14:00:00Z',
            'entry_zone' => ['low' => 99.9, 'high' => 100.1], 'invalidation' => ['price' => 99.], 'target' => ['strike' => 101.],
            'scope' => ['symbol' => 'SPY', 'session' => $date, 'timeframe' => '14d', 'units' => 'USD_per_1pct_move']];
        foreach ([['wall_interactions', 'wall-interactions.v1', ['timeframe' => '14d',
            'setups' => ['rule_version' => WallSetupRules::VERSION, 'review_seeds' => [$seed]]]],
            ['intraday_price_bars', 'wall-price-bars.v1', ['bars' => [['t' => strtotime($date.'T14:00:00Z') * 1000,
                'o' => 100., 'h' => 101.1, 'l' => 100., 'c' => 101.]]]]] as [$dataset, $schema, $payload]) {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            DB::table('wall_observations')->insert(['symbol' => 'SPY', 'dataset' => $dataset, 'schema_version' => $schema,
                'content_hash' => hash('sha256', $json.++$this->sequence), 'scope_key' => hash('sha256', $date),
                'model_version' => 'fixture.v1', 'analysis_session' => $date, 'source_date' => $date,
                'captured_at' => $date.' 14:10:00', 'recorded_at' => $date.' 14:10:00',
                'quality_state' => 'descriptive', 'payload_bytes' => strlen($json), 'payload_json' => $json]);
        }
    }
}
