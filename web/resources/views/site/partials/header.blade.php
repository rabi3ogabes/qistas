@php
    $links = [
        ['href' => route('home').'#how', 'label' => __('How it works'), 'current' => false],
        ['href' => route('pricing'), 'label' => __('Pricing'), 'current' => request()->routeIs('pricing')],
        ['href' => route('home').'#faq', 'label' => __('Questions'), 'current' => false],
    ];
@endphp
<header class="site-header">
    <div class="container site-header-inner">
        <a class="site-brand" href="{{ route('home') }}" aria-label="{{ config('qistas.app_name') }}"><x-logo :height="30" /></a>

        <nav class="site-nav" aria-label="{{ __('Main') }}">
            @foreach ($links as $link)
                <a href="{{ $link['href'] }}" @if ($link['current']) aria-current="page" @endif>{{ $link['label'] }}</a>
            @endforeach
        </nav>

        <div class="site-actions">
            <details class="menu hide-sm">
                <summary class="lang-trigger" aria-label="{{ __('Language') }}">
                    <x-icon name="globe" :size="18" />
                    <span>{{ \App\Support\Locale::options()[app()->getLocale()] }}</span>
                    <x-icon name="chevronDown" :size="14" />
                </summary>
                <div class="menu-panel" role="menu">
                    @foreach (\App\Support\Locale::options() as $code => $name)
                        <a class="menu-item" role="menuitem" lang="{{ $code }}" hreflang="{{ $code }}"
                           href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}"
                           @if ($code === app()->getLocale()) aria-current="true" @endif>{{ $name }}</a>
                    @endforeach
                </div>
            </details>

            <button type="button" class="icon-btn hide-sm" data-q-mode-toggle aria-label="{{ __('Switch between light and dark') }}">
                <x-icon name="moon" class="i-moon" /><x-icon name="sun" class="i-sun" />
            </button>

            @auth
                <a class="btn btn-sm hide-xs" href="{{ url(config('fortify.home')) }}">{{ __('Open app') }}</a>
            @else
                <a class="btn btn-ghost btn-sm hide-sm" href="{{ route('login') }}">{{ __('Sign in') }}</a>
                <a class="btn btn-sm hide-xs" href="{{ route('register') }}">{{ __('Start free') }}</a>
            @endauth

            <details class="menu show-sm">
                <summary class="icon-btn" aria-label="{{ __('Menu') }}"><x-icon name="menu" /></summary>
                <div class="menu-panel" role="menu">
                    @auth
                        <a class="menu-item menu-cta" role="menuitem" href="{{ url(config('fortify.home')) }}">{{ __('Open app') }}</a>
                    @else
                        <a class="menu-item menu-cta" role="menuitem" href="{{ route('register') }}">{{ __('Start free') }}</a>
                    @endauth
                    @foreach ($links as $link)
                        <a class="menu-item" role="menuitem" href="{{ $link['href'] }}">{{ $link['label'] }}</a>
                    @endforeach
                    @guest
                        <a class="menu-item" role="menuitem" href="{{ route('login') }}">{{ __('Sign in') }}</a>
                    @endguest
                    <hr class="menu-rule">
                    @foreach (\App\Support\Locale::options() as $code => $name)
                        <a class="menu-item" role="menuitem" lang="{{ $code }}" hreflang="{{ $code }}"
                           href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}"
                           @if ($code === app()->getLocale()) aria-current="true" @endif>{{ $name }}</a>
                    @endforeach
                    <hr class="menu-rule">
                    <button type="button" class="menu-item" data-q-mode-toggle>
                        <x-icon name="moon" class="i-moon" /><x-icon name="sun" class="i-sun" />
                        <span>{{ __('Switch between light and dark') }}</span>
                    </button>
                </div>
            </details>
        </div>
    </div>
</header>
