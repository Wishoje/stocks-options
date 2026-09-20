<?php

namespace Tests\Feature;

use App\Listeners\RecordBillingConversionEvents;
use App\Listeners\RecordRegistrationConversion;
use App\Listeners\RecordTrialActivationConversion;
use App\Models\ConversionEvent;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\TestCase;

class ConversionEventRecordingTest extends TestCase
{
    private const CONNECTION = 'conversion-event-test';

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
        config()->set('plans.plans.earlybird.prices.monthly', 'price_monthly_test');
        config()->set('plans.plans.earlybird.prices.yearly', 'price_yearly_test');
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

        $subscriptionsMigration = require database_path('migrations/2025_12_23_063737_create_subscriptions_table.php');
        $subscriptionsMigration->up();
        $subscriptionItemsMigration = require database_path('migrations/2025_12_23_063738_create_subscription_items_table.php');
        $subscriptionItemsMigration->up();

        $conversionMigration = require database_path('migrations/2026_09_17_000100_create_conversion_events_table.php');
        $conversionMigration->up();
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalDatabaseConnection);

        parent::tearDown();
    }

    public function test_registration_is_recorded_once_without_personal_properties(): void
    {
        $user = User::factory()->create();
        $listener = app(RecordRegistrationConversion::class);

        $listener->handle(new Registered($user));
        $listener->handle(new Registered($user));

        $this->assertDatabaseCount('conversion_events', 1);
        $event = ConversionEvent::query()->sole();
        $this->assertSame('registration_confirmed', $event->event_type);
        $this->assertSame('fortify', $event->authority);
        $this->assertSame(['method' => 'email'], $event->properties);
        $this->assertSame(64, strlen($event->event_key));
        $this->assertNotSame(
            hash('sha256', "registration_confirmed|{$user->id}|user:{$user->id}"),
            $event->event_key,
        );
        $this->assertStringNotContainsString($user->email, json_encode($event->toArray()));
    }

    public function test_confirmed_trial_webhook_is_deduplicated_by_account_and_type(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_trial']);
        $receivedListener = app(RecordBillingConversionEvents::class);
        $handledListener = app(RecordTrialActivationConversion::class);
        $payload = $this->subscriptionPayload('evt_trial_1', 'cus_trial');

        $receivedListener->handle(new WebhookReceived($payload));
        $this->assertDatabaseCount('conversion_events', 0);

        $this->createSubscription($user, 'sub_private', 'trialing', 'price_yearly_test', 1_789_500_000 + 604800);
        $handledListener->handle(new WebhookHandled($payload));
        $payload['id'] = 'evt_trial_retry';
        $handledListener->handle(new WebhookHandled($payload));

        $this->assertDatabaseCount('conversion_events', 1);
        $this->assertDatabaseHas('conversion_events', [
            'user_id' => $user->id,
            'event_type' => 'trial_activation_confirmed',
            'authority' => 'stripe_webhook_after_cashier',
        ]);
        $this->assertSame(
            ['state' => 'trial', 'plan' => 'earlybird', 'billing' => 'yearly'],
            ConversionEvent::query()->sole()->properties,
        );
    }

    public function test_only_first_positive_paid_invoice_counts_as_paid_activation(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_paid']);
        $this->createSubscription($user, 'sub_private', 'active', 'price_monthly_test');
        $listener = app(RecordBillingConversionEvents::class);

        $listener->handle(new WebhookReceived($this->invoicePayload('evt_zero', 'cus_paid', 0)));
        $listener->handle(new WebhookReceived($this->invoicePayload('evt_paid_1', 'cus_paid', 2999)));
        $listener->handle(new WebhookReceived($this->invoicePayload('evt_paid_2', 'cus_paid', 2999)));

        $this->assertDatabaseCount('conversion_events', 1);
        $this->assertDatabaseHas('conversion_events', [
            'user_id' => $user->id,
            'event_type' => 'paid_activation_confirmed',
            'authority' => 'stripe_webhook',
        ]);
        $event = ConversionEvent::query()->sole();
        $this->assertSame(
            ['state' => 'paid', 'currency' => 'usd', 'plan' => 'earlybird', 'billing' => 'monthly'],
            $event->properties,
        );
        $this->assertStringNotContainsString('evt_paid_1', $event->toJson());
        $this->assertStringNotContainsString('in_private', $event->toJson());
    }

    public function test_unconfirmed_billing_payloads_do_not_create_conversions(): void
    {
        User::factory()->create(['stripe_id' => 'cus_unconfirmed']);
        $paidListener = app(RecordBillingConversionEvents::class);
        $trialListener = app(RecordTrialActivationConversion::class);

        $activeWithoutTrial = $this->subscriptionPayload('evt_no_trial', 'cus_unconfirmed');
        $activeWithoutTrial['data']['object']['status'] = 'active';
        $activeWithoutTrial['data']['object']['trial_end'] = null;
        $trialListener->handle(new WebhookHandled($activeWithoutTrial));

        $failedInvoice = $this->invoicePayload('evt_failed', 'cus_unconfirmed', 2999);
        $failedInvoice['data']['object']['status'] = 'open';
        $paidListener->handle(new WebhookReceived($failedInvoice));

        $paidListener->handle(new WebhookReceived($this->invoicePayload('evt_zero', 'cus_unconfirmed', 0)));
        $oneTimeInvoice = $this->invoicePayload('evt_one_time', 'cus_unconfirmed', 2999);
        unset($oneTimeInvoice['data']['object']['subscription']);
        $paidListener->handle(new WebhookReceived($oneTimeInvoice));
        $paidListener->handle(new WebhookReceived([
            'id' => 'evt_checkout_return',
            'type' => 'checkout.session.completed',
            'created' => 1_789_500_000,
            'data' => ['object' => ['customer' => 'cus_unconfirmed']],
        ]));

        $this->assertDatabaseCount('conversion_events', 0);
    }

    public function test_a_preexisting_subscription_renewal_does_not_enter_the_new_paid_activation_cohort(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_existing']);
        $this->createSubscription(
            $user,
            'sub_private',
            'active',
            'price_monthly_test',
            createdAt: '2026-08-01 00:00:00',
        );

        app(RecordBillingConversionEvents::class)->handle(
            new WebhookReceived($this->invoicePayload('evt_renewal', 'cus_existing', 2999)),
        );

        $this->assertDatabaseCount('conversion_events', 0);
    }

    public function test_measurement_events_fail_closed_without_a_cohort_boundary(): void
    {
        config()->set('conversion_measurement.cohort_started_at');
        $user = User::factory()->create(['stripe_id' => 'cus_no_boundary']);
        $this->createSubscription($user, 'sub_private', 'trialing', 'price_monthly_test', 1_789_500_000 + 604800);

        app(RecordRegistrationConversion::class)->handle(new Registered($user));
        app(RecordTrialActivationConversion::class)->handle(
            new WebhookHandled($this->subscriptionPayload('evt_trial_no_boundary', 'cus_no_boundary')),
        );
        app(RecordBillingConversionEvents::class)->handle(
            new WebhookReceived($this->invoicePayload('evt_paid_no_boundary', 'cus_no_boundary', 2999)),
        );

        $this->assertDatabaseCount('conversion_events', 0);
    }

    public function test_deleting_an_account_removes_its_pseudonymous_conversion_rows(): void
    {
        $user = User::factory()->create();
        app(RecordRegistrationConversion::class)->handle(new Registered($user));

        $this->assertDatabaseCount('conversion_events', 1);

        $user->delete();

        $this->assertDatabaseCount('conversion_events', 0);
    }

    private function createSubscription(
        User $user,
        string $stripeId,
        string $status,
        string $priceId,
        ?int $trialEndsAt = null,
        ?string $createdAt = null,
    ): void {
        $createdAt ??= now()->toDateTimeString();
        DB::table('subscriptions')->insert([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => $priceId,
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt ? date('Y-m-d H:i:s', $trialEndsAt) : null,
            'ends_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function subscriptionPayload(string $eventId, string $customerId): array
    {
        return [
            'id' => $eventId,
            'type' => 'customer.subscription.created',
            'created' => 1_789_500_000,
            'data' => ['object' => [
                'id' => 'sub_private',
                'customer' => $customerId,
                'status' => 'trialing',
                'trial_end' => 1_789_500_000 + 604800,
            ]],
        ];
    }

    private function invoicePayload(string $eventId, string $customerId, int $amountPaid): array
    {
        return [
            'id' => $eventId,
            'type' => 'invoice.payment_succeeded',
            'created' => 1_789_500_000,
            'data' => ['object' => [
                'id' => 'in_private',
                'customer' => $customerId,
                'subscription' => 'sub_private',
                'status' => 'paid',
                'amount_paid' => $amountPaid,
                'currency' => 'usd',
            ]],
        ];
    }
}
