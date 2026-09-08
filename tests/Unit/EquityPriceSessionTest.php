<?php

namespace Tests\Unit;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EquityPriceSessionTest extends TestCase
{
    #[DataProvider('anchors')]
    public function test_daily_bar_anchor_uses_a_real_session_without_mutating_input(string $anchor, string $expected): void
    {
        $date = CarbonImmutable::parse($anchor, 'America/New_York');
        $before = $date->toIso8601String();
        $this->assertSame($expected, MarketSession::tradingDateOnOrBefore($date));
        $this->assertSame($before, $date->toIso8601String());
    }

    public static function anchors(): array
    {
        return [
            'ordinary session' => ['2026-09-08', '2026-09-08'],
            'Labor Day' => ['2026-09-07', '2026-09-04'],
            'Sunday' => ['2026-09-06', '2026-09-04'],
            'observed July Fourth' => ['2026-07-03', '2026-07-02'],
            'Good Friday' => ['2026-04-03', '2026-04-02'],
            'early close is still a session' => ['2026-11-27', '2026-11-27'],
            'announced mourning closure' => ['2025-01-09', '2025-01-08'],
            'Saturday New Year does not close Friday' => ['2028-01-01', '2027-12-31'],
        ];
    }
}
