<?php

namespace Tests\Feature;

use App\Jobs\ComputePositioningJob;
use App\Jobs\ComputeVolMetricsJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComputeOptionMetricsWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_positioning_keeps_short_dex_history_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-18 17:00:00', 'America/New_York'));

        $tooOld = $this->createExpiration('SPY', '2026-02-01');
        $recentHistory = $this->createExpiration('SPY', '2026-03-10');
        $nearForward = $this->createExpiration('SPY', '2026-04-17');
        $tooFar = $this->createExpiration('SPY', '2026-07-01');

        foreach ([$tooOld, $recentHistory, $nearForward, $tooFar] as $expirationId) {
            $this->insertChainPair($expirationId, '2026-03-18');
        }

        (new ComputePositioningJob(['SPY']))->handle();

        $expDates = DB::table('dex_by_expiry')
            ->where('symbol', 'SPY')
            ->where('data_date', '2026-03-18')
            ->orderBy('exp_date')
            ->pluck('exp_date')
            ->all();

        $this->assertSame(['2026-03-10', '2026-04-17'], $expDates);
    }

    public function test_vol_metrics_exclude_expired_expiries_and_clear_stale_skew_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-18 17:00:00', 'America/New_York'));

        $expired = $this->createExpiration('QQQ', '2026-03-10');
        $forward = $this->createExpiration('QQQ', '2026-04-17');

        $this->insertChainPair($expired, '2026-03-18');
        $this->insertChainPair($forward, '2026-03-18');

        DB::table('iv_skew')->insert([
            'symbol' => 'QQQ',
            'data_date' => '2026-03-18',
            'exp_date' => '2026-03-10',
            'skew_pc' => 0.5,
            'curvature' => 0.1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ComputeVolMetricsJob(['QQQ']))->handle();

        $termExpDates = DB::table('iv_term')
            ->where('symbol', 'QQQ')
            ->where('data_date', '2026-03-18')
            ->orderBy('exp_date')
            ->pluck('exp_date')
            ->all();

        $skewExpDates = DB::table('iv_skew')
            ->where('symbol', 'QQQ')
            ->where('data_date', '2026-03-18')
            ->orderBy('exp_date')
            ->pluck('exp_date')
            ->all();

        $this->assertSame(['2026-04-17'], $termExpDates);
        $this->assertSame(['2026-04-17'], $skewExpDates);
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

    protected function insertChainPair(int $expirationId, string $dataDate): void
    {
        DB::table('option_chain_data')->insert([
            [
                'expiration_id' => $expirationId,
                'data_date' => $dataDate,
                'option_type' => 'call',
                'strike' => 100,
                'open_interest' => 100,
                'volume' => 25,
                'gamma' => 0.01,
                'delta' => 0.5,
                'iv' => 0.24,
                'underlying_price' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'expiration_id' => $expirationId,
                'data_date' => $dataDate,
                'option_type' => 'put',
                'strike' => 100,
                'open_interest' => 80,
                'volume' => 20,
                'gamma' => 0.01,
                'delta' => -0.5,
                'iv' => 0.26,
                'underlying_price' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
