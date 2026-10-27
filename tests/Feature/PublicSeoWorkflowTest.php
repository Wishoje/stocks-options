<?php

namespace Tests\Feature;

use App\Support\PublicSpyPreview;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicSeoWorkflowTest extends TestCase
{
    public function test_workflows_and_methodology_are_public_complete_and_canonical(): void
    {
        $workflows = json_decode(file_get_contents(resource_path('marketing/public-workflows.json')), true);
        foreach (['/swing-trading-ideas' => 'workflows.swing', '/intraday-alerts' => 'workflows.alerts', '/about' => 'about'] as $path => $name) {
            $response = $this->get($path.'?utm_source=google')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Marketing/Workflow')->where('content.heading', $workflows[$name]['heading'])
                ->where('seo.canonical', 'https://gexoptions.com'.$path));
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(1, $xpath->query('//h1')->length);
            $this->assertSame($workflows[$name]['heading'], $xpath->query('//h1')->item(0)->textContent);
            foreach ($workflows[$name]['sections'] as $section) {
                $node = $xpath->query('//section[@id="'.$section['id'].'"]')->item(0);
                $this->assertNotNull($node);
                foreach ($section['paragraphs'] as $paragraph) {
                    $this->assertStringContainsString($paragraph, $node->textContent);
                }
            }
            $this->assertFalse($response->headers->has('X-Robots-Tag'));
            $this->assertSame(0, $xpath->query('//meta[@name="robots"]')->length);
            $schema = json_decode($xpath->query('//script[@inertia="product-json-ld"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('aggregateRating', json_encode($schema));
            foreach ($workflows[$name]['guides'] as $slug) {
                $this->assertSame(1, $xpath->query('//main//a[@href="/guides/'.$slug.'"]')->length);
            }
        }
        $this->get('/swing-ideas')->assertRedirect('/login');
        $this->getJson('/api/intraday/walls?symbol=SPY')->assertUnauthorized();
    }

    public function test_public_auth_pages_are_crawlable_but_not_indexable(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));
        foreach (['/login', '/register?plan=earlybird&billing=monthly', '/forgot-password', '/reset-password/example-token'] as $path) {
            $response = $this->get($path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
            $this->assertSame(1, $this->xpath($response->getContent())->query('//meta[@name="robots" and @content="noindex, follow"]')->length);
            $this->assertStringNotContainsString('Disallow: '.explode('?', $path)[0]."\n", $robots);
        }
        $this->assertStringContainsString('Disallow: /api/', $robots);
        $this->assertStringContainsString('Disallow: /dashboard', $robots);
        $this->get('/pricing')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_sitemap_uses_cached_spy_date_without_building_or_fetching_data(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-04 12:00:00', 'America/New_York'));
        // A provider/data build must never be reached from a sitemap request.
        $this->mock(\App\Http\Controllers\GexController::class)->shouldNotReceive('getGexLevels');
        Cache::put('public:spy-preview:v1:last-good', ['data_date' => '2026-11-03']);
        $xml = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());
        $dates = [];
        foreach ($xml->url as $url) {
            $dates[(string) $url->loc] = (string) $url->lastmod;
        }
        $this->assertSame('2026-11-03', $dates['https://gexoptions.com/spy-gex-levels']);
        Cache::put('public:spy-preview:v1:last-good', ['data_date' => '2026-11-03', 'content_updated_date' => '2026-11-04']);
        $this->assertSame('2026-11-04', app(PublicSpyPreview::class)->cachedDataDate());
        $manifest = json_decode(file_get_contents(resource_path('marketing/sitemap-content.json')), true);
        $this->assertSame($manifest['/']['lastmod'], $dates['https://gexoptions.com/']);
        Cache::put('public:spy-preview:v1:last-good', ['data_date' => '2026-11-05']);
        $this->assertNull(app(PublicSpyPreview::class)->cachedDataDate());
        $this->travelBack();
    }

    public function test_pricing_initial_html_uses_configured_prices_and_trial(): void
    {
        config(['plans.plans.earlybird.trial_days' => 14, 'plans.plans.earlybird.display.monthly.amount_minor' => 3599]);
        $main = $this->xpath($this->get('/pricing')->assertOk()->getContent())->query('//main')->item(0)->textContent;
        $this->assertStringContainsString('14-day trial', $main);
        $this->assertStringContainsString('USD 35.99 per month', $main);
        $this->assertStringNotContainsString('29.99', $main);
    }

    public function test_home_and_features_link_to_both_workflows(): void
    {
        foreach (['/', '/features'] as $path) {
            $xpath = $this->xpath($this->get($path)->assertOk()->getContent());
            foreach (['/swing-trading-ideas', '/intraday-alerts'] as $href) {
                $this->assertGreaterThan(0, $xpath->query('//main//a[@href="'.$href.'"]')->length);
            }
        }
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($dom);
    }
}
