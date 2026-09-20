<?php

namespace App\Http\Middleware;

use App\Support\ProductAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureFeature
{
    public function handle(Request $request, Closure $next, string $feature, string $mode = 'legacy')
    {
        $user = $request->user();
        if (! $user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->route('login');
        }

        $subName = (string) config('plans.default_subscription_name', 'default');
        $access = ProductAccess::for($user);

        if ($access['on_generic_trial']) {
            return $next($request);
        }

        if (! $access['subscribed']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'An active subscription is required.',
                    'code' => 'subscription_required',
                ], 403);
            }

            return redirect()->route('pricing');
        }

        $sub = $user->subscription($subName);
        $priceId = $sub?->items()?->first()?->stripe_price;

        $plans = config('plans.plans');
        foreach ($plans as $plan) {
            $allPrices = array_values($plan['prices'] ?? []);
            if ($priceId && in_array($priceId, $allPrices, true)) {
                $allowed = $plan['features'] ?? [];
                if (in_array($feature, $allowed, true)) {
                    return $next($request);
                }

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'This feature is not included in the current plan.',
                        'code' => 'feature_not_available',
                    ], 403);
                }

                return redirect()->route('pricing');
            }
        }

        // Preserve the established web behavior unless a sensitive API route
        // explicitly requires a mapped plan and feature entitlement.
        if ($access['subscribed'] && $mode !== 'strict') {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'This feature is not included in the current plan.',
                'code' => $mode === 'strict' ? 'plan_unmapped' : 'feature_not_available',
            ], 403);
        }

        return redirect()->route('pricing');
    }
}
