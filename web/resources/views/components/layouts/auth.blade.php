@props(['title' => null, 'heading' => null, 'lead' => null])
@php
    $rtl = in_array(app()->getLocale(), config('qistas.rtl_locales'), true);
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
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#F7F3EA">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0B1F44">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('qistas.app_name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/favicon.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth">
    <main class="auth-card">
        <a class="auth-brand" href="{{ url('/') }}" aria-label="{{ config('qistas.app_name') }}">
            <img src="{{ asset('brand/qistas-lockup-dual-h-color.svg') }}" alt="{{ config('qistas.app_name') }}" height="40">
        </a>

        @if ($heading)
            <header class="auth-head">
                <h1>{{ $heading }}</h1>
                @if ($lead)<p>{{ $lead }}</p>@endif
            </header>
        @endif

        <x-alert type="status" :message="$statusMessages[$status] ?? $status" />
        <x-alert type="warning" :message="session('warning')" />

        {{ $slot }}
    </main>
</body>
</html>
