<?php

namespace App\Http\Middleware;

use App\Support\BillingIntent;
use App\Support\ProductAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureSubscribed
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->route('login');
        }

        if (ProductAccess::for($user)['has_access']) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'An active subscription is required.',
                'code' => 'subscription_required',
            ], 403);
        }

        // If you want: allow “grace period” after cancel
        // if ($user->subscription($subName)?->onGracePeriod()) return $next($request);

        // send them to pricing
        BillingIntent::rememberReturnTo($request, $request->getRequestUri());

        return redirect()->route('pricing');
    }
}
