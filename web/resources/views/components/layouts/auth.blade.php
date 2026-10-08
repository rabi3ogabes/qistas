@props(['title' => null, 'heading' => null, 'lead' => null, 'wide' => false])
@php
    // Fortify flashes machine keys; show people a sentence. Anything else is already a sentence.
    $statusMessages = [
        'verification-link-sent' => __('A new verification link is on its way.'),
        'two-factor-authentication-enabled' => __('Scan the QR code, then enter a code to finish setting up two-factor authentication.'),
        'two-factor-authentication-confirmed' => __('Two-factor authentication is on.'),
        'two-factor-authentication-disabled' => __('Two-factor authentication is off.'),
        'recovery-codes-generated' => __('New recovery codes were generated. The old ones no longer work.'),
        'profile-information-updated' => __('Your profile was updated.'),
        'password-updated' => __('Your password was changed.'),
    ];
    $status = session('status');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locale::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#F7F3EA">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#071634">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('qistas.app_name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/favicon.svg') }}">
    @include('partials.mode-script')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <x-demo-banner />
    <x-logo-defs />
    <div class="auth-page">
        <aside class="auth-aside" aria-hidden="false">
            <span class="plumb" aria-hidden="true"></span>
            <a class="auth-brand" href="{{ route('home') }}" aria-label="{{ config('qistas.app_name') }}"><x-logo :height="38" /></a>
            <div>
                <p class="auth-tagline display">{{ __('The just balance.') }}</p>
                <p class="auth-aside-note">{{ __('Customers, contracts and payments, kept to the cent.') }}</p>
            </div>
            <span></span>
        </aside>

        <main class="auth-main">
            <div @class(['auth-card', 'is-wide' => $wide])>
                <a class="auth-brand" href="{{ route('home') }}" aria-label="{{ config('qistas.app_name') }}"><x-logo :height="34" /></a>

                @if ($heading)
                    <header class="auth-head">
                        <h1 class="display">{{ $heading }}</h1>
                        @if ($lead)<p>{{ $lead }}</p>@endif
                    </header>
                @endif

                <x-alert type="status" :message="$statusMessages[$status] ?? $status" />
                <x-alert type="warning" :message="session('warning')" />

                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
