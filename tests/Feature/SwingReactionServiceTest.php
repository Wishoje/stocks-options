<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SwingDataSource;
use App\Services\SwingReactionPriceSource;
use App\Services\SwingReactionService;
use App\Support\Swing\SwingReactionRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\SwingInput;
use Tests\TestCase;

class SwingReactionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'cache.coordination_enabled' => false, 'product_universe.enabled' => false]);
        DB::purge('sqlite');
        (require database_path('migrations/2026_11_11_000000_create_swing_reaction_tables.php'))->up();
        Schema::create('watchlists', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('symbol');
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-26 11:00', 'America/New_York'));
        Http::preventStrayRequests();
        Bus::fake();
    }

    private function idea(string $id, string $symbol, string $status = 'watching'): void
    {
        $state = ['symbol' => $symbol, 'horizon' => 'short', 'source_date' => '2026-10-23',
            'rule_version' => SwingReactionRules::VERSION, 'status' => $status,
            'side' => 'put', 'pattern' => 'hold_reclaim', 'direction' => 'bullish',
            'available_at' => '2026-10-26T13:00:00+00:00', 'entry_deadline' => '2026-10-28T20:00:00+00:00',
            'holding_deadline' => '2026-10-30T20:00:00+00:00',
            'levels' => ['wall' => 100., 'entry_low' => 100.1, 'entry_high' => 100.5, 'invalidation' => 99., 'target' => 105.],
            'evidence' => [], 'outcome' => $status === 'completed' ? 'target_reached' : null];
        DB::table('swing_reaction_ideas')->insert(['id' => $id, 'symbol' => $symbol, 'horizon' => 'short',
            'rule_version' => SwingReactionRules::VERSION, 'source_date' => '2026-10-23', 'status' => $status,
            'original_plan' => json_encode($state), 'current_state' => json_encode($state), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_all_accounts_share_active_universe_ideas_and_personal_filter_stays_personal(): void
    {
        DB::table('watchlists')->insert([['user_id' => 1, 'symbol' => 'SPY'], ['user_id' => 2, 'symbol' => 'QQQ']]);
        $this->idea('a', 'SPY');
        $this->idea('b', 'QQQ', 'ready');
        foreach (['completed', 'cancelled', 'expired', 'ambiguous'] as $status) {
            $this->idea($status, 'SPY', $status);
        }
        $service = new SwingReactionService;
        $this->assertSame(['QQQ', 'SPY'], array_column($service->read(1, 'short')['items'], 'symbol'));
        $this->assertSame($service->read(1, 'short')['items'], $service->read(3, 'short')['items']);
        $this->assertSame(['SPY'], array_column($service->read(1, 'short', 'watchlist')['items'], 'symbol'));
        $this->assertSame([], $service->read(3, 'short', 'watchlist')['items']);
        $this->assertSame(6, DB::table('swing_reaction_ideas')->count());
        $this->assertArrayNotHasKey('outcome', $service->read(1, 'short')['items'][0]);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_subscribed_page_uses_v2_and_is_read_only(): void
    {
        DB::table('watchlists')->insert(['user_id' => 2, 'symbol' => 'SPY']);
        $this->idea('a', 'SPY', 'entry_touched');
        $user = (new User)->forceFill(['id' => 1, 'email' => 'reader@example.test', 'trial_ends_at' => now()->addDay()]);
        $user->setRelation('subscriptions', collect());
        Sanctum::actingAs($user);
        $this->get('/swing-ideas')->assertOk()->assertInertia(fn (Assert $page) => $page->component('SwingIdeas')
            ->has('items', 1)->where('items.0.status', 'entry_touched')->where('scope', 'universe'));
        $this->getJson('/swing-ideas?demo=1')->assertForbidden();
        $this->assertSame(1, DB::table('swing_reaction_ideas')->count());
        $this->assertSame(0, DB::table('swing_reaction_reviews')->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_command_reads_structure_once_per_date_and_follows_all_shared_symbols(): void
    {
        DB::table('watchlists')->insert(['user_id' => 2, 'symbol' => 'SPY']);
        $source = \Mockery::mock(SwingDataSource::class);
        $source->shouldReceive('read')->once()->with('SPY')->andReturn(SwingInput::make());
        $this->app->instance(SwingDataSource::class, $source);
        $prices = \Mockery::mock(SwingReactionPriceSource::class);
        $prices->shouldReceive('capture')->zeroOrMoreTimes()->andReturn(['pinned' => 0, 'conflicts' => []]);
        $prices->shouldReceive('bars')->zeroOrMoreTimes()->andReturn([]);
        $this->app->instance(SwingReactionPriceSource::class, $prices);
        $this->artisan('swing:react --all')->assertSuccessful();
        $this->artisan('swing:react --all')->assertSuccessful();
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_new_publications_are_idempotent_and_keep_frozen_levels_and_legacy_records(): void
    {
        $input = SwingInput::make();
        foreach ([104., 105., 107., 109., 110., 112.] as $strike) {
            $cell = $input['strikes'][0]['expirations'][0];
            $cell['net_gex'] = 200000.;
            $input['strikes'][] = ['strike' => $strike, 'expirations' => [$cell]];
        }
        $input['prices'][array_key_last($input['prices'])]['close'] = 107.;
        $service = new SwingReactionService;
        $created = $service->seed($input, CarbonImmutable::now('UTC'));
        $this->assertGreaterThan(0, $created);
        $original = DB::table('swing_reaction_ideas')->orderBy('id')->get();
        $this->assertSame(0, $service->seed($input, CarbonImmutable::now('UTC')->addMinutes(2)));
        $this->assertEquals($original, DB::table('swing_reaction_ideas')->orderBy('id')->get());
        $this->assertSame(0, DB::table('swing_reaction_reviews')->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }
}
