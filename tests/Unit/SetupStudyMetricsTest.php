<?php

namespace Tests\Unit;

use App\Services\SetupStudy\SetupStudyService;
use App\Support\SetupStudy\SetupStudyMetrics;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SetupStudyMetricsTest extends TestCase
{
    private function sample(array $changes = []): array
    {
        return array_replace(['id' => 'sample', 'mode' => 'intraday', 'horizon' => 'session', 'symbol' => 'SPY',
            'direction' => 'bullish', 'signal_at' => '2026-10-27T19:40:00Z', 'available_at' => '2026-10-27T19:40:00Z',
            'signal_price' => 100., 'entry_low' => 99.8, 'entry_high' => 100.2, 'invalidation' => 99., 'target' => 103.,
            'review_until' => '2026-10-27T20:00:00Z', 'native_status' => 'conditions_met', 'confirmations' => [], 'bars' => []], $changes);
    }

    private function bars(array $closes, string $from = '2026-10-27T19:45:00Z'): array
    {
        return array_map(fn ($close, $i) => ['at' => CarbonImmutable::parse($from)->addMinutes(5 * $i)->toIso8601String(),
            'open' => $close, 'high' => $close + .1, 'low' => $close - .1, 'close' => $close], $closes, array_keys($closes));
    }

    private function measure(array $sample): array
    {
        return (new SetupStudyMetrics)->measure($sample, CarbonImmutable::parse('2026-10-27T21:00:00Z'));
    }

    public function test_partial_move_is_distinct_from_exact_target_and_counts_before_invalidation(): void
    {
        $r = $this->measure($this->sample(['bars' => $this->bars([100.6, 101.2, 100., 99.5])]));
        $this->assertTrue($r['followup_complete']);
        $this->assertSame('session_ended', $r['metrics']['strict_outcome']);
        $this->assertTrue($r['metrics']['reached_05r']);
        $this->assertTrue($r['metrics']['reached_1r']);
        $this->assertSame(1.2, $r['metrics']['mfe_r']);
        $this->assertSame(.5, $r['metrics']['mae_r']);
    }

    public function test_later_rally_never_erases_an_earlier_invalidation(): void
    {
        $r = $this->measure($this->sample(['bars' => $this->bars([100.6, 98.9, 105., 106.])]));
        $this->assertTrue($r['followup_complete']);
        $this->assertSame('invalidated', $r['metrics']['strict_outcome']);
        $this->assertSame(.6, $r['metrics']['mfe_r']);
        $this->assertTrue($r['metrics']['reached_05r']);
        $this->assertFalse($r['metrics']['reached_1r']);
        $this->assertSame(2, $r['metrics']['observations']);
    }

    public function test_target_can_complete_before_review_deadline_with_bearish_mirror(): void
    {
        $r = $this->measure($this->sample(['direction' => 'bearish', 'invalidation' => 101., 'target' => 97.,
            'bars' => $this->bars([99., 97.])]));
        $this->assertTrue($r['followup_complete']);
        $this->assertSame('target_reached', $r['metrics']['strict_outcome']);
        $this->assertSame(3., $r['metrics']['mfe_r']);
        $this->assertSame(0., $r['metrics']['mae_r']);
    }

    public function test_gap_or_no_followup_cannot_be_called_a_completed_failure_or_success(): void
    {
        $r = $this->measure($this->sample(['bars' => $this->bars([104.], '2026-10-27T19:50:00Z')]));
        $this->assertFalse($r['followup_complete']);
        $this->assertTrue($r['needs_update']);
        $this->assertTrue($r['metrics']['evidence_gap']);
        $empty = $this->measure($this->sample());
        $this->assertFalse($empty['followup_complete']);
        $this->assertNull($empty['metrics']['mfe_r']);
        $this->assertNull($empty['metrics']['reached_05r']);
        $this->assertNull($r['metrics']['strict_outcome']);
    }

    public function test_future_and_after_deadline_prices_do_not_influence_results(): void
    {
        $r = (new SetupStudyMetrics)->measure($this->sample(['bars' => $this->bars([100.2, 104., 105.])]),
            CarbonImmutable::parse('2026-10-27T19:46:00Z'));
        $this->assertFalse($r['followup_complete']);
        $this->assertFalse($r['metrics']['reached_05r']);
        $this->assertSame('in_progress', $r['status']);
        $this->assertSame(1, $r['metrics']['observations']);
    }

    public function test_invalidation_equality_is_not_a_cross_and_wicks_are_not_close_targets(): void
    {
        $bars = $this->bars([99., 100., 100., 100.]);
        $bars[0]['high'] = 110.;
        $r = $this->measure($this->sample(['bars' => $bars]));
        $this->assertSame('session_ended', $r['metrics']['strict_outcome']);
        $this->assertFalse($r['metrics']['reached_05r']);
        $this->assertSame(0., $r['metrics']['mfe_r']);
    }

    public function test_entry_revisit_cannot_use_a_bar_that_started_before_publication(): void
    {
        $bars = $this->bars([100., 101., 101., 101.]);
        $r = $this->measure($this->sample(['available_at' => '2026-10-27T19:41:45Z', 'bars' => $bars]));
        $this->assertFalse($r['metrics']['entry_revisited']);
        $this->assertTrue($r['metrics']['entry_observable']);
        $later = $this->measure($this->sample(['available_at' => '2026-10-27T20:15:00Z', 'bars' => $bars]));
        $this->assertNull($later['metrics']['entry_revisited']);
        $this->assertFalse($later['metrics']['entry_observable']);
    }

    public function test_swing_follows_market_sessions_across_weekends_and_early_closes(): void
    {
        $sample = $this->sample(['mode' => 'swing', 'horizon' => 'short', 'signal_at' => '2026-11-25T21:00:00Z',
            'available_at' => '2026-11-25T22:00:00Z', 'review_until' => '2026-11-27T18:00:00Z', 'bars' => [
                ['at' => '2026-11-27T18:00:00Z', 'high' => 101., 'low' => 100., 'close' => 100.5],
            ]]);
        $r = (new SetupStudyMetrics)->measure($sample, CarbonImmutable::parse('2026-11-27T19:00:00Z'));
        $this->assertTrue($r['followup_complete']);
        $this->assertFalse($r['metrics']['evidence_gap']);
        $this->assertSame('holding_ended', $r['metrics']['strict_outcome']);
        $this->assertTrue($r['metrics']['entry_revisited']);
    }

    public function test_pending_and_unverifiable_records_do_not_dilute_completed_rates(): void
    {
        $winner = $this->measure($this->sample(['bars' => $this->bars([103.])]));
        $unknown = $this->measure($this->sample());
        $watch = $this->measure($this->sample(['signal_at' => null, 'signal_price' => null, 'native_status' => 'watching']));
        $summary = (new SetupStudyService)->summarize([$winner, $unknown, $watch]);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['qualified']);
        $this->assertSame(1, $summary['watching']);
        $this->assertSame(['hits' => 1, 'eligible' => 1, 'rate' => 100.], $summary['strict_target']);
        $this->assertNull((new SetupStudyService)->summarize([])['strict_target']['rate']);
    }

    public function test_prepublication_targets_are_not_counted_as_after_publication_opportunities(): void
    {
        $early = $this->measure($this->sample(['available_at' => '2026-10-27T19:55:00Z', 'bars' => $this->bars([101., 103.])]));
        $late = $this->measure($this->sample(['available_at' => '2026-10-27T19:41:00Z', 'bars' => $this->bars([101., 103.])]));
        $earlyInvalid = $this->measure($this->sample(['available_at' => '2026-10-27T19:55:00Z', 'bars' => $this->bars([98.9])]));
        $unknown = $this->measure($this->sample(['available_at' => null, 'bars' => $this->bars([103.])]));
        $summary = (new SetupStudyService)->summarize([$early, $late, $earlyInvalid, $unknown]);
        $this->assertSame('resolved_before_publication', $early['metrics']['publication_timing']);
        $this->assertSame('open_at_publication', $late['metrics']['publication_timing']);
        $this->assertSame('timing_unknown', $unknown['metrics']['publication_timing']);
        $this->assertSame(2, $summary['resolved_before_publication']);
        $this->assertSame(1, $summary['publication_timing_unknown']);
        $this->assertSame(['hits' => 1, 'eligible' => 1, 'rate' => 100.], $summary['after_publication_target']);
        $this->assertSame(['hits' => 3, 'eligible' => 4, 'rate' => 75.], $summary['strict_target']);
    }
}
