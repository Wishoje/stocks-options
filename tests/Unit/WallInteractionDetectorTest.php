<?php

namespace Tests\Unit;

use App\Support\WallIntelligence\IntradayWallDemo;
use App\Support\WallIntelligence\WallInteractionDetector;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class WallInteractionDetectorTest extends TestCase
{
    private function at(int $index): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-30 10:00', 'America/New_York')->addMinutes(5 * $index);
    }

    private function bars(array $closes): array
    {
        $previous = 100.6;
        $bars = [];
        foreach ($closes as $i => $close) {
            $bars[] = ['t' => $this->at($i)->getTimestampMs(), 'o' => $previous,
                'h' => max($previous, $close) + .001, 'l' => min($previous, $close) - .001, 'c' => $close];
            $previous = $close;
        }

        return $bars;
    }

    private function observations(int $count): array
    {
        return array_map(fn ($i) => ['observed_at' => $this->at($i)->toIso8601String(), 'scope_key' => 'scope', 'basis_key' => 'basis',
            'scope' => ['symbol' => 'SPY', 'session' => '2026-09-30', 'timeframe' => '14d'],
            'provenance' => ['quote_source' => 'test'], 'walls' => ['put' => [['strike' => 100]], 'call' => [['strike' => 110]]]], range(0, $count - 1));
    }

    private function analyze(array $bars, ?array $observations = null, ?CarbonImmutable $cutoff = null): array
    {
        return (new WallInteractionDetector)->analyze('SPY', '2026-09-30', '14d',
            $observations ?? $this->observations(count($bars)), $bars, $cutoff ?? $this->at(count($bars)));
    }

    public function test_touch_bounce_break_acceptance_retest_and_continuation_have_separate_evidence(): void
    {
        $result = $this->analyze($this->bars([100.5, 100.03, 100.3, 99.7, 99.6, 99.98, 99.65, 99.5]));
        $events = array_values(array_filter($result['events'], fn ($event) => $event['side'] === 'put'));
        $this->assertSame(['testing', 'bounce', 'break', 'acceptance_below', 'testing', 'failed_reclaim', 'confirmed_breakdown'], array_column($events, 'status'));
        $last = $result['current']['put'];
        $this->assertSame('confirmed_breakdown', $last['status']);
        $this->assertNotNull($last['retest_time']);
        $this->assertTrue($last['accepted_below']);
        $this->assertSame('completed-5m.v1', $last['rule_version']);
        $this->assertSame($result['bars'][7]['id'], $last['evidence_bar_ids'][0]);
        $this->assertFalse($result['historical_outcome_eligible']);
        $this->assertFalse($result['actionable']);
    }

    public function test_rules_are_symmetric_for_a_break_higher_and_reclaim_lower(): void
    {
        $bars = $this->bars([100.5, 100.03, 100.3, 99.7, 99.6, 99.98, 99.65, 99.5]);
        foreach ($bars as &$bar) {
            [$bar['o'], $bar['c'], $bar['h'], $bar['l']] = [210 - $bar['o'], 210 - $bar['c'], 210 - $bar['l'], 210 - $bar['h']];
        }
        unset($bar);
        $result = $this->analyze($bars);
        $this->assertSame('confirmed_breakout', $result['current']['call']['status']);
        $this->assertTrue($result['current']['call']['accepted_above']);
        $this->assertContains('rejection', array_column($result['events'], 'status'));
    }

    public function test_wick_only_touch_and_band_boundary_do_not_count_as_breaks(): void
    {
        $bars = $this->bars([100.5, 100.4, 99.95]);
        $bars[1]['l'] = 99.4;
        $result = $this->analyze($bars);
        $this->assertSame('touch', $result['readings'][1]['status']);
        $this->assertSame('testing', $result['current']['put']['status']);
        $this->assertNotContains('break', array_column($result['events'], 'status'));
    }

    public function test_one_close_cannot_produce_acceptance_or_reclaim_and_unfinished_bars_are_excluded(): void
    {
        $bars = $this->bars([100.5, 99.8, 99.7, 100.2, 100.3]);
        $first = $this->analyze($bars, cutoff: $this->at(2)->subSecond());
        $this->assertCount(1, $first['bars']);
        $one = $this->analyze(array_slice($bars, 0, 2));
        $this->assertSame('break', $one['current']['put']['status']);
        $back = $this->analyze(array_slice($bars, 0, 4));
        $this->assertNotSame('reclaim', $back['current']['put']['status']);
        $this->assertSame('reclaim', $this->analyze($bars)['current']['put']['status']);
    }

    public function test_remaining_below_without_retest_never_means_failed_reclaim_or_confirmed_break(): void
    {
        $result = $this->analyze($this->bars([100.5, 99.7, 99.6, 99.5, 99.4, 99.3]));
        $this->assertSame('acceptance_below', $result['current']['put']['status']);
        $this->assertNull($result['current']['put']['retest_time']);
        $this->assertNotContains('failed_reclaim', array_column($result['events'], 'status'));
        $this->assertNotContains('confirmed_breakdown', array_column($result['events'], 'status'));
    }

    public function test_a_skipped_bar_resets_the_close_count_instead_of_bridging_it(): void
    {
        $bars = $this->bars([100.5, 99.7, 99.6, 99.5]);
        unset($bars[2]);
        $result = $this->analyze(array_values($bars), $this->observations(4), $this->at(4));
        $this->assertSame('unknown', $result['current']['put']['status']);
        $this->assertNotContains('acceptance_below', array_column($result['events'], 'status'));
    }

    public function test_wall_changes_and_option_basis_changes_restart_an_episode(): void
    {
        foreach (['strike', 'basis', 'scope', 'source'] as $change) {
            $observations = $this->observations(3);
            match ($change) {
                'strike' => $observations[2]['walls']['put'][0]['strike'] = 99,
                'basis' => $observations[2]['basis_key'] = 'new-expiry-inputs',
                'scope' => $observations[2]['scope_key'] = 'new-expirations',
                'source' => $observations[2]['provenance']['quote_source'] = 'new-source',
            };
            $result = $this->analyze($this->bars([100.5, 99.7, 99.6]), $observations);
            $this->assertNotSame('acceptance_below', $result['current']['put']['status'], $change);
            $this->assertSame(0, $result['current']['put']['consecutive_closes_beyond'], $change);
        }
    }

    public function test_a_wall_changing_within_a_bar_or_only_available_later_cannot_explain_that_bar(): void
    {
        $observations = $this->observations(3);
        $observations[1]['observed_at'] = $this->at(1)->addMinutes(2)->toIso8601String();
        $observations[1]['walls']['put'][0]['strike'] = 99;
        $result = $this->analyze($this->bars([100.5, 99.7, 99.6]), $observations);
        $this->assertNotContains('break', array_column($result['events'], 'status'));
        $later = $this->observations(1);
        $later[0]['observed_at'] = $this->at(2)->toIso8601String();
        $this->assertEmpty($this->analyze($this->bars([100.5, 99.7]), $later)['events']);
    }

    public function test_ambiguous_wide_bar_cannot_supply_touch_break_retest_and_confirmation_in_one_bar(): void
    {
        $bars = $this->bars([100.5, 99.5]);
        $bars[1]['h'] = 102;
        $bars[1]['l'] = 98;
        $result = $this->analyze($bars);
        $this->assertSame('break', $result['current']['put']['status']);
        $this->assertNull($result['current']['put']['retest_time']);
        $this->assertFalse($result['current']['put']['accepted_below']);
    }

    public function test_retest_after_the_window_cannot_confirm_a_failed_reclaim(): void
    {
        $result = $this->analyze($this->bars([100.5, 99.7, 99.6, 99.6, 99.6, 99.6, 99.6, 99.6, 99.99, 99.4, 99.2]));
        $this->assertNotContains('failed_reclaim', array_column($result['events'], 'status'));
        $this->assertNotContains('confirmed_breakdown', array_column($result['events'], 'status'));
    }

    public function test_retest_before_acceptance_cannot_be_reordered_into_a_confirmed_sequence(): void
    {
        $result = $this->analyze($this->bars([100.5, 99.7, 99.98, 99.65, 99.5]));
        $this->assertSame('acceptance_below', $result['current']['put']['status']);
        $this->assertNotContains('failed_reclaim', array_column($result['events'], 'status'));
        $this->assertNotContains('confirmed_breakdown', array_column($result['events'], 'status'));
    }

    public function test_invalid_duplicate_and_out_of_session_bars_are_not_evidence(): void
    {
        $bars = $this->bars([100.5, 99.7, 99.6, 99.5]);
        $bars[1]['h'] = 90; // Impossible OHLC.
        $bars[] = $bars[2]; // Ambiguous duplicate time.
        $bars[] = ['t' => $this->at(-10)->getTimestampMs(), 'o' => 100, 'h' => 102, 'l' => 99, 'c' => 100];
        $result = $this->analyze($bars, $this->observations(4), $this->at(4));
        $this->assertCount(2, $result['bars']);
        $this->assertNotContains('acceptance_below', array_column($result['events'], 'status'));
    }

    public function test_stale_quotes_session_rolls_and_early_close_do_not_create_current_confirmation(): void
    {
        $bars = $this->bars([100.5, 99.7, 99.6, 99.5, 99.4, 99.3]);
        $result = $this->analyze($bars, $this->observations(1));
        $this->assertSame('unknown', $result['current']['put']['status']);
        $old = $this->observations(6);
        foreach ($old as &$o) {
            $o['scope']['session'] = '2026-09-29';
        }
        $this->assertEmpty($this->analyze($bars, $old)['events']);
        $this->assertSame('awaiting_update', $this->analyze($bars, cutoff: $this->at(10))['state']);
        $holiday = (new WallInteractionDetector)->analyze('SPY', '2026-11-26', '14d', [], $bars, $this->at(10));
        $this->assertEmpty($holiday['bars']);
        $start = CarbonImmutable::parse('2026-11-27 13:00', 'America/New_York');
        $afterClose = [['t' => $start->getTimestampMs(), 'o' => 100, 'h' => 101, 'l' => 99, 'c' => 100]];
        $this->assertEmpty((new WallInteractionDetector)->analyze('SPY', '2026-11-27', '14d', [], $afterClose, $start->addHour())['bars']);
    }

    public function test_local_demo_contains_inspectable_break_and_reclaim_examples(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $demo = (new IntradayWallDemo)->build('SPY');
        $this->assertSame('synthetic_review', $demo['dataset']);
        $statuses = array_column($demo['wall_interaction']['events'], 'status');
        $this->assertContains('confirmed_breakdown', $statuses);
        $this->assertContains('reclaim', $statuses);
        $this->assertContains('confirmed_breakout', $statuses);
    }
}
