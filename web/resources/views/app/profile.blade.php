@php
    $locale = old('locale', $user->locale);
    $since = $user->created_at?->translatedFormat('F Y');
@endphp
<x-layouts.app :title="__('Your profile')" section="profile">
    <x-page-head :title="__('Your profile')">
        <x-slot:subtitle>{{ __('Your own details, the same in every business you work in.') }}</x-slot:subtitle>
    </x-page-head>

    <div class="profile-layout">
        {{-- Who you are, at a glance, with your photo. --}}
        <section class="card profile-card" aria-labelledby="profile-name">
            <div class="profile-identity">
                <x-avatar :user="$user" size="xl" />
                <div class="profile-who">
                    <h2 id="profile-name" class="profile-name" dir="auto">{{ $user->name }}</h2>
                    <p class="profile-mail" dir="ltr">{{ $user->email }}</p>
                    <p class="profile-badges">
                        @if ($user->hasVerifiedEmail())
                            <span class="badge badge-ok"><x-icon name="check" :size="12" /> {{ __('E-mail confirmed') }}</span>
                        @else
                            <span class="badge badge-warn">{{ __('E-mail not confirmed yet') }}</span>
                        @endif
                        @if ($role)<span class="badge">{{ $role->label() }}</span>@endif
                    </p>
                    @if ($since)<p class="profile-since">{{ __('Member since :date', ['date' => $since]) }}</p>@endif
                </div>
            </div>

            <div class="profile-photo" aria-labelledby="photo-title">
                <h3 id="photo-title" class="profile-photo-title">{{ __('Photo') }}</h3>
                <p class="field-hint">{{ __('A clear photo of your face helps your team know who did what. JPEG, PNG or WebP.') }}</p>
                <div class="profile-photo-actions">
                    <form method="POST" action="{{ route('app.profile.photo.store') }}" enctype="multipart/form-data">
                        @csrf
                        <label class="btn btn-quiet btn-sm" for="profile-photo-file"><x-icon name="upload" :size="16" /> {{ $photo ? __('Change photo') : __('Add a photo') }}</label>
                        <input id="profile-photo-file" class="sr-only" type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-autosubmit>
                    </form>
                    @if ($photo)
                        <form method="POST" action="{{ route('app.profile.photo.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-ghost btn-sm" type="submit">{{ __('Remove photo') }}</button>
                        </form>
                    @endif
                </div>
                @error('photo')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </div>
        </section>

        <div class="profile-forms">
            <form class="card" method="POST" action="{{ route('app.profile.update') }}" novalidate aria-labelledby="details-title">
                @csrf
                @method('PUT')
                <header class="card-head"><h2 id="details-title">{{ __('Your details') }}</h2></header>
                <div class="card-pad form form-grid">
                    <x-field wide name="name" bag="updateProfileInformation" :label="__('Your name')" :value="$user->name" autocomplete="name" maxlength="255" required />
                    <x-field wide name="email" type="email" bag="updateProfileInformation" :label="__('Email')" :value="$user->email" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" dir="ltr" required
                        :hint="__('A new e-mail is confirmed with a link we send to it.')" />
                    <x-field name="phone" type="tel" bag="updateProfileInformation" :label="__('Phone (optional)')" :value="$user->phone" autocomplete="tel" inputmode="tel" dir="ltr" maxlength="32"
                        :hint="__('Your own number, for notices by text message once they are offered.')" />
                    <div class="field">
                        <label for="f-locale">{{ __('Language') }}</label>
                        <select id="f-locale" name="locale" autocomplete="language">
                            @foreach ($languages as $code => $name)
                                <option value="{{ $code }}" lang="{{ $code }}" @selected($locale === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <p class="field-hint">{{ __('The website, the app and your e-mails use it.') }}</p>
                    </div>
                </div>
                <div class="settings-actions"><button class="btn" type="submit">{{ __('Save changes') }}</button></div>
            </form>

            <form class="card" method="POST" action="{{ route('app.profile.password') }}" novalidate aria-labelledby="password-title">
                @csrf
                @method('PUT')
                <header class="card-head"><h2 id="password-title">{{ __('Password') }}</h2></header>
                <div class="card-pad form form-grid">
                    <x-field wide name="current_password" type="password" bag="updatePassword" :label="__('Current password')" autocomplete="current-password" required />
                    <x-field name="password" type="password" bag="updatePassword" :label="__('New password')" autocomplete="new-password" required
                        :hint="__('At least 10 characters, with capital and small letters, a number and a symbol.')" />
                    <x-field name="password_confirmation" type="password" bag="updatePassword" :label="__('Confirm the new password')" autocomplete="new-password" required />
                </div>
                <div class="settings-actions"><button class="btn" type="submit">{{ __('Change password') }}</button></div>
            </form>

            <nav class="card profile-links" aria-label="{{ __('More for your account') }}">
                <a class="profile-link" href="{{ route('security') }}">
                    <x-icon name="shield" :size="20" />
                    <span><strong>{{ __('Security') }}</strong><span class="cell-sub">{{ __('Two-step sign-in and the devices signed in.') }}</span></span>
                    <x-icon name="chevronRight" :size="18" class="flip" />
                </a>
                <a class="profile-link" href="{{ route('app.settings.notifications') }}">
                    <x-icon name="bell" :size="20" />
                    <span><strong>{{ __('Notifications') }}</strong><span class="cell-sub">{{ __('What you are told, and how.') }}</span></span>
                    <x-icon name="chevronRight" :size="18" class="flip" />
                </a>
                <a class="profile-link profile-link-danger" href="{{ route('app.account.delete.show') }}">
                    <x-icon name="x" :size="20" />
                    <span><strong>{{ __('Delete my account') }}</strong><span class="cell-sub">{{ __('What happens, and how to do it.') }}</span></span>
                    <x-icon name="chevronRight" :size="18" class="flip" />
                </a>
            </nav>
        </div>
    </div>
</x-layouts.app>
