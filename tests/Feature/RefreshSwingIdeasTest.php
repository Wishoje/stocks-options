<?php

namespace Tests\Feature;

use App\Services\SwingDataSource;
use App\Services\SwingIdeaService;
use App\Support\Swing\SwingIdeaRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RefreshSwingIdeasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'cache.coordination_enabled' => false]);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_26_180000_create_swing_ideas_tables.php'))->up();
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('symbol');
        });
        DB::table('watchlists')->insert(['user_id' => 1, 'symbol' => 'SPY']);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 17:00', 'America/New_York'));
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_a_current_short_horizon_does_not_skip_the_stale_position_horizon(): void
    {
        $this->record('short', 'watching', '2026-10-26');
        $this->record('position', 'watching', '2026-10-23');

        $this->assertRefreshIsDue();
    }

    public function test_a_current_no_idea_does_not_hide_an_older_active_plan_awaiting_review(): void
    {
        $this->record('short', 'no_idea', '2026-10-26');
        $this->record('short', 'in_progress', '2026-10-23');
        $this->record('position', 'no_idea', '2026-10-26');

        $this->assertRefreshIsDue();
    }

    public function test_a_missing_horizon_is_prepared_even_when_the_other_horizon_is_current(): void
    {
        $this->record('short', 'no_idea', '2026-10-26');

        $this->assertRefreshIsDue();
    }

    public function test_a_configured_symbol_gets_swing_preparation_without_any_user_saving_it(): void
    {
        DB::table('watchlists')->delete();
        config(['product_universe.enabled' => true, 'product_universe.symbols' => ['SPY']]);

        $this->assertRefreshIsDue();
        $this->assertDatabaseCount('watchlists', 0);
    }

    public function test_both_current_horizons_need_no_source_read_or_refresh(): void
    {
        $this->record('short', 'watching', '2026-10-26');
        $this->record('position', 'no_idea', '2026-10-26');

        $this->assertRefreshIsNotDue();
    }

    public function test_a_frozen_plan_does_not_cause_repeated_no_op_refreshes(): void
    {
        $this->record('short', 'needs_update', '2026-10-23');
        $this->record('position', 'no_idea', '2026-10-26');

        $this->assertRefreshIsNotDue();
        $this->assertSame('needs_update', DB::table('swing_ideas')->where('horizon', 'short')->value('status'));
    }

    public function test_a_frozen_plan_does_not_suppress_reviewing_the_other_horizon(): void
    {
        $this->record('short', 'needs_update', '2026-10-23');
        $this->record('position', 'conditions_met', '2026-10-23');

        $this->assertRefreshIsDue();
    }

    private function record(string $horizon, string $status, string $reviewedThrough): void
    {
        DB::table('swing_ideas')->insert([
            'id' => hash('sha256', $horizon.':'.$status.':'.$reviewedThrough),
            'symbol' => 'SPY', 'horizon' => $horizon, 'rule_version' => SwingIdeaRules::VERSION,
            'source_date' => $reviewedThrough, 'status' => $status, 'reviewed_through' => $reviewedThrough,
            'source_hash' => str_repeat('a', 64), 'original_plan' => '{}', 'current_state' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertRefreshIsDue(): void
    {
        $input = ['symbol' => 'SPY', 'data_date' => '2026-10-26'];
        $source = \Mockery::mock(SwingDataSource::class);
        $source->shouldReceive('read')->once()->with('SPY')->andReturn($input);
        $this->app->instance(SwingDataSource::class, $source);
        $service = \Mockery::mock(SwingIdeaService::class);
        $service->shouldReceive('refresh')->once()->with($input)->andReturn([]);
        $this->app->instance(SwingIdeaService::class, $service);

        $this->artisan('swing:refresh --watchlist')->assertSuccessful();
        // The unchanged fixture remains due, but the existing attempt cooldown prevents another read.
        $this->artisan('swing:refresh --watchlist')->expectsOutput('No saved-symbol refresh is due.')->assertSuccessful();
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    private function assertRefreshIsNotDue(): void
    {
        $source = \Mockery::mock(SwingDataSource::class);
        $source->shouldNotReceive('read');
        $this->app->instance(SwingDataSource::class, $source);
        $service = \Mockery::mock(SwingIdeaService::class);
        $service->shouldNotReceive('refresh');
        $this->app->instance(SwingIdeaService::class, $service);

        $this->artisan('swing:refresh --watchlist')->expectsOutput('No saved-symbol refresh is due.')->assertSuccessful();
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }
}
