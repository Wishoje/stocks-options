<?php

namespace App\Http\Responses;

use App\Support\BillingIntent;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        $intended = (string) $request->session()->pull('url.intended', '');
        $nextUrl = str_starts_with($intended, '/checkout?')
            ? BillingIntent::intendedCheckoutUrl($request)
            : route('pricing', absolute: false);

        $request->session()->put('registration.handoff', [
            'next_url' => $nextUrl,
            'analytics_key' => bin2hex(random_bytes(12)),
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]);

        return redirect()->route('registration.handoff');
    }
}
