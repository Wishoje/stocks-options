<?php

namespace Tests\Unit;

use App\Support\WallIntelligence\WallIntelligenceMetrics;
use PHPUnit\Framework\TestCase;

class WallIntelligenceMetricsTest extends TestCase
{
    private function row(float $strike, float $net, string $expiry = '2026-10-02', string $date = '2026-09-30'): array
    {
        return ['strike' => $strike, 'net_gex' => $net, 'call_gex' => max(0, $net), 'put_gex' => max(0, -$net),
            'open_interest' => 10.0, 'inputs_valid' => true, 'expiry' => $expiry, 'data_date' => $date, 'call_rows' => 1, 'put_rows' => 1];
    }

    private function calculate(array $rows, array $history = [], ?array $expiries = null): array
    {
        return (new WallIntelligenceMetrics)->calculate([
            'symbol' => 'SPY', 'timeframe' => '7d', 'data_date' => '2026-09-30',
            'expiration_dates' => $expiries ?? ['2026-10-02'], 'put_support' => 100, 'call_resistance' => 110,
        ], $rows, $history, ['2026-09-30', '2026-09-29', '2026-09-28', '2026-09-25', '2026-09-24'], 105.0);
    }

    public function test_neighbors_exclude_target_use_two_each_side_and_include_zero(): void
    {
        $rows = array_map(fn ($pair) => $this->row(...$pair), [[85, -999], [90, -10], [95, 0], [100, -100], [105, 20], [110, 10], [115, 999]]);
        $r = $this->calculate($rows)['walls']['put'][0];
        $this->assertSame([90.0, 95.0, 105.0, 110.0], $r['neighbor_strikes']);
        $this->assertSame(10.0, $r['relative_magnitude']);
        $this->assertEqualsWithDelta(4.76190476, $r['distance_pct'], 1e-7);
        $this->assertLessThan(0, $r['signed_distance_pct']);
        $this->assertNull($r['strength_score']);
        $this->assertFalse($r['actionable']);
    }

    public function test_expiry_concentration_does_not_cancel_opposing_signs(): void
    {
        $r = $this->calculate([$this->row(100, -100), $this->row(100, 50, '2026-10-09')], [], ['2026-10-02', '2026-10-09'])['walls']['put'][0];
        $this->assertSame(-50.0, $r['net_gex']);
        $this->assertEqualsWithDelta(100, array_sum(array_column($r['expiry_contributions'], 'share_pct')), 1e-8);
        $this->assertEqualsWithDelta(66.66666667, $r['dominant_expiry_share_pct'], 1e-7);
        $this->assertSame(2, $r['distinct_expiry_count']);
        $this->assertNull($r['relative_magnitude']);
    }

    public function test_change_uses_magnitude_and_preserves_sign_and_oi_change_separately(): void
    {
        $prior = $this->row(100, 80, date: '2026-09-29');
        $prior['open_interest'] = 40;
        $r = $this->calculate([$this->row(100, -100)], ['2026-09-29' => [$prior]])['walls']['put'][0];
        $this->assertSame(25.0, $r['gex_magnitude_change_1d_pct']);
        $this->assertTrue($r['gex_sign_changed']);
        $this->assertSame(-30.0, $r['oi_change_1d']);
        $this->assertSame(1, $r['top_three_streak_sessions']);
    }

    public function test_zero_baseline_is_null_but_zero_current_is_a_real_reading(): void
    {
        $r = $this->calculate([$this->row(100, 0)], ['2026-09-29' => [$this->row(100, 0, date: '2026-09-29')]])['walls']['put'][0];
        $this->assertNull($r['gex_magnitude_change_1d_pct']);
        $this->assertSame(0.0, $r['history'][0]['net_gex']);
        $this->assertNull($r['dominant_expiry_share_pct']);
    }

    public function test_history_gap_stops_streak_without_skipping_to_an_older_baseline(): void
    {
        $r = $this->calculate([$this->row(100, -100)], [
            '2026-09-29' => [$this->row(100, -90, date: '2026-09-29')],
            '2026-09-25' => [$this->row(100, -60, date: '2026-09-25')],
        ])['walls']['put'][0];
        $this->assertSame(2, $r['top_three_streak_sessions']);
        $this->assertNull($r['history'][2]['net_gex']);
        $this->assertSame(-60.0, $r['history'][3]['net_gex']);
        $this->assertEqualsWithDelta(11.11111111, $r['gex_magnitude_change_1d_pct'], 1e-7);
    }

    public function test_expiry_roll_mixed_dates_and_invalid_target_inputs_prevent_comparison(): void
    {
        foreach (['roll', 'date', 'inputs', 'strike_set'] as $case) {
            $current = [$this->row(100, -100), $this->row(100, -50, '2026-10-09')];
            $prior = [$this->row(100, -90, date: '2026-09-29'), $this->row(100, -50, '2026-10-09', '2026-09-29')];
            if ($case === 'roll') {
                array_pop($prior);
            }
            if ($case === 'date') {
                $prior[1]['data_date'] = '2026-09-28';
            }
            if ($case === 'inputs') {
                $prior[1]['inputs_valid'] = false;
            }
            if ($case === 'strike_set') {
                $prior[1]['strike'] = 110.0;
            }
            $r = $this->calculate($current, ['2026-09-29' => $prior], ['2026-10-02', '2026-10-09'])['walls']['put'][0];
            $this->assertNull($r['gex_magnitude_change_1d_pct'], $case);
            $this->assertNull($r['oi_change_1d'], $case);
            $this->assertSame(1, $r['top_three_streak_sessions'], $case);
        }
    }
}
