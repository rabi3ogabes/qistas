@props(['title' => null, 'section' => 'overview'])
@php
    $user = auth()->user();
    $hasWorkspace = $user->primaryTenant() !== null;

    // The pages of the admin area, then the places a person goes next. One list each, so a new page is one line.
    $manage = array_values(array_filter([
        ['overview', __('Overview'), route('admin.home'), 'home'],
        ['businesses', __('Businesses'), route('admin.businesses.index'), 'accounts'],
        ['features', __('Feature control'), route('admin.features.index'), 'sliders'],
        \Illuminate\Support\Facades\Route::has('admin.appearance.index') ? ['appearance', __('Appearance'), route('admin.appearance.index'), 'palette'] : null,
    ]));
    $goTo = array_values(array_filter([
        $hasWorkspace ? ['app', __('Open app'), route('app.dashboard'), 'layers'] : null,
        ['website', __('Website'), route('home'), 'globe'],
    ]));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locale::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#F7F3EA">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#071634">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('qistas.app_name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/favicon.svg') }}">
    @include('partials.mode-script')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-body">
    <x-demo-banner />
    <x-logo-defs />
    <a class="sr-only" href="#main">{{ __('Skip to content') }}</a>

    {{-- On a phone the menu is a panel that slides in from the right; this bar holds the button that opens it. --}}
    <header class="admin-bar">
        <a class="admin-brand" href="{{ route('admin.home') }}" aria-label="{{ config('qistas.app_name') }}"><x-logo :height="26" :brand="false" /></a>
        <a class="admin-menu-button" href="#admin-nav" aria-controls="admin-nav" aria-label="{{ __('Menu') }}"><x-icon name="menu" :size="22" /></a>
    </header>

    <div class="admin-shell">
        <main id="main" class="admin-main">
            <x-alert type="status" :message="session('status')" />
            <x-alert type="warning" :message="session('warning')" />

            {{ $slot }}
        </main>

        <aside id="admin-nav" class="admin-rail" aria-label="{{ __('Admin menu') }}">
            <a href="#main" class="admin-scrim" tabindex="-1" aria-hidden="true"></a>

            <div class="rail">
                <div class="rail-head">
                    <a class="rail-brand" href="{{ route('admin.home') }}" aria-label="{{ config('qistas.app_name') }}"><x-logo :height="30" :brand="false" /></a>
                    <span class="rail-badge">{{ __('Admin area') }}</span>
                    <a href="#main" class="rail-close" aria-label="{{ __('Close the menu') }}"><x-icon name="x" :size="20" /></a>
                </div>

                <nav class="rail-nav" aria-label="{{ __('Admin pages') }}">
                    @foreach ($manage as [$key, $label, $href, $icon])
                        <a href="{{ $href }}" @if ($section === $key) aria-current="page" @endif><x-icon :name="$icon" :size="20" /> <span>{{ $label }}</span></a>
                    @endforeach
                </nav>

                <nav class="rail-nav rail-nav-quiet" aria-label="{{ __('Go to') }}">
                    @foreach ($goTo as [$key, $label, $href, $icon])
                        <a href="{{ $href }}"><x-icon :name="$icon" :size="20" /> <span>{{ $label }}</span><x-icon name="arrowUpRight" :size="16" class="rail-out" /></a>
                    @endforeach
                </nav>

                <div class="rail-foot">
                    <a class="rail-account" href="{{ route('security') }}" @if ($section === 'security') aria-current="page" @endif aria-label="{{ __('Security') }}">
                        <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                        <span class="rail-who"><span class="rail-name">{{ $user->name }}</span><span class="rail-mail">{{ $user->email }}</span></span>
                        <x-icon name="shield" :size="18" class="rail-shield" />
                    </a>

                    <div class="rail-tools">
                        <x-language-switcher placement="up" />
                        <button type="button" class="rail-tool" data-q-mode-toggle aria-label="{{ __('Switch between light and dark') }}" title="{{ __('Switch between light and dark') }}">
                            <x-icon name="moon" :size="18" class="i-moon" /><x-icon name="sun" :size="18" class="i-sun" />
                        </button>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="rail-signout"><x-icon name="x" :size="18" /> {{ __('Sign out') }}</button>
                    </form>
                </div>
            </div>
        </aside>
    </div>
</body>
</html>
