<x-layouts.auth :title="__('Recovery codes')" :heading="__('Recovery codes')" :lead="__('Each code works once if you lose your phone. Store them somewhere safe, such as a password manager.')">
    <ul class="codes" dir="ltr">
        @foreach ($codes as $code)
            <li><code>{{ $code }}</code></li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" class="form">
        @csrf
        <x-button variant="quiet">{{ __('Generate new codes') }}</x-button>
    </form>

    <p class="auth-alt"><a href="{{ route('security') }}">{{ __('Back to security') }}</a></p>
</x-layouts.auth>
