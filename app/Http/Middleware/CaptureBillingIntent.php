<?php

namespace App\Http\Middleware;

use App\Support\BillingIntent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureBillingIntent
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            $explicit = $request->query->has('plan') || $request->query->has('billing');
            BillingIntent::capture($request);

            if ($request->routeIs('pricing') && $request->query('canceled') === '1') {
                BillingIntent::clearCheckoutAttempt($request);
            }

            if ($explicit && ! $request->user() && $request->routeIs('login', 'register')) {
                $request->session()->put('url.intended', BillingIntent::intendedCheckoutUrl($request));
            }
        }

        return $next($request);
    }
}
