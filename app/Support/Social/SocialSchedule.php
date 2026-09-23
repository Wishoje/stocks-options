<?php

namespace App\Support\Social;

use App\Support\EodViewContext;
use App\Support\MarketSession;
use Carbon\CarbonImmutable;

final class SocialSchedule
{
    public static function session(?CarbonImmutable $at = null): ?string
    {
        $now = ($at ?? CarbonImmutable::now('America/New_York'))->setTimezone('America/New_York');
        if (! in_array($now->dayOfWeek, [0, 2, 4], true)) {
            return null;
        }
        $target = $now->isSunday() ? $now->addDay() : $now;

        return MarketSession::isTradingDay($target) ? $target->toDateString() : null;
    }

    public static function inWindow(bool $preparation = false, ?CarbonImmutable $at = null): bool
    {
        $now = ($at ?? CarbonImmutable::now('America/New_York'))->setTimezone('America/New_York');

        return self::session($now) !== null && $now->format('H:i') >= ($preparation ? '08:30' : '08:45') && $now->format('H:i') < '08:55';
    }

    public static function dailyPreparationWindow(?CarbonImmutable $at = null): bool
    {
        $now = ($at ?? CarbonImmutable::now('America/New_York'))->setTimezone('America/New_York');

        return $now->format('H:i') >= '08:30' && $now->format('H:i') < '08:55';
    }

    public static function manualSession(?CarbonImmutable $at = null): string
    {
        $now = ($at ?? CarbonImmutable::now('America/New_York'))->setTimezone('America/New_York');

        return MarketSession::describe($now)['state'] === 'rth' ? $now->toDateString() : EodViewContext::defaults($now)['next_session'];
    }
}
