<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class UseLocalReviewClock
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = trim((string) config('ui_review.now', ''));

        // Recorded intraday sessions carry their own dates. The EOD fixture
        // clock must not hide newer imported bars or mark them unfinished.
        if (! app()->environment('local') || $configured === '' || $request->is('api/intraday/walls')) {
            return $next($request);
        }

        try {
            $reviewNow = Carbon::parse($configured);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'UI_REVIEW_NOW must be a valid date and time.',
                previous: $exception,
            );
        }

        $previousClock = Carbon::getTestNow();
        Carbon::setTestNow($reviewNow);

        try {
            return $next($request);
        } finally {
            Carbon::setTestNow($previousClock);
        }
    }
}
