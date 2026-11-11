<?php

namespace App\Http\Controllers;

use App\Services\SwingReactionService;
use App\Support\PublicSpyPreview;
use App\Support\Swing\SwingReactionRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class SwingIdeaController extends Controller
{
    public function __invoke(Request $request, SwingReactionService $service)
    {
        $input = $request->validate([
            'horizon' => ['nullable', Rule::in(array_keys(SwingReactionRules::HORIZONS))],
            'scope' => ['nullable', Rule::in(['universe', 'watchlist'])],
            'demo' => ['nullable', 'boolean'],
        ]);
        $horizon = ($input['horizon'] ?? '') ?: 'short';
        $scope = ($input['scope'] ?? '') ?: 'universe';

        if ($request->boolean('demo')) {
            abort_unless(app()->environment('local'), 403);
            $data = app(\App\Support\Swing\SwingReactionDemo::class)->build($horizon);
        } else {
            $data = $service->read($request->user()->id, $horizon, $scope);
        }

        return Inertia::render('SwingIdeas', [...$data, 'localDemoAvailable' => app()->environment('local'),
            'horizon' => $horizon, 'horizons' => SwingReactionRules::HORIZONS, 'scope' => $scope,
            'completedSession' => PublicSpyPreview::completedSession()]);
    }
}
