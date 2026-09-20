<?php

namespace App\Support;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Cashier\Checkout;

final class CheckoutRedirect
{
    public static function response(Request $request, Checkout $checkout)
    {
        if ($request->header('X-Inertia')) {
            return Inertia::location((string) $checkout->asStripeCheckoutSession()->url);
        }

        return $checkout;
    }

    public static function urlResponse(Request $request, string $url)
    {
        if ($request->header('X-Inertia')) {
            return Inertia::location($url);
        }

        return redirect()->away($url, 303);
    }
}
