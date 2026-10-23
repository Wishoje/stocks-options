<?php

namespace Tests\Unit;

use App\Support\WallIntelligence\IntradayWallModel;
use App\Support\WallIntelligence\WallInteractionDetector;
use App\Support\WallIntelligence\WallSetupEvaluator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class WallSetupEvaluatorTest extends TestCase
{
    private function at(int $i): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-30 10:00', 'America/New_York')->addMinutes($i * 5);
    }

    private function observations(int $count): array
    {
        return array_map(fn ($i) => ['observed_at' => $this->at($i)->toIso8601String(), 'evidence_hash' => 'wall-'.$i,
            'scope_key' => 'scope', 'basis_key' => 'basis', 'model_version' => IntradayWallModel::MODEL,
            'scope' => ['symbol' => 'SPY', 'session' => '2026-09-30', 'timeframe' => '14d', 'units' => 'USD_per_1pct_move', 'expiries' => ['2026-10-02']],
            'provenance' => ['quote_source' => 'test', 'oi_date' => '2026-09-29'],
            'audit' => ['oi_input_coverage_pct' => 100],
            'walls' => ['put' => [['strike' => 100, 'net_gex' => -1000], ['strike' => 99, 'net_gex' => -500]],
                'call' => [['strike' => 101, 'net_gex' => 1000], ['strike' => 102, 'net_gex' => 500]]]], range(0, $count - 1));
    }

    private function bars(array $closes): array
    {
        $bars = [];
        $previous = 100.3;
        foreach ($closes as $i => $close) {
            $bars[] = ['t' => $this->at($i)->getTimestampMs(), 'o' => $previous,
                'h' => max($close, $previous) + .001, 'l' => min($close, $previous) - .001, 'c' => $close];
            $previous = $close;
        }

        return $bars;
    }

    private function analyze(array $closes, ?array $observations = null, ?array $bars = null, ?CarbonImmutable $cutoff = null): array
    {
        $observations ??= $this->observations(count($closes));
        $interaction = (new WallInteractionDetector)->analyze('SPY', '2026-09-30', '14d', $observations,
            $bars ?? $this->bars($closes), $cutoff ?? $this->at(count($closes)));

        return (new WallSetupEvaluator)->analyze($interaction, $observations);
    }

    public function test_continuation_requires_ordered_confirmation_and_freezes_supported_levels(): void
    {
        $result = $this->analyze([100.3, 99.93, 99.9, 100.0, 99.94, 99.88]);
        $put = $result['current']['put'];
        $this->assertSame('conditions_met', $put['status']);
        $this->assertSame('bearish', $put['direction']);
        $this->assertSame('continuation', $put['pattern']);
        $this->assertSame('strong', $put['evidence_grade']);
        $this->assertEqualsWithDelta(99.85, $put['entry_zone']['low'], .000001);
        $this->assertEqualsWithDelta(100.05, $put['invalidation']['price'], .000001);
        $this->assertSame(99.0, $put['target']['strike']);
        $this->assertEqualsWithDelta(4.25, $put['target_room_ratio'], .000001);
        $this->assertTrue($put['entry_eligible_at_observation']);
        $this->assertFalse($put['live_entry_eligible']);
        $this->assertFalse($result['actionable']);
        $this->assertFalse($result['historical_outcome_eligible']);
        $this->assertNull($result['win_probability']);
        $this->assertSame('wall-5', $put['target']['wall_evidence_hash']);
        $putHistory = array_values(array_filter($result['readings'], fn ($r) => $r['side'] === 'put'));
        $this->assertNotContains('conditions_met', array_column(array_slice($putHistory, 0, -1), 'status'));
    }

    public function test_reversal_needs_two_consecutive_closes_after_a_touch(): void
    {
        $first = $this->analyze([100.3, 100.01, 100.09]);
        $this->assertSame('watch', $first['current']['put']['status']);
        $result = $this->analyze([100.3, 100.01, 100.09, 100.12]);
        $this->assertSame('conditions_met', $result['current']['put']['status']);
        $this->assertSame('reversal', $result['current']['put']['pattern']);
        $this->assertSame('bullish', $result['current']['put']['direction']);
        $this->assertSame(101.0, $result['current']['put']['target']['strike']);
    }

    public function test_reclaim_requires_a_later_retest_and_separate_close_away(): void
    {
        $closes = [100.3, 99.93, 99.9, 100.07, 100.1, 100.0, 100.12];
        $before = $this->analyze(array_slice($closes, 0, -1));
        $this->assertSame('watch', $before['current']['put']['status']);
        $result = $this->analyze($closes);
        $this->assertSame('reclaim', $result['current']['put']['pattern']);
        $this->assertSame('conditions_met', $result['current']['put']['status']);
    }

    public function test_mirrored_call_wall_break_uses_bullish_geometry(): void
    {
        $closes = [100.3, 99.93, 99.9, 100.0, 99.94, 99.88];
        $observations = $this->observations(count($closes));
        foreach ($observations as &$o) {
            $walls = $o['walls'];
            foreach (['put' => 'call', 'call' => 'put'] as $from => $to) {
                $o['walls'][$to] = array_map(fn ($w) => ['strike' => 200 - $w['strike'], 'net_gex' => -$w['net_gex']], $walls[$from]);
            }
        }
        unset($o);
        $bars = $this->bars($closes);
        foreach ($bars as &$bar) {
            [$bar['o'], $bar['c'], $bar['h'], $bar['l']] = [200 - $bar['o'], 200 - $bar['c'], 200 - $bar['l'], 200 - $bar['h']];
        }
        unset($bar);
        $call = $this->analyze($closes, $observations, $bars)['current']['call'];
        $this->assertSame('conditions_met', $call['status']);
        $this->assertSame('bullish', $call['direction']);
        $this->assertEqualsWithDelta(99.95, $call['invalidation']['price'], .000001);
        $this->assertSame(101.0, $call['target']['strike']);
    }

    public function test_missed_entries_do_not_reopen_when_price_returns_to_the_zone(): void
    {
        $result = $this->analyze([100.3, 99.93, 99.9, 100.0, 99.94, 99.8, 99.9]);
        $this->assertSame('entry_missed', $result['current']['put']['status']);
        $this->assertFalse($result['current']['put']['entry_eligible_at_observation']);
    }

    public function test_target_must_be_recorded_and_a_nearer_obstacle_cannot_be_skipped(): void
    {
        $closes = [100.3, 99.93, 99.9, 100.0, 99.94, 99.88];
        $observations = $this->observations(6);
        foreach ($observations as &$o) {
            $o['walls']['put'] = [['strike' => 100, 'net_gex' => -1000]];
        }
        unset($o);
        $put = $this->analyze($closes, $observations)['current']['put'];
        $this->assertSame('no_setup', $put['status']);
        $this->assertNull($put['target']);
        foreach ($observations as &$o) {
            $o['walls']['put'][] = ['strike' => 99.8, 'net_gex' => -100];
            $o['walls']['put'][] = ['strike' => 99, 'net_gex' => -90];
        }
        unset($o);
        $put = $this->analyze($closes, $observations)['current']['put'];
        $this->assertSame('no_setup', $put['status']);
        $this->assertSame(99.8, $put['target']['strike']);
        $this->assertEqualsWithDelta(.25, $put['target_room_ratio'], .000001);
    }

    public function test_later_levels_do_not_change_earlier_assessments_or_frozen_targets(): void
    {
        $prefix = [100.3, 99.93, 99.9, 100.0, 99.94, 99.88];
        $before = $this->analyze($prefix);
        $observations = $this->observations(7);
        $observations[6]['walls']['put'][1]['strike'] = 98;
        $after = $this->analyze([...$prefix, 99.9], $observations);
        $this->assertSame($before['readings'], array_slice($after['readings'], 0, count($before['readings'])));
        $this->assertSame(99.0, $after['current']['put']['target']['strike']);
        $this->assertSame('wall-5', $after['current']['put']['confirmation_wall_hash']);
    }

    public function test_invalidation_and_session_close_end_the_scenario(): void
    {
        $closes = [100.3, 99.93, 99.9, 100.0, 99.94, 99.88];
        $put = $this->analyze([...$closes, 100.06])['current']['put'];
        $this->assertSame('invalidated', $put['status']);
        $result = $this->analyze($closes, cutoff: CarbonImmutable::parse('2026-09-30 16:00', 'America/New_York'));
        $this->assertSame('expired', $result['current']['put']['status']);
        $this->assertSame('conditions_met', $result['current']['put']['status_at_observation']);
        $this->assertFalse($result['current']['put']['live_entry_eligible']);
    }

    public function test_stale_assessments_and_entry_timeouts_cannot_claim_current_conditions(): void
    {
        $closes = [100.3, 99.93, 99.9, 100.0, 99.94, 99.88];
        $this->assertSame('awaiting_update', $this->analyze($closes, cutoff: $this->at(9))['current']['put']['status']);
        $more = [...$closes, 99.9, 99.9, 99.9, 99.9];
        $this->assertSame('expired', $this->analyze($more)['current']['put']['status']);
    }

    public function test_early_market_close_caps_the_entry_window(): void
    {
        $session = '2026-11-27';
        $start = CarbonImmutable::parse($session.' 12:25', 'America/New_York');
        $observations = $this->observations(6);
        $bars = $this->bars([100.3, 99.93, 99.9, 100.0, 99.94, 99.88]);
        foreach ($observations as $i => &$observation) {
            $observation['observed_at'] = $start->addMinutes($i * 5)->toIso8601String();
            $observation['scope']['session'] = $session;
            $observation['scope']['expiries'] = ['2026-12-04'];
            $bars[$i]['t'] = $start->addMinutes($i * 5)->getTimestampMs();
        }
        unset($observation);
        $detector = new WallInteractionDetector;
        $evaluator = new WallSetupEvaluator;
        $beforeClose = $evaluator->analyze($detector->analyze('SPY', $session, '14d', $observations, $bars, $start->addMinutes(30)), $observations);
        $this->assertSame('conditions_met', $beforeClose['current']['put']['status']);
        $this->assertSame('2026-11-27T18:00:00+00:00', $beforeClose['current']['put']['expires_at']);
        $closed = $evaluator->analyze($detector->analyze('SPY', $session, '14d', $observations, $bars, $start->addMinutes(35)), $observations);
        $this->assertSame('expired', $closed['current']['put']['status']);
    }

    public function test_gaps_scope_changes_and_invalid_model_inputs_do_not_produce_ready_setups(): void
    {
        $closes = [100.3, 100.01, 100.09, 100.12];
        $bars = $this->bars($closes);
        unset($bars[2]);
        $this->assertNotSame('conditions_met', $this->analyze($closes, bars: array_values($bars))['current']['put']['status']);
        $observations = $this->observations(4);
        $observations[3]['basis_key'] = 'changed';
        $this->assertNotSame('conditions_met', $this->analyze($closes, $observations)['current']['put']['status']);
        $observations = $this->observations(4);
        foreach ($observations as &$o) {
            $o['audit']['oi_input_coverage_pct'] = 90;
        }
        unset($o);
        $this->assertSame('no_setup', $this->analyze($closes, $observations)['current']['put']['status']);
        foreach ($observations as &$o) {
            $o['scope']['symbol'] = 'QQQ';
        }
        unset($o);
        $this->assertEmpty($this->analyze($closes, $observations)['readings']);
    }

    public function test_wick_only_contacts_and_touch_band_boundaries_cannot_confirm_entries(): void
    {
        $closes = [100.3, 100.3, 100.05, 100.05];
        $bars = $this->bars($closes);
        $bars[1]['l'] = 99.5;
        $result = $this->analyze($closes, bars: $bars);
        $this->assertNotContains('conditions_met', array_column($result['readings'], 'status'));
    }

    public function test_target_reached_is_descriptive_and_does_not_imply_an_executed_trade(): void
    {
        $result = $this->analyze([100.3, 99.93, 99.9, 100.0, 99.94, 99.88, 98.99]);
        $this->assertSame('target_reached', $result['current']['put']['status']);
        $this->assertFalse($result['current']['put']['actionable']);
    }

    public function test_next_condition_advances_one_step_at_a_time_through_the_actual_break_sequence(): void
    {
        $closes = [100.3, 99.93, 99.9, 100.0, 99.94, 99.88];
        $steps = [];
        foreach (range(2, 6) as $count) {
            $steps[] = $this->analyze(array_slice($closes, 0, $count))['current']['put']['next_condition'];
        }
        $this->assertSame(['acceptance_close', 'retest', 'retest_failure', 'continuation_close', 'conditions_met'], array_column($steps, 'code'));
        $this->assertSame(1, $steps[0]['completed_closes']);
        $this->assertSame(2, $steps[0]['required_closes']);
        $this->assertEqualsWithDelta(99.95, $steps[0]['threshold'], .000001);
        $this->assertEqualsWithDelta(99.89, $steps[3]['threshold'], .000001);
        $this->assertSame('below', $steps[3]['close_side']);
        $this->assertTrue($steps[3]['threshold_inclusive']);
        $this->assertStringContainsString('at or below 99.89', $steps[3]['text']);
        $this->assertNull($steps[4]['completed_closes']);
    }

    public function test_reversal_progress_counts_only_consecutive_qualifying_closes_and_resets_inside_the_band(): void
    {
        $one = $this->analyze([100.3, 100.01, 100.09])['current']['put']['next_condition'];
        $this->assertSame('reversal_close', $one['code']);
        $this->assertSame(1, $one['completed_closes']);
        $this->assertSame('above', $one['close_side']);
        $this->assertStringContainsString('second consecutive five-minute close above 100.05', $one['text']);
        $reset = $this->analyze([100.3, 100.01, 100.09, 100.01])['current']['put']['next_condition'];
        $this->assertSame(0, $reset['completed_closes']);
        $again = $this->analyze([100.3, 100.01, 100.09, 100.01, 100.09])['current']['put']['next_condition'];
        $this->assertSame(1, $again['completed_closes']);
        $bars = $this->bars([100.3, 100.01, 100.09, 100.12]);
        unset($bars[2]);
        $gap = $this->analyze([100.3, 100.01, 100.09, 100.12], bars: array_values($bars))['current']['put']['next_condition'];
        $this->assertNull($gap['completed_closes']);
    }

    public function test_reclaim_next_condition_distinguishes_the_retest_from_the_later_close(): void
    {
        $closes = [100.3, 99.93, 99.9, 100.07, 100.1];
        $this->assertSame('retest', $this->analyze($closes)['current']['put']['next_condition']['code']);
        $next = $this->analyze([...$closes, 100.0])['current']['put']['next_condition'];
        $this->assertSame('reclaim_close', $next['code']);
        $this->assertStringContainsString('separate five-minute close above 100.05', $next['text']);
        $this->assertSame('conditions_met', $this->analyze([...$closes, 100.0, 100.12])['current']['put']['next_condition']['code']);
    }

    public function test_next_condition_follows_terminal_and_stale_overrides_without_reusing_an_active_entry(): void
    {
        $closes = [100.3, 99.93, 99.9, 100.0, 99.94, 99.88];
        $stale = $this->analyze($closes, cutoff: $this->at(9))['current']['put'];
        $this->assertSame('awaiting_update', $stale['next_condition']['code']);
        $closed = $this->analyze($closes, cutoff: CarbonImmutable::parse('2026-09-30 16:00', 'America/New_York'))['current']['put'];
        $this->assertSame('expired', $closed['next_condition']['code']);
        $this->assertSame('conditions_met', $closed['status_at_observation']);
        $this->assertSame('invalidated', $this->analyze([...$closes, 100.06])['current']['put']['next_condition']['code']);
        $this->assertSame('entry_missed', $this->analyze([...array_slice($closes, 0, -1), 99.8])['current']['put']['next_condition']['code']);
    }
}
