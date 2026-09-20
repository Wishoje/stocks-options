<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AccountSubscriptionData;
use App\Support\ProductAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    private const CONNECTION = 'account-settings-test';

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
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::CONNECTION);
        DB::setDefaultConnection($this->originalDatabaseConnection);

        parent::tearDown();
    }

    public function test_account_page_does_not_invent_a_subscription(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Show')
                ->where('subscription.exists', false)
                ->where('subscription.plan_name', null)
                ->where('subscription.status_label', 'No subscription')
                ->where('subscription.has_access', false)
                ->where('subscription.can_open_portal', false)
                ->where('sessionsSupported', false));
    }

    public function test_account_page_reports_a_configured_subscription_from_local_cashier_state(): void
    {
        config(['plans.plans.earlybird.prices.monthly' => 'price_test_monthly']);

        $user = User::factory()->create(['stripe_id' => 'cus_test_account']);
        $user->subscriptions()->create([
            'type' => config('plans.default_subscription_name'),
            'stripe_id' => 'sub_test_account',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_monthly',
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.exists', true)
                ->where('subscription.plan_name', 'Early Bird')
                ->where('subscription.billing_interval', 'Monthly')
                ->where('subscription.state', 'active')
                ->where('subscription.status_label', 'Active')
                ->where('subscription.active', true)
                ->where('subscription.has_access', true)
                ->where('subscription.can_open_portal', true)
                ->where('subscription.can_cancel', true)
                ->where('subscription.next_charge_at', null));
    }

    public function test_account_page_distinguishes_a_generic_trial_from_a_stripe_subscription(): void
    {
        $user = User::factory()->create([
            'trial_ends_at' => now()->addDays(3),
        ]);

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.exists', false)
                ->where('subscription.plan_name', 'Account trial')
                ->where('subscription.state', 'generic_trial')
                ->where('subscription.status_label', 'Account trial')
                ->where('subscription.on_generic_trial', true)
                ->where('subscription.has_access', true)
                ->where('subscription.needs_checkout', true));
    }

    #[DataProvider('subscriptionStateProvider')]
    public function test_account_page_represents_saved_subscription_states_truthfully(
        string $providerStatus,
        string $endTiming,
        string $expectedState,
        string $expectedLabel,
        bool $expectedActive,
        bool $expectedCanCancel,
        bool $expectedCanResume,
    ): void {
        $user = User::factory()->create(['stripe_id' => 'cus_state_'.$expectedState]);
        $user->subscriptions()->create([
            'type' => config('plans.default_subscription_name'),
            'stripe_id' => 'sub_state_'.$expectedState,
            'stripe_status' => $providerStatus,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'trial_ends_at' => $providerStatus === 'trialing' ? now()->addDays(7) : null,
            'ends_at' => match ($endTiming) {
                'future' => now()->addDay(),
                'past' => now()->subMinute(),
                default => null,
            },
        ]);

        $subscription = AccountSubscriptionData::for($user);

        $this->assertSame($expectedState, $subscription['state']);
        $this->assertSame($expectedLabel, $subscription['status_label']);
        $this->assertSame($expectedActive, $subscription['active']);
        $this->assertSame($expectedCanCancel, $subscription['can_cancel']);
        $this->assertSame($expectedCanResume, $subscription['can_resume']);
        $this->assertSame(
            $expectedActive,
            ProductAccess::for($user)['has_access'],
            'Only the explicitly active subscription states may grant product access.',
        );
        $this->assertSame(
            ProductAccess::for($user)['has_access'],
            $subscription['has_access'],
            'Account presentation must report the same access decision enforced by ProductAccess.',
        );
    }

    public static function subscriptionStateProvider(): array
    {
        return [
            'active' => ['active', 'none', 'active', 'Active', true, true, false],
            'trialing' => ['trialing', 'none', 'trialing', 'Trial active', true, true, false],
            'grace period' => ['active', 'future', 'grace_period', 'Cancels at period end', true, false, true],
            'past due' => ['past_due', 'none', 'past_due', 'Payment past due', false, false, false],
            'incomplete' => ['incomplete', 'none', 'incomplete', 'Setup incomplete', false, false, false],
            'paused' => ['paused', 'none', 'paused', 'Paused', false, false, false],
            'unpaid' => ['unpaid', 'none', 'unpaid', 'Unpaid', false, false, false],
            'incomplete expired' => ['incomplete_expired', 'none', 'incomplete_expired', 'Setup expired', false, false, false],
            'canceled without end date' => ['canceled', 'none', 'canceled', 'Canceled; end pending', false, false, false],
            'unknown provider status' => ['provider_future_state', 'none', 'unknown', 'Provider status unknown', false, false, false],
            'paused with future local end' => ['paused', 'future', 'status_conflict', 'Provider status conflict', false, false, false],
            'expired with future local end' => ['incomplete_expired', 'future', 'status_conflict', 'Provider status conflict', false, false, false],
            'active provider status with past local end' => ['active', 'past', 'status_conflict', 'Provider status conflict', false, false, false],
            'ended' => ['canceled', 'past', 'ended', 'Ended', false, false, false],
            'expired and ended' => ['incomplete_expired', 'past', 'ended', 'Ended', false, false, false],
        ];
    }

    public function test_an_adverse_subscription_cannot_fall_through_to_a_stale_generic_trial(): void
    {
        $user = User::factory()->create([
            'stripe_id' => 'cus_stale_generic_trial',
            'trial_ends_at' => now()->addDays(3),
        ]);
        $user->subscriptions()->create([
            'type' => config('plans.default_subscription_name'),
            'stripe_id' => 'sub_stale_generic_trial',
            'stripe_status' => 'paused',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $access = ProductAccess::for($user);
        $subscription = AccountSubscriptionData::for($user);

        $this->assertFalse($access['on_generic_trial']);
        $this->assertFalse($access['has_access']);
        $this->assertSame('paused', $access['subscription_state']);
        $this->assertSame('paused', $subscription['state']);
        $this->assertFalse($subscription['has_access']);
    }

    public function test_adverse_subscription_states_cannot_invoke_cancel_or_resume_actions(): void
    {
        $paused = User::factory()->create(['stripe_id' => 'cus_paused_actions']);
        $paused->subscriptions()->create([
            'type' => config('plans.default_subscription_name'),
            'stripe_id' => 'sub_paused_actions',
            'stripe_status' => 'paused',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $this->actingAs($paused)
            ->post(route('billing.cancel'))
            ->assertBadRequest();

        $conflictingGrace = User::factory()->create(['stripe_id' => 'cus_conflicting_grace']);
        $conflictingGrace->subscriptions()->create([
            'type' => config('plans.default_subscription_name'),
            'stripe_id' => 'sub_conflicting_grace',
            'stripe_status' => 'paused',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => now()->addDay(),
        ]);

        $this->actingAs($conflictingGrace)
            ->post(route('billing.resume'))
            ->assertBadRequest();
    }

    public function test_account_page_lists_database_sessions_when_the_session_store_supports_it(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();

        DB::table(config('session.table', 'sessions'))->insert([
            'id' => 'other-session',
            'user_id' => $user->getAuthIdentifier(),
            'ip_address' => '203.0.113.8',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
            'payload' => '',
            'last_activity' => now()->subMinutes(2)->timestamp,
        ]);

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sessionsSupported', true)
                ->has('sessions', 1)
                ->where('sessions.0.ip_address', '203.0.113.8')
                ->where('sessions.0.agent.platform', 'Windows')
                ->where('sessions.0.agent.browser', 'Chrome'));
    }
}
