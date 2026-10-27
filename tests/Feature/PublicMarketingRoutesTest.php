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
            '/' => ['GexOptions - GEX Levels, Swing Ideas &amp; Intraday Alerts', 'https://gexoptions.com/'],
            '/features' => ['GexOptions Features - Swing Ideas, Alerts &amp; Options Analytics', 'https://gexoptions.com/features'],
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
        foreach (['/' => ['Intraday or swing trading.', 'See how options pricing is changing.', 'Swing ideas', '2–5 day', '1–3 week'], '/features' => ['Net GEX by strike', 'Dealer positioning and DEX', 'Setup alerts and your Recent alerts inbox', 'Swing ideas for 2–5 days and 1–3 weeks', 'Options Calculator']] as $path => $phrases) {
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
                $this->assertSame(21, $xpath->query('//nav[@aria-label="All product features"]//a')->length);
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

    public function test_public_support_pages_have_visible_initial_content(): void
    {
        foreach (['/pricing' => 'Swing ideas', '/contact' => 'support@gexoptions.com', '/terms-of-service' => 'Terms of Service', '/privacy-policy' => 'Privacy Policy'] as $path => $text) {
            $html = $this->get($path)->assertOk()->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//h1')->length);
            $main = $xpath->query('//main[@id="marketing-main"]')->item(0);
            $this->assertNotNull($main);
            $this->assertStringContainsString($text, $main->textContent);
        }
        $this->assertNull(\App\Support\PublicMarketingContent::forRoute('dashboard'));
    }

    public function test_dashboard_remains_protected_for_guests(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_sitemap_lists_the_stable_public_marketing_pages(): void
    {
        $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        preg_match_all('/<loc>([^<]+)<\/loc>/', $sitemap, $matches);

        $expectedPaths = ['/', '/pricing', '/features', '/swing-trading-ideas', '/intraday-alerts', '/about', '/guides',
            '/guides/how-to-read-spy-gex-levels', '/guides/how-to-use-intraday-wall-tracking',
            '/guides/how-to-use-intraday-setup-alerts', '/guides/how-to-use-swing-trading-ideas',
            '/spy-gex-levels', '/contact', '/terms-of-service', '/privacy-policy'];
        $this->assertSame(array_map(fn ($path) => 'https://gexoptions.com'.$path, $expectedPaths), $matches[1]);
        $this->assertNotFalse(simplexml_load_string($sitemap));
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

        foreach (['/', '/features', '/pricing', '/contact', '/guides', '/guides/how-to-read-spy-gex-levels', '/guides/how-to-use-intraday-wall-tracking', '/spy-gex-levels'] as $publicPath) {
            $blocked = array_filter(
                $disallowed,
                static fn (string $path): bool => $publicPath === $path || ($path !== '/' && str_starts_with($publicPath, $path))
            );
            $this->assertSame([], array_values($blocked), "$publicPath must remain crawlable.");
        }
    }
}
