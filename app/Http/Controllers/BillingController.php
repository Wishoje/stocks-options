<?php

namespace App\Http\Controllers;

use App\Support\BillingIntent;
use App\Support\CheckoutRedirect;
use App\Support\ProductAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BillingController extends Controller
{
    public function checkout(Request $request)
    {
        $user = $request->user();
        $subscriptionName = config('plans.default_subscription_name');
        $intent = BillingIntent::capture($request);
        $access = ProductAccess::for($user);

        if (! $access['needs_checkout']) {
            return redirect()->to(BillingIntent::returnTo($request));
        }

        if ($existing = BillingIntent::activeCheckoutAttempt($request, $intent)) {
            if (! $existing['matches_intent']) {
                return redirect()
                    ->route('pricing', $intent)
                    ->with('status', 'checkout-active-other-selection');
            }

            return CheckoutRedirect::urlResponse($request, $existing['checkout_url']);
        }

        $plan = $intent['plan'];
        $billing = $intent['billing'];
        $priceId = config("plans.plans.$plan.prices.$billing");
        abort_unless(is_string($priceId) && $priceId !== '', 400, 'This billing option is not configured.');

        $trialDays = (int) config("plans.plans.$plan.trial_days", 7);
        $startKey = sprintf('billing-checkout-start:%s:%s', $user->getAuthIdentifier(), $subscriptionName);
        $attemptToken = BillingIntent::checkoutAttemptToken();

        if (! Cache::add($startKey, true, now()->addSeconds(20))) {
            return redirect()
                ->route('pricing', $intent)
                ->with('status', 'checkout-already-starting');
        }

        try {
            $checkout = $user->newSubscription($subscriptionName, $priceId)
                ->trialDays($trialDays)
                ->checkout([
                    'success_url' => route('billing.success', [
                        ...$intent,
                        'attempt' => $attemptToken,
                    ]).'&session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => route('pricing', [
                        'canceled' => 1,
                        ...$intent,
                    ]),
                ]);

            BillingIntent::rememberCheckoutAttempt(
                $request,
                $attemptToken,
                (string) $checkout->asStripeCheckoutSession()->id,
                (string) $checkout->asStripeCheckoutSession()->url,
                $intent,
                (int) $checkout->asStripeCheckoutSession()->expires_at,
            );

            return CheckoutRedirect::response($request, $checkout);
        } catch (\Throwable $exception) {
            Cache::forget($startKey);

            throw $exception;
        }
    }

    public function success(Request $request)
    {
        $user = $request->user();
        $subscriptionName = config('plans.default_subscription_name');
        $intent = BillingIntent::consumeCheckoutAttempt(
            $request,
            $request->query('attempt'),
            $request->query('session_id'),
        );

        if (! $intent) {
            $access = ProductAccess::for($user);
            if ($access['subscribed'] || $access['on_trial']) {
                return redirect()->to(BillingIntent::returnTo($request));
            }

            return redirect()
                ->route('pricing', BillingIntent::current($request))
                ->with('status', 'checkout-return-unconfirmed');
        }

        if (ProductAccess::for($user)['subscribed']) {
            BillingIntent::clearActivation($request);
            $returnTo = BillingIntent::returnTo($request);

            if (str_starts_with($returnTo, '/dashboard')) {
                $returnTo .= str_contains($returnTo, '?') ? '&welcome=1' : '?welcome=1';
            }

            return redirect()
                ->to($returnTo)
                ->with('activation_confirmed', true);
        }

        BillingIntent::markActivationPending($request, $intent);

        return redirect()->route('pricing', [
            'activating' => 1,
            ...$intent,
        ]);
    }

    public function status(Request $request)
    {
        $access = ProductAccess::for($request->user());
        $active = $access['subscribed'] || $access['on_trial'];

        if ($active) {
            BillingIntent::clearActivation($request);
        }

        return response()->json([
            'active' => $active,
            'state' => $active
                ? 'active'
                : (BillingIntent::activation($request)['pending'] ? 'pending' : 'inactive'),
            'redirect' => $active ? BillingIntent::returnTo($request) : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function portal(Request $request)
    {
        return $request->user()->redirectToBillingPortal(route('dashboard'));
    }

    public function cancel(Request $request)
    {
        $user = $request->user();
        $subscriptionName = config('plans.default_subscription_name');
        $subscription = $user->subscription($subscriptionName);
        $access = ProductAccess::for($user);
        abort_unless(
            $subscription && in_array($access['subscription_state'], ['active', 'trialing'], true),
            400,
            'No active subscription',
        );

        $subscription->cancel();

        return back()->with('status', 'subscription-canceled');
    }

    public function resume(Request $request)
    {
        $user = $request->user();
        $subscriptionName = config('plans.default_subscription_name');
        $subscription = $user->subscription($subscriptionName);
        abort_unless(
            $subscription && ProductAccess::for($user)['subscription_state'] === 'grace_period',
            400,
            'Subscription is not on grace period',
        );

        $subscription->resume();

        return back()->with('status', 'subscription-resumed');
    }
}
