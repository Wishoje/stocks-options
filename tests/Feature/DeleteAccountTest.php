<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Subscription;
use Laravel\Jetstream\Features;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    private const CONNECTION = 'delete-account-test';

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

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        (require database_path('migrations/2024_12_12_010149_create_personal_access_tokens_table.php'))->up();
        (require database_path('migrations/2025_12_23_063737_create_subscriptions_table.php'))->up();
        (require database_path('migrations/2025_12_23_063738_create_subscription_items_table.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalDatabaseConnection);

        parent::tearDown();
    }

    public function test_user_accounts_can_be_deleted(): void
    {
        if (! Features::hasAccountDeletionFeatures()) {
            $this->markTestSkipped('Account deletion is not enabled.');
        }

        $this->actingAs($user = User::factory()->create());

        $this->delete('/user', [
            'password' => 'password',
        ]);

        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_before_account_can_be_deleted(): void
    {
        if (! Features::hasAccountDeletionFeatures()) {
            $this->markTestSkipped('Account deletion is not enabled.');
        }

        $this->actingAs($user = User::factory()->create());

        $this->delete('/user', [
            'password' => 'wrong-password',
        ]);

        $this->assertNotNull($user->fresh());
    }

    #[DataProvider('nonTerminalSubscriptionProvider')]
    public function test_every_non_terminal_local_subscription_blocks_account_deletion(
        string $status,
        string $endTiming,
    ): void {
        $this->actingAs($user = User::factory()->create());
        $this->createLocalSubscription(
            $user,
            $status,
            match ($endTiming) {
                'future' => now()->addDay(),
                'past' => now()->subMinute(),
                default => null,
            },
        );

        $response = $this->from('/user/profile')->delete('/user', [
            'password' => 'password',
        ]);

        $response->assertRedirect('/user/profile');
        $response->assertSessionHasErrors([
            'account' => 'A billing record attached to this account has not reached a verified terminal state. Resolve it in Billing and wait for the saved end date before deleting your account.',
        ]);
        $this->assertNotNull($user->fresh());
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'stripe_status' => $status,
        ]);
    }

    public static function nonTerminalSubscriptionProvider(): array
    {
        return [
            'active' => ['active', 'none'],
            'trialing' => ['trialing', 'none'],
            'past due' => ['past_due', 'none'],
            'incomplete' => ['incomplete', 'none'],
            'paused' => ['paused', 'none'],
            'unpaid' => ['unpaid', 'none'],
            'incomplete expired without a saved end' => ['incomplete_expired', 'none'],
            'canceled without a saved end' => ['canceled', 'none'],
            'unknown provider state' => ['provider_future_state', 'none'],
            'grace period' => ['active', 'future'],
            'paused with a future end date' => ['paused', 'future'],
            'active with a past end date' => ['active', 'past'],
            'unknown provider state with a past end date' => ['provider_future_state', 'past'],
        ];
    }

    public function test_only_ended_local_rows_pass_and_subscription_dependents_are_removed_transactionally(): void
    {
        $this->actingAs($user = User::factory()->create());
        config()->set('jetstream.features', [
            ...config('jetstream.features', []),
            Features::profilePhotos(),
        ]);
        Storage::fake('public');
        $photoPath = 'profile-photos/deleted-account.jpg';
        Storage::disk('public')->put($photoPath, 'photo');
        $user->forceFill(['profile_photo_path' => $photoPath])->save();
        $canceled = $this->createLocalSubscription(
            $user,
            'canceled',
            now()->subMinute(),
            'canceled',
        );
        $expired = $this->createLocalSubscription(
            $user,
            'incomplete_expired',
            now()->subMinute(),
            'expired',
        );

        foreach ([$canceled, $expired] as $subscription) {
            DB::table('subscription_items')->insert([
                'subscription_id' => $subscription->id,
                'stripe_id' => 'si_'.$subscription->id,
                'stripe_product' => 'prod_test',
                'stripe_price' => 'price_test',
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->delete('/user', [
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('subscription_items', 0);
        Storage::disk('public')->assertMissing($photoPath);
    }

    public function test_subscription_state_is_evaluated_from_one_loaded_snapshot(): void
    {
        $this->actingAs($user = User::factory()->create());
        $this->createLocalSubscription($user, 'active');
        $subscriptionReads = [];

        DB::listen(function (QueryExecuted $query) use (&$subscriptionReads): void {
            $sql = strtolower($query->sql);

            if (
                $query->connection->transactionLevel() > 0
                && str_starts_with($sql, 'select')
                && str_contains($sql, 'from "subscriptions"')
            ) {
                $subscriptionReads[] = $sql;
            }
        });

        $this->from('/user/profile')->delete('/user', [
            'password' => 'password',
        ])->assertSessionHasErrors('account');

        $this->assertCount(1, $subscriptionReads, implode(PHP_EOL, $subscriptionReads));
        $this->assertStringStartsWith('select *', $subscriptionReads[0]);
        $this->assertNotNull($user->fresh());
    }

    public function test_a_subscription_that_appears_after_the_snapshot_rolls_the_deletion_back(): void
    {
        $this->actingAs($user = User::factory()->create());
        config()->set('jetstream.features', [
            ...config('jetstream.features', []),
            Features::profilePhotos(),
        ]);
        Storage::fake('public');
        $photoPath = 'profile-photos/surviving-account.jpg';
        Storage::disk('public')->put($photoPath, 'photo');
        $user->forceFill(['profile_photo_path' => $photoPath])->save();
        $this->createLocalSubscription(
            $user,
            'canceled',
            now()->subMinute(),
            'terminal-before-race',
        );
        $injected = false;

        DB::listen(function (QueryExecuted $query) use (&$injected, $user): void {
            $sql = strtolower($query->sql);

            if (! $injected && str_starts_with($sql, 'delete from "subscriptions"')) {
                $injected = true;
                $query->connection->table('subscriptions')->insert([
                    'user_id' => $user->id,
                    'type' => config('plans.default_subscription_name', 'default'),
                    'stripe_id' => 'sub_inserted_after_snapshot_'.$user->id,
                    'stripe_status' => 'active',
                    'stripe_price' => 'price_test',
                    'quantity' => 1,
                    'trial_ends_at' => null,
                    'ends_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $response = $this->from('/user/profile')->delete('/user', [
            'password' => 'password',
        ]);

        $response->assertRedirect('/user/profile');
        $response->assertSessionHasErrors('account');
        $this->assertTrue($injected);
        $this->assertNotNull($user->fresh());
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'stripe_id' => 'sub_canceled_'.$user->id.'_terminal-before-race',
        ]);
        $this->assertDatabaseMissing('subscriptions', [
            'stripe_id' => 'sub_inserted_after_snapshot_'.$user->id,
        ]);
        Storage::disk('public')->assertExists($photoPath);
    }

    public function test_account_deletion_removes_its_database_sessions_and_password_reset_token_only(): void
    {
        $this->actingAs($user = User::factory()->create());
        $otherUser = User::factory()->create();

        DB::table('sessions')->insert([
            [
                'id' => 'deleted-user-session',
                'user_id' => $user->id,
                'ip_address' => '203.0.113.8',
                'user_agent' => 'Test browser',
                'payload' => 'test-payload',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'other-user-session',
                'user_id' => $otherUser->id,
                'ip_address' => '203.0.113.9',
                'user_agent' => 'Other browser',
                'payload' => 'other-payload',
                'last_activity' => now()->timestamp,
            ],
        ]);

        DB::table('password_reset_tokens')->insert([
            [
                'email' => $user->email,
                'token' => 'deleted-user-token',
                'created_at' => now(),
            ],
            [
                'email' => $otherUser->email,
                'token' => 'other-user-token',
                'created_at' => now(),
            ],
        ]);

        $this->delete('/user', [
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertDatabaseMissing('sessions', ['id' => 'deleted-user-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $otherUser->email]);
    }

    private function createLocalSubscription(
        User $user,
        string $status,
        ?CarbonInterface $endsAt = null,
        string $suffix = 'only',
    ): Subscription {
        return $user->subscriptions()->create([
            'type' => config('plans.default_subscription_name', 'default'),
            'stripe_id' => 'sub_'.strtolower($status).'_'.$user->id.'_'.$suffix,
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'trial_ends_at' => $status === 'trialing' ? now()->addDays(7) : null,
            'ends_at' => $endsAt,
        ]);
    }
}
