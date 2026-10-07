<x-layouts.auth :title="__('Reset your password')" :heading="__('Forgot your password?')" :lead="__('Enter your email and we will send you a link to choose a new one.')">
    <form method="POST" action="{{ route('password.email') }}" class="form" novalidate>
        @csrf
        <x-field name="email" type="email" :label="__('Email')" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" required autofocus />
        <x-button>{{ __('Send reset link') }}</x-button>
    </form>

    <p class="auth-alt"><a href="{{ route('login') }}">{{ __('Back to sign in') }}</a></p>
</x-layouts.auth>
