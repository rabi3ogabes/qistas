<x-layouts.auth :title="__('Security')" :heading="__('Two-factor authentication')" :lead="__('A second step at sign-in keeps your business data safe even if your password leaks.')">
    @if ($user->isPlatformAdmin())
        <x-alert type="warning" :message="__('Administrators must use two-factor authentication.')" />
    @endif

    @if ($user->hasConfirmedTwoFactor())
        <p class="alert alert-status">{{ __('Two-factor authentication is on.') }}</p>

        <p><a class="btn btn-quiet" href="{{ route('security.recovery-codes') }}">{{ __('View recovery codes') }}</a></p>

        <form method="POST" action="{{ route('two-factor.disable') }}" class="form">
            @csrf
            @method('DELETE')
            <x-button variant="danger">{{ __('Turn off two-factor authentication') }}</x-button>
        </form>
    @elseif ($user->two_factor_secret)
        <ol class="steps">
            <li>{{ __('Scan this QR code with an authenticator app such as Google Authenticator, Microsoft Authenticator or 1Password.') }}</li>
        </ol>
        <div class="qr" role="img" aria-label="{{ __('Two-factor setup QR code') }}">{!! $user->twoFactorQrCodeSvg() !!}</div>
        <p class="field-hint">{{ __('Cannot scan it? Enter this key instead:') }} <code dir="ltr">{{ decrypt($user->two_factor_secret) }}</code></p>

        <form method="POST" action="{{ route('two-factor.confirm') }}" class="form" novalidate>
            @csrf
            <x-field name="code" bag="confirmTwoFactorAuthentication" :label="__('Authentication code')" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" required autofocus />
            <x-button>{{ __('Confirm and turn on') }}</x-button>
        </form>
    @else
        <form method="POST" action="{{ route('two-factor.enable') }}" class="form">
            @csrf
            <x-button>{{ __('Turn on two-factor authentication') }}</x-button>
        </form>
    @endif

    @if ($user->tenants()->exists())
        <p style="margin-top:2rem"><a href="{{ route('app.account.delete.show') }}">{{ __('Delete my account') }}</a></p>
    @endif
</x-layouts.auth>
