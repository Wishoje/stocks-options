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

        if (!$user->subscribed($subName) && !$user->onTrial($subName)) {
            return redirect()->route('pricing');
        }

        // Determine allowed features by looking at the user's current Stripe price id
        $sub = $user->subscription($subName);
        $priceId = $sub?->items?->first()?->stripe_price;

        $plans = config('plans.plans');
        foreach ($plans as $plan) {
            $allPrices = array_values($plan['prices'] ?? []);
            if ($priceId && in_array($priceId, $allPrices, true)) {
                $allowed = $plan['features'] ?? [];
                if (in_array($feature, $allowed, true)) {
                    return $next($request);
                }
                return redirect()->route('pricing');
            }
        }

        // fallback: subscribed but unknown plan mapping
        return redirect()->route('pricing');
    }
}
