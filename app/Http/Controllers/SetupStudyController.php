<?php

namespace App\Http\Controllers;

use App\Services\SetupStudy\SetupStudyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class SetupStudyController extends Controller
{
    public function page()
    {
        return Inertia::render('SetupStudy', ['localDemoAvailable' => app()->environment('local')]);
    }

    public function report(Request $request, SetupStudyService $service)
    {
        $filters = $this->filters($request);
        if ($request->boolean('demo')) {
            return response()->json(app(\App\Support\SetupStudy\SetupStudyDemo::class)->report($filters));
        }

        return response()->json($service->report((int) $request->user()->id, $filters));
    }

    public function refresh(Request $request, SetupStudyService $service)
    {
        $this->filters($request);
        abort_if($request->boolean('demo'), 422, 'Examples cannot be collected.');
        abort_unless(config('setup_study.enabled'), 403, 'Study collection is disabled.');

        return response()->json($service->collect((int) $request->user()->id, (int) config('setup_study.batch_symbols', 12)));
    }

    private function filters(Request $request): array
    {
        $input = $request->validate([
            'cohort' => ['nullable', Rule::in(['intraday', 'swing_short', 'swing_position'])],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:from'],
            'symbol' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'direction' => ['nullable', Rule::in(['bullish', 'bearish'])],
        ]);
        $from = $input['from'] ?? now('America/New_York')->subDays(13)->toDateString();
        $to = $input['to'] ?? now('America/New_York')->toDateString();
        abort_if($from > $to || \Carbon\CarbonImmutable::parse($from)->diffInDays($to) > (int) config('setup_study.maximum_report_days', 180), 422, 'Choose a date range of 180 days or less.');

        return ['cohort' => $input['cohort'] ?? 'intraday', 'from' => $from, 'to' => $to,
            'symbol' => strtoupper($input['symbol'] ?? ''), 'direction' => $input['direction'] ?? ''];
    }
}
