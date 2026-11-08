<?php

namespace App\Http\Controllers;

use App\Services\WallWatchlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class WallWatchlistController extends Controller
{
    public function show(Request $request, WallWatchlistService $service): JsonResponse
    {
        $input = $request->validate(['demo' => ['sometimes', 'boolean'],
            'scope' => ['sometimes', Rule::in(['universe', 'watchlist'])]]);

        // Inbox and popups stay personal. Scanner explicitly requests the shared universe.
        $data = $request->boolean('demo') ? $service->demonstration()
            : $service->read($request->user()->id, $input['scope'] ?? 'watchlist');

        return response()->json(\App\Support\CustomerIdeaPresentation::watchlist($data))
            ->header('Cache-Control', 'private, no-store');
    }
}
