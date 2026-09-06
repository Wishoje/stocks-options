<?php

namespace App\Support;

use App\Models\OptionExpiration;
use Carbon\Carbon;

class Expirations
{
    // naive daily expiries; for SPY/QQQ/IWM you may want all weekdays (no holidays)
    public static function ensureForward(string $symbol, int $days = 90): int
    {
        $today = Carbon::now('America/New_York')->startOfDay();
        $end = $today->copy()->addDays($days);
        $adds = 0;
        $healthEnabled = (bool) config('eod_snapshot_health.enabled', false);
        $existing = $healthEnabled && $today->lte($end)
            ? OptionExpiration::where('symbol', $symbol)
                ->whereBetween('expiration_date', [$today->toDateString(), $end->toDateString()])
                ->pluck('expiration_date')->mapWithKeys(fn ($date): array => [substr((string) $date, 0, 10) => true])->all()
            : [];
        $token = null;
        $started = false;

        try {
            for ($d = $today->copy(); $d->lte($end); $d->addDay()) {
                // skip weekends for non-daily symbols if you want:
                // if ($d->isWeekend()) continue;

                if ($healthEnabled && isset($existing[$d->toDateString()])) {
                    continue;
                }
                if ($healthEnabled && ! $started) {
                    $token = app(EodSnapshotHealth::class)->begin($symbol,
                        'expiration-forward:v1:'.$today->toDateString().':'.$end->toDateString(),
                        ['source' => 'expiration-forward', 'data_date' => $today->toDateString()]);
                    $started = true;
                }
                $adds += OptionExpiration::firstOrCreate([
                    'symbol' => $symbol,
                    'expiration_date' => $d->toDateString(),
                ])->wasRecentlyCreated ? 1 : 0;
            }
            app(EodSnapshotHealth::class)->complete($token);
            $token = null;
            if ($healthEnabled && $started) {
                // A concurrent creator can leave adds=0 after our fence began.
                // Publish that completed revision too, without changing raw data.
                app(EodCacheVersion::class)->publish([$symbol], [EodCacheVersion::DOMAIN_GEX]);
            }

            return $adds;
        } finally {
            if ($token !== null) {
                app(EodSnapshotHealth::class)->fail($token);
            }
        }
    }
}
