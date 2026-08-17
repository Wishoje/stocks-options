<?php
namespace App\Http\Middleware;

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

        $subName = config('plans.default_subscription_name');

        if ($user->onGenericTrial()) {
            return $next($request);
        }

        $isTrial = $user->onTrial($subName);

        if (! $user->subscribed($subName) && ! $isTrial) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'An active subscription is required.',
                    'code' => 'subscription_required',
                ], 403);
            }

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
        if ($user->subscribed($subName) && $mode !== 'strict') {
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
