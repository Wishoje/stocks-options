<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PruneIntradayVolumesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('intraday_option_volumes', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol');
            $table->timestamp('captured_at');
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-23T07:00:00Z'));
    }

    public function test_only_expired_rows_are_removed_across_cursor_batches(): void
    {
        foreach (array_chunk(array_fill(0, 1001, ['symbol' => 'SPY', 'captured_at' => '2026-10-21 19:00:00']), 500) as $chunk) {
            DB::table('intraday_option_volumes')->insert($chunk);
        }
        DB::table('intraday_option_volumes')->insert([
            ['symbol' => 'QQQ', 'captured_at' => '2026-10-22 07:00:00'],
            ['symbol' => 'TSLA', 'captured_at' => '2026-10-23 06:59:00'],
        ]);
        $this->artisan('intraday:prune-option-volumes --hours=24 --batch=1000 --sleep-ms=0')
            ->expectsOutput('Deleted 1001 rows total.')->assertSuccessful();
        $this->assertSame(['QQQ', 'TSLA'], DB::table('intraday_option_volumes')->orderBy('id')->pluck('symbol')->all());
    }

    public function test_later_inserts_and_rows_refreshed_after_selection_are_preserved(): void
    {
        $id = DB::table('intraday_option_volumes')->insertGetId(['symbol' => 'SPY', 'captured_at' => '2026-10-21 19:00:00']);
        $armed = true;
        DB::connection()->beforeExecuting(function ($sql) use (&$armed, $id): void {
            if (! $armed || ! str_starts_with(strtolower(ltrim($sql)), 'delete')) {
                return;
            }
            $armed = false;
            DB::table('intraday_option_volumes')->where('id', $id)->update(['captured_at' => now('UTC')]);
            DB::table('intraday_option_volumes')->insert(['symbol' => 'QQQ', 'captured_at' => '2026-10-21 19:00:00']);
        });
        $this->artisan('intraday:prune-option-volumes --hours=24 --batch=1000 --sleep-ms=0')
            ->expectsOutput('Deleted 0 rows total.')->assertSuccessful();
        $this->assertSame(2, DB::table('intraday_option_volumes')->count());
    }

    public function test_legacy_large_batch_option_is_capped(): void
    {
        DB::table('intraday_option_volumes')->insert(['symbol' => 'SPY', 'captured_at' => '2026-10-21 19:00:00']);
        $this->artisan('intraday:prune-option-volumes --hours=24 --batch=100000 --sleep-ms=0')
            ->expectsOutput('Pruning intraday_option_volumes before 2026-10-22 07:00:00 UTC (batch=10000)...')
            ->assertSuccessful();
    }
}
