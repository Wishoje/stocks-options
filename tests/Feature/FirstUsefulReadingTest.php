<?php

namespace Tests\Feature;

use App\Models\ConversionEvent;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FirstUsefulReadingTest extends TestCase
{
    private const CONNECTION = 'first-useful-reading-test';

    private string $originalDatabaseConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDatabaseConnection = DB::getDefaultConnection();
        config()->set('database.connections.'.self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);
        config()->set('conversion_measurement.cohort_started_at', now('UTC')->subDay()->toIso8601String());

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->rememberToken();
            $table->foreignId('current_team_id')->nullable();
            $table->string('profile_photo_path', 2048)->nullable();
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        (require database_path('migrations/2025_12_23_063737_create_subscriptions_table.php'))->up();
        (require database_path('migrations/2025_12_23_063738_create_subscription_items_table.php'))->up();
        (require database_path('migrations/2026_09_17_000100_create_conversion_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalDatabaseConnection);

        parent::tearDown();
    }

    public function test_authenticated_reading_inspection_is_recorded_once_per_account(): void
    {
        $user = $this->subscribedUser();
        $payload = [
            'interaction' => 'reading_inspected',
            'surface' => 'dashboard',
        ];

        $this->actingAs($user)
            ->postJson('/product-events/first-useful-reading', $payload)
            ->assertCreated()
            ->assertExactJson(['recorded' => true, 'first' => true]);

        $this->actingAs($user)
            ->postJson('/product-events/first-useful-reading', $payload)
            ->assertOk()
            ->assertExactJson(['recorded' => true, 'first' => false]);

        $this->assertDatabaseCount('conversion_events', 1);
        $event = ConversionEvent::query()->sole();
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('first_useful_reading', $event->event_type);
        $this->assertSame('authenticated_dashboard', $event->authority);
        $this->assertSame(['surface' => 'dashboard', 'state' => 'ready'], $event->properties);
        $this->assertSame(64, strlen($event->event_key));
        $this->assertStringNotContainsString($user->email, $event->toJson());
        $this->assertStringNotContainsString('SPY', $event->toJson());
    }

    public function test_untrusted_or_ineligible_requests_cannot_create_the_event(): void
    {
        $unsubscribed = User::factory()->create();

        $this->postJson('/product-events/first-useful-reading', [
            'interaction' => 'reading_inspected',
            'surface' => 'dashboard',
        ])->assertUnauthorized();

        $this->actingAs($unsubscribed)
            ->postJson('/product-events/first-useful-reading', [
                'interaction' => 'reading_inspected',
                'surface' => 'dashboard',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');

        $this->actingAs($this->subscribedUser())
            ->postJson('/product-events/first-useful-reading', [
                'interaction' => 'page_loaded',
                'surface' => 'dashboard',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('conversion_events', 0);
    }

    public function test_event_fails_closed_without_the_measurement_boundary(): void
    {
        config()->set('conversion_measurement.cohort_started_at');

        $this->actingAs($this->subscribedUser())
            ->postJson('/product-events/first-useful-reading', [
                'interaction' => 'reading_inspected',
                'surface' => 'dashboard',
            ])
            ->assertNoContent();

        $this->assertDatabaseCount('conversion_events', 0);
    }

    private function subscribedUser(): User
    {
        $user = User::factory()->create();
        $user->subscriptions()->create([
            'type' => config('plans.default_subscription_name', 'default'),
            'stripe_id' => 'sub_first_use_'.$user->id,
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        return $user;
    }
}
