<?php

namespace Tests\Feature;

use App\Services\SwingDataSource;
use App\Services\SwingIdeaService;
use App\Support\Swing\PausedSwingPlan;
use App\Support\Swing\SwingIdeaRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\SwingInput;
use Tests\TestCase;

class PausedSwingReplacementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'cache.coordination_enabled' => false]);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_26_180000_create_swing_ideas_tables.php'))->up();
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('symbol');
        });
        DB::table('watchlists')->insert(['user_id' => 1, 'symbol' => 'SPY']);
        $this->travelTo(CarbonImmutable::parse('2026-10-31 22:00:00', 'UTC'));
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_a_later_valid_input_can_create_a_new_plan_without_rewriting_paused_evidence(): void
    {
        $oldId = $this->paused();
        $original = (array) DB::table('swing_ideas')->where('id', $oldId)->first();
        $reviews = DB::table('swing_idea_reviews')->where('idea_id', $oldId)->get()->toArray();

        $published = (new SwingIdeaService)->refresh($this->input());

        $new = DB::table('swing_ideas')->where('horizon', 'short')->where('source_date', '2026-10-30')->first();
        $this->assertNotNull($new);
        $this->assertContains($new->id, $published);
        $this->assertNotSame($oldId, $new->id);
        $this->assertSame('watching', $new->status);
        $newState = json_decode($new->original_plan, true);
        $expected = (new SwingIdeaRules)->propose($this->input(), 'short');
        $this->assertSame($expected['levels'], $newState['levels']);
        $this->assertSame(SwingIdeaRules::VERSION, $newState['rule_version']);
        $this->assertSame($original, (array) DB::table('swing_ideas')->where('id', $oldId)->first());
        $this->assertEquals($reviews, DB::table('swing_idea_reviews')->where('idea_id', $oldId)->get()->toArray());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_a_later_input_can_record_no_idea_without_rewriting_or_erasing_the_old_plan(): void
    {
        $oldId = $this->paused();
        $original = (array) DB::table('swing_ideas')->where('id', $oldId)->first();
        $input = $this->input();
        unset($input['prices'][60]);

        (new SwingIdeaService)->refresh($input);

        $this->assertDatabaseHas('swing_ideas', ['symbol' => 'SPY', 'horizon' => 'short',
            'source_date' => '2026-10-30', 'status' => 'no_idea']);
        $this->assertSame($original, (array) DB::table('swing_ideas')->where('id', $oldId)->first());
        $this->assertDatabaseCount('swing_idea_reviews', 1);
    }

    public function test_an_entry_deadline_equal_to_the_new_source_date_still_blocks_replacement(): void
    {
        $oldId = $this->paused(['entry_deadline' => '2026-10-30']);
        $original = (array) DB::table('swing_ideas')->where('id', $oldId)->first();

        (new SwingIdeaService)->refresh($this->input());

        $this->assertSame(1, DB::table('swing_ideas')->where('horizon', 'short')->count());
        $this->assertSame($original, (array) DB::table('swing_ideas')->where('id', $oldId)->first());
    }

    public function test_a_qualified_paused_plan_remains_blocking_after_its_entry_deadline(): void
    {
        $oldId = $this->paused(['qualified_at' => '2026-10-26', 'qualified_price' => 106.5]);
        $original = (array) DB::table('swing_ideas')->where('id', $oldId)->first();

        (new SwingIdeaService)->refresh($this->input());

        $this->assertSame(1, DB::table('swing_ideas')->where('horizon', 'short')->count());
        $this->assertSame($original, (array) DB::table('swing_ideas')->where('id', $oldId)->first());
        $this->assertDatabaseCount('swing_idea_reviews', 1);
    }

    public function test_watchlist_scheduler_prepares_a_later_plan_once_and_then_sees_the_current_publication(): void
    {
        $this->paused();
        $this->currentPosition();
        $input = $this->input();
        $source = \Mockery::mock(SwingDataSource::class);
        $source->shouldReceive('read')->once()->with('SPY')->andReturn($input);
        $this->app->instance(SwingDataSource::class, $source);

        $this->artisan('swing:refresh --watchlist')->assertSuccessful();
        $this->assertDatabaseHas('swing_ideas', ['symbol' => 'SPY', 'horizon' => 'short',
            'source_date' => '2026-10-30', 'status' => 'watching']);
        // Clear the cooldown to prove that the new current row suppresses same-date work itself.
        Cache::forget('swing:attempt:2026-10-30:SPY');
        $this->artisan('swing:refresh --watchlist')->expectsOutput('No saved-symbol refresh is due.')->assertSuccessful();
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_watchlist_scheduler_keeps_the_existing_attempt_cooldown_for_replacement_candidates(): void
    {
        $this->paused();
        $this->currentPosition();
        Cache::put('swing:attempt:2026-10-30:SPY', true, now()->addMinutes(30));
        $this->assertNotDue();
    }

    public function test_scheduler_keeps_qualified_or_not_yet_expired_paused_plans_frozen(): void
    {
        foreach ([['qualified_at' => '2026-10-26'], ['entry_deadline' => '2026-10-30']] as $changes) {
            DB::table('swing_idea_reviews')->delete();
            DB::table('swing_ideas')->delete();
            $this->paused($changes);
            $this->currentPosition();
            $this->assertNotDue();
        }
    }

    public function test_replaceability_fails_closed_for_unknown_qualification_invalid_dates_and_missing_evidence(): void
    {
        $state = ['status' => 'needs_update', 'qualified_at' => null,
            'source_date' => '2026-10-23', 'entry_deadline' => '2026-10-28'];
        $this->assertTrue(PausedSwingPlan::replaceable($state, '2026-10-30'));
        unset($state['qualified_at']);
        $this->assertTrue(PausedSwingPlan::replaceable($state, '2026-10-30'));
        foreach ([
            ['status' => 'watching'], ['qualified_at' => ''], ['qualified_at' => false],
            ['qualified_at' => '2026-10-26'], ['source_date' => null], ['entry_deadline' => null],
            ['source_date' => '2026-02-30'], ['entry_deadline' => '2026-13-01'],
            ['entry_deadline' => '2026-10-22'], ['source_date' => '2026-10-23 00:00:00'],
        ] as $changes) {
            $this->assertFalse(PausedSwingPlan::replaceable([...$state, ...$changes], '2026-10-30'));
        }
        foreach (['2026-10-23', '2026-10-28', '2026-02-30', 'tomorrow'] as $date) {
            $this->assertFalse(PausedSwingPlan::replaceable($state, $date));
        }
    }

    private function paused(array $changes = []): string
    {
        $plan = (new SwingIdeaRules)->propose(SwingInput::make(), 'short');
        $id = hash('sha256', json_encode(['SPY', 'short', SwingIdeaRules::VERSION, '2026-10-23']));
        $state = [...$plan, 'status' => 'needs_update', 'reason' => 'Frozen evidence needs review.', ...$changes];
        DB::table('swing_ideas')->insert([
            'id' => $id, 'symbol' => 'SPY', 'horizon' => 'short', 'rule_version' => SwingIdeaRules::VERSION,
            'source_date' => '2026-10-23', 'source_hash' => str_repeat('a', 64),
            'status' => 'needs_update', 'reviewed_through' => '2026-10-23',
            'original_plan' => json_encode($plan, JSON_PRESERVE_ZERO_FRACTION),
            'current_state' => json_encode($state, JSON_PRESERVE_ZERO_FRACTION),
            'created_at' => '2026-10-23 21:00:00', 'updated_at' => '2026-10-26 21:00:00',
        ]);
        DB::table('swing_idea_reviews')->insert(['idea_id' => $id, 'data_date' => '2026-10-26',
            'evidence_hash' => str_repeat('b', 64), 'assessment' => json_encode($state, JSON_PRESERVE_ZERO_FRACTION),
            'recorded_at' => '2026-10-26 21:00:00']);

        return $id;
    }

    private function currentPosition(): void
    {
        DB::table('swing_ideas')->insert([
            'id' => hash('sha256', json_encode(['SPY', 'position', SwingIdeaRules::VERSION, '2026-10-30'])),
            'symbol' => 'SPY', 'horizon' => 'position', 'rule_version' => SwingIdeaRules::VERSION,
            'source_date' => '2026-10-30', 'source_hash' => str_repeat('c', 64),
            'status' => 'no_idea', 'reviewed_through' => '2026-10-30',
            'original_plan' => '{}', 'current_state' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function input(): array
    {
        $input = SwingInput::make();
        $input['data_date'] = '2026-10-30';
        foreach (['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'] as $index => $date) {
            $close = 107. + .1 * $index;
            $input['prices'][] = ['date' => $date, 'open' => $close - .05, 'high' => $close + 1., 'low' => $close - 1., 'close' => $close];
        }
        foreach ($input['strikes'] as &$row) {
            $row['expirations'][0]['data_date'] = '2026-10-30';
        }
        unset($row);

        return $input;
    }

    private function assertNotDue(): void
    {
        $source = \Mockery::mock(SwingDataSource::class);
        $source->shouldNotReceive('read');
        $this->app->instance(SwingDataSource::class, $source);
        $service = \Mockery::mock(SwingIdeaService::class);
        $service->shouldNotReceive('refresh');
        $this->app->instance(SwingIdeaService::class, $service);
        $this->artisan('swing:refresh --watchlist')->expectsOutput('No saved-symbol refresh is due.')->assertSuccessful();
    }
}
