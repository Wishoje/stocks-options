<?php

namespace Tests\Unit;

use App\Support\EodViewContext;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class EodViewContextTest extends TestCase
{
    public function test_defaults_respect_weekends_holidays_early_closes_and_premarket(): void
    {
        foreach ([
            ['2026-09-18 15:00', 'latest_eod', '2026-09-21'],
            ['2026-09-18 16:00', 'next_session', '2026-09-21'],
            ['2026-09-20 12:00', 'next_session', '2026-09-21'],
            ['2026-09-21 08:00', 'next_session', '2026-09-21'],
            ['2026-09-07 10:00', 'next_session', '2026-09-08'],
            ['2026-11-27 13:01', 'next_session', '2026-11-30'],
        ] as [$clock, $view, $session]) {
            $this->assertSame(['default_view' => $view, 'next_session' => $session], EodViewContext::defaults(CarbonImmutable::parse($clock, 'America/New_York')));
        }
    }
}
