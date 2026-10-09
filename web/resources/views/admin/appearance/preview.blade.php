@php
    use Illuminate\Support\Str;

    // The draft as the three places would show it. Only this canvas wears the chosen colours; the admin page keeps its own.
    $logo = isset($pictures['logo']) ? route('brand.asset', ['asset' => $pictures['logo']->id]) : null;
    $logoDark = isset($pictures['logo_dark']) ? route('brand.asset', ['asset' => $pictures['logo_dark']->id]) : null;
    $hero = isset($pictures['hero']) ? route('brand.asset', ['asset' => $pictures['hero']->id]) : null;
    $bannerPicture = isset($pictures['banner']) ? route('brand.asset', ['asset' => $pictures['banner']->id]) : null;
    $vars = fn (array $tokens): string => collect($tokens)->map(fn ($hex, $token) => '--q-'.Str::kebab($token).': '.$hex.';')->implode(' ');

    // One banner as the preview shows it: the English words, and the place's settings.
    $previewBanner = function (string $surface) use ($banners): array {
        $b = $banners[$surface] ?? [];
        $en = $b['text']['en'] ?? [];

        return [
            'enabled' => (bool) ($b['enabled'] ?? false), 'tone' => $b['tone'] ?? 'gold', 'dismissible' => (bool) ($b['dismissible'] ?? true),
            'image' => (bool) ($b['image'] ?? false), 'title' => $en['title'] ?? '', 'message' => $en['message'] ?? '',
            'label' => $en['cta_label'] ?? '', 'link' => $b['cta_url'] ?? '',
        ];
    };
    $views = ['website' => [__('Website'), 'globe'], 'webapp' => [__('Web app'), 'monitor'], 'mobile' => [__('Phone'), 'smartphone']];
@endphp
<style @if ($nonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}" @endif>
    .sp-canvas[data-mode="light"] { {{ $vars($report['tokens']['light']) }} }
    .sp-canvas[data-mode="dark"] { {{ $vars($report['tokens']['dark']) }} }
</style>
<aside class="studio-preview" aria-labelledby="preview-title" data-preview
       data-logo="{{ $logo }}" data-logo-dark="{{ $logoDark }}" data-hero="{{ $hero }}" data-banner-picture="{{ $bannerPicture }}">
    <div class="sp-head">
        <h2 id="preview-title" class="sp-title"><x-icon name="eye" :size="16" /> {{ __('Preview') }}</h2>
        <button type="button" class="btn btn-ghost btn-sm sp-toggle" aria-expanded="true" aria-controls="sp-body" data-sp-toggle>{{ __('Hide') }}</button>
        <div class="sp-controls" id="sp-controls">
            <div class="sp-views" role="group" aria-label="{{ __('Place') }}">
                @foreach ($views as $view => [$label, $icon])
                    <button type="button" aria-pressed="{{ $view === 'website' ? 'true' : 'false' }}" data-sp-view="{{ $view }}" title="{{ $label }}"><x-icon :name="$icon" :size="16" /><span>{{ $label }}</span></button>
                @endforeach
            </div>
            <div class="sp-modes" role="group" aria-label="{{ __('Light or dark') }}">
                <button type="button" aria-pressed="true" data-sp-mode="light" aria-label="{{ __('Light mode') }}" title="{{ __('Light mode') }}"><x-icon name="sun" :size="16" /></button>
                <button type="button" aria-pressed="false" data-sp-mode="dark" aria-label="{{ __('Dark mode') }}" title="{{ __('Dark mode') }}"><x-icon name="moon" :size="16" /></button>
            </div>
        </div>
    </div>

    <div class="sp-canvas" id="sp-body" data-mode="light" data-sp-canvas>
        {{-- The website --}}
        <div class="sp-site" data-sp-surface="website">
            <div class="sp-browser" aria-hidden="true"><span class="sp-dots"><i></i><i></i><i></i></span><span class="sp-url" dir="ltr">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</span></div>
            <div class="sp-site-head">
                @include('admin.appearance.preview-logo')
                <span class="sp-nav"><span>{{ __('Pricing') }}</span><span>{{ __('Questions') }}</span></span>
                <span class="btn btn-sm sp-btn">{{ __('Open app') }}</span>
            </div>
            @include('admin.appearance.preview-banner', ['surface' => 'website', 'b' => $previewBanner('website')])
            <div class="sp-hero @if ($hero) sp-hero-has-pic @endif" data-sp-hero>
                <img class="sp-hero-pic" src="{{ $hero ?? '' }}" alt="" @unless ($hero) hidden @endunless data-sp-hero-pic>
                <p class="sp-hero-title">{{ __('Every instalment, to the cent.') }}</p>
                <p class="sp-hero-lead">{{ __('Qistas keeps your customers, contracts and payments in order, in Arabic and English.') }}</p>
                <span class="sp-hero-actions"><span class="btn btn-sm btn-gold sp-btn">{{ __('Start free') }}</span><span class="btn btn-sm btn-on-dark sp-btn">{{ __('See pricing') }}</span></span>
            </div>
            <div class="sp-site-body">
                @include('admin.appearance.preview-ledger')
            </div>
        </div>

        {{-- The web app --}}
        <div class="sp-app" data-sp-surface="webapp">
            <div class="sp-app-side" aria-hidden="true">
                @include('admin.appearance.preview-logo')
                <span class="sp-app-nav" data-current><x-icon name="home" :size="14" /> {{ __('Dashboard') }}</span>
                <span class="sp-app-nav"><x-icon name="users" :size="14" /> {{ __('Customers') }}</span>
                <span class="sp-app-nav"><x-icon name="fileText" :size="14" /> {{ __('Contracts') }}</span>
                <span class="sp-app-nav"><x-icon name="wallet" :size="14" /> {{ __('Payments') }}</span>
            </div>
            <div class="sp-app-main">
                <p class="sp-app-title">{{ __('Dashboard') }}</p>
                @include('admin.appearance.preview-banner', ['surface' => 'webapp', 'b' => $previewBanner('webapp')])
                <div class="sp-cards">
                    <div class="sp-card"><span>{{ __('Outstanding') }}</span><b dir="ltr">SAR 12,400</b></div>
                    <div class="sp-card"><span>{{ __('Overdue') }}</span><b class="sp-danger" dir="ltr">SAR 1,150</b></div>
                    <div class="sp-card"><span>{{ __('Collected this month') }}</span><b class="sp-positive" dir="ltr">SAR 8,320</b></div>
                </div>
                <div class="sp-app-row"><span class="btn btn-sm sp-btn">{{ __('Add a customer') }}</span><span class="sp-link">{{ __('See all contracts') }}</span></div>
            </div>
        </div>

        {{-- The Android app --}}
        <div class="sp-phone-wrap" data-sp-surface="mobile">
            <div class="sp-phone">
                <div class="sp-phone-status" aria-hidden="true" dir="ltr"><span>9:41</span><span class="sp-phone-icons"><i></i><i></i><i></i></span></div>
                <div class="sp-phone-bar">@include('admin.appearance.preview-logo')<span class="sp-avatar" aria-hidden="true">Q</span></div>
                <p class="sp-phone-hello">{{ __('Dashboard') }}</p>
                @include('admin.appearance.preview-banner', ['surface' => 'mobile', 'b' => $previewBanner('mobile')])
                @include('admin.appearance.preview-ledger')
                <div class="sp-phone-nav" aria-hidden="true">
                    <span data-current><x-icon name="home" :size="18" /></span><span><x-icon name="users" :size="18" /></span><span><x-icon name="fileText" :size="18" /></span><span><x-icon name="wallet" :size="18" /></span>
                </div>
            </div>
        </div>
    </div>
    <p class="sp-note">{{ __('This is your draft. Visitors see it after you publish.') }}</p>
</aside>
