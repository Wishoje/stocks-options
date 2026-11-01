<?php

namespace Tests\Feature;

use App\Support\SwingResearch\SwingResearchDemo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SwingResearchDemoTest extends TestCase
{
    public function test_synthetic_comparison_covers_both_horizons_and_different_outcomes_without_side_effects(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00', 'America/New_York'));
        Http::preventStrayRequests();
        Bus::fake();
        $environment = app()->environment();
        try {
            app()['env'] = 'local';
            foreach (['short', 'position'] as $horizon) {
                $report = (new SwingResearchDemo)->report($this->filters($horizon));
                $this->assertTrue($report['demonstration']);
                $this->assertFalse($report['enabled']);
                $this->assertCount(3, $report['groups']);
                $this->assertSame(['baseline', 'nearer_entry', 'earlier_exit'], array_column($report['summary'], 'variant'));
                [$spy, $qqq, $nvda] = $report['groups'];
                $spyCases = array_column($spy['cases'], null, 'variant');
                $this->assertSame('watching', $spyCases['baseline']['status']);
                $this->assertSame('in_progress', $spyCases['nearer_entry']['status']);
                $this->assertLessThan($spyCases['baseline']['latest_price']['distance_to_entry_pct'], $spyCases['nearer_entry']['latest_price']['distance_to_entry_pct']);
                $qqqCases = array_column($qqq['cases'], null, 'variant');
                $this->assertSame('in_progress', $qqqCases['baseline']['status']);
                $this->assertSame('target_reached', $qqqCases['earlier_exit']['state']['outcome']);
                $this->assertTrue($qqqCases['earlier_exit']['metrics']['followup_complete']);
                $this->assertSame($qqqCases['baseline']['state']['qualified_at'], $qqqCases['earlier_exit']['state']['qualified_at']);
                foreach ($nvda['cases'] as $case) {
                    $this->assertSame('bearish', $case['state']['direction']);
                    $this->assertSame('invalidated', $case['state']['outcome']);
                }
            }
            $filtered = (new SwingResearchDemo)->report([...$this->filters('short'), 'symbol' => 'QQQ']);
            $this->assertCount(1, $filtered['groups']);
            $empty = (new SwingResearchDemo)->report([...$this->filters('short'), 'symbol' => 'UNKNOWN']);
            $this->assertSame([], $empty['groups']);
            $this->assertSame(['hits' => 0, 'eligible' => 0, 'rate' => null], $empty['summary'][0]['target_rate']);
            Http::assertNothingSent();
            Bus::assertNothingDispatched();
        } finally {
            app()['env'] = $environment;
        }
    }

    public function test_synthetic_research_is_not_available_in_production(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new SwingResearchDemo)->report($this->filters('short'));
    }

    private function filters(string $horizon): array
    {
        return ['horizon' => $horizon, 'from' => '2026-10-01', 'to' => '2026-10-30', 'symbol' => ''];
    }
}
