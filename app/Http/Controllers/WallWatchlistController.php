<?php

namespace App\Http\Controllers;

use App\Services\WallWatchlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WallWatchlistController extends Controller
{
    public function show(Request $request, WallWatchlistService $service): JsonResponse
    {
        $request->validate(['demo' => ['sometimes', 'boolean']]);

        return response()->json($request->boolean('demo') ? $service->demonstration() : $service->read($request->user()->id))
            ->header('Cache-Control', 'private, no-store');
    }
}
