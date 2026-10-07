<x-layouts.auth :title="__('Account suspended')" :heading="__('Account suspended')">
    <x-alert type="warning" :message="__('Your account has been suspended. Please contact support.')" />

    @if (config('qistas.support_email'))
        <p class="auth-alt"><a href="mailto:{{ config('qistas.support_email') }}">{{ config('qistas.support_email') }}</a></p>
    @endif

    <p class="auth-alt"><a href="{{ route('login') }}">{{ __('Back to sign in') }}</a></p>
</x-layouts.auth>
