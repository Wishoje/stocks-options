<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicMarketingRoutesTest extends TestCase
{
    public function test_home_renders_the_public_home_component_with_shared_offer_data(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Marketing/Home')
                ->where('offer.plan', 'earlybird')
                ->where('offer.trial_days', 7)
                ->has('offer.display')
                ->has('offer.features'));
    }

    public function test_features_and_pricing_are_public_routes(): void
    {
        $this->get('/features')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Marketing/Features'));

        $this->get('/pricing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Marketing/Pricing'));
    }

    public function test_initial_html_contains_server_rendered_search_and_social_metadata(): void
    {
        $pages = [
            '/' => ['GexOptions - GEX Levels, Wall Tracking &amp; Setup Alerts', 'https://gexoptions.com/'],
            '/features' => ['GexOptions Features - Wall Analysis, Alerts, Scanner &amp; Options Tools', 'https://gexoptions.com/features'],
            '/pricing' => ['GexOptions Pricing', 'https://gexoptions.com/pricing'],
            '/contact' => ['Contact GexOptions', 'https://gexoptions.com/contact'],
            '/terms-of-service' => ['GexOptions Terms of Service', 'https://gexoptions.com/terms-of-service'],
            '/privacy-policy' => ['GexOptions Privacy Policy', 'https://gexoptions.com/privacy-policy'],
        ];
        $socialImage = 'https://gexoptions.com/marketing/current/social-preview.webp';
        $socialImageAlt = 'GEX Options Net GEX by strike preview with SPY scope, headline gamma levels, and a signed strike distribution.';

        foreach ($pages as $path => [$title, $canonical]) {
            $response = $this->get($path)->assertOk();
            $html = $response->getContent();

            $this->assertStringContainsString("<title inertia>{$title}</title>", $html);
            $this->assertStringContainsString('inertia="seo-description" name="description"', $html);
            $this->assertStringContainsString("inertia=\"seo-canonical\" rel=\"canonical\" href=\"{$canonical}\"", $html);
            $this->assertStringContainsString('inertia="seo-og-site-name" property="og:site_name" content="GEX Options"', $html);
            $this->assertStringContainsString("inertia=\"seo-og-image\" property=\"og:image\" content=\"{$socialImage}\"", $html);
            $this->assertStringContainsString('inertia="seo-og-image-width" property="og:image:width" content="1200"', $html);
            $this->assertStringContainsString('inertia="seo-og-image-height" property="og:image:height" content="630"', $html);
            $this->assertStringContainsString("inertia=\"seo-og-image-alt\" property=\"og:image:alt\" content=\"{$socialImageAlt}\"", $html);
            $this->assertStringContainsString('inertia="seo-twitter-card" name="twitter:card" content="summary_large_image"', $html);
            $this->assertStringContainsString("inertia=\"seo-twitter-image\" name=\"twitter:image\" content=\"{$socialImage}\"", $html);
            $this->assertStringContainsString("inertia=\"seo-twitter-image-alt\" name=\"twitter:image:alt\" content=\"{$socialImageAlt}\"", $html);

            $response->assertInertia(fn (Assert $page) => $page
                ->where('seo.image', $socialImage)
                ->where('seo.image_alt', $socialImageAlt)
                ->where('seo.image_width', 1200)
                ->where('seo.image_height', 630));
        }
    }

    public function test_product_explanations_are_available_without_executing_javascript(): void
    {
        foreach (['/' => ['Intraday or swing trading.', 'See how options pricing is changing.'], '/features' => ['Net GEX by strike', 'Dealer positioning and DEX', 'Setup alerts and your Recent alerts inbox', 'Options Calculator']] as $path => $phrases) {
            $html = $this->get($path)->assertOk()->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $main = $xpath->query('//div[@id="app"]//main[@id="marketing-main"]')->item(0);
            $this->assertNotNull($main, 'The initial response must contain visible product content.');
            $this->assertSame(1, $xpath->query('//h1')->length);
            foreach ($phrases as $phrase) {
                $this->assertStringContainsString($phrase, $main->textContent);
            }
            if ($path === '/features') {
                $this->assertSame(20, $xpath->query('//nav[@aria-label="All product features"]//a')->length);
                $this->assertSame(1, $xpath->query('//section[@id="eod-strikes"]')->length);
            }

            $script = $xpath->query('//script[@inertia="product-json-ld"]')->item(0);
            $schema = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
            $types = array_column($schema['@graph'], '@type');
            $this->assertContains('Organization', $types);
            $this->assertContains('WebApplication', $types);
            $this->assertStringNotContainsString('aggregateRating', $script->textContent);
            $this->assertStringNotContainsString('reviewCount', $script->textContent);
        }
    }

    public function test_initial_faq_and_schema_follow_the_configured_trial_length(): void
    {
        config(['plans.plans.earlybird.trial_days' => 14]);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('The current 14-day trial begins after checkout', $html);
        $this->assertStringNotContainsString('{trial_days}', $html);
        $this->assertStringNotContainsString('The current 7-day trial', $html);
    }

    public function test_other_pages_keep_the_existing_inertia_shell(): void
    {
        $this->assertStringNotContainsString('<main id="marketing-main"', $this->get('/pricing')->assertOk()->getContent());
        $this->assertNull(\App\Support\PublicMarketingContent::forRoute('dashboard'));
    }

    public function test_dashboard_remains_protected_for_guests(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_sitemap_lists_the_stable_public_marketing_pages(): void
    {
        $sitemap = file_get_contents(public_path('sitemap.xml'));
        preg_match_all('/<loc>([^<]+)<\/loc>/', $sitemap, $matches);

        $this->assertSame([
            'https://gexoptions.com/',
            'https://gexoptions.com/pricing',
            'https://gexoptions.com/features',
            'https://gexoptions.com/guides',
            'https://gexoptions.com/guides/how-to-read-spy-gex-levels',
            'https://gexoptions.com/guides/how-to-use-intraday-wall-tracking',
            'https://gexoptions.com/contact',
        ], $matches[1]);
        $this->assertStringNotContainsString('/dashboard', $sitemap);
        $this->assertStringNotContainsString('/checkout', $sitemap);
    }

    public function test_robots_keeps_marketing_pages_crawlable_and_points_to_the_sitemap(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));
        preg_match_all('/^Disallow:\s*(\S+)$/m', $robots, $matches);
        $disallowed = $matches[1];

        $this->assertStringContainsString('Sitemap: https://gexoptions.com/sitemap.xml', $robots);
        $this->assertContains('/api/', $disallowed);
        $this->assertContains('/dashboard', $disallowed);
        $this->assertContains('/checkout', $disallowed);
        $this->assertContains('/billing/', $disallowed);
        $this->assertContains('/user/', $disallowed);

        foreach (['/', '/features', '/pricing', '/contact', '/guides', '/guides/how-to-read-spy-gex-levels', '/guides/how-to-use-intraday-wall-tracking'] as $publicPath) {
            $blocked = array_filter(
                $disallowed,
                static fn (string $path): bool => $publicPath === $path || ($path !== '/' && str_starts_with($publicPath, $path))
            );
            $this->assertSame([], array_values($blocked), "$publicPath must remain crawlable.");
        }
    }
}
