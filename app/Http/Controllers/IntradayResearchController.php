<?php

namespace App\Http\Controllers;

use App\Services\IntradayResearch\IntradayResearchService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class IntradayResearchController extends Controller
{
    public function page()
    {
        return Inertia::render('IntradayResearch', ['localDemoAvailable' => app()->environment('local')]);
    }

    public function report(Request $request, IntradayResearchService $service)
    {
        $filters = $this->filters($request);
        if ($request->boolean('demo')) {
            abort_unless(app()->environment('local'), 403);

            return response()->json(app(\App\Support\IntradayResearch\IntradayResearchDemo::class)->report($filters));
        }

        return response()->json($service->report((int) $request->user()->id, $filters));
    }

    public function refresh(Request $request, IntradayResearchService $service)
    {
        abort_if($request->boolean('demo'), 422, 'Examples cannot be collected.');
        abort_unless(config('intraday_research.enabled'), 403, 'Research collection is disabled.');

        return response()->json($service->collect((int) $request->user()->id,
            max(1, min(5, (int) config('intraday_research.manual_batch_symbols', 5)))));
    }

    public function alerts(Request $request, IntradayResearchService $service)
    {
        return response()->json($service->alerts((int) $request->user()->id));
    }

    public function readAlert(Request $request, IntradayResearchService $service, string $id)
    {
        $service->markAlertRead((int) $request->user()->id, $id);

        return response()->json(['read' => true]);
    }

    private function filters(Request $request): array
    {
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:from'],
            'session' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'symbol' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.^\-]{1,16}$/'],
        ]);
        $to = $input['session'] ?? $input['to'] ?? now('America/New_York')->toDateString();
        $from = $input['session'] ?? $input['from'] ?? CarbonImmutable::parse($to)->subDays(29)->toDateString();
        abort_if($from > $to || CarbonImmutable::parse($from)->diffInDays($to) > 180, 422, 'Choose a date range of 180 days or less.');

        return ['from' => $from, 'to' => $to, 'session' => $input['session'] ?? '', 'symbol' => strtoupper($input['symbol'] ?? '')];
    }
}
