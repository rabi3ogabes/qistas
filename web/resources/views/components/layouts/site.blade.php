{{-- `focused`: a page for someone who is not choosing Qistas (a shop's customer signing): no offers, no sign-up. --}}
@props(['title', 'description' => null, 'path' => '/', 'index' => true, 'focused' => false])
@php
    use App\Site\SitePages;

    $description ??= __('Qistas keeps your customers, contracts and payments in order. Free to start, in Arabic and English.');
    // A public page names its own address (in the language path it was opened in) and each language's (Sprint 2, D10),
    // with the plain address, which reads in the reader's own language, as the x-default. Other pages name only themselves.
    $page = SitePages::current();
    $canonical = $page !== null ? SitePages::url($page) : url($path);
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
    @if ($page !== null)
        @foreach (config('qistas.locales') as $code)
            <link rel="alternate" hreflang="{{ $code }}" href="{{ SitePages::url($page, [], $code) }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ SitePages::plain($page) }}">
    @endif

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('qistas.app_name') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ asset('brand/qistas-og-card.png') }}">
    <meta property="og:locale" content="{{ $ogLocale }}">
    <meta name="twitter:card" content="summary_large_image">

    <meta name="color-scheme" content="light dark">
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/favicon.svg') }}">
    @include('partials.mode-script')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-brand-head />
    {{ $head ?? '' }}
</head>
<body>
    <x-demo-banner />
    <x-logo-defs />
    <a class="sr-only" href="#main">{{ __('Skip to content') }}</a>
    @include('site.partials.header', ['focused' => $focused])
    @unless ($focused)<x-welcome-banner surface="website" />@endunless
    <main id="main">{{ $slot }}</main>
    @include('site.partials.footer')
</body>
</html>
