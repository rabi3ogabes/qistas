@php
    $user = auth()->user();
    $isFree = $plan->isFree();
    $hasTools = app(\App\Settings\SettingsRegistry::class)->definitionsFor($tenant) !== [];
    $tabs = array_slice($nav, 0, 4);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locale::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }} · {{ config('qistas.app_name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/favicon.svg') }}">
    @include('partials.mode-script')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-brand-head />
</head>
<body class="app-body">
    <x-demo-banner />
    @if ($tenant->is_test && $user->isPlatformAdmin())
        <x-test-banner />
    @endif
    @if ($tenant->is_demo && $user->demo_expires_at !== null)
        <x-demo-account-banner />
    @endif
    <x-logo-defs />
    <a class="sr-only" href="#main">{{ __('Skip to content') }}</a>

    <div class="app">
        <aside class="app-side" aria-label="{{ __('Main') }}">
            <a class="app-brand" href="{{ route('app.dashboard') }}" aria-label="{{ config('qistas.app_name') }}"><x-logo :height="30" /></a>

            <div class="workspace">
                <p class="workspace-name">{{ $tenant->name }}</p>
                <span @class(['badge', 'badge-pro' => ! $isFree])>{{ $plan->name }}</span>
            </div>

            <nav class="app-nav">
                @foreach ($nav as $item)
                    <a class="nav-item" href="{{ route($item['route']) }}" @if ($item['key'] === $section) aria-current="page" @endif>
                        <x-icon :name="$item['icon']" :size="20" /><span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            <div class="app-side-foot">
                <section class="usage" aria-label="{{ __('Plan usage') }}">
                    <x-meter :label="__('Customers')" :entitlement="$usage['customers']" />
                    <x-meter :label="__('Active contracts')" :entitlement="$usage['contracts']" />
                </section>

                @if ($isFree)
                    <a class="btn btn-gold btn-block btn-sm" href="{{ url('/app/billing') }}">{{ __('Upgrade your plan') }}</a>
                @endif

                <x-language-switcher placement="up" />

                <details class="menu menu-up">
                    <summary class="user-chip">
                        <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                        <span class="user-lines"><span class="user-name">{{ $user->name }}</span><span class="user-mail">{{ $user->demo_expires_at !== null ? __('Demo account') : $user->email }}</span></span>
                        <x-icon name="chevronDown" :size="16" />
                    </summary>
                    <div class="menu-panel" role="menu">
                        @feature('members')
                            <a class="menu-item" role="menuitem" href="{{ route('app.team.index') }}"><x-icon name="users" :size="18" /> {{ __('Team') }}</a>
                        @endfeature
                        @feature('contract_items')
                            <a class="menu-item" role="menuitem" href="{{ route('app.products.index') }}"><x-icon name="layers" :size="18" /> {{ __('Products') }}</a>
                        @endfeature
                        {{-- Always there: the page holds the phone-security rule as well as the instalment tools. --}}
                        <a class="menu-item" role="menuitem" href="{{ route('app.settings.tools') }}"><x-icon name="sliders" :size="18" /> {{ $hasTools ? __('Instalment tools') : __('Settings') }}</a>
                        <a class="menu-item" role="menuitem" href="{{ route('app.settings.business') }}"><x-icon name="fileText" :size="18" /> {{ __('Business profile') }}</a>
                        <a class="menu-item" role="menuitem" href="{{ route('security') }}"><x-icon name="shield" :size="18" /> {{ __('Security') }}</a>
                        @if ($user->isPlatformAdmin())
                            <a class="menu-item" role="menuitem" href="{{ route('admin.home') }}"><x-icon name="sliders" :size="18" /> {{ __('Admin area') }}</a>
                        @endif
                        <button type="button" class="menu-item" data-q-mode-toggle>
                            <x-icon name="moon" class="i-moon" /><x-icon name="sun" class="i-sun" /> <span>{{ __('Switch between light and dark') }}</span>
                        </button>
                        <form method="POST" action="{{ route('logout') }}">@csrf
                            <button class="menu-item" role="menuitem"><x-icon name="x" :size="18" /> {{ __('Sign out') }}</button>
                        </form>
                    </div>
                </details>
            </div>
        </aside>

        <header class="app-top">
            <a class="app-brand" href="{{ route('app.dashboard') }}" aria-label="{{ config('qistas.app_name') }}"><x-logo variant="symbol" :height="30" /></a>
            <div class="workspace workspace-compact">
                <p class="workspace-name">{{ $tenant->name }}</p>
                <span @class(['badge', 'badge-pro' => ! $isFree])>{{ $plan->name }}</span>
            </div>
            <x-language-switcher />
        </header>

        <main id="main" class="app-main">
            @unless ($user->hasVerifiedEmail())
                <div class="alert alert-info notice" role="status">
                    <span>{{ __('Verify your email to unlock billing and exports. We sent you a link.') }}</span>
                    <form method="POST" action="{{ route('verification.send') }}">@csrf
                        <button class="btn btn-quiet btn-sm">{{ __('Resend verification email') }}</button>
                    </form>
                </div>
            @endunless

            @if ($tenant->isBeingDeleted())
                {{-- The owner asked to delete the business: say so on every page, with the way back for the owner. --}}
                <div class="alert alert-warning notice" role="alert">
                    <span>{{ __('This business will be deleted on :date. Until then nothing can be changed.', ['date' => $tenant->restoreUntil()]) }}</span>
                    @if ($user->roleIn($tenant->id) === \App\Tenancy\TenantRole::Owner)
                        <form method="POST" action="{{ route('app.account.delete.destroy') }}">@csrf @method('DELETE')
                            <button class="btn btn-quiet btn-sm">{{ __('Restore') }}</button>
                        </form>
                    @endif
                </div>
            @endif

            <x-alert type="status" :message="session('status')" />
            <x-alert type="warning" :message="session('warning')" />
            <x-alert type="error" :message="session('error')" />

            {{ $slot }}
        </main>

        <nav class="tabbar" aria-label="{{ __('Main') }}">
            @foreach ($tabs as $item)
                <a class="tab" href="{{ route($item['route']) }}" @if ($item['key'] === $section) aria-current="page" @endif>
                    <x-icon :name="$item['icon']" :size="22" /><span>{{ $item['label'] }}</span>
                </a>
            @endforeach
            <details class="tab tab-more menu menu-up">
                <summary><x-icon name="menu" :size="22" /><span>{{ __('More') }}</span></summary>
                <div class="menu-panel" role="menu">
                    @foreach (array_slice($nav, 4) as $item)
                        <a class="menu-item" role="menuitem" href="{{ route($item['route']) }}"><x-icon :name="$item['icon']" :size="18" /> {{ $item['label'] }}</a>
                    @endforeach
                    @feature('members')
                        <a class="menu-item" role="menuitem" href="{{ route('app.team.index') }}"><x-icon name="users" :size="18" /> {{ __('Team') }}</a>
                    @endfeature
                    @feature('contract_items')
                        <a class="menu-item" role="menuitem" href="{{ route('app.products.index') }}"><x-icon name="layers" :size="18" /> {{ __('Products') }}</a>
                    @endfeature
                    <a class="menu-item" role="menuitem" href="{{ route('app.settings.tools') }}"><x-icon name="sliders" :size="18" /> {{ $hasTools ? __('Instalment tools') : __('Settings') }}</a>
                        <a class="menu-item" role="menuitem" href="{{ route('app.settings.business') }}"><x-icon name="fileText" :size="18" /> {{ __('Business profile') }}</a>
                    <a class="menu-item" role="menuitem" href="{{ route('security') }}"><x-icon name="shield" :size="18" /> {{ __('Security') }}</a>
                    @if ($isFree)
                        <a class="menu-item menu-cta" role="menuitem" href="{{ url('/app/billing') }}">{{ __('Upgrade your plan') }}</a>
                    @endif
                    <button type="button" class="menu-item" data-q-mode-toggle><x-icon name="moon" class="i-moon" /><x-icon name="sun" class="i-sun" /> <span>{{ __('Switch between light and dark') }}</span></button>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="menu-item" role="menuitem"><x-icon name="x" :size="18" /> {{ __('Sign out') }}</button>
                    </form>
                </div>
            </details>
        </nav>
    </div>

    <x-upgrade-sheet :upgrade="session('upgrade')" />
</body>
</html>
