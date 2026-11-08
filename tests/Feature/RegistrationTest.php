<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Watchlist;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    private const CONNECTION = 'registration-test';

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
        config()->set('conversion_measurement.cohort_started_at', '2026-09-01T00:00:00Z');

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
        Schema::create('watchlists', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('symbol', 10);
            $table->timestamps();
            $table->unique(['user_id', 'symbol']);
        });

        $conversionMigration = require database_path('migrations/2026_09_17_000100_create_conversion_events_table.php');
        $conversionMigration->up();

        Queue::fake();
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalDatabaseConnection);

        parent::tearDown();
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        if (! Features::enabled(Features::registration())) {
            $this->markTestSkipped('Registration support is not enabled.');
        }

        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_registration_screen_cannot_be_rendered_if_support_is_disabled(): void
    {
        if (Features::enabled(Features::registration())) {
            $this->markTestSkipped('Registration support is enabled.');
        }

        $response = $this->get('/register');

        $response->assertStatus(404);
    }

    public function test_new_users_can_register(): void
    {
        if (! Features::enabled(Features::registration())) {
            $this->markTestSkipped('Registration support is not enabled.');
        }

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('registration.handoff', absolute: false));
        $this->assertDatabaseHas('watchlists', ['user_id' => auth()->id(), 'symbol' => 'SPY']);
        $this->assertDatabaseCount('watchlists', 1);
    }

    public function test_registration_rolls_back_the_account_if_the_default_watchlist_cannot_be_saved(): void
    {
        Schema::drop('watchlists');

        try {
            (new CreateNewUser)->create([
                'name' => 'Test User', 'email' => 'atomic@example.test',
                'password' => 'password', 'password_confirmation' => 'password', 'terms' => true,
            ]);
            $this->fail('Registration should fail when its default cannot be saved.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseMissing('users', ['email' => 'atomic@example.test']);
        }
    }

    public function test_deleting_the_default_symbol_is_respected_on_later_reads_and_login(): void
    {
        $user = (new CreateNewUser)->create([
            'name' => 'Test User', 'email' => 'empty@example.test',
            'password' => 'password', 'password_confirmation' => 'password', 'terms' => true,
        ]);
        Watchlist::where('user_id', $user->id)->delete();
        $user->forceFill(['trial_ends_at' => now()->addDay()]);
        $user->setRelation('subscriptions', collect());
        $this->actingAs($user);

        $this->getJson('/api/watchlist')->assertOk()->assertExactJson([]);
        $this->assertDatabaseCount('watchlists', 0);
    }
}
