{{-- Notifications for everyone (Sprint 3 Task 1b): which channels are ready, the Firebase key, and which kind may use which channel. --}}
@php
    use App\Notifications\Catalog;

    $groups = collect($kinds)->groupBy(fn (array $kind) => $kind['group'], preserveKeys: true);
@endphp
<x-layouts.admin :title="__('Notifications')" section="notifications">
    <x-page-head :title="__('Notifications')">
        <x-slot:subtitle>{{ __('What reaches people, and how: in the app with a push to their phones, by e-mail, or by text message. Each person then chooses among what you allow here.') }}</x-slot:subtitle>
    </x-page-head>

    <ul class="health" aria-label="{{ __('Channels') }}">
        <li class="health-row" data-status="{{ $pushProject ? 'ok' : 'warn' }}">
            <span class="health-dot" aria-hidden="true"></span>
            <div>
                <p class="health-title">{{ __('Pushes to Android phones and iPhones') }}</p>
                <p class="health-detail">
                    {{ $pushProject ? __('Sent through the Firebase project :project.', ['project' => $pushProject]) : __('Pushes are written to the log until the Firebase key is saved below. The inbox in the app works either way.') }}
                </p>
            </div>
        </li>
        <li class="health-row" data-status="{{ $available['email'] ? 'ok' : 'warn' }}">
            <span class="health-dot" aria-hidden="true"></span>
            <div>
                <p class="health-title">{{ __('E-mail') }}</p>
                <p class="health-detail">
                    {{ $available['email'] ? __('Sent through :host.', ['host' => $mailHost ?? config('mail.default')]) : __('Not set up: save your mail server in E-mail to send notices by e-mail.') }}
                    <a class="link" href="{{ route('admin.mail.index') }}">{{ __('E-mail settings') }}</a>
                </p>
            </div>
        </li>
        <li class="health-row" data-status="{{ $available['sms'] ? 'ok' : 'off' }}">
            <span class="health-dot" aria-hidden="true"></span>
            <div>
                <p class="health-title">{{ __('Text messages (SMS)') }}</p>
                <p class="health-detail">{{ $available['sms'] ? __('Ready.') : __('No SMS provider yet. Text messages are offered once you choose one.') }}</p>
            </div>
        </li>
    </ul>

    <section class="card" aria-labelledby="allowed-title" style="margin-top:1rem">
        <div class="card-head"><h2 id="allowed-title">{{ __('What may go where') }}</h2></div>
        <form method="POST" action="{{ route('admin.notifications.update') }}">
            @csrf
            @method('PUT')
            <div class="table-wrap">
                <table class="table table-stack table-matrix">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Notice') }}</th>
                            @foreach (Catalog::CHANNELS as $channel)
                                <th scope="col" class="center">{{ Catalog::channel($channel) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    @foreach ($groups as $group => $members)
                        <tbody>
                            <tr class="table-group"><th scope="rowgroup" colspan="{{ 1 + count(Catalog::CHANNELS) }}">{{ Catalog::group($group) }}</th></tr>
                            @foreach ($members as $kind => $definition)
                                <tr>
                                    <th scope="row" data-label="{{ __('Notice') }}">
                                        <span class="biz-cell"><span>{{ Catalog::label($kind) }}</span><span class="cell-sub">{{ Catalog::description($kind) }}</span></span>
                                    </th>
                                    @foreach (Catalog::CHANNELS as $channel)
                                        <td data-label="{{ Catalog::channel($channel) }}" class="center">
                                            <input type="checkbox" name="allowed[{{ $kind }}][{{ $channel }}]" value="1" @checked($allowed[$kind][$channel])
                                                aria-label="{{ Catalog::label($kind) }}: {{ Catalog::channel($channel) }}">
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
            <div class="card-pad form-actions"><button class="btn btn-gold" type="submit">{{ __('Save') }}</button></div>
        </form>
    </section>

    <section class="card card-pad stack" aria-labelledby="firebase-title" style="margin-top:1rem">
        <h2 id="firebase-title">{{ __('Firebase key for pushes') }}</h2>
        <p class="muted">{{ __('In the Firebase console: Project settings, then Service accounts, then Generate new private key. Paste the whole file here. It is kept encrypted and never shown again.') }}</p>
        <form method="POST" action="{{ route('admin.notifications.push') }}" class="form">
            @csrf
            @method('PUT')
            <x-field name="service_account" type="textarea" :label="$pushProject ? __('Replace the service account') : __('Service account (JSON)')" rows="5" spellcheck="false" autocomplete="off" />
            <div class="form-actions"><button class="btn btn-gold" type="submit">{{ __('Save') }}</button></div>
        </form>
        @if ($pushProject)
            <form method="POST" action="{{ route('admin.notifications.push') }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="remove" value="1">
                <button class="btn btn-ghost btn-sm danger-text" type="submit">{{ __('Remove the Firebase key') }}</button>
            </form>
        @endif
        <p class="field-hint">{{ __('iPhones also need the Apple push key uploaded in Firebase (Cloud Messaging, Apple app configuration).') }}</p>
    </section>
</x-layouts.admin>
