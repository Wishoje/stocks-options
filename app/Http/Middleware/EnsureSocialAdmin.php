<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSocialAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user() && in_array((int) $request->user()->id, config('social.admin_ids', []), true), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
