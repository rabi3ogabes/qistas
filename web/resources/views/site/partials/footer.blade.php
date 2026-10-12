<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div>
                <x-logo :height="34" />
                <p class="footer-tagline display">{{ __('The just balance.') }}</p>
            </div>

            <nav aria-label="{{ __('Product') }}">
                <h2 class="footer-title">{{ __('Product') }}</h2>
                <ul class="footer-links">
                    <li><a href="{{ \App\Site\SitePages::url('home') }}#how">{{ __('How it works') }}</a></li>
                    <li><a href="{{ \App\Site\SitePages::url('calculator') }}">{{ __('Instalment calculator') }}</a></li>
                    <li><a href="{{ \App\Site\SitePages::url('pricing') }}">{{ __('Pricing') }}</a></li>
                    <li><a href="{{ \App\Site\SitePages::url('home') }}#faq">{{ __('Questions') }}</a></li>
                    <li><a href="{{ route('login') }}">{{ __('Sign in') }}</a></li>
                </ul>
            </nav>

            <nav aria-label="{{ __('Moving to Qistas') }}">
                <h2 class="footer-title">{{ __('Moving to Qistas') }}</h2>
                <ul class="footer-links">
                    <li><a href="{{ \App\Site\SitePages::url('switch.notebook') }}">{{ __('From a notebook') }}</a></li>
                    <li><a href="{{ \App\Site\SitePages::url('switch.excel') }}">{{ __('From Excel') }}</a></li>
                    <li><a href="{{ \App\Site\SitePages::url('switch.app') }}">{{ __('From another app') }}</a></li>
                </ul>
            </nav>

            <nav aria-label="{{ __('Legal') }}">
                <h2 class="footer-title">{{ __('Legal') }}</h2>
                <ul class="footer-links">
                    <li><a href="{{ \App\Site\SitePages::url('terms') }}">{{ __('Terms of Service') }}</a></li>
                    <li><a href="{{ \App\Site\SitePages::url('privacy') }}">{{ __('Privacy Policy') }}</a></li>
                    <li><a href="{{ \App\Site\SitePages::url('status') }}">{{ __('Service status') }}</a></li>
                    @if (config('qistas.support_email'))
                        <li><a href="mailto:{{ config('qistas.support_email') }}">{{ __('Contact support') }}</a></li>
                    @endif
                </ul>
            </nav>

            <nav aria-label="{{ __('Language') }}">
                <h2 class="footer-title">{{ __('Language') }}</h2>
                <ul class="footer-links">
                    @foreach (\App\Support\Locale::options() as $code => $name)
                        <li><a lang="{{ $code }}" hreflang="{{ $code }}" href="{{ \App\Site\SitePages::here($code) ?? request()->fullUrlWithQuery(['lang' => $code]) }}">{{ $name }}</a></li>
                    @endforeach
                </ul>
            </nav>
        </div>

        <p class="footer-base">© {{ now()->year }} {{ config('qistas.app_name') }}</p>
    </div>
</footer>
