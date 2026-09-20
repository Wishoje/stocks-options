<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class EodViewContext
{
    public static function defaults(?CarbonInterface $at = null): array
    {
        $clock = $at ?? now('America/New_York');
        $market = MarketSession::describe($clock);

        return [
            'default_view' => $market['state'] === 'rth' ? 'latest_eod' : 'next_session',
            'next_session' => CarbonImmutable::parse($market['next_open_at'])->setTimezone('America/New_York')->toDateString(),
        ];
    }

    public static function previousSession(string $session): string
    {
        return MarketSession::tradingDateOnOrBefore(CarbonImmutable::parse($session, 'America/New_York')->subDay());
    }
}
