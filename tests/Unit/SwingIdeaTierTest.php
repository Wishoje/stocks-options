<?php

namespace Tests\Unit;

use App\Support\Swing\SwingIdeaTier;
use Tests\TestCase;

class SwingIdeaTierTest extends TestCase
{
    public function test_gold_requires_all_gates_including_exact_boundaries(): void
    {
        $tier = (new SwingIdeaTier)->assess($this->goldBoundary());
        $this->assertSame('gold', $tier['key']);
        $this->assertSame('Gold', $tier['label']);
        $this->assertSame(1, $tier['rank']);
        $this->assertSame(SwingIdeaTier::VERSION, $tier['version']);
        $this->assertEqualsWithDelta(2.5, $tier['metrics']['reward_risk'], .00000001);
        $this->assertEqualsWithDelta(3.5, $tier['metrics']['risk_pct'], .00000001);
        $this->assertSame([], $tier['tradeoffs']);
        $this->assertLessThanOrEqual(3, count($tier['reasons']));
        $plan = $this->goldBoundary();
        $plan['evidence']['close'] = 100 / (1 - .015);
        $this->assertSame('gold', (new SwingIdeaTier)->assess($plan)['key']);
    }

    public function test_each_gold_gate_can_downgrade_independently(): void
    {
        $base = $this->goldBoundary();
        foreach (['room', 'risk', 'distance', 'wall_rolloff', 'target_rolloff', 'relative', 'trend'] as $gate) {
            $plan = $base;
            match ($gate) {
                'room' => $plan['levels']['target'] = 108.749,
                'risk' => $plan['levels']['invalidation'] = 96.499,
                'distance' => $plan['evidence']['close'] = 101.524,
                'wall_rolloff' => $plan['evidence']['wall_expiry_shares'][0]['share_pct'] = 40.01,
                'target_rolloff' => $plan['evidence']['target_rolloff_pct'] = 25.01,
                'relative' => $plan['evidence']['wall_relative_gex'] = 1.999,
                'trend' => $plan['evidence']['sma20'] = 101.,
            };
            $tier = (new SwingIdeaTier)->assess($plan);
            $this->assertSame('silver', $tier['key'], $gate);
            $this->assertNotEmpty($tier['tradeoffs'], $gate);
        }
    }

    public function test_silver_boundaries_and_bronze_for_a_more_demanding_plan(): void
    {
        $plan = $this->goldBoundary();
        $plan['levels']['invalidation'] = 94.;
        $plan['levels']['target'] = 110.8;
        $plan['evidence']['close'] = 100 / (1 - .05);
        $plan['evidence']['wall_expiry_shares'] = [
            ['expiration' => '2026-11-20', 'share_pct' => 80.],
            ['expiration' => '2026-12-18', 'share_pct' => 20.],
        ];
        $plan['evidence']['target_rolloff_pct'] = 50.;
        $plan['evidence']['wall_relative_gex'] = 1.5;
        $tier = (new SwingIdeaTier)->assess($plan);
        $this->assertSame('silver', $tier['key']);
        $this->assertEqualsWithDelta(1.8, $tier['metrics']['reward_risk'], .00000001);
        $plan['levels']['invalidation'] = 93.99;
        $this->assertSame('bronze', (new SwingIdeaTier)->assess($plan)['key']);
    }

    public function test_wall_rolloff_is_distinct_from_target_rolloff_and_weights_are_normalized(): void
    {
        $plan = $this->goldBoundary();
        $plan['evidence']['target_rolloff_pct'] = 0.;
        $plan['evidence']['wall_expiry_shares'] = [
            ['expiration' => '2026-11-20', 'share_pct' => 32.],
            ['expiration' => '2026-11-20', 'share_pct' => 32.],
            ['expiration' => '2026-12-18', 'share_pct' => 36.],
        ];
        $tier = (new SwingIdeaTier)->assess($plan);
        $this->assertSame('silver', $tier['key']);
        $this->assertSame(64., $tier['metrics']['wall_rolloff_pct']);
        $this->assertSame(0., $tier['metrics']['target_rolloff_pct']);
        $this->assertStringContainsString('64%', $tier['tradeoffs'][0]);
        foreach ($plan['evidence']['wall_expiry_shares'] as &$row) {
            $row['share_pct'] *= 2;
        }
        unset($row);
        $this->assertSame(64., (new SwingIdeaTier)->assess($plan)['metrics']['wall_rolloff_pct']);
        $plan['evidence']['wall_expiry_shares'] = [['expiration' => '2026-12-18', 'share_pct' => 100.]];
        $plan['evidence']['target_rolloff_pct'] = 30.;
        $this->assertSame('silver', (new SwingIdeaTier)->assess($plan)['key']);
    }

    public function test_current_prices_outcomes_and_status_cannot_upgrade_the_frozen_tier(): void
    {
        $plan = $this->goldBoundary();
        $original = (new SwingIdeaTier)->assess($plan);
        $plan['last_price'] = 200.;
        $plan['latest_price'] = ['close' => 200.];
        $plan['entry_price'] = 80.;
        $plan['evidence']['target_room_ratio'] = 999.;
        $plan['status'] = 'completed';
        $plan['outcome'] = 'target_reached';
        $plan['holding_deadline'] = '2027-01-15T21:00:00+00:00';
        $this->assertSame($original, (new SwingIdeaTier)->assess($plan));
    }

    public function test_bearish_geometry_is_mirrored_and_the_twenty_day_context_is_directional(): void
    {
        $plan = $this->goldBoundary();
        $plan['direction'] = 'bearish';
        $plan['levels'] = ['entry_low' => 100., 'entry_high' => 100., 'invalidation' => 103.5, 'target' => 91.25];
        $plan['evidence']['sma20'] = 100.;
        $tier = (new SwingIdeaTier)->assess($plan);
        $this->assertSame('gold', $tier['key']);
        $this->assertEqualsWithDelta(2.5, $tier['metrics']['reward_risk'], .00000001);
        $plan['evidence']['sma20'] = 99.;
        $this->assertSame('silver', (new SwingIdeaTier)->assess($plan)['key']);
    }

    public function test_missing_or_invalid_inputs_cannot_claim_gold_or_misleading_geometry(): void
    {
        $rules = new SwingIdeaTier;
        foreach ([null, 0, -1, NAN, INF, 'bad'] as $value) {
            $plan = $this->goldBoundary();
            $plan['levels']['entry_high'] = $value;
            $tier = $rules->assess($plan);
            $this->assertSame('bronze', $tier['key']);
            $this->assertNull($tier['metrics']['reward_risk']);
            $this->assertNull($tier['metrics']['risk_pct']);
        }
        $this->assertSame('bronze', $rules->assess([])['key']);
        $this->assertSame('bronze', $rules->assess(['evidence' => 'bad', 'levels' => 'bad'])['key']);
        $plan = $this->goldBoundary();
        $plan['levels']['target'] = 90.;
        $this->assertNull($rules->assess($plan)['metrics']['target_pct']);
        $plan = $this->goldBoundary();
        $plan['evidence']['close'] = 0.;
        $this->assertSame('bronze', $rules->assess($plan)['key']);
        $this->assertNull($rules->assess($plan)['metrics']['entry_distance_pct']);
    }

    public function test_unknown_exposure_timing_and_bad_dates_never_claim_durability(): void
    {
        $rules = new SwingIdeaTier;
        foreach ([null, [], [['expiration' => '2026-11-20', 'share_pct' => 0]],
            [['expiration' => '2026-11-20', 'share_pct' => -1]],
            [['expiration' => '2026-11-20', 'share_pct' => INF]],
            [['expiration' => '2026-02-30', 'share_pct' => 100]],
            [['expiration' => 'bad', 'share_pct' => 100]]] as $shares) {
            $plan = $this->goldBoundary();
            $plan['evidence']['wall_expiry_shares'] = $shares;
            $tier = $rules->assess($plan);
            $this->assertSame('bronze', $tier['key']);
            $this->assertNull($tier['metrics']['wall_rolloff_pct']);
        }
        foreach (['bad', '2026-02-30T21:00:00+00:00'] as $deadline) {
            $plan = $this->goldBoundary();
            $plan['entry_deadline'] = $deadline;
            $this->assertNull($rules->assess($plan)['metrics']['max_exit_date']);
        }
        $plan = $this->goldBoundary();
        $plan['max_holding_sessions'] = 1000000;
        $this->assertSame('bronze', $rules->assess($plan)['key']);
    }

    public function test_max_exit_uses_new_york_date_market_holidays_and_only_earlier_expirations(): void
    {
        $plan = $this->goldBoundary();
        $plan['entry_deadline'] = '2026-11-25T01:00:00+00:00'; // November 24 in New York.
        $plan['max_holding_sessions'] = 5;
        $plan['evidence']['wall_expiry_shares'] = [
            ['expiration' => '2026-11-27', 'share_pct' => 30.],
            ['expiration' => '2026-12-02', 'share_pct' => 70.],
        ];
        $tier = (new SwingIdeaTier)->assess($plan);
        $this->assertSame('2026-12-02', $tier['metrics']['max_exit_date']);
        $this->assertSame(30., $tier['metrics']['wall_rolloff_pct']);
    }

    private function goldBoundary(): array
    {
        return ['direction' => 'bullish', 'horizon' => 'position',
            'entry_deadline' => '2026-11-17T21:00:00+00:00', 'max_holding_sessions' => 15,
            'levels' => ['entry_low' => 100., 'entry_high' => 100., 'invalidation' => 96.5, 'target' => 108.75],
            'evidence' => ['close' => 100., 'sma20' => 100., 'wall_relative_gex' => 2., 'target_rolloff_pct' => 25.,
                'wall_expiry_shares' => [['expiration' => '2026-11-20', 'share_pct' => 40.],
                    ['expiration' => '2026-12-18', 'share_pct' => 60.]]]];
    }
}
