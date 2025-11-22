<?php

namespace App\Support;

use Carbon\Carbon;

class Market
{
    public static function isRthOpen(?Carbon $now = null): bool
    {
        $ny = ($now ?? now())->copy()->setTimezone('America/New_York');
        // Weekends
        if ($ny->isWeekend()) return false;

        // Simple RTH window 09:30–16:00 ET (you can expand later with a holiday table)
        $hm = (int)$ny->format('Hi');
        return $hm >= 930 && $hm < 1600;
    }
}
