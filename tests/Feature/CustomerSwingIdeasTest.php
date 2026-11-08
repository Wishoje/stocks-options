<?php

namespace Tests\Feature;

use App\Services\SwingIdeaService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\SwingInput;
use Tests\TestCase;

class CustomerSwingIdeasTest extends TestCase
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
        Schema::create('prices_daily', function (Blueprint $table) {
            $table->string('symbol');
            $table->date('trade_date');
            $table->decimal('close', 12, 4);
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-23 17:00', 'America/New_York'));
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_customer_read_retains_a_dated_idea_after_its_private_target_result_without_mutation(): void
    {
        $service = new SwingIdeaService;
        $service->refresh(SwingInput::make());
        DB::table('watchlists')->insert(['user_id' => 1, 'symbol' => 'SPY']);
        $row = DB::table('swing_ideas')->where('horizon', 'short')->first();
        $state = [...json_decode($row->current_state, true), 'status' => 'finished', 'outcome' => 'target_reached',
            'reason' => 'A later daily close reached the frozen reference target.',
            'qualified_at' => '2026-10-26', 'qualified_price' => 106.5, 'outcome_at' => '2026-10-27'];
        DB::table('swing_ideas')->where('id', $row->id)->update(['status' => 'finished', 'current_state' => json_encode($state)]);
        DB::table('prices_daily')->insert(['symbol' => 'SPY', 'trade_date' => '2026-10-28', 'close' => 111.2]);
        $before = DB::table('swing_ideas')->where('id', $row->id)->first();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 17:00', 'America/New_York'));

        $public = $service->readCustomerIdeas(1, 'short');

        $this->assertCount(1, $public['items']);
        $idea = $public['items'][0];
        $this->assertSame('2026-10-26', $idea['idea_date']);
        $this->assertSame('2026-10-30', $idea['available_through']);
        $this->assertSame('conditions_met', $idea['status']);
        $this->assertSame(111.2, $idea['latest_price']['close']);
        $this->assertArrayNotHasKey('outcome', $idea);
        $this->assertStringNotContainsString('reached the frozen', json_encode($public));
        $this->assertSame([], $service->readCustomerIdeas(2, 'short')['items']);
        $this->assertEquals($before, DB::table('swing_ideas')->where('id', $row->id)->first());
        $this->assertSame('target_reached', $service->read(1, 'short')['items'][0]['outcome']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_public_availability_ends_after_five_or_fifteen_sessions_while_internal_rows_remain(): void
    {
        $service = new SwingIdeaService;
        $service->refresh(SwingInput::make());
        DB::table('watchlists')->insert(['user_id' => 1, 'symbol' => 'SPY']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/New_York'));

        $this->assertSame([], $service->readCustomerIdeas(1, 'short')['items']);
        $position = $service->readCustomerIdeas(1, 'position')['items'];
        $this->assertCount(1, $position);
        $this->assertSame('2026-11-13', $position[0]['available_through']);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'America/New_York'));
        $this->assertSame([], $service->readCustomerIdeas(1, 'position')['items']);
        $this->assertSame(2, DB::table('swing_ideas')->count());
    }

    public function test_customer_read_chooses_the_newest_eligible_idea_without_deleting_older_research(): void
    {
        $service = new SwingIdeaService;
        $service->refresh(SwingInput::make());
        DB::table('watchlists')->insert(['user_id' => 1, 'symbol' => 'SPY']);
        $old = DB::table('swing_ideas')->where('horizon', 'short')->first();
        $original = [...json_decode($old->original_plan, true), 'source_date' => '2026-10-26',
            'activation_session' => '2026-10-27'];
        $new = [...(array) $old, 'id' => hash('sha256', 'newer-idea'), 'source_date' => '2026-10-26',
            'original_plan' => json_encode($original), 'current_state' => json_encode($original),
            'created_at' => '2026-10-26 22:00:00', 'updated_at' => '2026-10-26 22:00:00'];
        DB::table('swing_ideas')->insert($new);
        $this->travelTo(CarbonImmutable::parse('2026-10-28 17:00', 'America/New_York'));

        $ideas = $service->readCustomerIdeas(1, 'short')['items'];

        $this->assertCount(1, $ideas);
        $this->assertSame($new['id'], $ideas[0]['id']);
        $this->assertSame('2026-10-27', $ideas[0]['idea_date']);
        $this->assertSame('2026-11-02', $ideas[0]['available_through']);
        $this->assertSame(3, DB::table('swing_ideas')->count());
    }

    public function test_date_limited_ideas_do_not_linger_over_the_weekend_or_before_the_next_open(): void
    {
        $service = new SwingIdeaService;
        $service->refresh(SwingInput::make());
        DB::table('watchlists')->insert(['user_id' => 1, 'symbol' => 'SPY']);
        foreach (['2026-10-31 10:00', '2026-11-02 08:00'] as $at) {
            $this->travelTo(CarbonImmutable::parse($at, 'America/New_York'));
            $this->assertSame([], $service->readCustomerIdeas(1, 'short')['items']);
            $this->assertSame(2, DB::table('swing_ideas')->count());
        }
    }
}
