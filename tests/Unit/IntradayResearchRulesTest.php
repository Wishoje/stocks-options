<?php

namespace Tests\Unit;

use App\Support\IntradayResearch\IntradayResearchRules as Rules;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class IntradayResearchRulesTest extends TestCase
{
    public function test_only_a_whole_bar_after_private_publication_can_qualify(): void
    {
        $sample = $this->sample();
        $original = $sample;
        $plan = Rules::create($sample, $this->at('10:00:30'), 'post_publication_retest');
        $this->assertSame($this->at('10:15:30')->toIso8601String(), $plan['entry_deadline']);
        $this->assertSame($sample['entry_expires_at'], $plan['original_entry_expires_at']);
        $waiting = Rules::evaluate($plan, $this->bars('10:05'), $this->at('10:20:05'));
        $this->assertSame('watching', $waiting['status']);
        $this->assertNull($waiting['signal_at']);
        $actual = Rules::evaluate($plan, $this->bars('10:10'), $this->at('10:25:05'));
        $this->assertSame('conditions_met', $actual['status']);
        $this->assertSame($this->at('10:10')->toIso8601String(), $actual['signal_at']);
        $this->assertTrue($actual['notification_eligible']);
        $this->assertSame($original, $sample);
        foreach (['entry_low', 'entry_high', 'target', 'invalidation'] as $key) {
            $this->assertSame($sample[$key], $actual[$key]);
        }
    }

    public function test_baseline_keeps_original_signal_close_and_never_notifies(): void
    {
        $plan = Rules::create($this->sample(), $this->at('10:00:30'), 'baseline');
        $state = Rules::evaluate($plan, $this->bars('10:10'), $this->at('10:25:05'));
        $this->assertSame($this->sample()['signal_at'], $state['signal_at']);
        $this->assertSame(101., $state['signal_price']);
        $this->assertFalse($state['notification_eligible']);
        $this->assertSame($this->sample()['available_at'], $state['signal_available_at']);
    }

    public function test_target_or_invalidation_before_entry_cannot_be_recycled(): void
    {
        foreach ([105., 98.] as $price) {
            $bars = $this->bars('10:10');
            $bars[1] = $this->bar('09:55', $price);
            $state = Rules::evaluate($this->plan(), $bars, $this->at('10:25:05'));
            $this->assertSame('not_qualified', $state['status']);
            $this->assertSame('resolved_before_entry', $state['outcome']);
            $this->assertNull($state['signal_at']);
            $this->assertFalse($state['notification_eligible']);
        }
    }

    public function test_same_batch_later_terminal_close_suppresses_fresh_alert(): void
    {
        foreach ([105. => 'target_reached', 98. => 'invalidated'] as $price => $outcome) {
            $bars = [...$this->bars('10:10'), $this->bar('10:15', (float) $price)];
            $state = Rules::evaluate($this->plan(), $bars, $this->at('10:30:05'));
            $this->assertSame('completed', $state['status']);
            $this->assertSame($outcome, $state['outcome']);
            $this->assertTrue($state['metrics']['followup_complete']);
            $this->assertFalse($state['notification_eligible']);
            $this->assertSame($this->at('10:10')->toIso8601String(), $state['signal_at']);
        }
    }

    public function test_wicks_and_exact_invalidation_boundary_do_not_end_close_based_case(): void
    {
        $bars = $this->bars('10:10');
        $bars[4]['high'] = 106.;
        $bars[4]['low'] = 98.;
        $bars[] = $this->bar('10:15', 99.);
        $state = Rules::evaluate($this->plan(), $bars, $this->at('10:30:05'));
        $this->assertSame('in_progress', $state['status']);
        $this->assertNull($state['outcome']);
        $this->assertTrue($state['notification_eligible']);
    }

    public function test_directional_entry_boundaries_and_bearish_outcomes(): void
    {
        foreach ([['bullish', 100., false], ['bullish', 102., true], ['bearish', 100., true], ['bearish', 102., false]] as [$direction, $price, $qualifies]) {
            $sample = $this->sample();
            $sample['direction'] = $direction;
            if ($direction === 'bearish') {
                $sample['invalidation'] = 103.;
                $sample['target'] = 97.;
                $sample['invalidation_side'] = 'above';
            }
            $plan = Rules::create($sample, $this->at('10:00:30'), 'post_publication_retest');
            $bars = $this->bars('10:10');
            $bars[4] = $this->bar('10:10', $price);
            $state = Rules::evaluate($plan, $bars, $this->at('10:25:05'));
            $this->assertSame($qualifies, $state['metrics']['qualified']);
            if ($qualifies && $direction === 'bearish') {
                $state = Rules::evaluate($plan, [...$bars, $this->bar('10:15', 97.)], $this->at('10:30:05'));
                $this->assertSame('target_reached', $state['outcome']);
                $this->assertGreaterThan(0, $state['metrics']['outcome_move_pct']);
            }
        }
    }

    public function test_future_delayed_bars_and_stale_evidence_cannot_raise_alerts(): void
    {
        $plan = $this->plan();
        $state = Rules::evaluate($plan, $this->bars('10:10'), $this->at('10:24:59'));
        $this->assertSame('watching', $state['status']);
        $this->assertNull($state['signal_at']);
        $stale = Rules::evaluate($plan, $this->bars('10:10'), $this->at('10:40'));
        $this->assertFalse($stale['notification_eligible']);
        $closed = Rules::evaluate($plan, $this->bars('10:10'), $this->at('16:01'));
        $this->assertFalse($closed['notification_eligible']);
    }

    public function test_missing_duplicate_and_malformed_bars_need_review(): void
    {
        $missing = $this->bars('10:10');
        unset($missing[1]);
        $duplicate = [...$this->bars('10:10'), $this->bar('10:10', 101.)];
        $invalid = $this->bars('10:10');
        $invalid[4]['high'] = 100.;
        foreach ([$missing, $duplicate, $invalid] as $bars) {
            $state = Rules::evaluate($this->plan(), $bars, $this->at('10:25:05'));
            $this->assertSame('needs_update', $state['status']);
            $this->assertTrue($state['metrics']['needs_update']);
            $this->assertFalse($state['notification_eligible']);
        }
    }

    public function test_window_end_is_not_qualification_and_missing_last_bar_is_not_a_failure(): void
    {
        $bars = $this->bars('10:15', 102.5);
        $state = Rules::evaluate($this->plan(), $bars, $this->at('10:31'));
        $this->assertSame('not_qualified', $state['status']);
        $this->assertSame('entry_window_ended', $state['outcome']);
        $missing = Rules::evaluate($this->plan(), array_slice($bars, 0, -1), $this->at('10:31'));
        $this->assertSame('needs_update', $missing['status']);
        $later = Rules::evaluate($this->plan(), [...$bars, $this->bar('10:20', 105.)], $this->at('10:35'));
        $this->assertSame('entry_window_ended', $later['outcome']);
    }

    public function test_qualification_reception_is_preserved_across_evaluations(): void
    {
        $state = Rules::evaluate($this->plan(), $this->bars('10:10'), $this->at('10:25:05'));
        $plan = [...$this->plan(), 'qualified_recorded_at' => $state['qualified_recorded_at']];
        $later = Rules::evaluate($plan, $this->bars('10:15'), $this->at('10:30:05'));
        $this->assertSame($state['qualified_recorded_at'], $later['qualified_recorded_at']);
        $this->assertSame($state['signal_at'], $later['signal_at']);
        $this->assertSame($state['signal_price'], $later['signal_price']);
    }

    public function test_late_discovery_of_old_qualification_with_fresh_later_prices_does_not_notify(): void
    {
        $state = Rules::evaluate($this->plan(), $this->bars('10:45'), $this->at('11:00:05'));
        $this->assertSame('in_progress', $state['status']);
        $this->assertSame($this->at('10:10')->toIso8601String(), $state['signal_at']);
        $this->assertFalse($state['notification_eligible']);
    }

    public function test_half_day_window_is_capped_and_closed_session_cannot_create_a_watch(): void
    {
        $sample = $this->sample();
        $sample['session'] = '2026-11-27';
        foreach (['signal_at' => '12:35', 'available_at' => '12:50', 'review_until' => '13:00'] as $field => $time) {
            $sample[$field] = $this->at($time, '2026-11-27')->toIso8601String();
        }
        $plan = Rules::create($sample, $this->at('12:51', '2026-11-27'), 'post_publication_retest');
        $this->assertSame($sample['review_until'], $plan['entry_deadline']);
        $closed = Rules::create($sample, $this->at('13:01', '2026-11-27'), 'post_publication_retest');
        $this->assertSame('not_qualified', $closed['status']);
    }

    public function test_invalid_or_synthetic_inputs_cannot_qualify(): void
    {
        foreach (['demonstration' => true, 'timeframe' => '14d', 'rule_version' => 'unknown-policy', 'target' => 100., 'available_at' => $this->at('10:01')->toIso8601String(), 'signal_at' => $this->at('09:45:01')->toIso8601String()] as $key => $value) {
            $sample = [...$this->sample(), $key => $value];
            $plan = Rules::create($sample, $this->at('10:00:30'), 'post_publication_retest');
            $this->assertSame('needs_update', $plan['status']);
            $this->assertFalse(Rules::evaluate($plan, $this->bars('10:10'), $this->at('10:25:05'))['notification_eligible']);
        }
    }

    private function plan(): array
    {
        return Rules::create($this->sample(), $this->at('10:00:30'), 'post_publication_retest');
    }

    private function sample(): array
    {
        return ['id' => 'original', 'mode' => 'intraday', 'symbol' => 'SPY', 'session' => '2026-10-30', 'timeframe' => '7d',
            'direction' => 'bullish', 'side' => 'put', 'pattern' => 'reversal', 'rule_version' => 'conditional-walls.v1',
            'signal_at' => $this->at('09:45')->toIso8601String(), 'available_at' => $this->at('10:00')->toIso8601String(),
            'entry_expires_at' => $this->at('10:00')->toIso8601String(), 'review_until' => $this->at('16:00')->toIso8601String(),
            'signal_price' => 101., 'entry_low' => 100., 'entry_high' => 102., 'invalidation' => 99., 'invalidation_side' => 'below', 'target' => 105.];
    }

    private function bars(string $through, float $close = 101.): array
    {
        $bars = [];
        for ($at = $this->at('09:50'); $at <= $this->at($through); $at = $at->addMinutes(5)) {
            $bars[] = [...$this->bar('09:50', $close), 'at' => $at->toIso8601String()];
        }

        return $bars;
    }

    private function bar(string $time, float $close): array
    {
        return ['at' => $this->at($time)->toIso8601String(), 'open' => $close, 'high' => $close + .1, 'low' => $close - .1, 'close' => $close];
    }

    private function at(string $time, string $date = '2026-10-30'): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time, 'America/New_York')->utc();
    }
}
