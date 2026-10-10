{{-- The page an invitation link opens: which business, which role, and the shortest way in. --}}
@php
    $roleNames = ['manager' => __('Manager'), 'accountant' => __('Accountant'), 'collector' => __('Collector'), 'viewer' => __('Viewer')];
@endphp
<x-layouts.site :title="__('Join a business on Qistas')" :path="'/invite'">
    <div class="container prose-page">
        <article class="prose" style="max-width:34rem;margin-inline:auto">
            <x-alert type="error" :message="session('error')" />

            @if ($invitation === null)
                <h1>{{ __('This invitation link is no longer valid') }}</h1>
                <p>{{ __('It may have been used already, withdrawn, or be more than 7 days old. Ask the person who invited you for a new one.') }}</p>
                <p><a class="btn btn-quiet" href="{{ route('home') }}">{{ __('Go to the Qistas website') }}</a></p>
            @else
                <h1>{{ __('Join :name', ['name' => $invitation->tenant->name]) }}</h1>
                <p>{{ __('You are invited as :role.', ['role' => $roleNames[$invitation->role->value] ?? $invitation->role->value]) }}</p>

                @auth
                    <form method="post" action="{{ route('invitation.accept', ['token' => $token]) }}">
                        @csrf
                        <x-button variant="gold">{{ __('Join :name', ['name' => $invitation->tenant->name]) }}</x-button>
                    </form>
                    <p class="field-hint">{{ __('You keep any business you already belong to.') }}</p>
                @else
                    <h2>{{ __('New to Qistas? Make your login') }}</h2>
                    <form method="post" action="{{ route('invitation.register', ['token' => $token]) }}" class="form" novalidate>
                        @csrf
                        <x-field name="name" :label="__('Your name')" autocomplete="name" required />
                        <x-field name="email" type="email" :label="__('Email')" autocomplete="email" required />
                        <x-field name="password" type="password" :label="__('Password')" autocomplete="new-password" required />
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
                        <x-button variant="gold">{{ __('Make my login and join') }}</x-button>
                    </form>
                    <p>{!! __('Already have a login? :link, then open this link again.', ['link' => '<a href="'.e(route('login')).'">'.e(__('Sign in')).'</a>']) !!}</p>
                @endauth
            @endif
        </article>
    </div>
</x-layouts.site>
