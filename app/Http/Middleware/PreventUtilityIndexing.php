<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PreventUtilityIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $utility = $request->is('login', 'register', 'forgot-password', 'reset-password', 'reset-password/*', 'two-factor-challenge', 'email/*');
        if ($utility) {
            $request->attributes->set('seo_noindex', true);
        }
        $response = $next($request);
        if ($utility) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }
}
