<?php

namespace Tests\Feature;

use App\Jobs\BootstrapUserSymbolJob;
use App\Support\ProductUniverse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductUniverseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'cache.coordination_enabled' => false,
            'symbol_bootstrap.enabled' => false, 'product_universe.enabled' => true,
            'product_universe.symbols' => ['AVGO', 'NFLX', 'TQQQ']]);
        DB::purge('sqlite');
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('symbol');
        });
        Schema::create('option_expirations', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->date('expiration_date');
        });
        Schema::create('prices_daily', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->date('trade_date');
        });
        $this->travelTo(CarbonImmutable::parse('2026-11-08 19:00', 'America/New_York'));
        Cache::flush();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_shared_symbols_are_deduplicated_and_independent_of_the_owner_watchlist(): void
    {
        DB::table('watchlists')->insert([
            ['user_id' => 1, 'symbol' => ' spy '], ['user_id' => 1, 'symbol' => 'SPY'],
            ['user_id' => 2, 'symbol' => 'QQQ'], ['user_id' => 2, 'symbol' => 'I:SPX'],
        ]);
        $this->assertSame(['AVGO', 'NFLX', 'QQQ', 'SPY', 'TQQQ'], ProductUniverse::symbols());
        $this->assertSame(['SPY'], ProductUniverse::symbols(1));
        $this->assertSame(['QQQ'], ProductUniverse::symbols(2));
        $this->assertSame([], ProductUniverse::symbols(3));
        $this->assertDatabaseCount('watchlists', 4);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_disabling_configured_expansion_keeps_existing_shared_watchlist_coverage(): void
    {
        DB::table('watchlists')->insert(['user_id' => 2, 'symbol' => 'QQQ']);
        config(['product_universe.enabled' => false]);
        $this->assertSame(['QQQ'], ProductUniverse::symbols());
    }

    public function test_dry_run_never_dispatches_or_claims_the_initialization_batch(): void
    {
        $this->artisan('universe:prepare --dry-run --limit=2')
            ->expectsOutput('AVGO, NFLX')->assertSuccessful();
        $this->assertFalse(Cache::has('universe:prepare:attempt:AVGO'));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_initialization_is_bounded_and_moves_to_the_remaining_names(): void
    {
        $this->artisan('universe:prepare --limit=2')->assertSuccessful();
        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 2);
        $this->artisan('universe:prepare --limit=2')->assertSuccessful();
        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 3);
        $this->artisan('universe:prepare --limit=2')->assertSuccessful();
        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 3);
        $this->assertDatabaseCount('watchlists', 0);
        Http::assertNothingSent();
    }

    public function test_a_current_catalog_alone_does_not_hide_a_missing_completed_eod_price(): void
    {
        DB::table('option_expirations')->insert([
            ['symbol' => 'AVGO', 'expiration_date' => '2026-11-13'],
            ['symbol' => 'NFLX', 'expiration_date' => '2026-11-13'],
            ['symbol' => 'TQQQ', 'expiration_date' => '2026-11-06'],
        ]);
        DB::table('prices_daily')->insert(['symbol' => 'AVGO', 'trade_date' => '2026-11-06']);
        $this->artisan('universe:prepare --dry-run --limit=5')
            ->expectsOutput('NFLX, TQQQ')->assertSuccessful();
        Bus::assertNothingDispatched();
    }

    public function test_scheduled_initialization_runs_overnight_and_skips_market_hours(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'universe:prepare');
        $this->assertNotNull($event);
        $this->assertStringContainsString('--limit=2', $event->command);
        foreach (['2026-11-08 19:00', '2026-11-09 07:30'] as $at) {
            $this->travelTo(CarbonImmutable::parse($at, 'America/New_York'));
            $this->assertTrue($event->filtersPass($this->app), $at);
        }
        foreach (['2026-11-09 09:30', '2026-11-09 15:55', '2026-11-09 16:15'] as $at) {
            $this->travelTo(CarbonImmutable::parse($at, 'America/New_York'));
            $this->assertFalse($event->filtersPass($this->app), $at);
        }
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }
}
