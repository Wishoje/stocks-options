<?php

namespace Tests\Unit;

use App\Support\Swing\SwingReactionRules;
use Carbon\CarbonImmutable;
use Tests\Fixtures\SwingInput;
use Tests\TestCase;

class SwingReactionRulesTest extends TestCase
{
    public function test_both_wall_sides_offer_holds_and_breaks_without_a_trend_direction_gate(): void
    {
        $rules = new SwingReactionRules;
        foreach (['short', 'position'] as $horizon) {
            $plans = $rules->propose($this->input(), $horizon, $this->at('2026-10-23 16:01'));
            $this->assertCount(4, $plans);
            $this->assertSame(['hold_reclaim', 'break_retest', 'hold_reclaim', 'break_retest'], array_column($plans, 'pattern'));
            $this->assertSame(['bullish', 'bearish', 'bearish', 'bullish'], array_column($plans, 'direction'));
            foreach ($plans as $plan) {
                $this->assertSame('price_touch', $plan['metric_basis']);
                $this->assertSame('watching', $plan['status']);
                $this->assertGreaterThanOrEqual(1.5, $plan['evidence']['target_room_ratio']);
                $this->assertGreaterThanOrEqual(1.5, $plan['evidence']['wall_relative_magnitude']);
                $this->assertSame('context_only', $plan['evidence']['trend_role']);
                $this->assertNotEmpty($plan['evidence']['wall_expiry_shares']);
            }
        }
    }

    public function test_bad_context_dates_coverage_and_nonfinite_values_do_not_create_plans(): void
    {
        $rules = new SwingReactionRules;
        $available = $this->at('2026-10-23 16:01');
        foreach (['coverage', 'mixed_date', 'price_gap', 'bad_price', 'nan_gex', 'duplicate_expiry', 'expired'] as $case) {
            $input = $this->input();
            if ($case === 'coverage') {
                foreach ($input['strikes'] as &$row) {
                    $row['expirations'][0]['covered_oi'] = 9700;
                }
                unset($row);
            } elseif ($case === 'mixed_date') {
                $input['strikes'][0]['expirations'][0]['data_date'] = '2026-10-22';
            } elseif ($case === 'price_gap') {
                unset($input['prices'][68]);
            } elseif ($case === 'bad_price') {
                $input['prices'][69]['low'] = NAN;
            } elseif ($case === 'nan_gex') {
                $input['strikes'][0]['expirations'][0]['net_gex'] = INF;
            } elseif ($case === 'duplicate_expiry') {
                $input['strikes'][0]['expirations'][] = $input['strikes'][0]['expirations'][0];
            }
            $this->assertSame([], $rules->propose($input, 'short', $case === 'expired' ? $this->at('2026-10-29 12:00') : $available), $case);
        }
    }

    public function test_nearest_significant_obstacle_is_not_skipped_to_improve_room(): void
    {
        $input = $this->input();
        $this->addStrike($input, 102., 9000000.);
        $plans = (new SwingReactionRules)->propose($input, 'short', $this->at('2026-10-23 16:01'));
        $this->assertEmpty(array_filter($plans, fn ($p) => $p['side'] === 'put' && $p['pattern'] === 'hold_reclaim'));
    }

    public function test_unexceptional_walls_and_targets_that_roll_off_too_soon_are_rejected(): void
    {
        $rules = new SwingReactionRules;
        $input = $this->input();
        foreach ($input['strikes'] as &$row) {
            $row['expirations'][0]['net_gex'] = 10000.;
        }
        unset($row);
        $this->assertSame([], $rules->propose($input, 'short', $this->at('2026-10-23 16:01')));
        $input = $this->input();
        foreach ($input['strikes'] as &$row) {
            $row['expirations'][0]['expiration'] = '2026-10-30';
        }
        unset($row);
        $this->assertSame([], $rules->propose($input, 'short', $this->at('2026-10-23 16:01')));
    }

    public function test_hold_needs_a_touch_then_a_separate_whole_bar_reaction_then_later_entry_touch(): void
    {
        $rules = new SwingReactionRules;
        $plan = $this->plan();
        $plan = $this->advance($rules, $plan, '09:30', 99.9, 100.2, 100.);
        $this->assertSame('awaiting_reclaim', $plan['phase']);
        $this->assertSame('watching', $plan['status']);
        $plan = $this->advance($rules, $plan, '09:35', 100.1, 100.8, 100.7);
        $this->assertSame('watching', $plan['status']); // Close above the trigger alone does not qualify.
        $plan = $this->advance($rules, $plan, '09:40', 100.4, 100.8, 100.6);
        $this->assertSame('ready', $plan['status']);
        $this->assertNull($plan['entry_at']);
        $plan = $this->advance($rules, $plan, '09:45', 100.7, 101.2, 101.1);
        $this->assertSame('entry_touched', $plan['status']);
        $this->assertSame(101., $plan['entry_price']);
        $this->assertSame(0., $plan['metrics']['mfe_pct']); // Never use the unknown pre-entry part of this bar.
        $plan = $this->advance($rules, $plan, '09:50', 100.7, 103., 102.);
        $this->assertTrue($plan['metrics']['reached_05r']);
        $this->assertEqualsWithDelta(100 * 2 / 101, $plan['metrics']['mfe_pct'], .000001);
        $plan = $this->advance($rules, $plan, '09:55', 103., 105., 104.);
        $this->assertSame('completed', $plan['status']);
        $this->assertSame('target_reached', $plan['outcome']); // Wick touch, without requiring a target close.
        $this->assertSame($plan, $this->advance($rules, $plan, '10:00', 96., 98., 97.));
    }

    public function test_break_requires_three_consecutive_whole_bars_a_later_retest_and_separate_reaction(): void
    {
        $rules = new SwingReactionRules;
        $plan = $this->plan('call', 'break_retest');
        $plan = $this->advance($rules, $plan, '09:30', 105.4, 106., 105.7);
        $plan = $this->advance($rules, $plan, '09:35', 105.4, 106., 105.7);
        $this->assertSame('awaiting_acceptance', $plan['phase']);
        $plan = $this->advance($rules, $plan, '09:40', 105.4, 106., 105.7);
        $this->assertSame('awaiting_retest', $plan['phase']);
        $plan = $this->advance($rules, $plan, '09:45', 105., 105.6, 105.5);
        $this->assertSame('awaiting_retest_reaction', $plan['phase']);
        $this->assertSame('watching', $plan['status']);
        $plan = $this->advance($rules, $plan, '09:50', 105.4, 105.8, 105.6);
        $this->assertSame('ready', $plan['status']);
        $this->assertNull($plan['entry_at']);
    }

    public function test_prepublication_and_pre_readiness_bars_cannot_backfill_an_entry(): void
    {
        $rules = new SwingReactionRules;
        $plan = $this->plan();
        $plan['available_at'] = $this->at('2026-10-26 09:32')->toIso8601String();
        $this->assertSame($plan, $this->advance($rules, $plan, '09:30', 99.9, 100.2, 100.));
        $plan = $this->plan();
        $plan = $this->advance($rules, $plan, '09:30', 99.9, 100.2, 100.);
        $bar = $this->bar('09:35', 100.4, 100.8, 100.6);
        $plan = $rules->advance($plan, $bar, $this->at('2026-10-26 09:55'));
        $this->assertSame('ready', $plan['status']);
        foreach (['09:40', '09:45', '09:50'] as $time) {
            $plan = $rules->advance($plan, $this->bar($time, 100.7, 101.2, 101.1), $this->at('2026-10-26 10:00'));
            $this->assertNull($plan['entry_at']);
        }
        $plan = $this->advance($rules, $plan, '09:55', 100.7, 101.2, 101.1);
        $this->assertSame('entry_touched', $plan['status']);
    }

    public function test_target_or_stop_before_entry_cancels_without_recording_a_success_or_loss(): void
    {
        $rules = new SwingReactionRules;
        foreach (['target_before_entry' => [104.8, 105.1, 105.], 'stop_before_entry' => [98.2, 98.7, 98.4]] as $outcome => [$low, $high, $close]) {
            $plan = $this->readyPlan();
            $plan = $this->advance($rules, $plan, '09:40', $low, $high, $close);
            $this->assertSame('cancelled', $plan['status']);
            $this->assertSame($outcome, $plan['outcome']);
            $this->assertNull($plan['entry_at']);
            $this->assertNull($plan['metrics']);
        }
    }

    public function test_an_unarmed_break_is_not_cancelled_by_being_on_the_future_stop_side(): void
    {
        $rules = new SwingReactionRules;
        $plan = $this->plan('call', 'break_retest');
        $plan = $this->advance($rules, $plan, '09:30', 101., 102., 101.5);
        $this->assertSame('watching', $plan['status']);
        $this->assertNull($plan['episode_started_at']);
    }

    public function test_gap_over_entry_does_not_fill_and_same_bar_event_order_is_ambiguous(): void
    {
        $rules = new SwingReactionRules;
        $plan = $this->readyPlan();
        $gap = $this->advance($rules, $plan, '09:40', 101.5, 102., 101.8);
        $this->assertSame('ready', $gap['status']);
        $this->assertNull($gap['entry_at']);
        foreach ([[100.5, 105.1, 104.], [98.4, 100.8, 100.5]] as [$low, $high, $close]) {
            $result = $this->advance($rules, $plan, '09:40', $low, $high, $close);
            $this->assertSame('ambiguous', $result['status']);
            $this->assertSame('ambiguous', $result['outcome']);
        }
        $entered = $this->advance($rules, $plan, '09:40', 100.7, 101.1, 100.9);
        $result = $this->advance($rules, $entered, '09:45', 98.4, 105.1, 102.);
        $this->assertSame('ambiguous', $result['status']);
    }

    public function test_missing_bars_reset_unconfirmed_sequence_and_prevent_a_later_false_win(): void
    {
        $rules = new SwingReactionRules;
        $plan = $this->plan('call', 'break_retest');
        $plan = $this->advance($rules, $plan, '09:30', 105.4, 106., 105.7);
        $plan = $this->advance($rules, $plan, '09:40', 105.4, 106., 105.7);
        $this->assertSame(1, $plan['acceptance_bars']);
        $this->assertSame('watching', $plan['status']);
        $plan = $this->readyPlan();
        $plan = $this->advance($rules, $plan, '09:40', 100.7, 101.1, 100.9);
        $plan = $this->advance($rules, $plan, '09:50', 102., 104., 103.);
        $this->assertTrue($plan['evidence_gap']);
        $plan = $this->advance($rules, $plan, '09:55', 104., 105.1, 104.7);
        $this->assertSame('ambiguous', $plan['status']);
        $this->assertNotSame('target_reached', $plan['outcome']);
    }

    public function test_bearish_price_touches_and_risk_geometry_mirror_bullish(): void
    {
        $rules = new SwingReactionRules;
        $plan = $this->plan('call', 'hold_reclaim');
        $plan = $this->advance($rules, $plan, '09:30', 104.9, 105.1, 105.);
        $plan = $this->advance($rules, $plan, '09:35', 104.3, 104.6, 104.4);
        $this->assertSame('ready', $plan['status']);
        $plan = $this->advance($rules, $plan, '09:40', 104.3, 104.8, 104.5);
        $this->assertSame(104., $plan['entry_price']);
        $plan = $this->advance($rules, $plan, '09:45', 104., 106.6, 105.);
        $this->assertSame('stop_reached', $plan['outcome']);
    }

    public function test_regular_market_overnight_and_weekend_gaps_are_not_missing_bars(): void
    {
        $rules = new SwingReactionRules;
        $plan = $this->readyPlan();
        $plan['last_bar_at'] = $this->at('2026-10-23 16:00')->toIso8601String();
        $plan['ready_recorded_at'] = $plan['last_bar_at'];
        $plan['last_recorded_at'] = $plan['last_bar_at'];
        $plan = $this->advance($rules, $plan, '09:30', 100.7, 101.1, 100.9);
        $this->assertFalse($plan['evidence_gap']);
        $this->assertSame('entry_touched', $plan['status']);
    }

    public function test_expiration_uses_entry_and_holding_windows_and_does_not_reopen(): void
    {
        $rules = new SwingReactionRules;
        $watch = $this->plan();
        $expired = $rules->expire($watch, $this->at('2026-10-28 16:00'));
        $this->assertSame('expired', $expired['status']);
        $this->assertSame('no_entry', $expired['outcome']);
        $entered = $this->advance($rules, $this->readyPlan(), '09:40', 100.7, 101.1, 100.9);
        $this->assertSame($this->at('2026-11-02 16:00')->toIso8601String(), $entered['holding_deadline']);
        $expired = $rules->expire($entered, $this->at('2026-11-02 16:00'));
        $this->assertSame('ambiguous', $expired['outcome']); // A stale last bar cannot establish no stop or target.
        $this->assertSame($expired, $rules->expire($expired, $this->at('2026-11-03 16:00')));
        $entered['last_bar_at'] = $entered['holding_deadline'];
        $expired = $rules->expire($entered, $this->at('2026-11-02 16:00'));
        $this->assertSame('holding_ended', $expired['outcome']);
    }

    public function test_a_ready_gap_ends_ambiguously_and_a_delayed_target_cancels_before_entry(): void
    {
        $rules = new SwingReactionRules;
        $plan = $this->readyPlan();
        $result = $this->advance($rules, $plan, '09:45', 100.7, 101.1, 100.9);
        $this->assertSame('ambiguous', $result['status']);
        $this->assertNull($result['entry_at']);

        $plan['ready_recorded_at'] = $this->at('2026-10-26 10:00')->toIso8601String();
        $result = $rules->advance($plan, $this->bar('09:40', 100.7, 105.1, 104.), $this->at('2026-10-26 10:00'));
        $this->assertSame('cancelled', $result['status']);
        $this->assertSame('target_before_entry', $result['outcome']);
        $this->assertNull($result['entry_at']);
    }

    public function test_a_stop_touch_after_entry_does_not_need_a_close_beyond_the_boundary(): void
    {
        $rules = new SwingReactionRules;
        $plan = $this->advance($rules, $this->readyPlan(), '09:40', 100.7, 101.1, 100.9);
        $plan = $this->advance($rules, $plan, '09:45', 98.5, 101., 100.);
        $this->assertSame('stop_reached', $plan['outcome']);
        $this->assertSame('completed', $plan['status']);
        $this->assertSame(4, $plan['observations']);
        $this->assertSame($plan['last_bar_at'], $plan['last_at']);
    }

    public function test_outcome_bar_extremes_cannot_invent_excursions_after_a_stop_or_target(): void
    {
        $rules = new SwingReactionRules;
        $entered = $this->advance($rules, $this->readyPlan(), '09:40', 100.7, 101.1, 100.9);
        $stopped = $this->advance($rules, $entered, '09:45', 98., 104., 100.);
        $this->assertSame('stop_reached', $stopped['outcome']);
        $this->assertSame(0., $stopped['metrics']['mfe_r']);
        $this->assertSame(1., $stopped['metrics']['mae_r']);
        $this->assertFalse($stopped['metrics']['reached_1r']);
        $this->assertFalse($stopped['metrics']['reached_05r']);

        $target = $this->advance($rules, $entered, '09:45', 99., 107., 104.);
        $this->assertSame('target_reached', $target['outcome']);
        $this->assertEqualsWithDelta(1.6, $target['metrics']['mfe_r'], .000001);
        $this->assertSame(0., $target['metrics']['mae_r']);
        $this->assertEqualsWithDelta(100 * 4 / 101, $target['metrics']['mfe_pct'], .000001);

        $prior = $this->advance($rules, $entered, '09:45', 100., 102., 101.);
        $stopped = $this->advance($rules, $prior, '09:50', 98., 104., 100.);
        $this->assertSame($prior['metrics']['mfe_r'], $stopped['metrics']['mfe_r']);
        $this->assertSame($prior['metrics']['mfe_pct'], $stopped['metrics']['mfe_pct']);
        $ambiguous = $this->advance($rules, $prior, '09:50', 98., 107., 103.);
        $this->assertSame('ambiguous', $ambiguous['status']);
        $this->assertSame($prior['metrics'], $ambiguous['metrics']);
    }

    public function test_large_price_discontinuities_cannot_manufacture_entry_or_outcomes(): void
    {
        $rules = new SwingReactionRules;
        $entered = $this->advance($rules, $this->readyPlan(), '09:40', 100.7, 101.1, 100.9);
        foreach ([$this->plan(), $this->readyPlan(), $entered] as $plan) {
            $time = $plan['status'] === 'entry_touched' ? '09:45' : ($plan['status'] === 'ready' ? '09:40' : '09:30');
            $result = $this->advance($rules, $plan, $time, 48., 52., 50.);
            $this->assertSame('ambiguous', $result['status']);
            $this->assertTrue($result['evidence_discontinuity']);
            $this->assertSame($plan['metrics'], $result['metrics']);
            $this->assertSame($plan['entry_at'], $result['entry_at']);
            $this->assertNotSame('target_reached', $result['outcome']);
            $this->assertNotSame('stop_reached', $result['outcome']);
        }
        $jumped = $this->advance($rules, $entered, '09:45', 150., 152., 151.);
        $this->assertSame('ambiguous', $jumped['outcome']);
        $this->assertSame($entered['metrics'], $jumped['metrics']);
    }

    public function test_the_entry_deadline_respects_thanksgiving_and_the_friday_half_day(): void
    {
        $input = $this->input();
        $day = CarbonImmutable::parse('2026-11-23', 'America/New_York');
        $dates = [];
        while (count($dates) < 70) {
            if (\App\Support\MarketSession::isTradingDay($day)) {
                $dates[] = $day->toDateString();
            }
            $day = $day->subDay();
        }
        foreach (array_reverse($dates) as $i => $date) {
            $input['prices'][$i]['date'] = $date;
        }
        $input['data_date'] = '2026-11-23';
        foreach ($input['strikes'] as &$row) {
            $row['expirations'][0]['data_date'] = '2026-11-23';
            $row['expirations'][0]['expiration'] = '2026-12-18';
        }
        unset($row);
        $plans = (new SwingReactionRules)->propose($input, 'short', $this->at('2026-11-23 16:01'));
        $this->assertNotEmpty($plans);
        $this->assertSame($this->at('2026-11-27 13:00')->toIso8601String(), $plans[0]['entry_deadline']);
        $this->assertSame([], (new SwingReactionRules)->propose($input, 'short', $this->at('2026-11-23 15:59')));
    }

    public function test_malformed_duplicate_future_and_non_rth_bars_are_ignored(): void
    {
        $rules = new SwingReactionRules;
        $plan = $this->readyPlan();
        $valid = $this->bar('09:40', 100.7, 101.1, 100.9);
        foreach ([['low' => NAN], ['at' => 'not a time'], ['high' => 100.], ['start_at' => $this->at('2026-10-26 09:41')->toIso8601String()]] as $change) {
            $this->assertSame($plan, $rules->advance($plan, [...$valid, ...$change], $this->at('2026-10-26 10:00')));
        }
        $this->assertSame($plan, $rules->advance($plan, $valid, $this->at('2026-10-26 09:43')));
        $this->assertSame($plan, $this->advance($rules, $plan, '08:00', 100.7, 101.1, 100.9));
        $this->assertSame($plan, $this->advance($rules, $plan, '09:35', 100.4, 100.8, 100.6));
    }

    private function input(): array
    {
        $input = SwingInput::make();
        foreach ($input['prices'] as &$bar) {
            foreach (['open', 'high', 'low', 'close'] as $field) {
                $bar[$field] = round($bar[$field] - 5.9, 5);
            }
        }
        unset($bar);
        $input['strikes'] = [];
        foreach ([94 => 10000., 95 => -9000000., 96 => 10000., 99 => 10000., 100 => -10000000.,
            101 => 10000., 104 => 10000., 105 => 10000000., 106 => 10000., 109 => 10000., 110 => 9000000., 111 => 10000.] as $strike => $gex) {
            $this->addStrike($input, (float) $strike, $gex);
        }

        return $input;
    }

    private function addStrike(array &$input, float $strike, float $gex): void
    {
        $input['strikes'][] = ['strike' => $strike, 'expirations' => [['expiration' => '2026-11-20',
            'data_date' => '2026-10-23', 'net_gex' => $gex, 'known_oi' => 10000., 'covered_oi' => 10000.,
            'contract_rows' => 2, 'excluded_rows' => 0]]];
    }

    private function plan(string $side = 'put', string $pattern = 'hold_reclaim'): array
    {
        $plans = (new SwingReactionRules)->propose($this->input(), 'short', $this->at('2026-10-23 16:01'));

        return array_values(array_filter($plans, fn ($p) => $p['side'] === $side && $p['pattern'] === $pattern))[0];
    }

    private function readyPlan(): array
    {
        $rules = new SwingReactionRules;
        $plan = $this->advance($rules, $this->plan(), '09:30', 99.9, 100.2, 100.);

        return $this->advance($rules, $plan, '09:35', 100.4, 100.8, 100.6);
    }

    private function advance(SwingReactionRules $rules, array $plan, string $time, float $low, float $high, float $close): array
    {
        $bar = $this->bar($time, $low, $high, $close);

        return $rules->advance($plan, $bar, CarbonImmutable::parse($bar['at']));
    }

    private function bar(string $time, float $low, float $high, float $close): array
    {
        $start = $this->at('2026-10-26 '.$time);

        return ['start_at' => $start->toIso8601String(), 'at' => $start->addMinutes(5)->toIso8601String(),
            'open' => ($low + $high) / 2, 'low' => $low, 'high' => $high, 'close' => $close];
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'America/New_York')->utc();
    }
}
