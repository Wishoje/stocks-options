<?php

namespace App\Support;

final class TradingGuides
{
    public static function all(): array
    {
        return json_decode(file_get_contents(resource_path('marketing/trading-guides.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function find(string $slug): ?array
    {
        foreach (self::all() as $guide) {
            if ($guide['slug'] === $slug) {
                return $guide;
            }
        }

        return null;
    }

    public static function structuredData(array $content, array $seo): array
    {
        $site = 'https://gexoptions.com/';
        $organization = ['@type' => 'Organization', '@id' => $site.'#organization', 'name' => 'GEX Options', 'url' => $site, 'logo' => $site.'marketing/gexoptions_logo.svg'];
        $page = ['@type' => 'CollectionPage', '@id' => $seo['canonical'].'#webpage', 'url' => $seo['canonical'], 'name' => $seo['title'], 'description' => $seo['description'], 'inLanguage' => 'en-US'];
        $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $site], ['@type' => 'ListItem', 'position' => 2, 'name' => 'Trading guides', 'item' => $site.'guides']];
        if (isset($content['guide'])) {
            $guide = $content['guide'];
            $page = [
                ...$page, '@type' => 'Article', 'headline' => $guide['title'],
                'mainEntityOfPage' => $seo['canonical'], 'image' => $seo['image'],
                'datePublished' => $guide['published'].'T00:00:00-05:00', 'dateModified' => $guide['updated'].'T00:00:00-05:00',
                'author' => ['@type' => 'Organization', 'name' => 'GEX Options', 'url' => $site.'contact'],
                'publisher' => ['@id' => $site.'#organization'],
            ];
            $crumbs[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $guide['title'], 'item' => $seo['canonical']];
        } else {
            $page['mainEntity'] = ['@type' => 'ItemList', 'itemListElement' => array_map(static fn (array $guide, int $position): array => [
                '@type' => 'ListItem', 'position' => $position + 1, 'name' => $guide['title'], 'url' => $site.'guides/'.$guide['slug'],
            ], $content['guides'], array_keys($content['guides']))];
        }

        return ['@context' => 'https://schema.org', '@graph' => [$organization, $page, ['@type' => 'BreadcrumbList', 'itemListElement' => $crumbs]]];
    }
}
