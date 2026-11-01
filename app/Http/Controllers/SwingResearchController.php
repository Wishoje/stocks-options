<?php

namespace App\Http\Controllers;

use App\Services\SwingResearch\SwingResearchService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class SwingResearchController extends Controller
{
    public function page()
    {
        return Inertia::render('SwingResearch', ['localDemoAvailable' => app()->environment('local')]);
    }

    public function report(Request $request, SwingResearchService $service)
    {
        $filters = $this->filters($request);
        if ($request->boolean('demo')) {
            abort_unless(app()->environment('local'), 403);

            return response()->json(app(\App\Support\SwingResearch\SwingResearchDemo::class)->report($filters));
        }

        return response()->json($service->report((int) $request->user()->id, $filters));
    }

    public function refresh(Request $request, SwingResearchService $service)
    {
        abort_if($request->boolean('demo'), 422, 'Examples cannot be collected.');
        abort_unless(config('swing_research.enabled'), 403, 'Research collection is disabled.');

        return response()->json($service->collect((int) $request->user()->id, min(2, max(1, (int) config('swing_research.batch_symbols', 2)))));
    }

    public function alerts(Request $request, SwingResearchService $service)
    {
        return response()->json($service->alerts((int) $request->user()->id));
    }

    public function readAlert(Request $request, SwingResearchService $service, string $id)
    {
        $service->markAlertRead((int) $request->user()->id, $id);

        return response()->json(['read' => true]);
    }

    private function filters(Request $request): array
    {
        $input = $request->validate([
            'horizon' => ['nullable', Rule::in(['short', 'position'])],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:from'],
            'symbol' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
        ]);
        $from = $input['from'] ?? now('America/New_York')->subDays(44)->toDateString();
        $to = $input['to'] ?? now('America/New_York')->toDateString();
        abort_if($from > $to || CarbonImmutable::parse($from)->diffInDays($to) > 180, 422, 'Choose a date range of 180 days or less.');

        return ['horizon' => $input['horizon'] ?? 'short', 'from' => $from, 'to' => $to, 'symbol' => strtoupper($input['symbol'] ?? '')];
    }
}
