<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntradayScheduleWindowTest extends TestCase
{
    #[DataProvider('marketTimes')]
    public function test_intraday_window_uses_new_york_time_before_filters_are_built(string $instant, bool $expected): void
    {
        $this->travelTo(CarbonImmutable::parse($instant, 'UTC'));
        \Illuminate\Support\Facades\Schedule::swap(new Schedule('UTC'));
        require base_path('routes/console.php');
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'intraday:polygon:pull');

        $this->assertNotNull($event);
        $this->assertSame($expected, $event->isDue($this->app) && $event->filtersPass($this->app));
        $this->travelBack();
    }

    public static function marketTimes(): array
    {
        return [
            'before first pull' => ['2026-10-02 13:30:00', false],
            'first pull EDT' => ['2026-10-02 13:35:00', true],
            'noon EDT' => ['2026-10-02 16:00:00', true],
            'afternoon EDT' => ['2026-10-02 19:30:00', true],
            'final pull EDT' => ['2026-10-02 19:55:00', true],
            'after close EDT' => ['2026-10-02 20:00:00', false],
            'weekend' => ['2026-10-03 16:00:00', false],
            'before first pull EST' => ['2026-11-03 14:30:00', false],
            'first pull EST' => ['2026-11-03 14:35:00', true],
            'afternoon EST' => ['2026-11-03 20:30:00', true],
            'final pull EST' => ['2026-11-03 20:55:00', true],
            'after close EST' => ['2026-11-03 21:00:00', false],
        ];
    }
}
