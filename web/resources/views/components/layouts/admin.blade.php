@props(['title' => null, 'section' => 'overview'])
@php
    $user = auth()->user();
    $hasWorkspace = $user->primaryTenant() !== null;
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

    <header class="admin-top">
        <div class="admin-top-inner">
            <a class="admin-brand" href="{{ route('admin.home') }}" aria-label="{{ config('qistas.app_name') }}"><x-logo :height="28" /></a>
            <span class="admin-badge">{{ __('Admin area') }}</span>

            <nav class="admin-nav" aria-label="{{ __('Main') }}">
                <a href="{{ route('admin.home') }}" @if ($section === 'overview') aria-current="page" @endif>{{ __('Overview') }}</a>
                @if ($hasWorkspace)
                    <a href="{{ route('app.dashboard') }}">{{ __('Open app') }}</a>
                @endif
                <a href="{{ route('security') }}" @if ($section === 'security') aria-current="page" @endif>{{ __('Security') }}</a>
                <a href="{{ route('home') }}">{{ __('Website') }}</a>
            </nav>

            <details class="menu admin-menu">
                <summary class="user-chip" aria-label="{{ __('Account') }}">
                    <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                    <span class="user-lines"><span class="user-name">{{ $user->name }}</span><span class="user-mail">{{ $user->email }}</span></span>
                    <x-icon name="chevronDown" :size="16" />
                </summary>
                <div class="menu-panel" role="menu">
                    @foreach (\App\Support\Locale::options() as $code => $name)
                        <a class="menu-item" role="menuitem" lang="{{ $code }}" href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" @if ($code === app()->getLocale()) aria-current="true" @endif>{{ $name }}</a>
                    @endforeach
                    <button type="button" class="menu-item" data-q-mode-toggle>
                        <x-icon name="moon" class="i-moon" /><x-icon name="sun" class="i-sun" /> <span>{{ __('Switch between light and dark') }}</span>
                    </button>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="menu-item" role="menuitem"><x-icon name="x" :size="18" /> {{ __('Sign out') }}</button>
                    </form>
                </div>
            </details>
        </div>
    </header>

    <main id="main" class="admin-main">
        <x-alert type="status" :message="session('status')" />
        <x-alert type="warning" :message="session('warning')" />

        {{ $slot }}
    </main>
</body>
</html>
