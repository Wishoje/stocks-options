<?php

namespace Tests\Feature;

use App\Support\OptionLiveTotalsRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OptionLiveTotalsCommandsTest extends TestCase
{
    /** @var list<string> */
    private array $symbols = ['G12CMDA', 'G12CMDB', 'G12PRUNEOLD', 'G12PRUNENEW'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('option_live_counters')) {
            $migration = require database_path(
                'migrations/2025_10_29_075045_create_option_live_counters_table.php'
            );
            $migration->up();
        }
        if (! Schema::hasTable('option_live_totals')) {
            $migration = require database_path(
                'migrations/2026_08_16_000004_create_option_live_totals_table.php'
            );
            $migration->up();
        }

        $this->clearRows();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->clearRows();

        parent::tearDown();
    }

    public function test_backfill_is_chunked_idempotent_and_comparison_reports_differences(): void
    {
        $freshId = $this->insertLegacyKey('G12CMDA', '2026-08-15', 70, 30, 100, '12.3400');
        $this->insertLegacyKey('G12CMDB', '2026-08-15', 45, 55, 100, '20.0000');

        $arguments = [
            '--from' => '2026-08-15',
            '--to' => '2026-08-15',
            '--symbols' => 'g12cmda,g12cmdb',
            '--chunk' => 1,
        ];

        $this->assertSame(0, Artisan::call('intraday:backfill-live-totals', $arguments));
        $this->assertStringContainsString('keys_scanned=2', Artisan::output());
        $this->assertSame(2, DB::table('option_live_totals')->whereIn('symbol', ['G12CMDA', 'G12CMDB'])->count());

        $canonical = DB::table('option_live_totals')->where('symbol', 'G12CMDA')->first();
        $this->assertNotNull($canonical);
        $this->assertSame($freshId, (int) $canonical->source_row_id);
        $this->assertSame(70, (int) $canonical->call_volume);
        $this->assertSame(30, (int) $canonical->put_volume);
        $this->assertSame(100, (int) $canonical->volume);

        $this->assertSame(0, Artisan::call('intraday:backfill-live-totals', $arguments));
        $this->assertSame(2, DB::table('option_live_totals')->whereIn('symbol', ['G12CMDA', 'G12CMDB'])->count());

        $this->assertSame(0, Artisan::call('intraday:compare-live-totals', $arguments));
        $this->assertStringContainsString('matched=2, mismatched=0', Artisan::output());

        DB::table('option_live_totals')->where('symbol', 'G12CMDB')->update(['volume' => 999]);

        $this->assertSame(1, Artisan::call('intraday:compare-live-totals', $arguments));
        $output = Artisan::output();
        $this->assertStringContainsString('G12CMDB/2026-08-15 differs', $output);
        $this->assertStringContainsString('matched=1, mismatched=1', $output);
    }

    public function test_comparison_checks_canonical_only_keys(): void
    {
        DB::table('option_live_totals')->insert([
            'symbol' => 'G12CMDA',
            'trade_date' => '2026-08-15',
            'call_volume' => 1,
            'put_volume' => 2,
            'volume' => 3,
            'premium_usd' => '4.0000',
            'asof' => '2026-08-15 20:00:00',
            'source_updated_at' => '2026-08-15 20:00:01',
            'source_row_id' => 1,
            'freshness_key' => '1:20260815200000000000:20260815200001000000:00000000000000000001',
            'created_at' => '2026-08-15 20:00:02',
            'updated_at' => '2026-08-15 20:00:02',
        ]);

        $this->assertSame(1, Artisan::call('intraday:compare-live-totals', [
            '--from' => '2026-08-15',
            '--to' => '2026-08-15',
            '--symbols' => 'G12CMDA',
            '--chunk' => 1,
        ]));
        $this->assertStringContainsString('canonical_only=1', Artisan::output());
    }

    public function test_backfill_cannot_regress_a_fresher_canonical_total(): void
    {
        $this->insertLegacyKey('G12CMDA', '2026-08-15', 70, 30, 100, '12.3400');
        app(OptionLiveTotalsRepository::class)->store([
            'symbol' => 'G12CMDA',
            'trade_date' => '2026-08-15',
            'call_volume' => 700,
            'put_volume' => 300,
            'volume' => 1000,
            'premium_usd' => '123.4000',
            'asof' => '2026-08-15 20:01:00',
            'source_updated_at' => '2026-08-15 20:01:01',
            'source_row_id' => 999999,
        ]);

        $this->assertSame(0, Artisan::call('intraday:backfill-live-totals', [
            '--from' => '2026-08-15',
            '--to' => '2026-08-15',
            '--symbols' => 'G12CMDA',
            '--chunk' => 1,
        ]));

        $canonical = DB::table('option_live_totals')->where('symbol', 'G12CMDA')->first();
        $this->assertNotNull($canonical);
        $this->assertSame(700, (int) $canonical->call_volume);
        $this->assertSame(300, (int) $canonical->put_volume);
        $this->assertSame(1000, (int) $canonical->volume);
        $this->assertSame(999999, (int) $canonical->source_row_id);
    }

    public function test_commands_reject_invalid_symbol_scopes(): void
    {
        $this->assertSame(1, Artisan::call('intraday:backfill-live-totals', [
            '--symbols' => 'SPY,bad symbol',
        ]));
        $this->assertStringContainsString('contains an invalid symbol', Artisan::output());

        $this->assertSame(1, Artisan::call('intraday:compare-live-totals', [
            '--symbols' => 'SPY,',
        ]));
        $this->assertStringContainsString('contains an invalid symbol', Artisan::output());
    }

    public function test_prune_deletes_expired_rows_from_both_stores_and_rejects_unsafe_days(): void
    {
        Carbon::setTestNow('2026-08-16 12:00:00 America/New_York');
        $this->insertLegacyKey('G12PRUNEOLD', '2026-08-08', 1, 2, 3, null);
        $this->insertLegacyKey('G12PRUNENEW', '2026-08-09', 4, 5, 9, null);

        $repository = app(OptionLiveTotalsRepository::class);
        $repository->backfillOne('G12PRUNEOLD', '2026-08-08');
        $repository->backfillOne('G12PRUNENEW', '2026-08-09');

        $this->assertSame(1, Artisan::call('intraday:prune-counters', ['--days' => 0]));
        $this->assertSame(2, DB::table('option_live_totals')->whereIn('symbol', [
            'G12PRUNEOLD', 'G12PRUNENEW',
        ])->count());

        $this->assertSame(0, Artisan::call('intraday:prune-counters', ['--days' => 7]));
        $this->assertDatabaseMissing('option_live_totals', ['symbol' => 'G12PRUNEOLD']);
        $this->assertDatabaseMissing('option_live_counters', ['symbol' => 'G12PRUNEOLD']);
        $this->assertDatabaseHas('option_live_totals', ['symbol' => 'G12PRUNENEW']);
        $this->assertDatabaseHas('option_live_counters', ['symbol' => 'G12PRUNENEW']);
    }

    private function insertLegacyKey(
        string $symbol,
        string $tradeDate,
        int $callVolume,
        int $putVolume,
        int $totalVolume,
        ?string $premium
    ): int {
        $base = [
            'symbol' => $symbol,
            'trade_date' => $tradeDate,
            'created_at' => $tradeDate.' 20:00:00',
        ];

        DB::table('option_live_counters')->insert([
            $base + [
                'exp_date' => '2026-09-18',
                'strike' => '100.0000',
                'option_type' => 'call',
                'volume' => $callVolume,
                'premium_usd' => null,
                'asof' => $tradeDate.' 20:00:00',
                'updated_at' => $tradeDate.' 20:00:01',
            ],
            $base + [
                'exp_date' => '2026-09-18',
                'strike' => '100.0000',
                'option_type' => 'put',
                'volume' => $putVolume,
                'premium_usd' => null,
                'asof' => $tradeDate.' 20:00:00',
                'updated_at' => $tradeDate.' 20:00:01',
            ],
        ]);

        $freshId = (int) DB::table('option_live_counters')->insertGetId($base + [
            'exp_date' => null,
            'strike' => null,
            'option_type' => null,
            'volume' => $totalVolume,
            'premium_usd' => $premium,
            'asof' => $tradeDate.' 20:00:00',
            'updated_at' => $tradeDate.' 20:00:02',
        ]);

        // Insert the stale row last so the test proves source freshness wins over ID order.
        DB::table('option_live_counters')->insert($base + [
            'exp_date' => null,
            'strike' => null,
            'option_type' => null,
            'volume' => 1,
            'premium_usd' => '1.0000',
            'asof' => $tradeDate.' 19:59:00',
            'updated_at' => $tradeDate.' 20:00:03',
        ]);

        return $freshId;
    }

    private function clearRows(): void
    {
        DB::table('option_live_counters')->whereIn('symbol', $this->symbols)->delete();
        DB::table('option_live_totals')->whereIn('symbol', $this->symbols)->delete();
    }
}
