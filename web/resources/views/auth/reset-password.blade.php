<x-layouts.auth :title="__('Choose a new password')" :heading="__('Choose a new password')">
    <form method="POST" action="{{ route('password.update') }}" class="form" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-field name="email" type="email" :value="$request->email" :label="__('Email')" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" required />
        <x-field name="password" type="password" :label="__('New password')" :hint="__('At least 10 characters with upper and lower case letters, a number and a symbol.')" autocomplete="new-password" required autofocus />
        <x-field name="password_confirmation" type="password" :label="__('Confirm new password')" autocomplete="new-password" required />

        <x-button>{{ __('Save new password') }}</x-button>
    </form>
</x-layouts.auth>
