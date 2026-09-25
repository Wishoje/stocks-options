<?php

namespace App\Http\Controllers;

use App\Support\WallIntelligence\WallFoundationAudit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

class WallFoundationController extends Controller
{
    public function index()
    {
        return Inertia::render('WallFoundation', [
            'productionCaptureAvailable' => is_file(config('wall_foundation.production_capture_path')),
        ]);
    }

    public function audit(Request $request, WallFoundationAudit $audit)
    {
        $validated = $request->validate([
            'dataset' => 'required|in:local_review,production_capture',
            'view' => 'required|in:latest_eod,next_session',
        ]);
        try {
            return response()->json($audit->report($validated['dataset'], $validated['view']))
                ->header('Cache-Control', 'private, no-store');
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
