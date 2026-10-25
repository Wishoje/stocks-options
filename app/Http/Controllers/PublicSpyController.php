<?php

namespace App\Http\Controllers;

use App\Support\PublicSpyPreview;
use Inertia\Inertia;

final class PublicSpyController extends Controller
{
    public function __invoke(PublicSpyPreview $source)
    {
        $preview = $source->read();
        $content = json_decode(file_get_contents(resource_path('marketing/spy-preview.json')), true, flags: JSON_THROW_ON_ERROR);
        $seo = [...config('marketing_seo.pages')['spy.preview'], 'image' => config('marketing_seo.social_image.url'),
            'image_alt' => config('marketing_seo.social_image.alt'), 'image_width' => config('marketing_seo.social_image.width'),
            'image_height' => config('marketing_seo.social_image.height')];
        $seo['structured_data'] = ['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'Organization', '@id' => 'https://gexoptions.com/#organization', 'name' => 'GEX Options', 'url' => 'https://gexoptions.com/'],
            ['@type' => 'WebPage', '@id' => $seo['canonical'].'#webpage', 'name' => $seo['title'], 'url' => $seo['canonical'],
                'description' => $seo['description'], 'inLanguage' => 'en-US', 'publisher' => ['@id' => 'https://gexoptions.com/#organization']],
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://gexoptions.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'SPY GEX levels', 'item' => $seo['canonical']],
            ]],
        ]];

        return Inertia::render('Marketing/SpyPreview', compact('preview', 'content', 'seo'));
    }
}
