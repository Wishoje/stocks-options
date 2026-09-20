<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BillingIntent;
use App\Support\ProductAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Tests\TestCase;

class BillingJourneyTest extends TestCase
{
    private const CONNECTION = 'billing-journey-test';

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
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'stripe_status']);
        });
        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'stripe_price']);
        });
        $conversionMigration = require database_path('migrations/2026_09_17_000100_create_conversion_events_table.php');
        $conversionMigration->up();

        config()->set('cache.default', 'array');
        config()->set('plans.plans.earlybird.prices.monthly', 'price_test_monthly');
        config()->set('plans.plans.earlybird.prices.yearly', 'price_test_yearly');
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalDatabaseConnection);

        parent::tearDown();
    }

    public function test_guest_auth_entry_preserves_a_normalized_checkout_intent(): void
    {
        if (! Features::enabled(Features::registration())) {
            $this->markTestSkipped('Registration support is not enabled.');
        }

        Queue::fake();
        $this->get('/register?plan=earlybird&billing=yearly')->assertOk();

        $this->assertSame([
            'plan' => 'earlybird',
            'billing' => 'yearly',
        ], session(BillingIntent::SESSION_KEY));
        $this->assertSame(
            '/checkout?plan=earlybird&billing=yearly',
            session('url.intended'),
        );
    }

    public function test_registration_uses_a_one_time_same_origin_handoff_before_checkout(): void
    {
        if (! Features::enabled(Features::registration())) {
            $this->markTestSkipped('Registration support is not enabled.');
        }

        Queue::fake();
        $this->get('/register?plan=earlybird&billing=yearly')->assertOk();

        $this->withHeader('X-Inertia', 'true')->post('/register', [
            'name' => 'New Trader',
            'email' => 'new-trader@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => false,
        ])->assertRedirect(route('registration.handoff', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('conversion_events', [
            'event_type' => 'registration_confirmed',
            'authority' => 'fortify',
        ]);

        $this->withHeader('X-Inertia-Version', Inertia::getVersion())
            ->get(route('registration.handoff'))
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Auth/RegistrationHandoff')
            ->assertJsonPath('props.next_url', '/checkout?plan=earlybird&billing=yearly')
            ->assertJsonPath(
                'props.analytics_key',
                fn ($value) => is_string($value) && preg_match('/\A[a-f0-9]{24}\z/', $value) === 1,
            );

        $this->withoutHeaders(['X-Inertia', 'X-Inertia-Version'])
            ->get(route('registration.handoff'))
            ->assertRedirect('/pricing');
    }

    public function test_checkout_concurrency_lock_blocks_a_different_billing_selection(): void
    {
        $user = User::factory()->create();
        $key = "billing-checkout-start:{$user->getKey()}:default";
        Cache::put($key, true, now()->addMinute());

        $this->actingAs($user)
            ->get('/checkout?plan=earlybird&billing=yearly')
            ->assertRedirect('/pricing?plan=earlybird&billing=yearly')
            ->assertSessionHas('status', 'checkout-already-starting');
    }

    public function test_unexpired_checkout_attempt_is_reused_instead_of_creating_another_session(): void
    {
        $user = User::factory()->create();
        $attempt = $this->attemptState($user, 'active-attempt', 'cs_active');

        $this->actingAs($user)
            ->withSession([BillingIntent::CHECKOUT_ATTEMPT_KEY => $attempt])
            ->get('/checkout?plan=earlybird&billing=monthly')
            ->assertRedirect($attempt['checkout_url']);

        $this->assertSame(
            $attempt['checkout_session_hash'],
            session(BillingIntent::CHECKOUT_ATTEMPT_KEY)['checkout_session_hash'],
        );
    }

    public function test_active_checkout_for_another_selection_is_not_reused_or_overwritten(): void
    {
        $user = User::factory()->create();
        $attempt = $this->attemptState($user, 'active-monthly-attempt', 'cs_active_monthly');

        $this->actingAs($user)
            ->withSession([BillingIntent::CHECKOUT_ATTEMPT_KEY => $attempt])
            ->get('/checkout?plan=earlybird&billing=yearly')
            ->assertRedirect('/pricing?plan=earlybird&billing=yearly')
            ->assertSessionHas('status', 'checkout-active-other-selection');

        $this->assertSame(
            $attempt['checkout_session_hash'],
            session(BillingIntent::CHECKOUT_ATTEMPT_KEY)['checkout_session_hash'],
        );
        $this->assertSame('monthly', session(BillingIntent::CHECKOUT_ATTEMPT_KEY)['billing']);
    }

    public function test_expired_checkout_attempt_is_cleared_before_the_concurrency_guard(): void
    {
        $user = User::factory()->create();
        $attempt = $this->attemptState($user, 'expired-attempt', 'cs_expired');
        $attempt['expires_at'] = now()->subMinute()->timestamp;
        $key = "billing-checkout-start:{$user->getKey()}:default";
        Cache::put($key, true, now()->addMinute());

        $this->actingAs($user)
            ->withSession([BillingIntent::CHECKOUT_ATTEMPT_KEY => $attempt])
            ->get('/checkout?plan=earlybird&billing=monthly')
            ->assertRedirect('/pricing?plan=earlybird&billing=monthly')
            ->assertSessionHas('status', 'checkout-already-starting');

        $this->assertNull(session(BillingIntent::CHECKOUT_ATTEMPT_KEY));
    }

    public function test_checkout_return_is_pending_until_the_local_subscription_state_is_confirmed(): void
    {
        $user = User::factory()->create();
        $attemptToken = 'pending-attempt';
        $checkoutSessionId = 'cs_test_only';

        $this->actingAs($user)
            ->withSession([
                BillingIntent::CHECKOUT_ATTEMPT_KEY => $this->attemptState($user, $attemptToken, $checkoutSessionId),
            ])
            ->get("/billing/success?plan=earlybird&billing=monthly&attempt=wrong&session_id={$checkoutSessionId}")
            ->assertRedirect('/pricing?plan=earlybird&billing=monthly')
            ->assertSessionHas('status', 'checkout-return-unconfirmed');

        $this->actingAs($user)
            ->get("/billing/success?plan=earlybird&billing=monthly&attempt={$attemptToken}&session_id={$checkoutSessionId}")
            ->assertRedirect('/pricing?activating=1&plan=earlybird&billing=monthly');

        $this->assertTrue(session(BillingIntent::ACTIVATION_KEY)['expires_at'] > now()->timestamp);

        $this->actingAs($user)
            ->getJson('/billing/status')
            ->assertOk()
            ->assertJson([
                'active' => false,
                'state' => 'pending',
                'redirect' => null,
            ])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_unverified_checkout_return_never_marks_activation_pending(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/billing/success?plan=earlybird&billing=monthly')
            ->assertRedirect('/pricing?plan=earlybird&billing=monthly')
            ->assertSessionHas('status', 'checkout-return-unconfirmed');

        $this->assertNull(session(BillingIntent::ACTIVATION_KEY));
    }

    public function test_confirmed_local_trial_redirects_to_the_preserved_product_context(): void
    {
        $user = User::factory()->create();
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_confirmed_local',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_test_monthly',
            'quantity' => 1,
            'trial_ends_at' => now()->addDays(7),
        ]);

        $attemptToken = 'confirmed-attempt';
        $checkoutSessionId = 'cs_confirmed';

        $this->actingAs($user)
            ->withSession([
                BillingIntent::RETURN_KEY => '/dashboard?symbol=SPY&mode=eod&tab=strikes&timeframe=30d',
                BillingIntent::CHECKOUT_ATTEMPT_KEY => $this->attemptState($user, $attemptToken, $checkoutSessionId),
                BillingIntent::ACTIVATION_KEY => [
                    'plan' => 'earlybird',
                    'billing' => 'monthly',
                    'started_at' => now()->timestamp,
                    'expires_at' => now()->addMinutes(10)->timestamp,
                ],
            ])
            ->get("/billing/success?plan=earlybird&billing=monthly&attempt={$attemptToken}&session_id={$checkoutSessionId}")
            ->assertRedirect('/dashboard?symbol=SPY&mode=eod&tab=strikes&timeframe=30d&welcome=1')
            ->assertSessionHas('activation_confirmed', true);

        $this->assertNull(session(BillingIntent::ACTIVATION_KEY));

        $this->actingAs($user)
            ->get("/billing/success?plan=earlybird&billing=monthly&attempt={$attemptToken}&session_id={$checkoutSessionId}")
            ->assertRedirect('/dashboard?symbol=SPY&mode=eod&tab=strikes&timeframe=30d');
    }

    public function test_checkout_return_does_not_confirm_an_adverse_local_subscription(): void
    {
        $user = User::factory()->create();
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_paused_checkout_return',
            'stripe_status' => 'paused',
            'stripe_price' => 'price_test_monthly',
            'quantity' => 1,
        ]);

        $attemptToken = 'paused-attempt';
        $checkoutSessionId = 'cs_paused';

        $this->actingAs($user)
            ->withSession([
                BillingIntent::CHECKOUT_ATTEMPT_KEY => $this->attemptState($user, $attemptToken, $checkoutSessionId),
            ])
            ->get("/billing/success?plan=earlybird&billing=monthly&attempt={$attemptToken}&session_id={$checkoutSessionId}")
            ->assertRedirect('/pricing?activating=1&plan=earlybird&billing=monthly')
            ->assertSessionMissing('activation_confirmed');

        $this->assertTrue(session(BillingIntent::ACTIVATION_KEY)['expires_at'] > now()->timestamp);
    }

    public function test_existing_subscriber_manual_success_url_does_not_flash_activation_confirmation(): void
    {
        $user = User::factory()->create();
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_existing',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_monthly',
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get('/billing/success?plan=earlybird&billing=monthly&attempt=fake&session_id=cs_fake')
            ->assertRedirect('/dashboard')
            ->assertSessionMissing('activation_confirmed');
    }

    public function test_persisted_generic_trial_is_cast_to_a_date_and_grants_access_without_skipping_checkout(): void
    {
        $user = User::factory()->create(['trial_ends_at' => now()->addDays(3)])->fresh();
        $access = ProductAccess::for($user);

        $this->assertTrue($user->trial_ends_at->isFuture());
        $this->assertTrue($access['has_access']);
        $this->assertTrue($access['on_generic_trial']);
        $this->assertTrue($access['needs_checkout']);
    }

    public function test_unsafe_return_destination_is_never_used(): void
    {
        $this->assertSame(
            ['plan' => 'earlybird', 'billing' => 'monthly'],
            BillingIntent::normalize('earlybird', 'currency'),
        );
        $this->assertNull(BillingIntent::safeReturnTo('https://evil.example/steal'));
        $this->assertNull(BillingIntent::safeReturnTo('//evil.example/steal'));
        $this->assertSame(
            '/dashboard?symbol=SPY&tab=strikes',
            BillingIntent::safeReturnTo('/dashboard?symbol=SPY&tab=strikes&token=secret'),
        );
    }

    private function attemptState(
        User $user,
        string $token,
        string $checkoutSessionId,
        string $billing = 'monthly',
    ): array {
        return [
            'plan' => 'earlybird',
            'billing' => $billing,
            'user_id' => (string) $user->getKey(),
            'token_hash' => hash('sha256', $token),
            'checkout_session_hash' => hash('sha256', $checkoutSessionId),
            'checkout_url' => "https://checkout.stripe.com/c/pay/{$checkoutSessionId}",
            'expires_at' => now()->addMinutes(20)->timestamp,
        ];
    }
}
