<x-layouts.auth :title="__('Two-factor authentication')" :heading="__('Confirm it is you')" :lead="__('Enter the 6-digit code from your authenticator app.')">
    <form method="POST" action="{{ route('two-factor.login.store') }}" class="form" novalidate>
        @csrf
        <x-field name="code" :label="__('Authentication code')" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" required autofocus />
        <x-button>{{ __('Continue') }}</x-button>
    </form>

    <details class="auth-alt" @if ($errors->has('recovery_code')) open @endif>
        <summary>{{ __('Lost your phone? Use a recovery code') }}</summary>
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="form" novalidate>
            @csrf
            <x-field name="recovery_code" :label="__('Recovery code')" autocomplete="off" autocapitalize="none" spellcheck="false" required />
            <x-button variant="quiet">{{ __('Use recovery code') }}</x-button>
        </form>
    </details>
</x-layouts.auth>
