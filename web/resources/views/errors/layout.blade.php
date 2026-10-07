{{-- Standalone on purpose: no session, database or authentication, so an error can never cause another error. --}}
@php($locale = app()->getLocale())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ \App\Support\Locale::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title') · {{ config('qistas.app_name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/favicon.svg') }}">
    @include('partials.mode-script')
    @vite(['resources/css/app.css'])
</head>
<body>
    <x-logo-defs />
    <header class="container" style="padding-block:1.25rem">
        <a class="site-brand" href="{{ url('/') }}" aria-label="{{ config('qistas.app_name') }}"><x-logo :height="30" /></a>
    </header>
    <main class="container error-page">
        <div>
            <p class="code" aria-hidden="true">@yield('code')</p>
            <h1 class="display">@yield('title')</h1>
            <p>@yield('message')</p>
            @yield('actions')
        </div>
    </main>
</body>
</html>
