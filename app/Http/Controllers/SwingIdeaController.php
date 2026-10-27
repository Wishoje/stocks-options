<?php

namespace App\Http\Controllers;

use App\Services\SwingIdeaService;
use App\Support\PublicSpyPreview;
use App\Support\Swing\SwingIdeaRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class SwingIdeaController extends Controller
{
    public function __invoke(Request $request, SwingIdeaService $service)
    {
        $input = $request->validate(['horizon' => ['nullable', Rule::in(array_keys(SwingIdeaRules::HORIZONS))], 'demo' => ['nullable', 'boolean']]);
        $horizon = ($input['horizon'] ?? '') ?: 'short';

        $data = $request->boolean('demo') ? app(\App\Support\Swing\SwingIdeaDemo::class)->build($horizon) : $service->read($request->user()->id, $horizon);

        return Inertia::render('SwingIdeas', [...$data, 'localDemoAvailable' => app()->environment('local'),
            'horizon' => $horizon, 'horizons' => SwingIdeaRules::HORIZONS,
            'completedSession' => PublicSpyPreview::completedSession()]);
    }
}
