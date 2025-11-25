<?php

namespace App\Support;

use Carbon\Carbon;

class Market
{
    public static function isRthOpen(?Carbon $ts = null): bool
    {
        $ny = ($ts ?? now())->copy()->setTimezone('America/New_York');

        if ($ny->isWeekend()) {
            return false;
        }

        // simple 09:30–16:00 ET window
        $t = (int) $ny->format('Hi'); // "0935", "1559", etc.
        return $t >= 930 && $t <= 1600;
    }
}
