<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureFeature
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        $user = $request->user();
        if (!$user) return redirect()->route('login');

        $subName = config('plans.default_subscription_name');

        $isTrial = $user->onTrial($subName) || $user->onGenericTrial();

        if (!$user->subscribed($subName) && !$isTrial) {
            return redirect()->route('pricing');
        }

        // If trial but subscription hasn't synced yet, don't block the user
        $sub = $user->subscription($subName);
        if ($isTrial && !$sub) {
            return $next($request); // or restrict to a default feature set if you want
        }

        $priceId = $sub?->items()?->first()?->stripe_price; // note items() query is safer than items property

        $plans = config('plans.plans');
        foreach ($plans as $plan) {
            $allPrices = array_values($plan['prices'] ?? []);
            if ($priceId && in_array($priceId, $allPrices, true)) {
                $allowed = $plan['features'] ?? [];
                return in_array($feature, $allowed, true)
                    ? $next($request)
                    : redirect()->route('pricing');
            }
        }

        // If subscribed but mapping missing, let them in (or log + allow)
        if ($user->subscribed($subName)) {
            return $next($request);
        }

        return redirect()->route('pricing');
    }

}
