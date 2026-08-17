<?php

namespace App\Http\Middleware;

use App\Models\WorkRun;
use Closure;
use Illuminate\Http\Request;

final class EnsureWorkRunFeature
{
    public function __construct(private readonly EnsureFeature $features) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $run = WorkRun::query()->findOrFail((string) $request->route('runId'));
        $feature = match ($run->kind) {
            'calculator_refresh' => 'calculator.access',
            'intraday_refresh' => 'intraday.access',
            'symbol_bootstrap' => 'app.access',
            default => null,
        };

        if ($feature === null) {
            return response()->json([
                'message' => 'This work-run type is unavailable.',
                'code' => 'work_run_type_unavailable',
            ], 403);
        }

        $request->attributes->set('workRun', $run);

        return $this->features->handle($request, $next, $feature, 'strict');
    }
}
