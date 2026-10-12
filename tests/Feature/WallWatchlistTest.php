<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WallObservation;
use App\Services\WallWatchlistService;
use App\Support\WallIntelligence\IntradayWallModel;
use App\Support\WallIntelligence\WallInteractionDetector;
use App\Support\WallIntelligence\WallInteractionRules;
use App\Support\WallIntelligence\WallSetupRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WallWatchlistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'wall_tracking.quote_delay_seconds' => 900]);
        DB::purge('sqlite');
        (require database_path('migrations/2026_09_25_080000_create_wall_observations_table.php'))->up();
        (require database_path('migrations/2026_09_30_120000_index_intraday_wall_sessions.php'))->up();
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('symbol');
        });
        $this->travelTo(CarbonImmutable::parse('2026-09-30T14:31:00Z'));
        Http::preventStrayRequests();
        Bus::fake();
    }

    private function watchlist(string $symbol, int $user = 987): void
    {
        DB::table('watchlists')->insert(['user_id' => $user, 'symbol' => $symbol]);
    }

    private function model(string $symbol = 'SPY', array $changes = []): array
    {
        return array_replace_recursive(['scope' => ['symbol' => $symbol, 'session' => '2026-09-30', 'timeframe' => '7d'],
            'scope_key' => $symbol.'-scope', 'basis_key' => 'basis', 'provenance' => ['quote_source' => 'test'],
            'walls' => ['put' => [['strike' => 100]], 'call' => [['strike' => 110]]],
            'observed_at' => '2026-09-30T14:15:00Z'], $changes);
    }

    private function assessment(array $model, string $status = 'testing', array $setupChanges = []): array
    {
        $identity = (new WallInteractionDetector)->identity($model, 'put');
        $row = ['side' => 'put', 'strike' => 100, 'status' => $status, 'wall_identity' => $identity,
            'observed_at' => '2026-09-30T14:15:00Z', 'close' => 100.1];
        $setup = array_replace(['status' => 'conditions_met', 'direction' => 'bullish', 'pattern' => 'reversal',
            'entry_eligible_at_observation' => true, 'observed_at' => $row['observed_at'], 'wall_identity' => $identity,
            'confirmed_at' => $row['observed_at'], 'expires_at' => '2026-09-30T14:30:00Z',
            'entry_zone' => ['low' => 100.05, 'high' => 100.15], 'invalidation' => ['price' => 99.95, 'close_side' => 'below'],
            'target' => ['strike' => 101], 'evidence_checks' => [['key' => 'ordered_price_confirmation', 'passed' => true]]], $setupChanges);

        return ['state' => 'ready', 'symbol' => $model['scope']['symbol'], 'session' => '2026-09-30',
            'timeframe' => '7d', 'as_of' => $row['observed_at'], 'current' => ['put' => $row],
            'setups' => ['rule_version' => WallSetupRules::VERSION, 'current' => ['put' => $setup]]];
    }

    private function record(array $payload, string $dataset): void
    {
        $model = $dataset === 'intraday_capture';
        $session = $model ? $payload['scope']['session'] : $payload['session'];
        $symbol = $model ? $payload['scope']['symbol'] : $payload['symbol'];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $at = CarbonImmutable::parse($model ? $payload['observed_at'] : $payload['as_of'])->toDateTimeString();
        WallObservation::insert(['symbol' => $symbol, 'content_hash' => hash('sha256', $json.$dataset),
            'scope_key' => $model ? hash('sha256', $symbol) : hash('sha256', json_encode([$symbol, $session, $dataset, $payload['timeframe'], WallInteractionRules::VERSION])), 'dataset' => $dataset,
            'schema_version' => $model ? IntradayWallModel::SCHEMA : WallInteractionDetector::SCHEMA,
            'model_version' => $model ? IntradayWallModel::MODEL : WallInteractionRules::VERSION,
            'observation_kind' => $model ? 'model_observation' : 'interaction_assessment',
            'analysis_session' => $session, 'source_date' => $session, 'observed_at' => $at,
            'captured_at' => now(), 'recorded_at' => now(), 'quality_state' => 'descriptive',
            'payload_bytes' => strlen($json), 'payload_json' => $json]);
    }

    public function test_personal_watchlist_projects_only_latest_current_data_with_bounded_queries_and_no_work(): void
    {
        foreach (['SPY', 'QQQ', 'NVDA'] as $symbol) {
            $this->watchlist($symbol);
            $model = $this->model($symbol);
            $this->record($model, 'intraday_capture');
            $this->record($this->assessment($model, 'testing', ['status' => $symbol === 'SPY' ? 'conditions_met' : 'watch']), 'wall_interactions');
        }
        $this->watchlist('TSLA', 999);
        $model = $this->model('TSLA');
        $this->record($model, 'intraday_capture');
        $this->record($this->assessment($model), 'wall_interactions');
        $before = WallObservation::count();
        DB::enableQueryLog();
        $result = app(WallWatchlistService::class)->read(987);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $queries);
        $this->assertSame(3, $result['watchlist_count']);
        $this->assertSame(3, $result['assessed_count']);
        $this->assertCount(3, $result['items']);
        $this->assertSame('SPY', $result['items'][0]['symbol']);
        $this->assertSame('conditions_met', $result['items'][0]['status']);
        $this->assertSame(101, $result['items'][0]['setup']['target']['strike']);
        $this->assertNotContains('TSLA', array_column($result['items'], 'symbol'));
        $this->assertStringNotContainsString('select *', strtolower($queries[1]['query']));
        $this->assertStringNotContainsString('"bars"', $queries[2]['query']);
        $this->assertSame($before, WallObservation::count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_latest_quiet_or_revised_assessment_replaces_earlier_activity(): void
    {
        $this->watchlist('SPY');
        $model = $this->model();
        $this->record($model, 'intraday_capture');
        $this->record($this->assessment($model), 'wall_interactions');
        $quiet = $this->assessment($model, 'unknown', ['status' => 'watch']);
        $this->record($quiet, 'wall_interactions');
        $result = app(WallWatchlistService::class)->read(987);
        $this->assertSame([], $result['items']);
        $this->assertSame(1, $result['assessed_count']);
    }

    public function test_a_late_insert_of_older_data_does_not_replace_the_latest_assessment(): void
    {
        $this->watchlist('SPY');
        $model = $this->model(changes: ['revision_of' => 123]);
        $this->record($model, 'intraday_capture');
        $this->record($this->assessment($model, 'touch'), 'wall_interactions');
        $older = $this->assessment($model, 'testing', ['status' => 'watch']);
        $older['as_of'] = $older['current']['put']['observed_at'] = '2026-09-30T14:10:00Z';
        $this->record($older, 'wall_interactions');
        $result = app(WallWatchlistService::class)->read(987);
        $this->assertSame('conditions_met', $result['items'][0]['status']);
        $this->assertSame('2026-09-30T14:15:00Z', $result['items'][0]['observed_at']);
    }

    public function test_ready_entry_uses_the_same_exclusive_inner_boundary_as_the_setup_evaluator(): void
    {
        $this->watchlist('SPY');
        $model = $this->model();
        $this->record($model, 'intraday_capture');
        $payload = $this->assessment($model, 'bounce');
        $payload['current']['put']['close'] = 100.05;
        $this->record($payload, 'wall_interactions');
        $result = app(WallWatchlistService::class)->read(987);
        $this->assertSame('bounce', $result['items'][0]['status']);
        $this->assertNull($result['items'][0]['setup']);
    }

    public function test_changes_in_strike_basis_source_or_revision_cannot_carry_over_an_old_setup(): void
    {
        foreach ([['walls' => ['put' => [['strike' => 99]]]], ['basis_key' => 'new'],
            ['provenance' => ['quote_source' => 'new']], ['revision_of' => 123]] as $i => $change) {
            $symbol = 'SYM'.$i;
            $this->watchlist($symbol);
            $old = $this->model($symbol);
            $this->record($this->assessment($old), 'wall_interactions');
            $this->record($this->model($symbol, $change), 'intraday_capture');
        }
        $this->assertSame([], app(WallWatchlistService::class)->read(987)['items']);
    }

    public function test_no_current_entry_is_displayed_for_expired_missed_incomplete_or_ineligible_setups(): void
    {
        foreach ([['status' => 'expired'], ['status' => 'entry_missed'], ['expires_at' => '2026-09-30T14:10:00Z'],
            ['target' => null], ['entry_eligible_at_observation' => false], ['confirmed_at' => '2026-09-30T14:20:00Z']] as $i => $change) {
            $symbol = 'SYM'.$i;
            $this->watchlist($symbol);
            $model = $this->model($symbol);
            $this->record($model, 'intraday_capture');
            $this->record($this->assessment($model, 'touch', $change), 'wall_interactions');
        }
        $result = app(WallWatchlistService::class)->read(987);
        $this->assertCount(6, $result['items']);
        foreach ($result['items'] as $row) {
            $this->assertSame('touch', $row['status']);
            $this->assertNull($row['setup']);
        }
    }

    public function test_old_scope_old_session_stale_and_future_records_do_not_supply_current_activity(): void
    {
        foreach (['past', 'scope', 'stale', 'future'] as $i => $kind) {
            $symbol = 'SYM'.$i;
            $this->watchlist($symbol);
            $model = $this->model($symbol);
            $assessment = $this->assessment($model);
            if ($kind === 'past') {
                $model['scope']['session'] = $assessment['session'] = '2026-09-29';
            } elseif ($kind === 'scope') {
                $model['scope']['timeframe'] = $assessment['timeframe'] = '14d';
            } else {
                $at = $kind === 'future' ? '2026-09-30T14:20:00Z' : '2026-09-30T13:55:00Z';
                $model['observed_at'] = $assessment['as_of'] = $assessment['current']['put']['observed_at'] = $at;
            }
            $this->record($model, 'intraday_capture');
            $this->record($assessment, 'wall_interactions');
        }
        $this->assertSame([], app(WallWatchlistService::class)->read(987)['items']);
    }

    public function test_cache_is_private_membership_changes_take_effect_and_cached_rows_age_out(): void
    {
        $this->watchlist('SPY');
        $model = $this->model();
        $this->record($model, 'intraday_capture');
        $this->record($this->assessment($model), 'wall_interactions');
        $service = app(WallWatchlistService::class);
        $this->assertCount(1, $service->read(987)['items']);
        $this->assertSame([], $service->read(999)['items']);
        DB::table('watchlists')->where('user_id', 987)->delete();
        $this->assertSame([], $service->read(987)['items']);
        $this->watchlist('SPY');
        $this->travelTo(CarbonImmutable::parse('2026-09-30T14:41:00Z'));
        $this->assertSame([], $service->read(987)['items']);
    }

    public function test_market_close_early_close_and_weekend_have_no_current_setups(): void
    {
        $this->watchlist('SPY');
        foreach (['2026-09-30T20:00:00Z', '2026-11-27T18:00:00Z', '2026-10-03T15:00:00Z'] as $at) {
            $this->travelTo(CarbonImmutable::parse($at));
            $result = app(WallWatchlistService::class)->read(987);
            $this->assertSame('outside_session', $result['state']);
            $this->assertSame([], $result['items']);
            $this->assertNull($result['refresh_until']);
        }
    }

    public function test_endpoint_requires_authentication_and_intraday_access_and_ignores_foreign_symbols(): void
    {
        $this->getJson('/api/intraday/wall-watchlist')->assertUnauthorized();
        $user = (new User)->forceFill(['id' => 987, 'email' => 'review@example.test']);
        $user->setRelation('subscriptions', collect());
        Sanctum::actingAs($user);
        $this->getJson('/api/intraday/wall-watchlist')->assertForbidden();
        $user->trial_ends_at = now()->addDays(2);
        $this->watchlist('SPY', 999);
        $this->getJson('/api/intraday/wall-watchlist?user_id=999&symbols[]=SPY&session=2026-09-29')
            ->assertOk()->assertJsonPath('watchlist_count', 0)->assertJsonPath('session', '2026-09-30')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_demonstration_is_local_only_and_uses_the_existing_recorded_examples(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $result = app(WallWatchlistService::class)->demonstration();
        $this->assertSame('demonstration', $result['state']);
        $this->assertCount(3, $result['items']);
        $this->assertSame('conditions_met', $result['items'][0]['status']);
        $this->assertNotEmpty($result['items'][0]['event_id']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(WallWatchlistService::class)->demonstration();
    }
}
