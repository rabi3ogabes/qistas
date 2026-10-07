<x-layouts.auth :title="__('Confirm your password')" :heading="__('Confirm your password')" :lead="__('This is a sensitive area. Please enter your password to continue.')">
    <form method="POST" action="{{ route('password.confirm.store') }}" class="form" novalidate>
        @csrf
        <x-field name="password" type="password" :label="__('Password')" autocomplete="current-password" required autofocus />
        <x-button>{{ __('Confirm') }}</x-button>
    </form>
</x-layouts.auth>
