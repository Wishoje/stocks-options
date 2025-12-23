<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class BillingController extends Controller
{
    public function checkout(Request $request)
    {
        $user = $request->user();
        $subName = config('plans.default_subscription_name'); // e.g. 'default'

        if ($user->subscribed($subName) || $user->onTrial($subName)) {
            return redirect()->route('dashboard');
        }

        $plan = $request->query('plan', 'earlybird');
        $billing = $request->query('billing', 'monthly');

        $priceId = config("plans.plans.$plan.prices.$billing");
        abort_unless($priceId, 400, 'Invalid plan');

        return $user->newSubscription($subName, $priceId)
            ->trialDays(7)
            ->checkout([
                'success_url' => route('billing.success') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => route('pricing') . '?canceled=1',
            ]);
    }


    public function success(Request $request)
    {
        // Cashier will sync subscription via webhook or on next request.
        // Keep it simple: send them to the app.
        return redirect()->route('dashboard');
    }

    public function portal(Request $request)
    {
        return $request->user()->redirectToBillingPortal(route('dashboard'));
    }
}
