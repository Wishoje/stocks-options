<?php

namespace App\Http\Controllers;

use App\Support\BillingIntent;
use App\Support\ProductAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationHandoffController extends Controller
{
    public function __invoke(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $handoff = (array) $request->session()->pull('registration.handoff', []);
        $nextUrl = (string) ($handoff['next_url'] ?? '');
        $analyticsKey = (string) ($handoff['analytics_key'] ?? '');
        $valid = (int) ($handoff['expires_at'] ?? 0) >= now()->timestamp
            && preg_match('/\A[a-f0-9]{24}\z/', $analyticsKey)
            && ($nextUrl === route('pricing', absolute: false) || str_starts_with($nextUrl, '/checkout?'));

        if (! $valid) {
            return ProductAccess::for($request->user())['has_access']
                ? redirect()->route('dashboard')
                : redirect()->route('pricing');
        }

        return Inertia::render('Auth/RegistrationHandoff', [
            'next_url' => $nextUrl === route('pricing', absolute: false)
                ? $nextUrl
                : BillingIntent::intendedCheckoutUrl($request),
            'analytics_key' => $analyticsKey,
        ]);
    }
}
