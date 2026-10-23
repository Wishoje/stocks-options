<?php

namespace Tests\Unit;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleContractTest extends TestCase
{
    public function test_every_application_schedule_has_an_overlap_and_leader_policy(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $name = $event->description ?: $event->getSummaryForDisplay();
            $this->assertTrue($event->withoutOverlapping, "Schedule {$name} lacks withoutOverlapping.");
            $this->assertTrue($event->onOneServer, "Schedule {$name} lacks onOneServer.");
        }
    }

    public function test_volatility_metrics_have_one_scheduled_producer(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => $event->description === 'vol-metrics:core:eod');

        $this->assertCount(1, $events);
    }

    public function test_calculator_watchlist_has_one_five_minute_scheduled_producer(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => $event->description === 'calculator:prime:watchlist');

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * 1-5', $events->first()->expression);
        $this->assertSame('America/New_York', $events->first()->timezone);
        $this->assertStringContainsString('calculator:prime-watchlist', $events->first()->command);
    }

    public function test_retention_runs_daily_without_shortening_the_existing_windows(): void
    {
        $events = collect(app(Schedule::class)->events());
        $calculator = $events->firstWhere('description', 'calculator:prune-publications');
        $this->assertSame('15 3 * * *', $calculator->expression);
        $this->assertSame('America/New_York', $calculator->timezone);
        $this->assertStringContainsString('--hours=168', $calculator->command);
        $chain = $events->first(fn ($event) => str_contains($event->command ?? '', 'options:prune-chain-data'));
        $this->assertSame('35 3 * * *', $chain->expression);
        $this->assertStringContainsString('--days=180', $chain->command);
    }
}
