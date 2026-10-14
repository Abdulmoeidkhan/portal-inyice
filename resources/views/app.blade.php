<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1f7ae0">
    <title>{{ $social['title'] ?? 'inYice-Lite' }}</title>
    @isset($social)
        <meta name="description" content="{{ $social['description'] }}">
        <meta name="robots" content="noindex, nofollow">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $social['title'] }}">
        <meta property="og:description" content="{{ $social['description'] }}">
        <meta property="og:site_name" content="{{ $social['brand'] }}">
        <meta property="og:url" content="{{ $social['url'] }}">
        <meta property="og:image" content="{{ $social['image'] }}">
        <meta property="og:image:alt" content="{{ $social['brand'] }} logo">
        <meta name="twitter:card" content="summary">
        <meta name="twitter:title" content="{{ $social['title'] }}">
        <meta name="twitter:description" content="{{ $social['description'] }}">
        <meta name="twitter:image" content="{{ $social['image'] }}">
        <meta name="twitter:image:alt" content="{{ $social['brand'] }} logo">
        <link rel="canonical" href="{{ $social['url'] }}">
    @endisset
    @include('mcp.discovery-links')
    <link rel="icon" href="/images/icons/icon-96x96.png" type="image/png" sizes="96x96">
    <link rel="icon" href="/images/icons/icon-192x192.png" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="/images/icons/icon-192x192.png">
    <link rel="manifest" href="/site.webmanifest">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
    <div id="root"></div>
</body>
</html>
