@props(['title', 'description' => null, 'path' => '/', 'index' => true])
@php
    $description ??= __('Qistas keeps your customers, contracts and payments in order. Free to start, in Arabic and English.');
    $canonical = url($path);
    // Language alternates point at the same page with ?lang= (the root needs its trailing slash before the query).
    $alternateBase = $path === '/' ? url('/').'/' : url($path);
    $locale = app()->getLocale();
    $ogLocale = str_replace('-', '_', $locale);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ \App\Support\Locale::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <title>{{ $title }} · {{ config('qistas.app_name') }}</title>
    <meta name="description" content="{{ $description }}">
    @unless ($index)<meta name="robots" content="noindex">@endunless
    <link rel="canonical" href="{{ $canonical }}">
    @foreach (config('qistas.locales') as $code)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ $alternateBase }}?lang={{ $code }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $canonical }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('qistas.app_name') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ asset('brand/qistas-og-card.png') }}">
    <meta property="og:locale" content="{{ $ogLocale }}">
    <meta name="twitter:card" content="summary_large_image">

    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#F7F3EA">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#071634">
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/favicon.svg') }}">
    @include('partials.mode-script')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <x-logo-defs />
    <a class="sr-only" href="#main">{{ __('Skip to content') }}</a>
    @include('site.partials.header')
    <main id="main">{{ $slot }}</main>
    @include('site.partials.footer')
</body>
</html>
