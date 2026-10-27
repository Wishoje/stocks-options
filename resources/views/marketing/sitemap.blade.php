{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($entries as $path => $entry)
    <url><loc>{{ 'https://gexoptions.com'.($path === '/' ? '/' : $path) }}</loc><lastmod>{{ $entry['lastmod'] }}</lastmod></url>
@endforeach
</urlset>
