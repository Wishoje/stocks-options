<?php

namespace App\Http\Middleware;

use App\Support\SetupStudy\SetupStudyAccess;
use Closure;
use Illuminate\Http\Request;

final class EnsureSetupStudyAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(SetupStudyAccess::allows($request->user()), 403);

        return $next($request);
    }
}
