<?php

namespace App\Support;

use App\Models\OptionExpiration;
use Carbon\Carbon;

class Expirations {
    // naive daily expiries; for SPY/QQQ/IWM you may want all weekdays (no holidays)
    public static function ensureForward(string $symbol, int $days = 90): int {
        $today = Carbon::now('America/New_York')->startOfDay();
        $end   = $today->copy()->addDays($days);
        $adds  = 0;

        for ($d = $today->copy(); $d->lte($end); $d->addDay()) {
            // skip weekends for non-daily symbols if you want:
            // if ($d->isWeekend()) continue;

            $adds += OptionExpiration::firstOrCreate([
                'symbol' => $symbol,
                'expiration_date' => $d->toDateString(),
            ])->wasRecentlyCreated ? 1 : 0;
        }
        return $adds;
    }
}
