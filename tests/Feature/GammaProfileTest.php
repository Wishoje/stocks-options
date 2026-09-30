<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Models\User;
use App\Services\AiExportBuilder;
use App\Services\GammaProfileService;
use App\Support\EodSnapshotSelector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class GammaProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
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
            foreach (['strike', 'iv', 'gamma', 'open_interest', 'underlying_price'] as $f) {
                $t->double($f)->nullable();
            }
        });
        Schema::create('prices_daily', function (Blueprint $t) {
            $t->string('symbol');
            $t->date('trade_date');
            $t->double('close');
        });
        DB::table('option_expirations')->insert(['id' => 1, 'symbol' => 'SPY', 'expiration_date' => '2026-10-02']);
        DB::table('prices_daily')->insert(['symbol' => 'SPY', 'trade_date' => '2026-09-30', 'close' => 100]);
        foreach (['call' => 1, 'put' => 2] as $type => $gamma) {
            DB::table('option_chain_data')->insert(['expiration_id' => 1, 'data_date' => '2026-09-30', 'strike' => 100,
                'option_type' => $type, 'gamma' => $gamma, 'iv' => $type === 'call' ? .2 : .25, 'open_interest' => 10, 'underlying_price' => 100]);
        }
        $selector = Mockery::mock(EodSnapshotSelector::class);
        $selector->shouldReceive('selectedRows')->andReturnUsing(fn ($ids, $columns, $anchor) => DB::table('option_chain_data')->whereIn('expiration_id', $ids)->where('data_date', '<=', $anchor)->get());
        $this->app->instance(EodSnapshotSelector::class, $selector);
        Http::preventStrayRequests();
    }

    private function levels(): array
    {
        return ['symbol' => 'SPY', 'timeframe' => '14d', 'data_date' => '2026-09-30', 'expiration_dates' => ['2026-10-02'],
            'view_context' => ['view' => 'next_session', 'session_date' => '2026-10-01', 'source_anchor' => '2026-09-30'],
            'strike_data' => [['strike' => 100, 'net_gex' => -10000000]]];
    }

    private function signIn(bool $entitled = true): void
    {
        $user = (new User)->forceFill(['id' => 987, 'email' => 'review@example.test', 'trial_ends_at' => $entitled ? now()->addDays(2) : null]);
        $user->setRelation('subscriptions', collect());
        Sanctum::actingAs($user);
    }

    public function test_scoped_eod_profile_uses_dated_close_and_preserves_model_assumptions(): void
    {
        $r = app(GammaProfileService::class)->build($this->levels());
        $this->assertSame('ready', $r['status']);
        $this->assertSame(100.0, $r['reference_price']['value']);
        $this->assertFalse($r['reference_price']['live']);
        $this->assertSame('2026-10-01', $r['view_context']['session_date']);
        $this->assertSame('2026-09-30T20:00:00+00:00', $r['model_evaluated_at']);
        $this->assertSame('call_minus_put', $r['inventory_convention']);
        $this->assertSame('USD per 1% underlying move', $r['unit']);
        $this->assertSame(60, $r['assumptions']['zero_dte_time_floor_seconds']);
        Http::assertNothingSent();
    }

    public function test_wrong_date_close_and_mixed_expiry_dates_cannot_be_substituted(): void
    {
        DB::table('prices_daily')->update(['trade_date' => '2026-09-29']);
        $this->assertSame('no_reference_close', app(GammaProfileService::class)->build($this->levels())['reason']);
        DB::table('prices_daily')->update(['trade_date' => '2026-09-30']);
        DB::table('option_chain_data')->where('option_type', 'put')->update(['data_date' => '2026-09-29']);
        $this->assertSame('model_basis_not_ready', app(GammaProfileService::class)->build($this->levels())['reason']);
    }

    public function test_changed_generation_requires_dashboard_refresh(): void
    {
        DB::table('option_chain_data')->update(['gamma' => 4]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(GammaProfileService::class)->build($this->levels());
    }

    public function test_route_requires_auth_and_entitlement(): void
    {
        $this->getJson('/api/gamma-profile?symbol=SPY&timeframe=14d')->assertUnauthorized();
        $this->signIn(false);
        $this->getJson('/api/gamma-profile?symbol=SPY&timeframe=14d')->assertForbidden();
    }

    public function test_api_export_parity_and_scope_validation(): void
    {
        $this->signIn();
        $controller = Mockery::mock(GexController::class);
        $controller->shouldReceive('getGexLevels')->andReturnUsing(fn () => response()->json($this->levels()));
        $this->app->instance(GexController::class, $controller);
        $this->getJson('/api/gamma-profile?symbol=SPY&timeframe=bad')->assertUnprocessable();
        $r = $this->getJson('/api/gamma-profile?symbol=SPY&timeframe=14d&view=next_session')->assertOk()->json();
        $export = app(AiExportBuilder::class)->build(['SPY'], ['gamma_profile'], '14d', ['gex_view' => 'next_session', 'target_session' => '2026-10-01']);
        $this->assertSame($r, $export['items'][0]['gamma_profile']['data']);
        $this->assertSame($r['crossings'], $export['items'][0]['summary']['gamma_profile']['crossings']);
        $this->assertSame('2026-09-30', $export['items'][0]['summary']['data_dates']['gamma_profile']);
    }
}
