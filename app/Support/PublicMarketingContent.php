<?php

namespace App\Support;

class PublicMarketingContent
{
    public static function forRoute(?string $routeName, int $trialDays = 7): ?array
    {
        if (!in_array($routeName, ['home', 'features'], true)) {
            return null;
        }

        // Generated from the same content registry used by the Vue pages.
        $json = file_get_contents(resource_path('marketing/public-content.json'));
        $pages = json_decode(str_replace('{trial_days}', (string) max(1, $trialDays), $json), true, flags: JSON_THROW_ON_ERROR);

        return $pages[$routeName];
    }

    public static function structuredData(array $content, array $seo): array
    {
        $site = 'https://gexoptions.com/';
        $features = self::forRoute('features');
        $featureNames = [];
        foreach ($features['groups'] as $group) {
            foreach ($group['features'] as $feature) {
                $featureNames[] = $feature['title'];
            }
        }

        $page = [
            '@type' => isset($content['groups']) ? 'CollectionPage' : 'WebPage',
            '@id' => $seo['canonical'].'#webpage',
            'url' => $seo['canonical'],
            'name' => $seo['title'],
            'description' => $seo['description'],
            'inLanguage' => 'en-US',
            'isPartOf' => ['@type' => 'WebSite', '@id' => $site.'#website', 'name' => 'GEX Options', 'url' => $site],
            'about' => ['@id' => $site.'#application'],
            'publisher' => ['@id' => $site.'#organization'],
        ];

        if (isset($content['groups'])) {
            $items = [];
            foreach ($content['groups'] as $group) {
                foreach ($group['features'] as $feature) {
                    $items[] = [
                        '@type' => 'ListItem',
                        'position' => count($items) + 1,
                        'name' => $feature['title'],
                        'url' => $seo['canonical'].'#'.$feature['id'],
                    ];
                }
            }
            $page['mainEntity'] = ['@type' => 'ItemList', 'itemListElement' => $items];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Organization', '@id' => $site.'#organization', 'name' => 'GEX Options', 'url' => $site, 'logo' => $site.'marketing/gexoptions_logo.svg'],
                [
                    '@type' => 'WebApplication', '@id' => $site.'#application', 'name' => 'GEX Options',
                    'url' => $site, 'applicationCategory' => 'FinanceApplication', 'operatingSystem' => 'Web browser',
                    'description' => $features['intro']['description'], 'featureList' => $featureNames,
                    'provider' => ['@id' => $site.'#organization'],
                ],
                $page,
            ],
        ];
    }

    public static function faqSchema(array $content): array
    {
        return [
            '@context' => 'https://schema.org', '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn (array $faq): array => [
                '@type' => 'Question', 'name' => $faq['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['a']],
            ], $content['faqs']),
        ];
    }
}
