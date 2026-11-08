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

        if ($request->boolean('demo')) {
            $data = app(\App\Support\Swing\SwingIdeaDemo::class)->build($horizon);
            $examples = array_values(array_filter($data['items'], fn (array $row): bool => ($row['status'] ?? null) !== 'no_idea' && is_array($row['levels'] ?? null)));
            $data['items'] = array_map(fn (array $row): array => \App\Support\CustomerIdeaPresentation::swing($row, $row,
                array_intersect_key($row, array_flip(['id', 'demonstration', 'is_current', 'reviewed_through']))), $examples);
        } else {
            $data = $service->readCustomerIdeas($request->user()->id, $horizon);
        }

        return Inertia::render('SwingIdeas', [...$data, 'localDemoAvailable' => app()->environment('local'),
            'horizon' => $horizon, 'horizons' => SwingIdeaRules::HORIZONS,
            'completedSession' => PublicSpyPreview::completedSession()]);
    }
}
