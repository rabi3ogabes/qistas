<x-layouts.auth :title="__('Create your account')" :heading="__('Start free')" :lead="__('No card needed. Upgrade to Pro whenever you are ready.')">
    <form method="POST" action="{{ route('register.store') }}" class="form" novalidate>
        @csrf
        <input type="hidden" name="locale" value="{{ app()->getLocale() }}">

        <x-field name="name" :label="__('Your name')" autocomplete="name" required autofocus />
        <x-field name="business_name" :label="__('Business name')" autocomplete="organization" required />

        <div class="field">
            <label for="f-country">{{ __('Country') }}</label>
            <select id="f-country" name="country" autocomplete="country" required @error('country') aria-invalid="true" @enderror>
                <option value="" disabled @selected(! old('country'))>{{ __('Select your country') }}</option>
                @foreach (\App\Support\Countries::options() as $code => $name)
                    <option value="{{ $code }}" @selected(old('country') === $code)>{{ $name }}</option>
                @endforeach
            </select>
            @error('country')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div>

        <x-field name="email" type="email" :label="__('Email')" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" required />
        <x-field name="password" type="password" :label="__('Password')" :hint="__('At least 10 characters with upper and lower case letters, a number and a symbol.')" autocomplete="new-password" required />
        <x-field name="password_confirmation" type="password" :label="__('Confirm password')" autocomplete="new-password" required />

        <div class="field">
            <label class="check">
                <input type="checkbox" name="terms" value="1" @checked(old('terms')) required @error('terms') aria-invalid="true" @enderror>
                <span>{!! __('I agree to the :terms and the :privacy.', [
                    'terms' => '<a href="'.e(url('/terms')).'">'.e(__('Terms of Service')).'</a>',
                    'privacy' => '<a href="'.e(url('/privacy')).'">'.e(__('Privacy Policy')).'</a>',
                ]) !!}</span>
            </label>
            @error('terms')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div>

        <x-button>{{ __('Create free account') }}</x-button>
    </form>

    <p class="auth-alt">{{ __('Already have an account?') }} <a href="{{ route('login') }}">{{ __('Sign in') }}</a></p>
</x-layouts.auth>
