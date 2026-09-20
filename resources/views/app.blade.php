<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/marketing/gexoptions_logo.svg">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @php($seo = data_get($page, 'props.seo'))
        @if (is_array($seo))
            <title inertia>{{ $seo['title'] }}</title>
            <meta inertia="seo-description" name="description" content="{{ $seo['description'] }}">
            <link inertia="seo-canonical" rel="canonical" href="{{ $seo['canonical'] }}">
            <meta inertia="seo-og-type" property="og:type" content="website">
            <meta inertia="seo-og-site-name" property="og:site_name" content="GEX Options">
            <meta inertia="seo-og-title" property="og:title" content="{{ $seo['title'] }}">
            <meta inertia="seo-og-description" property="og:description" content="{{ $seo['description'] }}">
            <meta inertia="seo-og-url" property="og:url" content="{{ $seo['canonical'] }}">
            <meta inertia="seo-og-image" property="og:image" content="{{ $seo['image'] }}">
            <meta inertia="seo-og-image-width" property="og:image:width" content="{{ $seo['image_width'] }}">
            <meta inertia="seo-og-image-height" property="og:image:height" content="{{ $seo['image_height'] }}">
            <meta inertia="seo-og-image-alt" property="og:image:alt" content="{{ $seo['image_alt'] }}">
            <meta inertia="seo-twitter-card" name="twitter:card" content="summary_large_image">
            <meta inertia="seo-twitter-title" name="twitter:title" content="{{ $seo['title'] }}">
            <meta inertia="seo-twitter-description" name="twitter:description" content="{{ $seo['description'] }}">
            <meta inertia="seo-twitter-image" name="twitter:image" content="{{ $seo['image'] }}">
            <meta inertia="seo-twitter-image-alt" name="twitter:image:alt" content="{{ $seo['image_alt'] }}">
        @endif

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
