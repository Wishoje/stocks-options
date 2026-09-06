<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

class IntradayWriteBenchmarkTest extends MySqlTestCase
{
    use RefreshDatabase;

    public function test_benchmark_matches_without_writing_application_rows_and_restores_configuration(): void
    {
        config()->set('intraday_ingestion.bulk_enabled', false);
        config()->set('intraday_ingestion.chunk_size', 17);
        $before = DB::table('intraday_option_volumes')->count();
        $clock = Carbon::getTestNow();

        $this->assertSame(0, Artisan::call('intraday:benchmark-writes', ['--rows' => 12, '--chunk' => 5, '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['matches']);
        foreach ($report['phases'] as $phase) {
            $this->assertSame(24, $phase['legacy']['queries']);
            $this->assertSame(3, $phase['bulk']['queries']);
            $this->assertSame(12, $phase['bulk']['row_count']);
        }
        $this->assertSame($before, DB::table('intraday_option_volumes')->count());
        $this->assertSame($clock, Carbon::getTestNow());
        $this->assertFalse(config('intraday_ingestion.bulk_enabled'));
        $this->assertSame(17, config('intraday_ingestion.chunk_size'));
    }

    public function test_benchmark_rejects_unbounded_scope(): void
    {
        $this->artisan('intraday:benchmark-writes', ['--rows' => 2001, '--json' => true])->assertExitCode(1);
        $this->artisan('intraday:benchmark-writes', ['--chunk' => 0, '--json' => true])->assertExitCode(1);
    }
}
