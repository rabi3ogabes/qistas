<x-layouts.auth :title="__('Sign in')" :heading="__('Welcome back')" :lead="__('Sign in to manage your customers and instalments.')">
    <form method="POST" action="{{ route('login.store') }}" class="form" novalidate>
        @csrf
        <x-field name="email" type="email" :label="__('Email')" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" required autofocus />
        <x-field name="password" type="password" :label="__('Password')" autocomplete="current-password" required />

        <div class="form-row">
            <label class="check"><input type="checkbox" name="remember" value="1"> <span>{{ __('Keep me signed in') }}</span></label>
            <a href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
        </div>

        <x-button>{{ __('Sign in') }}</x-button>
    </form>

    <p class="auth-alt">{{ __('New to :app?', ['app' => config('qistas.app_name')]) }} <a href="{{ route('register') }}">{{ __('Create a free account') }}</a></p>
</x-layouts.auth>
