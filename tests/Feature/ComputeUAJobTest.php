<?php

namespace Tests\Feature;

use App\Jobs\ComputeUAJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComputeUAJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_excludes_today_from_ua_baseline_stats(): void
    {
        $expirationId = $this->createExpiration('SPY', '2026-03-20');

        $this->insertUaRows($expirationId, '2026-03-13', 100, 8, 50);
        $this->insertUaRows($expirationId, '2026-03-14', 100, 10, 50);
        $this->insertUaRows($expirationId, '2026-03-17', 100, 12, 50);
        $this->insertUaRows($expirationId, '2026-03-18', 100, 160, 20);

        $job = new ComputeUAJob(['SPY'], 30, 3.0, 0.5, 50, 1, 3);
        $job->handle();

        $row = DB::table('unusual_activity')
            ->where('symbol', 'SPY')
            ->where('data_date', '2026-03-18')
            ->where('exp_date', '2026-03-20')
            ->where('strike', 100)
            ->first();

        $this->assertNotNull($row);
        $meta = json_decode($row->meta, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(3, $meta['history_samples']);
        $this->assertSame('normal', $meta['confidence']);
        $this->assertTrue($meta['baseline_excludes_today']);
        $this->assertEquals(10.0, $meta['mu']);
        $this->assertNotNull($row->z_score);
        $this->assertGreaterThan(100, (float) $row->z_score);
    }

    public function test_it_marks_low_confidence_rows_when_history_is_short_but_signal_is_strong(): void
    {
        $expirationId = $this->createExpiration('QQQ', '2026-03-27');

        $this->insertUaRows($expirationId, '2026-03-17', 100, 5, 20);
        $this->insertUaRows($expirationId, '2026-03-18', 100, 120, 10);

        $job = new ComputeUAJob(['QQQ'], 30, 3.0, 0.5, 50, 1, 3);
        $job->handle();

        $row = DB::table('unusual_activity')
            ->where('symbol', 'QQQ')
            ->where('data_date', '2026-03-18')
            ->where('exp_date', '2026-03-27')
            ->where('strike', 100)
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->z_score);

        $meta = json_decode($row->meta, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $meta['history_samples']);
        $this->assertSame('low', $meta['confidence']);
        $this->assertTrue($meta['baseline_excludes_today']);
        $this->assertEquals(5.0, $meta['mu']);
    }

    protected function createExpiration(string $symbol, string $expirationDate): int
    {
        return DB::table('option_expirations')->insertGetId([
            'symbol' => $symbol,
            'expiration_date' => $expirationDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function insertUaRows(int $expirationId, string $dataDate, float $strike, int $volume, int $openInterest): void
    {
        foreach (['call', 'put'] as $optionType) {
            DB::table('option_chain_data')->insert([
                'expiration_id' => $expirationId,
                'data_date' => $dataDate,
                'option_type' => $optionType,
                'strike' => $strike,
                'open_interest' => $openInterest,
                'volume' => intdiv($volume, 2),
                'gamma' => 0.01,
                'delta' => $optionType === 'call' ? 0.5 : -0.5,
                'iv' => 0.25,
                'underlying_price' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
