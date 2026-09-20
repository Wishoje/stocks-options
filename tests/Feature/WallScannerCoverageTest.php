<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WallScannerCoverageTest extends TestCase
{
    private const CONNECTION = 'wall-scanner-contract-test';

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
        Carbon::setTestNow(Carbon::parse('2026-09-16 17:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalDatabaseConnection);
        parent::tearDown();
    }

    public function test_scan_reports_coverage_for_matches_stale_invalid_missing_wall_and_unmatched_rows(): void
    {
        $this->wallRow('SPY', '2026-09-16', '1d', 500, 490, 501);
        $this->wallRow('SPY', '2026-09-14', '7d', 500, 499, 501);
        $this->wallRow('QQQ', '2026-09-16', '1d', 0, 490, 501);
        $this->wallRow('IWM', '2026-09-16', '1d', 200, null, null);
        $this->wallRow('AAPL', '2026-09-16', '1d', 250, 200, 300);

        Sanctum::actingAs($this->createEntitledUser());
        $response = $this->postJson('/api/scanner/walls', [
            'symbols' => ['SPY', 'QQQ', 'IWM', 'AAPL'],
            'timeframes' => ['1d', '7d'],
            'near_pct' => 1,
            'near_pts' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('near_pct', 1)
            ->assertJsonPath('near_pts', 2)
            ->assertJsonPath('applied.near_pct', 1)
            ->assertJsonPath('applied.near_pts', 2)
            ->assertJsonPath('coverage.requested_symbols', 4)
            ->assertJsonPath('coverage.requested_timeframes', 2)
            ->assertJsonPath('coverage.requested_pairs', 8)
            ->assertJsonPath('coverage.latest_rows', 5)
            ->assertJsonPath('coverage.stale_rows', 1)
            ->assertJsonPath('coverage.invalid_rows', 1)
            ->assertJsonPath('coverage.no_wall_rows', 1)
            ->assertJsonPath('coverage.invalid_or_no_wall_rows', 2)
            ->assertJsonPath('coverage.usable_rows', 2)
            ->assertJsonPath('coverage.unmatched_usable_rows', 1)
            ->assertJsonPath('coverage.matched_rows', 1)
            ->assertJsonPath('items.0.symbol', 'SPY')
            ->assertJsonPath('items.0.trade_date', '2026-09-16')
            ->assertJsonPath('items.0.walls.eod_call.distance_pt', 1)
            ->assertJsonPath('items.0.walls.eod_call.distance_pc', 0.2);

        $this->assertSame(['SPY'], collect($response->json('items'))->pluck('symbol')->all());
        $this->assertArrayHasKey('1d', $response->json('by_timeframe'));
    }

    public function test_empty_snapshot_response_keeps_stable_applied_and_coverage_shape(): void
    {
        Sanctum::actingAs($this->createEntitledUser());
        $this->postJson('/api/scanner/walls', [
            'symbols' => ['DIA'],
            'timeframes' => ['1d', '30d'],
            'near_pct' => 0,
            'near_pts' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('applied.near_pct', 0)
            ->assertJsonPath('applied.near_pts', 0)
            ->assertJsonPath('coverage.requested_pairs', 2)
            ->assertJsonPath('coverage.latest_rows', 0)
            ->assertJsonPath('coverage.usable_rows', 0)
            ->assertJsonPath('coverage.matched_rows', 0)
            ->assertJsonPath('items', [])
            ->assertJsonPath('by_timeframe', []);
    }

    public function test_wall_scan_validates_thresholds_and_symbol_batch_size(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $this->postJson('/api/scanner/walls', [
            'symbols' => ['SPY'],
            'near_pct' => 101,
        ])->assertUnprocessable();

        $this->postJson('/api/scanner/walls', [
            'symbols' => ['SPY'],
            'near_pts' => -1,
        ])->assertUnprocessable();

        $this->postJson('/api/scanner/walls', [
            'symbols' => array_map(fn (int $index) => 'S'.$index, range(0, 500)),
        ])->assertUnprocessable();

        $this->postJson('/api/scanner/walls', [
            'symbols' => ['SPY'],
            'timeframe' => 'invalid',
        ])->assertUnprocessable();

        $this->postJson('/api/scanner/walls', [
            'symbols' => ['SPY'],
            'timeframes' => ['1d', 'invalid'],
        ])->assertUnprocessable();
    }

    private function createEntitledUser(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Wall scanner test user',
            'email' => 'wall-scanner-'.uniqid('', true).'@example.test',
            'password' => 'unused-test-password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->findOrFail($id);
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

    private function wallRow(
        string $symbol,
        string $tradeDate,
        string $timeframe,
        float $spot,
        ?float $putWall,
        ?float $callWall,
    ): void {
        DB::table('symbol_wall_snapshots')->insert([
            'symbol' => $symbol,
            'trade_date' => $tradeDate,
            'timeframe' => $timeframe,
            'spot' => $spot,
            'eod_put_wall' => $putWall,
            'eod_call_wall' => $callWall,
            'eod_put_dist_pct' => $putWall === null || $spot <= 0 ? null : abs($spot - $putWall) / $spot * 100,
            'eod_call_dist_pct' => $callWall === null || $spot <= 0 ? null : abs($spot - $callWall) / $spot * 100,
            'intraday_put_wall' => null,
            'intraday_call_wall' => null,
            'intraday_put_dist_pct' => null,
            'intraday_call_dist_pct' => null,
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
        $schema->create('symbol_wall_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16);
            $table->date('trade_date');
            $table->string('timeframe', 16);
            $table->decimal('spot', 12, 4)->nullable();
            $table->decimal('eod_put_wall', 12, 4)->nullable();
            $table->decimal('eod_call_wall', 12, 4)->nullable();
            $table->decimal('eod_put_dist_pct', 8, 4)->nullable();
            $table->decimal('eod_call_dist_pct', 8, 4)->nullable();
            $table->decimal('intraday_put_wall', 12, 4)->nullable();
            $table->decimal('intraday_call_wall', 12, 4)->nullable();
            $table->decimal('intraday_put_dist_pct', 8, 4)->nullable();
            $table->decimal('intraday_call_dist_pct', 8, 4)->nullable();
            $table->timestamps();
        });
    }
}
