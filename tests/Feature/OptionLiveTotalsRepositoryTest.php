<?php

namespace Tests\Feature;

use App\Support\OptionLiveTotalsRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class OptionLiveTotalsRepositoryTest extends TestCase
{
    private OptionLiveTotalsRepository $totals;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('option_live_counters')) {
            Schema::create('option_live_counters', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol', 12)->index();
                $table->date('trade_date')->index();
                $table->string('exp_date', 10)->nullable();
                $table->decimal('strike', 12, 4)->nullable();
                $table->string('option_type')->nullable();
                $table->bigInteger('volume')->default(0);
                $table->decimal('premium_usd', 18, 4)->nullable();
                $table->timestamp('asof')->nullable();
                $table->timestamps();
                $table->unique(
                    ['symbol', 'trade_date', 'exp_date', 'strike', 'option_type'],
                    'olc_test_nullable_unique'
                );
            });
        }

        if (! Schema::hasTable('option_live_totals')) {
            $migration = require database_path(
                'migrations/2026_08_16_000004_create_option_live_totals_table.php'
            );
            $migration->up();
        }

        DB::table('option_live_totals')->delete();
        DB::table('option_live_counters')->delete();
        config()->set('option_live_totals.dual_write', false);
        config()->set('option_live_totals.compare_writes', false);
        config()->set('option_live_totals.read_from_canonical', false);

        $this->totals = new OptionLiveTotalsRepository;
    }

    public function test_atomic_store_keeps_exactly_one_freshest_canonical_total(): void
    {
        $this->store([
            'call_volume' => 10,
            'put_volume' => 20,
            'premium_usd' => 100.25,
            'asof' => null,
            'source_updated_at' => '2026-08-16 15:00:00.000001',
            'source_row_id' => 1,
        ]);
        $this->store([
            'call_volume' => 30,
            'put_volume' => 40,
            'premium_usd' => 200.50,
            'asof' => '2026-08-16 14:00:00',
            'source_updated_at' => '2026-08-16 14:01:00',
            'source_row_id' => 2,
        ]);

        // A newer ingestion timestamp cannot make an older source event win.
        $this->store([
            'call_volume' => 900,
            'put_volume' => 900,
            'premium_usd' => 999.99,
            'asof' => '2026-08-16 13:59:59',
            'source_updated_at' => '2026-08-16 20:00:00',
            'source_row_id' => 3,
        ]);

        // For the same source as-of, updated_at and then source id break ties.
        $this->store([
            'call_volume' => 35,
            'put_volume' => 45,
            'premium_usd' => 250,
            'asof' => '2026-08-16 14:00:00',
            'source_updated_at' => '2026-08-16 14:02:00',
            'source_row_id' => 4,
        ]);
        $this->store([
            'call_volume' => 36,
            'put_volume' => 46,
            'premium_usd' => 260,
            'asof' => '2026-08-16 14:00:00',
            'source_updated_at' => '2026-08-16 14:02:00',
            'source_row_id' => 5,
        ]);

        $row = $this->totals->canonicalTotal('SPY', '2026-08-16');

        $this->assertSame(1, DB::table('option_live_totals')->count());
        $this->assertSame(36, $row['call_volume']);
        $this->assertSame(46, $row['put_volume']);
        $this->assertSame(82, $row['volume']);
        $this->assertSame('260.0000', $row['premium_usd']);
        $this->assertSame(5, $row['source_row_id']);
        $this->assertSame(64, strlen($row['freshness_key']));
    }

    public function test_equal_freshness_retry_is_idempotent(): void
    {
        $source = [
            'asof' => '2026-08-16 14:00:00',
            'source_updated_at' => '2026-08-16 14:01:00',
            'source_row_id' => 10,
        ];
        $this->store([
            ...$source,
            'call_volume' => 10,
            'put_volume' => 20,
            'premium_usd' => 100,
        ]);
        $this->store([
            ...$source,
            'call_volume' => 999,
            'put_volume' => 999,
            'premium_usd' => 999,
        ]);

        $row = $this->totals->canonicalTotal('SPY', '2026-08-16');

        $this->assertSame(1, DB::table('option_live_totals')->count());
        $this->assertSame(10, $row['call_volume']);
        $this->assertSame(20, $row['put_volume']);
        $this->assertSame('100.0000', $row['premium_usd']);
    }

    public function test_mysql_preserves_large_decimal_premium_without_float_conversion(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Exact DECIMAL persistence is a MySQL storage contract.');
        }

        $premium = '99999999999999.9999';
        $this->store([
            'call_volume' => 10,
            'put_volume' => 20,
            'premium_usd' => $premium,
            'asof' => '2026-08-16 14:00:00',
            'source_updated_at' => '2026-08-16 14:01:00',
            'source_row_id' => 20,
        ]);

        $this->assertSame(
            $premium,
            $this->totals->canonicalTotal('SPY', '2026-08-16')['premium_usd']
        );
    }

    public function test_legacy_selector_and_backfill_choose_the_freshest_authoritative_total(): void
    {
        $this->insertComponent('call', 120, 500, '2026-08-16 15:00:00');
        $this->insertComponent('put', 80, 490, '2026-08-16 15:00:00');

        $this->insertLegacyTotal(100, '1000.00', '2026-08-16 14:00:00', '2026-08-16 15:00:00');
        $this->insertLegacyTotal(999, '9999.00', null, '2026-08-16 20:00:00');
        $this->insertLegacyTotal(200, '2000.00', '2026-08-16 14:01:00', '2026-08-16 15:00:00');
        $this->insertLegacyTotal(300, '3000.00', '2026-08-16 14:01:00', '2026-08-16 15:01:00');
        $freshestId = $this->insertLegacyTotal(
            400,
            '4000.00',
            '2026-08-16 14:01:00',
            '2026-08-16 15:01:00'
        );

        $legacy = $this->totals->legacyTotal(' spy ', '2026-08-16');
        $backfilled = $this->totals->backfillOne('SPY', '2026-08-16');
        $comparison = $this->totals->compare('SPY', '2026-08-16');

        $this->assertSame(120, $legacy['call_volume']);
        $this->assertSame(80, $legacy['put_volume']);
        $this->assertSame(400, $legacy['volume']);
        $this->assertSame('4000.0000', $legacy['premium_usd']);
        $this->assertSame($freshestId, $legacy['source_row_id']);
        $this->assertSame($legacy['source_row_id'], $backfilled['source_row_id']);
        $this->assertSame($legacy['call_volume'], $backfilled['call_volume']);
        $this->assertSame($legacy['put_volume'], $backfilled['put_volume']);
        $this->assertTrue($comparison['matches']);
        $this->assertSame([], $comparison['differences']);
    }

    public function test_publish_and_flagged_read_roll_out_without_changing_safe_defaults(): void
    {
        $this->insertComponent('call', 70, 500, '2026-08-16 15:00:00', 'QQQ');
        $this->insertComponent('put', 30, 490, '2026-08-16 15:00:00', 'QQQ');

        $legacyOnly = $this->totals->publish([
            'symbol' => 'QQQ',
            'trade_date' => '2026-08-16',
            'call_volume' => 70,
            'put_volume' => 30,
            'premium_usd' => 1000,
            'asof' => '2026-08-16 15:00:00',
            'source_updated_at' => '2026-08-16 15:01:00',
        ]);

        $this->assertNull($legacyOnly['canonical']);
        $this->assertSame(0, DB::table('option_live_totals')->count());
        $this->assertNull($this->totals->read('QQQ', '2026-08-16')['freshness_key']);

        config()->set('option_live_totals.dual_write', true);
        config()->set('option_live_totals.compare_writes', true);
        $dual = $this->totals->publish([
            'symbol' => 'QQQ',
            'trade_date' => '2026-08-16',
            'call_volume' => 70,
            'put_volume' => 30,
            'premium_usd' => 1100,
            'asof' => '2026-08-16 15:01:00',
            'source_updated_at' => '2026-08-16 15:02:00',
        ]);

        $this->assertNotNull($dual['canonical']);
        $this->assertTrue($dual['comparison']['matches']);
        $this->assertNull($this->totals->read('QQQ', '2026-08-16')['freshness_key']);

        config()->set('option_live_totals.read_from_canonical', true);
        $this->assertNotNull($this->totals->read('QQQ', '2026-08-16')['freshness_key']);
    }

    public function test_stale_publish_does_not_relabel_stale_components_as_fresh_canonical_data(): void
    {
        config()->set('option_live_totals.dual_write', true);
        $this->totals->publish([
            'symbol' => 'SPY',
            'trade_date' => '2026-08-16',
            'call_volume' => 100,
            'put_volume' => 50,
            'premium_usd' => 5000,
            'asof' => '2026-08-16 15:00:00',
            'source_updated_at' => '2026-08-16 15:01:00',
        ]);

        $stale = $this->totals->publish([
            'symbol' => 'SPY',
            'trade_date' => '2026-08-16',
            'call_volume' => 900,
            'put_volume' => 900,
            'premium_usd' => 9999,
            'asof' => '2026-08-16 14:59:59',
            'source_updated_at' => '2026-08-16 16:00:00',
        ]);

        $canonical = $this->totals->canonicalTotal('SPY', '2026-08-16');
        $this->assertSame(100, $canonical['call_volume']);
        $this->assertSame(50, $canonical['put_volume']);
        $this->assertSame('5000.0000', $canonical['premium_usd']);
        $this->assertEquals($canonical, $stale['canonical']);
        $this->assertSame(1, DB::table('option_live_totals')->count());
    }

    public function test_same_second_publish_is_first_writer_wins_at_legacy_precision(): void
    {
        config()->set('option_live_totals.dual_write', true);
        config()->set('option_live_totals.compare_writes', true);
        $this->insertComponent('call', 100, 500, '2026-08-16 15:00:00');
        $this->insertComponent('put', 50, 490, '2026-08-16 15:00:00');
        $scope = [
            'symbol' => 'SPY',
            'trade_date' => '2026-08-16',
        ];

        $this->totals->publish([
            ...$scope,
            'call_volume' => 100,
            'put_volume' => 50,
            'premium_usd' => 5000,
            'asof' => '2026-08-16 15:00:00.200000',
            'source_updated_at' => '2026-08-16 15:01:00.200000',
        ]);
        $retry = $this->totals->publish([
            ...$scope,
            'call_volume' => 900,
            'put_volume' => 900,
            'premium_usd' => 9999,
            'asof' => '2026-08-16 15:00:00.300000',
            'source_updated_at' => '2026-08-16 15:01:00.300000',
        ]);

        $this->assertSame(100, $retry['legacy']['call_volume']);
        $this->assertSame(50, $retry['legacy']['put_volume']);
        $this->assertSame('5000.0000', $retry['legacy']['premium_usd']);
        $this->assertSame(100, $retry['canonical']['call_volume']);
        $this->assertSame(50, $retry['canonical']['put_volume']);
        $this->assertSame('5000.0000', $retry['canonical']['premium_usd']);
        $this->assertSame(0, $retry['canonical']['asof']->micro);
        $this->assertSame(0, $retry['canonical']['source_updated_at']->micro);
        $this->assertTrue($retry['comparison']['matches']);
    }

    public function test_trade_date_validation_rejects_calendar_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->totals->canonicalTotal('SPY', '2026-02-31');
    }

    /** @param array<string, mixed> $overrides */
    private function store(array $overrides): void
    {
        $this->totals->store([
            'symbol' => 'SPY',
            'trade_date' => '2026-08-16',
            ...$overrides,
        ]);
    }

    private function insertLegacyTotal(
        int $volume,
        string $premium,
        ?string $asof,
        string $updatedAt,
        string $symbol = 'SPY'
    ): int {
        return (int) DB::table('option_live_counters')->insertGetId([
            'symbol' => $symbol,
            'trade_date' => '2026-08-16',
            'exp_date' => null,
            'strike' => null,
            'option_type' => null,
            'volume' => $volume,
            'premium_usd' => $premium,
            'asof' => $asof,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function insertComponent(
        string $type,
        int $volume,
        int $strike,
        string $asof,
        string $symbol = 'SPY'
    ): void {
        DB::table('option_live_counters')->insert([
            'symbol' => $symbol,
            'trade_date' => '2026-08-16',
            'exp_date' => '2026-08-21',
            'strike' => $strike,
            'option_type' => $type,
            'volume' => $volume,
            'premium_usd' => null,
            'asof' => $asof,
            'created_at' => $asof,
            'updated_at' => $asof,
        ]);
    }
}
