<?php

namespace App\Support;

use Carbon\CarbonInterface;

class Market
{
    public static function isRthOpen(?CarbonInterface $ts = null): bool
    {
        return MarketSession::describe($ts)['is_rth'];
    }
}
