<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Receipt freshness controls polling; source timestamps retain their market meaning. */
final class QuoteRefreshPolicy
{
    public static function enabled(): bool
    {
        return (bool) config('quote_refresh.enabled', false);
    }

    public function window(?CarbonInterface $at = null): array
    {
        $now = $at ? CarbonImmutable::instance($at)->utc() : CarbonImmutable::now('UTC');
        $session = MarketSession::describe($now);
        $close = $session['closes_at'] ? CarbonImmutable::parse($session['closes_at']) : null;
        $finalStarts = $close?->addMinutes(max(15, (int) config('quote_refresh.final_delay_minutes', 15)));
        $finalEnds = $finalStarts?->addMinutes(max(1, min(30, (int) config('quote_refresh.final_window_minutes', 15))));
        $phase = $session['is_rth'] ? 'regular'
            : ($finalStarts && $now->greaterThanOrEqualTo($finalStarts) && $now->lessThan($finalEnds) ? 'final' : null);

        return [
            'eligible' => $phase !== null,
            'reason' => $phase === null ? 'market_closed' : 'refresh_window',
            'session_date' => $session['session_date'],
            'phase' => $phase,
            'opens_at' => $session['opens_at'],
            'closes_at' => $session['closes_at'],
            'final_starts_at' => $finalStarts?->toIso8601String(),
            'final_ends_at' => $finalEnds?->toIso8601String(),
            'checked_at' => $now->toIso8601String(),
        ];
    }

    public function isDue(?object $state, array $window): bool
    {
        if (! ($window['eligible'] ?? false)) {
            return false;
        }
        if ($state === null) {
            return true;
        }
        if (($window['phase'] ?? null) === 'final') {
            $start = CarbonImmutable::parse($window['final_starts_at']);

            return ! ($state->final_completed_at ?? null)
                || ! ($state->final_captured_at ?? null)
                || ! ($state->final_received_at ?? null)
                || CarbonImmutable::parse($state->final_captured_at, 'UTC')->lessThan($start)
                || CarbonImmutable::parse($state->final_received_at, 'UTC')->lessThan($start);
        }
        $completed = $state->ingestion_completed_at ?? null;
        $captured = $state->captured_at ?? null;
        if ($completed === null || $captured === null) {
            return true;
        }
        $now = CarbonImmutable::parse($window['checked_at']);
        $captured = CarbonImmutable::parse($captured, 'UTC');
        $completed = CarbonImmutable::parse($completed, 'UTC');
        if ($captured->isAfter($now) || $completed->isAfter($now) || $completed->lessThan($captured)) {
            return true;
        }
        // A successful 09:30 request completed at 09:30:02 must not suppress
        // the 09:35 tick. Bucket admission does not change durable run identity.
        $seconds = max(1, (int) config('quote_refresh.completed_ttl_seconds', 300));

        return intdiv($captured->getTimestamp(), $seconds) !== intdiv($now->getTimestamp(), $seconds);
    }
}
