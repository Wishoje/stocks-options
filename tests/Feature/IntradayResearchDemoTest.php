<?php

namespace Tests\Feature;

use App\Support\IntradayResearch\IntradayResearchDemo;
use App\Support\IntradayResearch\IntradayResearchRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntradayResearchDemoTest extends TestCase
{
    public function test_synthetic_paired_examples_show_target_invalidation_and_entry_window_end_without_side_effects(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00', 'America/New_York'));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Http::preventStrayRequests();
        Bus::fake();
        $environment = app()->environment();
        try {
            app()['env'] = 'local';
            DB::enableQueryLog();
            $report = (new IntradayResearchDemo)->report($this->filters());
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $this->assertSame(['prepared', 'enabled', 'demonstration', 'filters', 'variants', 'summary', 'groups', 'collection', 'warnings'], array_keys($report));
            $this->assertTrue($report['prepared']);
            $this->assertTrue($report['demonstration']);
            $this->assertFalse($report['enabled']);
            $this->assertSame(IntradayResearchRules::variants(), $report['variants']);
            $this->assertSame(['SPY', 'QQQ', 'NVDA'], array_column($report['groups'], 'symbol'));
            $this->assertSame(['baseline', 'post_publication_retest'], array_column($report['summary'], 'variant'));
            $this->assertSame([], $queries);
            $this->assertSame([], $report['collection']);
            $this->assertStringContainsString('invented prices', implode(' ', $report['warnings']));

            foreach ($report['groups'] as $group) {
                $this->assertSame('2026-10-30', $group['session']);
                $this->assertSame(['id', 'symbol', 'session', 'created_at', 'source_signal_at', 'source_available_at', 'cases'], array_keys($group));
                $this->assertCount(2, $group['cases']);
                foreach ($group['cases'] as $case) {
                    $this->assertSame(['id', 'variant', 'label', 'status', 'state', 'notification_recorded_at', 'alert_read_at'], array_keys($case));
                    $this->assertFalse($case['state']['notification_eligible']);
                    $this->assertNull($case['notification_recorded_at']);
                    $this->assertNull($case['alert_read_at']);
                    $this->assertFalse($case['state']['metrics']['needs_update']);
                }
            }
            [$spy, $qqq, $nvda] = $report['groups'];
            foreach ($spy['cases'] as $case) {
                $this->assertSame('target_reached', $case['state']['outcome']);
                $this->assertSame('completed', $case['status']);
                $this->assertGreaterThan(0, $case['state']['metrics']['outcome_move_pct']);
            }
            foreach ($qqq['cases'] as $case) {
                $this->assertSame('bearish', $case['state']['direction']);
                $this->assertSame('invalidated', $case['state']['outcome']);
                $this->assertLessThan(0, $case['state']['metrics']['outcome_move_pct']);
            }
            $nvdaCases = array_column($nvda['cases'], null, 'variant');
            $this->assertSame('session_ended', $nvdaCases['baseline']['state']['outcome']);
            $this->assertSame('entry_window_ended', $nvdaCases['post_publication_retest']['state']['outcome']);
            $this->assertSame('not_qualified', $nvdaCases['post_publication_retest']['status']);
            $this->assertFalse($nvdaCases['post_publication_retest']['state']['metrics']['qualified']);
            $this->assertSame(['hits' => 1, 'eligible' => 3, 'rate' => 33.3], $report['summary'][0]['target_rate']);
            $this->assertSame(['hits' => 1, 'eligible' => 2, 'rate' => 50.0], $report['summary'][1]['target_rate']);
            $this->assertSame(3, $report['summary'][0]['completed']);
            $this->assertSame(2, $report['summary'][1]['completed']);
            Http::assertNothingSent();
            Bus::assertNothingDispatched();
        } finally {
            DB::disableQueryLog();
            app()['env'] = $environment;
        }
    }

    public function test_symbol_and_date_filters_keep_empty_denominators_and_half_day_session_end(): void
    {
        $environment = app()->environment();
        try {
            app()['env'] = 'local';
            $demo = new IntradayResearchDemo;
            $filtered = $demo->report([...$this->filters(), 'symbol' => 'QQQ']);
            $this->assertSame(['QQQ'], array_column($filtered['groups'], 'symbol'));
            $this->assertSame(['hits' => 0, 'eligible' => 1, 'rate' => 0.0], $filtered['summary'][1]['target_rate']);
            foreach ([['symbol' => 'UNKNOWN'], ['from' => '2026-10-31', 'to' => '2026-10-31']] as $override) {
                $empty = $demo->report([...$this->filters(), ...$override]);
                $this->assertSame([], $empty['groups']);
                foreach ($empty['summary'] as $summary) {
                    $this->assertSame(0, $summary['total']);
                    $this->assertSame(['hits' => 0, 'eligible' => 0, 'rate' => null], $summary['target_rate']);
                    $this->assertNull($summary['avg_outcome_move_pct']);
                }
            }
            $halfDay = $demo->report([...$this->filters(), 'from' => '2026-11-27', 'to' => '2026-11-27', 'symbol' => 'NVDA']);
            $this->assertSame('2026-11-27', $halfDay['groups'][0]['session']);
            $case = $halfDay['groups'][0]['cases'][0];
            $this->assertSame('2026-11-27T18:00:00+00:00', $case['state']['review_until']);
            $this->assertSame('session_ended', $case['state']['outcome']);
            $this->assertTrue($case['state']['metrics']['followup_complete']);
        } finally {
            app()['env'] = $environment;
        }
    }

    public function test_examples_are_rejected_outside_the_local_environment(): void
    {
        $environment = app()->environment();
        try {
            app()['env'] = 'production';
            $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
            (new IntradayResearchDemo)->report($this->filters());
        } finally {
            app()['env'] = $environment;
        }
    }

    private function filters(): array
    {
        return ['from' => '2026-10-01', 'to' => '2026-10-31', 'session' => '', 'symbol' => ''];
    }
}
