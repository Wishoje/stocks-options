<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $subName = config('plans.default_subscription_name', 'default');
        $sub = $request->user()->subscription($subName);

        $subscription = null;
        if ($sub) {
            $subscription = [
                'status' => $sub->stripe_status,
                'active' => $sub->valid(),
                'on_grace_period' => $sub->onGracePeriod(),
                'plan_name' => 'Early Bird',
                // we'll add next charge below (optional)
                'next_charge_at' => null,
            ];
        }

        // render the same Jetstream profile page:
        return Inertia::render('Profile/Show', [
            'sessions' => [], // if you don't use it, you can remove in your Vue too
            'confirmsTwoFactorAuthentication' => false,
            'subscription' => $subscription,
        ]);
    }
}
