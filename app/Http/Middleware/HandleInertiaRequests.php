<?php

namespace App\Http\Middleware;

use App\Support\BillingIntent;
use App\Support\ProductAccess;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $access = ProductAccess::for($user);
        $offer = config('plans.plans.earlybird', []);
        $seoPages = (array) config('marketing_seo.pages', []);
        $socialImage = (array) config('marketing_seo.social_image', []);
        $routeName = $request->route()?->getName();
        $seo = is_string($routeName) && isset($seoPages[$routeName])
            ? [
                ...(array) $seoPages[$routeName],
                'image' => (string) ($socialImage['url'] ?? ''),
                'image_alt' => (string) ($socialImage['alt'] ?? ''),
                'image_width' => (int) ($socialImage['width'] ?? 0),
                'image_height' => (int) ($socialImage['height'] ?? 0),
            ]
            : null;

        return array_merge(parent::share($request), [
            'marketing' => [
                'ga4_id' => config('services.ga4_id'),
                'show_glossary' => (bool) env('SHOW_GLOSSARY', false),
            ],
            'seo' => $seo,
            'socialAdmin' => $user && in_array((int) $user->id, config('social.admin_ids', []), true),
            'billing' => [
                ...$access,
                'intent' => BillingIntent::current($request),
                'activation' => BillingIntent::activation($request),
            ],
            'offer' => [
                'plan' => 'earlybird',
                'label' => (string) ($offer['label'] ?? 'Early Bird'),
                'trial_days' => (int) ($offer['trial_days'] ?? 7),
                'display' => (array) ($offer['display'] ?? []),
                'features' => array_values((array) ($offer['features'] ?? [])),
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'activation_confirmed' => fn () => (bool) $request->session()->get('activation_confirmed', false),
            ],
        ]);
    }
}
