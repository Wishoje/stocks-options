<?php

namespace Tests\Unit;

use App\Support\Swing\SwingIdeaRules;
use App\Support\SwingResearch\SwingResearchRules;
use InvalidArgumentException;
use Tests\Fixtures\SwingInput;
use Tests\TestCase;

class SwingResearchRulesTest extends TestCase
{
    public function test_baseline_has_identical_production_fields_for_both_directions_and_horizons(): void
    {
        $research = new SwingResearchRules;
        $customer = new SwingIdeaRules;

        foreach (['bullish', 'bearish'] as $direction) {
            foreach (array_keys(SwingIdeaRules::HORIZONS) as $horizon) {
                $input = $this->input($direction);
                $original = $input;
                $expected = $customer->propose($input, $horizon);
                $actual = $research->propose($input, $horizon, 'baseline');

                $this->assertSame($direction, $expected['direction']);
                $this->assertSame(SwingIdeaRules::VERSION, $actual['rule_version']);
                $this->assertSame(SwingResearchRules::VERSION, $actual['research_version']);
                $this->assertSame('baseline', $actual['variant']);
                unset($actual['research_version'], $actual['variant']);
                $this->assertSame($expected, $actual);
                $this->assertSame($expected, $customer->proposeWithEntryCandidates($input, $horizon, 3));
                $this->assertSame($original, $input);
            }
        }
    }

    public function test_expanded_candidates_can_choose_a_fourth_ranked_wall_without_moving_targets(): void
    {
        $rules = new SwingResearchRules;
        foreach (['bullish', 'bearish'] as $direction) {
            foreach (array_keys(SwingIdeaRules::HORIZONS) as $horizon) {
                $input = $this->input($direction, true);
                $baseline = $rules->propose($input, $horizon, 'baseline');
                $nearer = $rules->propose($input, $horizon, 'nearer_entry');

                $this->assertSame('watching', $baseline['status']);
                $this->assertSame('watching', $nearer['status']);
                $this->assertSame($direction === 'bullish' ? 106. : 94., $baseline['levels']['wall']);
                $this->assertSame($direction === 'bullish' ? 106.25 : 93.75, $nearer['levels']['wall']);
                $this->assertSame($baseline['levels']['target'], $nearer['levels']['target']);
                $this->assertSame($baseline['entry_deadline'], $nearer['entry_deadline']);
                $this->assertSame($baseline['max_holding_sessions'], $nearer['max_holding_sessions']);
                $this->assertGreaterThanOrEqual(1.5, $nearer['evidence']['target_room_ratio']);
            }
        }
    }

    public function test_research_preserves_quality_target_and_two_atr_distance_gates(): void
    {
        $missingBar = SwingInput::make();
        unset($missingBar['prices'][40]);
        $staleOptions = SwingInput::make();
        $staleOptions['strikes'][0]['expirations'][0]['data_date'] = '2026-10-22';
        $lowCoverage = SwingInput::make();
        foreach ($lowCoverage['strikes'] as &$row) {
            $row['expirations'][0]['covered_oi'] = 9700.;
        }
        unset($row);
        $nearObstacle = SwingInput::make();
        $nearObstacle['strikes'][1]['strike'] = 108.;
        $distantWall = SwingInput::make();
        $distantWall['strikes'][0]['strike'] = 102.;
        $rules = new SwingResearchRules;

        foreach ([$missingBar, $staleOptions, $lowCoverage, $nearObstacle, $distantWall] as $input) {
            foreach (array_keys(SwingIdeaRules::HORIZONS) as $horizon) {
                $expected = (new SwingIdeaRules)->propose($input, $horizon);
                $this->assertSame('no_idea', $expected['status']);
                foreach (array_keys(SwingResearchRules::variants()) as $variant) {
                    $actual = $rules->propose($input, $horizon, $variant);
                    unset($actual['research_version'], $actual['variant']);
                    $this->assertSame($expected, $actual);
                }
            }
        }
    }

    public function test_earlier_exit_preserves_admission_evidence_and_changes_only_the_target(): void
    {
        $rules = new SwingResearchRules;
        foreach (['bullish', 'bearish'] as $direction) {
            foreach (array_keys(SwingIdeaRules::HORIZONS) as $horizon) {
                $baseline = $rules->propose($this->input($direction), $horizon, 'baseline');
                $earlier = $rules->propose($this->input($direction), $horizon, 'earlier_exit');
                $far = $baseline['levels'][$direction === 'bullish' ? 'entry_high' : 'entry_low'];
                $expected = $far + .5 * ($baseline['levels']['target'] - $far);

                $this->assertSame('watching', $earlier['status']);
                $this->assertSame($baseline['levels']['target'], $earlier['original_target']);
                $this->assertSame($expected, $earlier['levels']['target']);
                $this->assertSame($baseline['evidence'], $earlier['evidence']);
                $this->assertLessThan(1.5, abs($expected - $far) / abs($far - $earlier['levels']['invalidation']));
                $earlier['levels']['target'] = $earlier['original_target'];
                unset($earlier['original_target']);
                $earlier['variant'] = 'baseline';
                $this->assertSame($baseline, $earlier);
            }
        }
    }

    public function test_qualification_is_shared_and_only_a_later_close_can_reach_the_earlier_exit(): void
    {
        $rules = new SwingResearchRules;
        foreach (['bullish', 'bearish'] as $direction) {
            foreach (array_keys(SwingIdeaRules::HORIZONS) as $horizon) {
                $qualified = [];
                foreach (array_keys(SwingResearchRules::variants()) as $variant) {
                    $plan = $rules->propose($this->input($direction), $horizon, $variant);
                    $bar = $this->bar($direction, '2026-10-26', 112., 105.9, 106.5);
                    $qualified[$variant] = $rules->advance($plan, $bar);
                    $this->assertSame('conditions_met', $qualified[$variant]['status']);
                    $this->assertSame('2026-10-26', $qualified[$variant]['qualified_at']);
                    $this->assertNull($qualified[$variant]['outcome']);
                    $this->assertSame($plan['levels'], $qualified[$variant]['levels']);
                    $this->assertSame($qualified[$variant], $rules->advance($qualified[$variant],
                        $this->bar($direction, '2026-10-26', 112., 105.9, 111.)));
                }

                $targetWick = $this->bar($direction, '2026-10-27', 112., 106., 108.);
                foreach ($qualified as $variant => $plan) {
                    $qualified[$variant] = $rules->advance($plan, $targetWick);
                    $this->assertSame('in_progress', $qualified[$variant]['status']);
                }
                $laterClose = $this->bar($direction, '2026-10-28', 112., 107., 109.);
                $earlier = $rules->advance($qualified['earlier_exit'], $laterClose);
                $this->assertSame('target_reached', $earlier['outcome']);
                $this->assertSame('2026-10-28', $earlier['outcome_at']);
                $this->assertSame($qualified['earlier_exit']['levels'], $earlier['levels']);
                $this->assertSame($qualified['earlier_exit']['original_target'], $earlier['original_target']);
                $this->assertSame('in_progress', $rules->advance($qualified['baseline'], $laterClose)['status']);
                $this->assertSame('in_progress', $rules->advance($qualified['nearer_entry'], $laterClose)['status']);
                $this->assertSame($earlier, $rules->advance($earlier,
                    $this->bar($direction, '2026-10-29', 113., 103., 104.)));
            }
        }
    }

    public function test_research_uses_the_same_invalidation_and_missing_session_rules(): void
    {
        $rules = new SwingResearchRules;
        foreach (['bullish', 'bearish'] as $direction) {
            foreach (array_keys(SwingResearchRules::variants()) as $variant) {
                $plan = $rules->propose($this->input($direction), 'short', $variant);
                $missing = $rules->advance($plan, $this->bar($direction, '2026-10-27', 108., 105., 106.5));
                $this->assertSame('needs_update', $missing['status']);
                $plan = $rules->advance($plan, $this->bar($direction, '2026-10-26', 108., 105.9, 106.5));
                $invalidated = $rules->advance($plan, $this->bar($direction, '2026-10-27', 108., 103., 104.));
                $this->assertSame('invalidated', $invalidated['outcome']);
                $this->assertSame($plan['levels'], $invalidated['levels']);
                $this->assertSame($variant, $invalidated['variant']);
                $this->assertSame(SwingResearchRules::VERSION, $invalidated['research_version']);
            }
        }
    }

    public function test_unknown_variants_are_rejected(): void
    {
        $this->assertSame(['baseline', 'nearer_entry', 'earlier_exit'], array_keys(SwingResearchRules::variants()));
        $this->expectException(InvalidArgumentException::class);
        (new SwingResearchRules)->propose(SwingInput::make(), 'short', 'custom');
    }

    public function test_entry_candidate_count_cannot_become_an_unbounded_setting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SwingIdeaRules)->proposeWithEntryCandidates(SwingInput::make(), 'short', 1000);
    }

    private function input(string $direction, bool $extraWalls = false): array
    {
        $input = SwingInput::make();
        if ($extraWalls) {
            foreach ([[104.5, -9000000.], [103.5, -8000000.], [106.25, -7000000.]] as [$strike, $net]) {
                $wall = $input['strikes'][0];
                $wall['strike'] = $strike;
                $wall['expirations'][0]['net_gex'] = $net;
                $input['strikes'][] = $wall;
            }
        }
        if ($direction === 'bearish') {
            foreach ($input['prices'] as &$bar) {
                [$bar['high'], $bar['low']] = [200 - $bar['low'], 200 - $bar['high']];
                $bar['open'] = 200 - $bar['open'];
                $bar['close'] = 200 - $bar['close'];
            }
            unset($bar);
            foreach ($input['strikes'] as &$row) {
                $row['strike'] = 200 - $row['strike'];
                $row['expirations'][0]['net_gex'] *= -1;
            }
            unset($row);
        }

        return $input;
    }

    private function bar(string $direction, string $date, float $high, float $low, float $close): array
    {
        return $direction === 'bullish'
            ? ['date' => $date, 'high' => $high, 'low' => $low, 'close' => $close]
            : ['date' => $date, 'high' => 200 - $low, 'low' => 200 - $high, 'close' => 200 - $close];
    }
}
