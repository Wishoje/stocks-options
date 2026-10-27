<?php

namespace App\Http\Controllers;

use App\Support\PublicSpyPreview;

final class PublicSitemapController extends Controller
{
    public function __invoke(PublicSpyPreview $preview)
    {
        $entries = json_decode(file_get_contents(resource_path('marketing/sitemap-content.json')), true, flags: JSON_THROW_ON_ERROR);
        $dataDate = $preview->cachedDataDate();
        if ($dataDate !== null) {
            $entries['/spy-gex-levels']['lastmod'] = max($entries['/spy-gex-levels']['lastmod'], $dataDate);
        }

        return response()->view('marketing.sitemap', compact('entries'))
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
