<?php

namespace App\Support\Swing;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;

final class SwingIdeaDemo
{
    public function build(string $horizon): array
    {
        abort_unless(app()->environment('local'), 404);
        $dates = [];
        $day = CarbonImmutable::parse('2026-09-11', 'America/New_York');
        while (count($dates) < 70) {
            if (MarketSession::isTradingDay($day)) {
                $dates[] = $day->toDateString();
            }
            $day = $day->subDay();
        }
        $prices = [];
        foreach (array_reverse($dates) as $index => $date) {
            $close = 100 + $index * .1;
            $prices[] = ['date' => $date, 'open' => $close - .05, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close];
        }
        $expiry = $horizon === 'short' ? '2026-10-09' : '2026-10-16';
        $cell = fn ($net) => ['expiration' => $expiry, 'data_date' => '2026-09-11', 'net_gex' => $net,
            'known_oi' => 10000., 'covered_oi' => 10000., 'contract_rows' => 2, 'excluded_rows' => 0];
        $input = ['symbol' => 'SPY', 'data_date' => '2026-09-11', 'prices' => $prices, 'strikes' => [
            ['strike' => 106., 'expirations' => [$cell(-10000000.)]], ['strike' => 111., 'expirations' => [$cell(6000000.)]]],
            'volatility' => ['iv1m' => .2, 'rv20' => .18], 'dex' => [['expiration' => $expiry, 'value' => 5000.]]];
        $rules = new SwingIdeaRules;
        $base = $rules->propose($input, $horizon);
        $qualified = $rules->advance($base, ['date' => '2026-09-14', 'high' => 107., 'low' => 105.9, 'close' => 106.5]);
        $progress = $rules->advance($qualified, ['date' => '2026-09-15', 'high' => 109., 'low' => 106.5, 'close' => 108.]);
        $finished = $rules->advance($progress, ['date' => '2026-09-16', 'high' => 112., 'low' => 108., 'close' => 111.]);
        $paused = $rules->advance($base, ['date' => '2026-09-16', 'close' => 106.5]);
        $items = [];
        foreach (['SPY' => $base, 'QQQ' => $qualified, 'TSLA' => $progress, 'NVDA' => $finished, 'AAPL' => $paused] as $symbol => $plan) {
            $items[] = [...$plan, 'symbol' => $symbol, 'id' => 'example-'.$symbol, 'demonstration' => true,
                'status_group' => $plan['status'], 'is_current' => true, 'reviewed_through' => $plan['reviewed_through'] ?? $plan['source_date']];
        }

        return ['items' => $items, 'prepared' => true, 'demonstration' => true];
    }
}
