<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Models\User;
use App\Services\AiExportBuilder;
use App\Services\WallIntelligenceService;
use App\Support\EodSnapshotSelector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class WallIntelligenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'eod_publications.read_enabled' => false, 'eod_publications.write_enabled' => false]);
        DB::purge('sqlite');
        Schema::create('option_expirations', function (Blueprint $t) {
            $t->id();
            $t->string('symbol');
            $t->date('expiration_date');
        });
        Schema::create('option_chain_data', function (Blueprint $t) {
            $t->id();
            $t->integer('expiration_id');
            $t->date('data_date');
            $t->string('option_type');
            foreach (['strike', 'gamma', 'open_interest', 'underlying_price'] as $field) {
                $t->double($field)->nullable();
            }
        });
        Schema::create('prices_daily', function (Blueprint $t) {
            $t->string('symbol');
            $t->date('trade_date');
            $t->double('close');
        });
        DB::table('option_expirations')->insert(['id' => 1, 'symbol' => 'SPY', 'expiration_date' => '2026-10-02']);
        DB::table('prices_daily')->insert(['symbol' => 'SPY', 'trade_date' => '2026-09-30', 'close' => 101]);
        foreach (['2026-09-30', '2026-09-29', '2026-09-28'] as $date) {
            foreach (['call' => 1, 'put' => 2] as $type => $gamma) {
                DB::table('option_chain_data')->insert(['expiration_id' => 1, 'data_date' => $date, 'strike' => 100, 'option_type' => $type, 'gamma' => $gamma, 'open_interest' => 10, 'underlying_price' => 100]);
            }
        }
        $selector = Mockery::mock(EodSnapshotSelector::class);
        $selector->shouldReceive('selectedDatesSubquery')->andReturnUsing(fn () => DB::table('option_chain_data')->select('expiration_id')->selectRaw('MAX(data_date) as max_date')->groupBy('expiration_id'));
        $selector->shouldReceive('minSideRatio')->andReturn(.35);
        $this->app->instance(EodSnapshotSelector::class, $selector);
        Http::preventStrayRequests();
        Bus::fake();
    }

    private function levels(): array
    {
        return ['symbol' => 'SPY', 'timeframe' => '14d', 'data_date' => '2026-09-30', 'expiration_dates' => ['2026-10-02'],
            'put_support' => 100, 'view_context' => ['view' => 'latest_eod', 'session_date' => '2026-09-30', 'source_anchor' => '2026-09-30'],
            'strike_data' => [['strike' => 100, 'net_gex' => -10000000, 'call_gex' => 10000000, 'put_gex' => 20000000]]];
    }

    private function signIn(bool $entitled = true): void
    {
        $user = (new User)->forceFill(['id' => 987, 'email' => 'review@example.test', 'trial_ends_at' => $entitled ? now()->addDays(2) : null]);
        $user->setRelation('subscriptions', collect());
        Sanctum::actingAs($user);
    }

    private function mockLevels(): void
    {
        $gex = Mockery::mock(GexController::class);
        $gex->shouldReceive('getGexLevels')->andReturnUsing(fn () => response()->json($this->levels()));
        $this->app->instance(GexController::class, $gex);
    }

    public function test_query_reconciles_units_uses_aligned_close_and_preserves_the_market_calendar(): void
    {
        $result = app(WallIntelligenceService::class)->build($this->levels());
        $wall = $result['walls']['put'][0];
        $this->assertSame(-100000.0, $wall['net_gex']);
        $this->assertSame(-100000.0, $wall['expiry_contributions'][0]['net_gex']);
        $this->assertSame(101.0, $result['reference_price']['value']);
        $this->assertSame(3, $wall['top_three_streak_sessions']);
        $this->assertSame(['2026-09-30', '2026-09-29', '2026-09-28', '2026-09-25', '2026-09-24'], array_column($wall['history'], 'date'));
        $this->assertFalse($result['audit']['historical_outcome_eligible']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_old_close_is_not_substituted_for_the_selected_date(): void
    {
        DB::table('prices_daily')->update(['trade_date' => '2026-09-29']);
        $result = app(WallIntelligenceService::class)->build($this->levels());
        $this->assertNull($result['reference_price']['value']);
        $this->assertNull($result['walls']['put'][0]['distance_pct']);
    }

    public function test_changed_chain_generation_cannot_be_attached_to_cached_levels(): void
    {
        DB::table('option_chain_data')->where('data_date', '2026-09-30')->update(['gamma' => 4]);
        try {
            app(WallIntelligenceService::class)->build($this->levels());
            $this->fail('Expected generation mismatch');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_endpoint_requires_authentication_and_product_access(): void
    {
        $url = '/api/wall-intelligence?symbol=SPY&timeframe=14d';
        $this->getJson($url)->assertUnauthorized();
        $this->signIn(false);
        $this->getJson($url)->assertForbidden();
    }

    public function test_endpoint_validates_bounded_scope_and_reuses_cached_analysis(): void
    {
        $this->signIn();
        $this->mockLevels();
        $this->getJson('/api/wall-intelligence?symbol=SPY&timeframe=999d')->assertUnprocessable();
        $service = Mockery::mock(WallIntelligenceService::class);
        $service->shouldReceive('build')->once()->andReturn(['schema_version' => 'wall-intelligence.v1', 'walls' => []]);
        $this->app->instance(WallIntelligenceService::class, $service);
        for ($i = 0; $i < 2; $i++) {
            $this->getJson('/api/wall-intelligence?symbol=SPY&timeframe=14d&view=latest_eod')->assertOk()->assertJsonPath('schema_version', 'wall-intelligence.v1');
        }
    }

    public function test_ai_export_uses_the_same_payload_and_analysis_scope(): void
    {
        $this->signIn();
        $this->mockLevels();
        $response = $this->getJson('/api/wall-intelligence?symbol=SPY&timeframe=14d&view=latest_eod')->assertOk()->json();
        $export = app(AiExportBuilder::class)->build(['SPY'], ['wall_intelligence'], '14d', ['gex_view' => 'latest_eod']);
        $this->assertTrue($export['items'][0]['wall_intelligence']['ok']);
        $this->assertSame($response, $export['items'][0]['wall_intelligence']['data']);
        $this->assertSame('2026-09-30', $export['items'][0]['summary']['data_dates']['wall_intelligence']);
    }
}
