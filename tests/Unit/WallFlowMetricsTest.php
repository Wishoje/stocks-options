<?php

namespace Tests\Unit;

use App\Support\WallIntelligence\WallFlowMetrics;
use PHPUnit\Framework\TestCase;

class WallFlowMetricsTest extends TestCase
{
    private array $sessions = ['2026-09-30', '2026-09-29', '2026-09-28', '2026-09-25', '2026-09-24', '2026-09-23'];

    private function row(float $put, float $call = 50, string $expiry = '2026-10-02'): array
    {
        return ['strike' => 100.0, 'expiry' => $expiry, 'data_date' => '2026-09-30',
            'put_oi' => $put, 'call_oi' => $call, 'put_rows' => 1, 'call_rows' => 1,
            'put_oi_valid' => true, 'call_oi_valid' => true,
            'call_volume' => 200.0, 'put_volume' => 500.0, 'call_volume_valid' => true, 'put_volume_valid' => true];
    }

    private function calculate(array $current, array $prior, array $older = [], string $side = 'put', array $scope = []): array
    {
        return (new WallFlowMetrics)->calculate(100.0, $side, $current, [
            '2026-09-29' => $prior, '2026-09-23' => $older,
            // Four sessions back deliberately differs from the five-session baseline.
            '2026-09-24' => [$this->row(9999)],
        ], $this->sessions, array_replace(array_fill_keys($this->sessions, true), $scope));
    }

    public function test_oi_sides_and_five_trading_session_baseline_are_independent(): void
    {
        $current = [$this->row(120, 30), $this->row(80, 10, '2026-10-09')];
        $prior = [$this->row(100, 60), $this->row(50, 20, '2026-10-09')];
        $older = [$this->row(200, 100), $this->row(100, 50, '2026-10-09')];
        $put = $this->calculate($current, $prior, $older);
        $call = $this->calculate($current, $prior, $older, 'call');
        $this->assertSame('building', $put['wall_build_state']);
        $this->assertSame(50.0, $put['daily']['change']);
        $this->assertSame(-100.0, $put['five_session']['change']);
        $this->assertSame('2026-09-23', $put['five_session']['baseline_date']);
        $this->assertSame('unwinding', $call['wall_build_state']);
        $this->assertSame(-40.0, $call['daily']['change']);
        $this->assertSame(-50.0, $call['daily']['change_pct']);
        $this->assertSame('2026-10-09', $put['expiry_contributions'][0]['expiry']);
        $this->assertEquals($put['daily']['change'], array_sum(array_column($put['expiry_contributions'], 'change_1d')));
        $this->assertEquals($put['five_session']['change'], array_sum(array_column($put['expiry_contributions'], 'change_5d')));
        $this->assertSame(1000.0, $put['activity']['put_volume']);
        $this->assertFalse($put['activity']['direction_inferred']);
    }

    public function test_neutral_band_boundaries_and_zero_baselines(): void
    {
        foreach ([[102, 100, 'stable', 2.0], [98, 100, 'stable', -2.0], [103, 100, 'building', 3.0],
            [97, 100, 'unwinding', -3.0], [0, 100, 'unwinding', -100.0], [0, 0, 'stable', null], [10, 0, 'building', null]] as [$oi, $prior, $state, $pct]) {
            $r = $this->calculate([$this->row($oi)], [$this->row($prior)]);
            $this->assertSame($state, $r['wall_build_state'], "$prior -> $oi");
            $this->assertSame($pct, $r['daily']['change_pct']);
            $this->assertNull($r['five_session']['change']);
        }
    }

    public function test_missing_pairs_and_changed_baskets_never_become_stable_or_zero(): void
    {
        $invalid = $this->row(100);
        $invalid['put_oi_valid'] = false;
        $differentCount = $this->row(100);
        $differentCount['put_rows'] = 2;
        $differentStrike = $this->row(100);
        $differentStrike['strike'] = 101;
        foreach ([[], [$invalid], [$differentCount], [$differentStrike], [$this->row(100, expiry: '2026-10-09')]] as $prior) {
            $r = $this->calculate([$this->row(100)], $prior, [$this->row(100)]);
            $this->assertNull($r['wall_build_state']);
            $this->assertNull($r['daily']['change']);
            $this->assertFalse($r['daily']['comparable']);
            $this->assertSame(0.0, $r['five_session']['change']);
            $this->assertNull($r['expiry_contributions'][0]['change_1d']);
        }
        foreach (['2026-09-30', '2026-09-29'] as $date) {
            $r = $this->calculate([$this->row(100)], [$this->row(100)], scope: [$date => false]);
            $this->assertNull($r['wall_build_state']);
        }
    }

    public function test_volume_and_gamma_cannot_drive_oi_classification_and_null_volume_is_not_zero(): void
    {
        $row = $this->row(100);
        $row['inputs_valid'] = false; // Gamma is irrelevant to observed OI.
        $row['put_volume_valid'] = false;
        $row['call_volume'] = 0.0;
        $row['call_oi_valid'] = false;
        $r = $this->calculate([$row], [$this->row(100)]);
        $this->assertSame('stable', $r['wall_build_state']);
        $this->assertNull($r['activity']['put_volume']);
        $this->assertSame(0.0, $r['activity']['call_volume']);
        $this->assertNull($this->calculate([$row], [$this->row(100)], side: 'call')['wall_build_state']);
    }
}
