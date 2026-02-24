<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureEodHealthAccess
{
    /**
     * Temporary gate for EOD health visibility/access.
     *
     * @var int[]
     */
    protected array $allowedUserIds = [3, 4];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            return redirect()->route('login');
        }

        if (!in_array((int) $user->id, $this->allowedUserIds, true)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Forbidden'], 403);
            }

            abort(403);
        }

        return $next($request);
    }
}
