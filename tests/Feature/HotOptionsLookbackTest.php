<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HotOptionsLookbackTest extends TestCase
{
    private const CONNECTION = 'hot-options-contract-test';

    private string $originalDatabaseConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDatabaseConnection = DB::getDefaultConnection();
        config()->set('database.connections.'.self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);
        $this->createContractTables();
        $this->app->register(\Laravel\Sanctum\SanctumServiceProvider::class);

        config()->set('plans.default_subscription_name', 'default');
        config()->set('plans.plans', [
            'scanner-test' => [
                'prices' => ['monthly' => 'price_scanner_test'],
                'features' => ['scanner.access'],
            ],
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalDatabaseConnection);

        parent::tearDown();
    }

    public function test_endpoint_requires_authentication_and_scanner_entitlement(): void
    {
        $this->getJson('/api/hot-options')->assertUnauthorized();

        Sanctum::actingAs($this->createUser());
        $this
            ->getJson('/api/hot-options')
            ->assertForbidden();
    }

    public function test_stored_snapshot_remains_authoritative_and_exposes_its_source_window(): void
    {
        $localExpiration = $this->expiration('LOCAL');
        $this->chainRow($localExpiration, '2026-09-11', 'call', 999_999, 999_999, 123.00);

        $this->hotRow('SPY', 1, 'polygon_eod', [
            'window_start' => '2026-09-02',
            'window_end' => '2026-09-11',
        ], 1_200, 0.75, 500.00);
        $this->hotRow('QQQ', 2, 'polygon_eod', [
            'window_start' => '2026-09-02',
            'window_end' => '2026-09-11',
        ], 900, 1.25, 480.00);

        $user = $this->createEntitledUser();

        Sanctum::actingAs($user);
        $fiveDay = $this->getJson('/api/hot-options?limit=1&days=5')
            ->assertOk()
            ->assertJsonPath('source', 'hot_option_symbols')
            ->assertJsonPath('items.0.symbol', 'SPY')
            ->assertJsonPath('meta.available_count', 2)
            ->assertJsonPath('meta.source', 'polygon_eod')
            ->assertJsonPath('meta.window_start', '2026-09-02')
            ->assertJsonPath('meta.window_end', '2026-09-11')
            ->assertJsonPath('meta.effective_days', 10)
            ->assertJsonPath('meta.requested_days', 5)
            ->assertJsonPath('meta.lookback_control_applied', false)
            ->assertJsonPath('meta.scope', 'stored_snapshot');

        $twentyDay = $this->getJson('/api/hot-options?limit=2&days=20')
            ->assertOk()
            ->assertJsonPath('meta.requested_days', 20)
            ->assertJsonPath('meta.lookback_control_applied', false);

        $this->assertSame(['SPY'], $fiveDay->json('symbols'));
        $this->assertSame(['SPY', 'QQQ'], $twentyDay->json('symbols'));
        $this->assertNotContains('LOCAL', $twentyDay->json('symbols'));
    }

    public function test_steadyapi_snapshot_is_labeled_as_source_defined_session_data(): void
    {
        $this->hotRow('IWM', 1, 'steadyapi', ['vendor_session' => 'current'], 0, 0.0, 0.0);

        Sanctum::actingAs($this->createEntitledUser());
        $this->getJson('/api/hot-options?limit=100&days=10')
            ->assertOk()
            ->assertJsonPath('items.0.symbol', 'IWM')
            ->assertJsonPath('items.0.total_volume', 0)
            ->assertJsonPath('items.0.put_call', 0)
            ->assertJsonPath('items.0.last_price', 0)
            ->assertJsonPath('meta.window_start', null)
            ->assertJsonPath('meta.effective_days', null)
            ->assertJsonPath('meta.lookback_control_applied', false)
            ->assertJsonPath('meta.scope', 'source_defined_session');
    }

    public function test_database_fallback_applies_allowed_lookback_and_caches_canonical_top_500_before_slicing(): void
    {
        $spyExpiration = $this->expiration('SPY');
        $aaplExpiration = $this->expiration('AAPL');
        $this->chainRow($spyExpiration, '2026-09-11', 'call', 10, 5, 500.00);
        $this->chainRow($spyExpiration, '2026-09-11', 'put', 5, 5, 500.00);
        $this->chainRow($aaplExpiration, '2026-09-01', 'call', 100, 100, 250.00);
        $this->chainRow($aaplExpiration, '2026-09-01', 'put', 100, 100, 250.00);

        $user = $this->createEntitledUser();

        Sanctum::actingAs($user);
        $first = $this->getJson('/api/hot-options?limit=1&days=20')
            ->assertOk()
            ->assertJsonPath('source', 'fallback_db')
            ->assertJsonPath('meta.available_count', 2)
            ->assertJsonPath('meta.lookback_control_applied', true)
            ->assertJsonPath('meta.scope', 'local_chain_fallback')
            ->assertJsonPath('meta.window_start', '2026-08-23')
            ->assertJsonPath('items.0.symbol', 'AAPL');

        $second = $this->getJson('/api/hot-options?limit=100&days=20')
            ->assertOk();

        $this->assertCount(1, $first->json('items'));
        $this->assertSame(['AAPL', 'SPY'], $second->json('symbols'));

        $fiveDay = $this->getJson('/api/hot-options?limit=100&days=5')
            ->assertOk()
            ->assertJsonPath('meta.window_start', '2026-09-07')
            ->assertJsonPath('items.0.symbol', 'SPY');

        $this->assertCount(1, $fiveDay->json('items'));
    }

    public function test_query_contract_rejects_unbounded_or_ambiguous_requests(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $this->getJson('/api/hot-options?days=6')->assertUnprocessable();
        $this->getJson('/api/hot-options?limit=501')->assertUnprocessable();
        $this->getJson('/api/hot-options?date=2026-09-11&days=10')->assertUnprocessable();
    }

    private function createUser(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Scanner test user',
            'email' => 'scanner-'.uniqid('', true).'@example.test',
            'password' => 'unused-test-password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function createEntitledUser(): User
    {
        $user = $this->createUser();
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid('', true),
            'stripe_status' => 'active',
            'stripe_price' => 'price_scanner_test',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscription_items')->insert([
            'subscription_id' => $subscriptionId,
            'stripe_id' => 'si_'.uniqid('', true),
            'stripe_product' => 'prod_scanner_test',
            'stripe_price' => 'price_scanner_test',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    private function hotRow(string $symbol, int $rank, string $source, array $payload, int $volume, float $putCall, float $lastPrice): void
    {
        DB::table('hot_option_symbols')->insert([
            'trade_date' => '2026-09-11',
            'symbol' => $symbol,
            'rank' => $rank,
            'total_volume' => $volume,
            'call_volume' => 0,
            'put_volume' => 0,
            'put_call_ratio' => $putCall,
            'last_price' => $lastPrice,
            'source' => $source,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function expiration(string $symbol): int
    {
        return DB::table('option_expirations')->insertGetId([
            'symbol' => $symbol,
            'expiration_date' => '2026-10-16',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function chainRow(int $expirationId, string $date, string $type, int $volume, int $openInterest, float $spot): void
    {
        DB::table('option_chain_data')->insert([
            'expiration_id' => $expirationId,
            'data_date' => $date,
            'option_type' => $type,
            'strike' => 100,
            'open_interest' => $openInterest,
            'volume' => $volume,
            'gamma' => null,
            'delta' => null,
            'iv' => null,
            'underlying_price' => $spot,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createContractTables(): void
    {
        $schema = Schema::connection(self::CONNECTION);
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('trial_ends_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        $schema->create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
        $schema->create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();
        });
        $schema->create('hot_option_symbols', function (Blueprint $table): void {
            $table->id();
            $table->date('trade_date');
            $table->string('symbol', 16);
            $table->unsignedInteger('rank');
            $table->unsignedBigInteger('total_volume')->nullable();
            $table->unsignedBigInteger('call_volume')->nullable();
            $table->unsignedBigInteger('put_volume')->nullable();
            $table->decimal('put_call_ratio', 8, 4)->nullable();
            $table->decimal('last_price', 16, 4)->nullable();
            $table->string('source', 32);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
        $schema->create('option_expirations', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16);
            $table->date('expiration_date');
            $table->timestamps();
        });
        $schema->create('option_chain_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('expiration_id');
            $table->date('data_date');
            $table->string('option_type', 8);
            $table->decimal('strike', 12, 4);
            $table->bigInteger('open_interest')->nullable();
            $table->bigInteger('volume')->nullable();
            $table->decimal('gamma', 18, 8)->nullable();
            $table->decimal('delta', 18, 8)->nullable();
            $table->decimal('iv', 18, 8)->nullable();
            $table->decimal('underlying_price', 16, 4)->nullable();
            $table->timestamps();
        });
    }
}
