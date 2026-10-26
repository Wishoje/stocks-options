<?php

namespace Tests\Fixtures;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;

final class SwingInput
{
    public static function make(): array
    {
        $dates = [];
        $day = CarbonImmutable::parse('2026-10-23', 'America/New_York');
        while (count($dates) < 70) {
            if (MarketSession::isTradingDay($day)) {
                $dates[] = $day->toDateString();
            }
            $day = $day->subDay();
        }
        $prices = [];
        foreach (array_reverse($dates) as $i => $date) {
            $close = 100 + $i * .1;
            $prices[] = ['date' => $date, 'open' => $close - .05, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close];
        }
        $cell = fn ($net) => ['expiration' => '2026-11-20', 'data_date' => '2026-10-23',
            'net_gex' => $net, 'known_oi' => 10000., 'covered_oi' => 10000., 'contract_rows' => 2, 'excluded_rows' => 0];

        return ['symbol' => 'SPY', 'data_date' => '2026-10-23', 'prices' => $prices,
            'source_version' => 'first-gex', 'volatility_version' => 'first-vol',
            'strikes' => [['strike' => 106., 'expirations' => [$cell(-10000000.)]], ['strike' => 111., 'expirations' => [$cell(6000000.)]]],
            'volatility' => ['date' => '2026-10-23', 'iv1m' => .2, 'rv20' => .18, 'vrp' => .02, 'terms' => []],
            'dex' => [['expiration' => '2026-11-20', 'value' => 10000.]],
            'prior_oi' => ['2026-11-20:106' => ['count' => 2, 'oi' => 9000., 'invalid' => 0]],
            'benchmark' => ['symbol' => 'SPY', 'date' => '2026-10-23', 'return5_pct' => .5]];
    }
}
