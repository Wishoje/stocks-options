<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSubscribed
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        $subName = config('plans.default_subscription_name');

        // allow active subscription or trial
        if ($user->subscribed($subName) || $user->onTrial($subName)) {
            return $next($request);
        }

        // If you want: allow “grace period” after cancel
        // if ($user->subscription($subName)?->onGracePeriod()) return $next($request);

        // send them to pricing
        return redirect()->route('pricing');
    }
}
