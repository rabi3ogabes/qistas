{{-- E-mail (Sprint 3, D1): the owner's SMTP server. The password is never shown back, only that one is saved. --}}
<x-layouts.admin :title="__('E-mail')" section="mail">
    <x-page-head :title="__('E-mail')">
        <x-slot:subtitle>{{ __('Verification, password-reset and alert e-mails go out through your own mail server.') }}</x-slot:subtitle>
    </x-page-head>

    <div class="detail-layout">
        <section class="card card-pad stack" aria-labelledby="mail-server-title">
            <h2 id="mail-server-title">{{ __('Mail server (SMTP)') }}</h2>
            <p>
                @if ($serverDecides)
                    <span class="badge">{{ __('The server’s own MAIL_MAILER setting is in use; these details wait until it is removed.') }}</span>
                @elseif ($smtp !== null)
                    <span class="badge badge-ok">{{ __('Sending through :host', ['host' => $smtp['host']]) }}</span>
                @else
                    <span class="badge badge-warn">{{ __('Not sending: e-mails only go to the log') }}</span>
                @endif
            </p>
            <form method="POST" action="{{ route('admin.mail.update') }}" class="form">
                @csrf
                @method('PUT')
                <x-field name="host" :label="__('Server')" :value="$smtp['host'] ?? ''" :hint="__('For example smtp.yourdomain.com')" autocomplete="off" spellcheck="false" required />
                <x-field name="port" type="number" :label="__('Port')" :value="$smtp['port'] ?? 587" min="1" max="65535" required />
                <div class="field">
                    <label for="f-security">{{ __('Security') }}</label>
                    <select id="f-security" name="security" @error('security') aria-invalid="true" @enderror>
                        @foreach (['starttls' => __('STARTTLS (usually port 587)'), 'ssl' => __('SSL (usually port 465)')] as $value => $label)
                            <option value="{{ $value }}" @selected(old('security', $smtp['security'] ?? 'starttls') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('security')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <x-field name="username" :label="__('User name')" :value="$smtp['username'] ?? ''" autocomplete="off" spellcheck="false" />
                <x-field name="password" type="password" :label="($smtp['password'] ?? false) ? __('Replace the password') : __('Password')"
                    :hint="($smtp['password'] ?? false) ? __('A password is saved. Leave this empty to keep it.') : __('Kept encrypted and never shown again.')" autocomplete="new-password" />
                <x-field name="from_address" type="email" :label="__('Send from (address)')" :value="$smtp['from_address'] ?? ''" required />
                <x-field name="from_name" :label="__('Send from (name)')" :value="$smtp['from_name'] ?? config('qistas.app_name')" required />
                <div class="form-actions"><button class="btn btn-gold" type="submit">{{ __('Save') }}</button></div>
            </form>
            @if ($smtp !== null)
                <form method="POST" action="{{ route('admin.mail.update') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="remove" value="1">
                    <button class="btn btn-ghost btn-sm danger-text" type="submit">{{ __('Remove these settings') }}</button>
                </form>
            @endif
        </section>

        <section class="card card-pad stack" aria-labelledby="mail-test-title">
            <h2 id="mail-test-title">{{ __('Check it works') }}</h2>
            <p class="muted">{{ __('Sends one e-mail to :email through the saved server.', ['email' => auth()->user()?->email]) }}</p>
            <form method="POST" action="{{ route('admin.mail.test') }}">
                @csrf
                <button class="btn" type="submit" @disabled($smtp === null && ! $serverDecides)><x-icon name="send" :size="18" /> {{ __('Send a test e-mail') }}</button>
            </form>
        </section>
    </div>
</x-layouts.admin>
