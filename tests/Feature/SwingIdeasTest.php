<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SwingDataSource;
use App\Services\SwingIdeaService;
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

class SwingIdeasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'cache.coordination_enabled' => false]);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_26_180000_create_swing_ideas_tables.php'))->up();
        Schema::create('watchlists', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('symbol');
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-23 17:00', 'America/New_York'));
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_publication_is_idempotent_and_daily_reviews_keep_the_original_plan(): void
    {
        $service = new SwingIdeaService;
        $input = SwingInput::make();
        $this->assertCount(2, $service->refresh($input));
        $this->assertSame([], $service->refresh($input));
        $original = DB::table('swing_ideas')->where('horizon', 'short')->first();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 17:00', 'America/New_York'));
        $input['data_date'] = '2026-10-26';
        $input['prices'][] = ['date' => '2026-10-26', 'open' => 106., 'high' => 107., 'low' => 105.9, 'close' => 106.5];
        $service->refresh($input);
        $service->refresh($input);
        $row = DB::table('swing_ideas')->where('id', $original->id)->first();
        $this->assertSame($original->original_plan, $row->original_plan);
        $this->assertSame('conditions_met', $row->status);
        $this->assertSame(2, DB::table('swing_idea_reviews')->count());
        $this->assertSame(2, DB::table('swing_ideas')->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_personal_read_is_read_only_and_excludes_other_watchlists(): void
    {
        $service = new SwingIdeaService;
        $service->refresh(SwingInput::make());
        DB::table('watchlists')->insert([['user_id' => 1, 'symbol' => 'SPY'], ['user_id' => 2, 'symbol' => 'QQQ']]);
        $this->assertCount(1, $service->read(1, 'short')['items']);
        $this->assertSame([], $service->read(2, 'short')['items']);
        $this->assertSame(2, DB::table('swing_ideas')->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->get('/swing-ideas')->assertRedirect('/login');
        $this->assertContains('subscribed', app('router')->getRoutes()->getByName('options.swing-ideas')->gatherMiddleware());
    }

    public function test_refresh_command_uses_existing_source_once_for_both_horizons_and_no_jobs(): void
    {
        $source = \Mockery::mock(SwingDataSource::class);
        $source->shouldReceive('read')->once()->with('SPY')->andReturn(SwingInput::make());
        $this->app->instance(SwingDataSource::class, $source);
        $this->artisan('swing:refresh --symbols=SPY')->assertSuccessful();
        $this->assertSame(2, DB::table('swing_ideas')->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->artisan('swing:refresh')->assertFailed();
    }

    public function test_local_examples_cover_both_horizons_without_persisting_or_notifying(): void
    {
        $environment = app()->environment();
        try {
            app()['env'] = 'local';
            foreach (['short', 'position'] as $horizon) {
                $result = (new \App\Support\Swing\SwingIdeaDemo)->build($horizon);
                $this->assertTrue($result['demonstration']);
                $this->assertSame(['watching', 'conditions_met', 'in_progress', 'finished', 'needs_update'], array_column($result['items'], 'status_group'));
            }
            $this->assertSame(0, DB::table('swing_ideas')->count());
            Http::assertNothingSent();
            Bus::assertNothingDispatched();
        } finally {
            app()['env'] = $environment;
        }
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new \App\Support\Swing\SwingIdeaDemo)->build('short');
    }

    public function test_a_changed_same_date_publication_does_not_replace_the_original_plan(): void
    {
        $service = new SwingIdeaService;
        $input = SwingInput::make();
        $service->refresh($input);
        $original = DB::table('swing_ideas')->where('horizon', 'short')->first();
        $input['source_version'] = 'corrected';
        $service->refresh($input);
        $row = DB::table('swing_ideas')->where('id', $original->id)->first();
        $this->assertSame('needs_update', $row->status);
        $this->assertSame($original->original_plan, $row->original_plan);
    }

    public function test_a_corrected_reviewed_bar_pauses_the_plan_without_rewriting_its_original_review(): void
    {
        $service = new SwingIdeaService;
        $input = SwingInput::make();
        $service->refresh($input);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 17:00', 'America/New_York'));
        $input['data_date'] = '2026-10-26';
        $input['prices'][] = ['date' => '2026-10-26', 'open' => 106., 'high' => 107., 'low' => 105.9, 'close' => 106.5];
        $service->refresh($input);
        $original = DB::table('swing_ideas')->where('horizon', 'short')->first();
        $review = DB::table('swing_idea_reviews')->where('idea_id', $original->id)->first();
        $this->assertSame('conditions_met', $original->status);

        $input['prices'][array_key_last($input['prices'])]['close'] = 106.6;
        $service->refresh($input);
        $row = DB::table('swing_ideas')->where('id', $original->id)->first();
        $this->assertSame('needs_update', $row->status);
        $this->assertSame($original->original_plan, $row->original_plan);
        $this->assertSame($review->assessment, DB::table('swing_idea_reviews')->where('idea_id', $row->id)->value('assessment'));
        $this->assertSame($review->evidence_hash, DB::table('swing_idea_reviews')->where('idea_id', $row->id)->value('evidence_hash'));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_empty_optional_horizon_uses_the_default_instead_of_failing_the_page(): void
    {
        $user = (new User)->forceFill(['id' => 1, 'email' => 'swing.reader@example.test', 'trial_ends_at' => now()->addDays(2)]);
        $user->setRelation('subscriptions', collect());
        Sanctum::actingAs($user);

        $this->get('/swing-ideas?horizon=')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('SwingIdeas')->where('horizon', 'short'));
        $this->getJson('/swing-ideas?horizon=unknown')->assertUnprocessable();
        $this->assertSame(0, DB::table('swing_ideas')->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_first_publication_cannot_advertise_a_plan_whose_entry_window_already_ended(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-30 17:00', 'America/New_York'));
        $service = new SwingIdeaService;
        $service->refresh(SwingInput::make());
        $rows = DB::table('swing_ideas')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $plan = json_decode($row->original_plan, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('no_idea', $row->status);
            $this->assertSame('no_idea', $plan['status']);
            $this->assertStringContainsString('before this plan could become active', $plan['reason']);
            $this->assertGreaterThan($plan['entry_deadline'], $plan['activation_session']);
            $this->assertSame(111., $plan['levels']['target']);
            $this->assertNotEmpty($plan['evidence']);
        }
        $this->assertSame([], $service->refresh(SwingInput::make()));
        $this->assertSame(2, DB::table('swing_ideas')->count());
    }

    public function test_publication_on_the_last_entry_session_keeps_that_session_available(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 08:00', 'America/New_York'));
        (new SwingIdeaService)->refresh(SwingInput::make());
        $row = DB::table('swing_ideas')->where('horizon', 'short')->first();
        $plan = json_decode($row->original_plan, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('watching', $row->status);
        $this->assertSame('2026-10-28', $plan['activation_session']);
        $this->assertSame($plan['entry_deadline'], $plan['activation_session']);
    }
}
