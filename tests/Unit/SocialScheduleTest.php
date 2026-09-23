<?php

namespace Tests\Unit;

use App\Support\Social\SocialSchedule;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SocialScheduleTest extends TestCase
{
    public function test_three_mornings_holidays_and_dst(): void
    {
        foreach ([['2026-09-20 08:45', '2026-09-21', true], ['2026-09-21 08:45', null, false], ['2026-09-22 08:45', '2026-09-22', true], ['2026-09-23 08:45', null, false], ['2026-09-24 08:45', '2026-09-24', true], ['2026-09-25 08:45', null, false], ['2026-09-26 08:45', null, false], ['2026-05-24 08:45', null, false], ['2026-11-01 08:45', '2026-11-02', true], ['2026-09-22 08:55', '2026-09-22', false]] as [$time,$session,$due]) {
            $at = CarbonImmutable::parse($time, 'America/New_York');
            $this->assertSame($session, SocialSchedule::session($at));
            $this->assertSame($due, SocialSchedule::inWindow(false, $at));
        }
    }
}
