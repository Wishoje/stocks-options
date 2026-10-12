<?php

namespace Tests\Unit;

use App\Support\WallIntelligence\WallSetupOutcomeEvaluator;
use App\Support\WallIntelligence\WallSetupRules;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class WallSetupOutcomeEvaluatorTest extends TestCase
{
    private function at(int $minutes): string
    {
        return CarbonImmutable::parse('2026-09-30 10:00', 'America/New_York')->addMinutes($minutes)->toIso8601String();
    }

    private function caseSeed(array $changes = []): array
    {
        return array_replace(['id' => 'setup-one', 'side' => 'put', 'strike' => 100.0, 'pattern' => 'reversal',
            'direction' => 'bullish', 'status' => 'conditions_met', 'entry_eligible_at_observation' => true,
            'observed_at' => $this->at(0), 'confirmed_at' => $this->at(0), 'close' => 100.1,
            'entry_zone' => ['low' => 100.05, 'high' => 100.15], 'invalidation' => ['price' => 99.95],
            'target' => ['strike' => 101.0], 'wall_identity' => 'wall-one', 'evidence_grade' => 'strong',
            'scope' => ['symbol' => 'SPY', 'session' => '2026-09-30', 'timeframe' => '14d', 'units' => 'USD_per_1pct_move']], $changes);
    }

    private function interaction(array $closes, int $cutoffMinutes = 60, array $identities = []): array
    {
        $bars = [];
        $rows = [];
        foreach ($closes as $i => $close) {
            $bars[] = ['id' => 'bar-'.$i, 't' => strtotime($this->at($i * 5)) * 1000, 'c' => $close, 'h' => 105, 'l' => 95];
            foreach (['put', 'call'] as $side) {
                $rows[] = ['side' => $side, 'observed_at' => $this->at(($i + 1) * 5), 'wall_identity' => $identities[$i] ?? 'wall-one'];
            }
        }

        return ['symbol' => 'SPY', 'session' => '2026-09-30', 'timeframe' => '14d',
            'as_of' => $this->at(count($closes) * 5), 'evaluated_through' => $this->at($cutoffMinutes), 'bars' => $bars, 'readings' => $rows];
    }

    private function review(array $closes, ?array $seed = null, int $cutoff = 60, array $identities = []): array
    {
        return (new WallSetupOutcomeEvaluator)->analyze($this->interaction($closes, $cutoff, $identities), [$seed ?? $this->caseSeed()]);
    }

    public function test_unique_setup_keeps_its_first_eligible_reading_and_frozen_target(): void
    {
        $context = ['symbol' => 'SPY', 'session' => '2026-09-30', 'timeframe' => '14d', 'rule_version' => WallSetupRules::VERSION,
            'readings' => [$this->caseSeed(['status' => 'watch']), $this->caseSeed(), $this->caseSeed(['observed_at' => $this->at(5), 'target' => ['strike' => 105]])]];
        $seeds = (new WallSetupOutcomeEvaluator)->seeds($context);
        $this->assertCount(1, $seeds);
        $this->assertSame(101.0, $seeds[0]['target']['strike']);
        $this->assertSame($this->at(0), $seeds[0]['observed_at']);
        $result = $this->review([100.3, 101.0]);
        $this->assertSame('target_reached', $result['cases'][0]['outcome']);
        $this->assertSame(1, $result['summary']['qualified_outcomes']['target_reached']);
        $this->assertFalse($result['historical_outcome_eligible']);
        $this->assertFalse($result['cases'][0]['executed_trade']);
        $this->assertNull($result['win_rate']);
    }

    public function test_closes_determine_outcomes_and_wicks_do_not_assume_intrabar_order(): void
    {
        $result = $this->review([100.2, 99.94]); // Every synthetic bar also crosses both levels with its wick.
        $this->assertSame('invalidated', $result['cases'][0]['outcome']);
        $this->assertSame(99.94, $result['cases'][0]['outcome_close']);
        $this->assertCount(2, $result['cases'][0]['follow_up_bars']);
        $this->assertSame('in_progress', $this->review([100.2, 99.95], cutoff: 10)['cases'][0]['outcome']);
    }

    public function test_bearish_direction_mirrors_the_price_boundaries(): void
    {
        $seed = $this->caseSeed(['side' => 'call', 'direction' => 'bearish', 'close' => 99.9,
            'entry_zone' => ['low' => 99.85, 'high' => 99.95], 'target' => ['strike' => 99.0], 'invalidation' => ['price' => 100.05]]);
        $this->assertSame('target_reached', $this->review([99.5, 99.0], $seed)['cases'][0]['outcome']);
        $this->assertSame('invalidated', $this->review([100.06], $seed)['cases'][0]['outcome']);
    }

    public function test_complete_horizon_expires_and_partial_continuity_is_unresolved(): void
    {
        $this->assertSame('expired', $this->review(array_fill(0, 12, 100.2))['cases'][0]['outcome']);
        $this->assertSame('unresolved', $this->review([100.2, 100.3])['cases'][0]['outcome']);
        $interaction = $this->interaction([100.2, 100.3, 101.1]);
        unset($interaction['bars'][1]);
        $result = (new WallSetupOutcomeEvaluator)->analyze($interaction, [$this->caseSeed()]);
        $this->assertSame('unresolved', $result['cases'][0]['outcome']);
        $this->assertCount(1, $result['cases'][0]['follow_up_bars']);
    }

    public function test_wall_comparison_change_ends_review_before_the_later_price_result(): void
    {
        $case = $this->review([100.2, 101.2], identities: [1 => 'new-wall'])['cases'][0];
        $this->assertSame('expired', $case['outcome']);
        $this->assertCount(1, $case['follow_up_bars']);
        $this->assertNull($case['outcome_close']);
    }

    public function test_future_bars_and_wrong_scope_do_not_change_an_earlier_review(): void
    {
        $before = $this->review([100.2], cutoff: 5);
        $after = $this->review([100.2, 101.2], cutoff: 5);
        $this->assertSame($before['cases'], $after['cases']);
        $seed = $this->caseSeed();
        $seed['scope']['symbol'] = 'QQQ';
        $this->assertEmpty($this->review([101.2], $seed)['cases']);
        $bad = $this->caseSeed(['target' => ['strike' => 100.11]]);
        $this->assertEmpty($this->review([101.2], $bad)['cases']);
    }

    public function test_cases_that_never_qualified_stay_visible_outside_the_qualified_denominator(): void
    {
        $context = $this->interaction([100.2]);
        $seeds = [$this->caseSeed(['id' => 'missed', 'status' => 'entry_missed']), $this->caseSeed(['id' => 'rejected', 'status' => 'no_setup', 'target' => null])];
        $result = (new WallSetupOutcomeEvaluator)->analyze($context, $seeds);
        $this->assertSame(2, $result['summary']['scenarios']);
        $this->assertSame(0, $result['summary']['qualified']);
        $this->assertSame(1, $result['summary']['entry_missed']);
        $this->assertSame(1, $result['summary']['no_setup']);
        $this->assertSame(0, array_sum($result['summary']['qualified_outcomes']));
    }

    public function test_overlapping_reviews_are_flagged_instead_of_claimed_as_independent_samples(): void
    {
        $result = (new WallSetupOutcomeEvaluator)->analyze($this->interaction([100.2, 101.2]),
            [$this->caseSeed(), $this->caseSeed(['id' => 'second', 'side' => 'call', 'observed_at' => $this->at(5)])]);
        $this->assertSame(2, $result['summary']['qualified']);
        $this->assertSame(1, $result['summary']['overlapping_reviews']);
    }

    public function test_early_close_caps_the_follow_up_window(): void
    {
        $seed = $this->caseSeed();
        $seed['observed_at'] = '2026-11-27T17:55:00Z';
        $seed['confirmed_at'] = $seed['observed_at'];
        $seed['scope']['session'] = '2026-11-27';
        $context = $this->interaction([]);
        $context['session'] = '2026-11-27';
        $context['evaluated_through'] = '2026-11-27T18:00:00Z';
        $context['bars'] = [['id' => 'close', 't' => strtotime($seed['observed_at']) * 1000, 'c' => 100.2]];
        $context['readings'] = [['side' => 'put', 'observed_at' => '2026-11-27T18:00:00Z', 'wall_identity' => 'wall-one']];
        $case = (new WallSetupOutcomeEvaluator)->analyze($context, [$seed])['cases'][0];
        $this->assertSame('2026-11-27T18:00:00+00:00', $case['review_until']);
        $this->assertSame('expired', $case['outcome']);
    }
}
