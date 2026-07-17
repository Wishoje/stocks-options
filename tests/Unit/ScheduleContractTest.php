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
}
