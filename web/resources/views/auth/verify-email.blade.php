<x-layouts.auth :title="__('Verify your email')" :heading="__('Check your inbox')" :lead="__('We sent a verification link to your email address. Open it to confirm that it is yours.')">
    <form method="POST" action="{{ route('verification.send') }}" class="form">
        @csrf
        <x-button>{{ __('Resend verification email') }}</x-button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="form">
        @csrf
        <x-button variant="quiet">{{ __('Sign out') }}</x-button>
    </form>
</x-layouts.auth>
