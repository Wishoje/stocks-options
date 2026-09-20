<?php

namespace App\Http\Controllers;

use App\Support\ConversionEventRecorder;
use App\Support\ConversionMeasurementWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class FirstUsefulReadingController extends Controller
{
    public function __invoke(
        Request $request,
        ConversionEventRecorder $events,
    ): JsonResponse|Response {
        $request->validate([
            'interaction' => ['required', 'string', Rule::in(['reading_inspected'])],
            'surface' => ['required', 'string', Rule::in(['dashboard'])],
        ]);

        $occurredAt = now('UTC');
        if (! ConversionMeasurementWindow::includes($occurredAt)) {
            return response()->noContent();
        }

        $user = $request->user();
        $event = $events->recordFirst(
            userId: $user->id,
            eventType: 'first_useful_reading',
            providerReference: 'account:'.$user->id,
            authority: 'authenticated_dashboard',
            occurredAt: $occurredAt,
            properties: [
                'surface' => 'dashboard',
                'state' => 'ready',
            ],
        );

        return response()->json([
            'recorded' => true,
            'first' => $event->wasRecentlyCreated,
        ], $event->wasRecentlyCreated ? 201 : 200);
    }
}
