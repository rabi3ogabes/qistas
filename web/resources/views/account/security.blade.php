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

    {{-- Win Plan PP16: the phones signed in with the app, when and where each was last seen; any one can be signed out. --}}
    <section class="devices" aria-labelledby="devices-title">
        <h2 id="devices-title" class="devices-title">{{ __('Phones signed in') }}</h2>
        @if ($devices->isEmpty())
            <p class="field-hint">{{ __('No phone is signed in with the app.') }}</p>
        @else
            <ul class="devices-list">
                @foreach ($devices as $device)
                    <li>
                        <span class="devices-name">
                            <strong>{{ $device->name }}</strong>
                            <span class="field-hint">
                                {{ $device->last_used_at ? __('Last seen :when', ['when' => $device->last_used_at->diffForHumans()]) : __('Not used since signing in') }}@if ($device->getAttribute('last_used_ip')) · <span dir="ltr">{{ $device->getAttribute('last_used_ip') }}</span>@endif
                            </span>
                        </span>
                        <form method="POST" action="{{ route('security.devices.destroy', $device->id) }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-quiet btn-sm">{{ __('Sign out') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($user->tenants()->exists())
        <p style="margin-top:2rem"><a href="{{ route('app.account.delete.show') }}">{{ __('Delete my account') }}</a></p>
    @endif
</x-layouts.auth>
