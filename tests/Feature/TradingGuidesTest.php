<?php

namespace Tests\Feature;

use App\Support\TradingGuides;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TradingGuidesTest extends TestCase
{
    public function test_guides_are_public_and_linked_from_initial_marketing_html(): void
    {
        $this->get('/guides')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Marketing/Guides')->has('guides', 4));
        foreach (['/', '/features', '/guides'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $xpath = $this->xpath($html);
            foreach (TradingGuides::all() as $guide) {
                $this->assertGreaterThan(0, $xpath->query('//main//a[@href="/guides/'.$guide['slug'].'"]')->length);
            }
        }
    }

    public function test_each_article_has_complete_initial_content_and_matching_schema(): void
    {
        foreach (TradingGuides::all() as $guide) {
            $url = '/guides/'.$guide['slug'];
            $response = $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Marketing/Guide')->where('guide.slug', $guide['slug'])
                ->where('seo.type', 'article')->where('seo.canonical', 'https://gexoptions.com'.$url));
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(1, $xpath->query('//h1')->length);
            $this->assertSame($guide['title'], $xpath->query('//h1')->item(0)->textContent);
            foreach ($guide['sections'] as $section) {
                $node = $xpath->query('//main//section[@id="'.$section['id'].'"]')->item(0);
                $this->assertNotNull($node);
                $this->assertStringContainsString($section['title'], $node->textContent);
                foreach ($section['paragraphs'] ?? [] as $paragraph) {
                    $this->assertStringContainsString($paragraph, $node->textContent);
                }
            }
            $schema = json_decode($xpath->query('//script[@inertia="product-json-ld"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
            $article = array_values(array_filter($schema['@graph'], fn ($item) => $item['@type'] === 'Article'))[0];
            $this->assertSame($guide['title'], $article['headline']);
            $this->assertSame('https://gexoptions.com'.$url, $article['mainEntityOfPage']);
            $this->assertSame($guide['published'].'T00:00:00-05:00', $article['datePublished']);
            $this->assertSame('GEX Options', $article['author']['name']);
            $this->assertSame('https://gexoptions.com/about', $article['author']['url']);
            $this->assertSame(1, $xpath->query('//meta[@property="og:type" and @content="article"]')->length);
            $this->assertSame(0, $xpath->query('//script[@inertia="faq-json-ld"]')->length);
        }
    }

    public function test_unknown_guides_return_404_instead_of_publishing_arbitrary_content(): void
    {
        $this->get('/guides/not-a-published-guide')->assertNotFound();
        $this->get('/guides/invalid/nested-path')->assertNotFound();
    }

    public function test_sitemap_and_collection_schema_list_every_published_guide(): void
    {
        $xpath = $this->xpath($this->get('/guides')->assertOk()->getContent());
        $schema = json_decode($xpath->query('//script[@inertia="product-json-ld"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
        $collection = array_values(array_filter($schema['@graph'], fn ($item) => $item['@type'] === 'CollectionPage'))[0];
        $expected = array_map(fn ($guide) => 'https://gexoptions.com/guides/'.$guide['slug'], TradingGuides::all());
        $this->assertSame($expected, array_column($collection['mainEntity']['itemListElement'], 'url'));
        foreach ($expected as $url) {
            $this->assertStringContainsString('<loc>'.$url.'</loc>', $this->get('/sitemap.xml')->assertOk()->getContent());
        }
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($dom);
    }
}
