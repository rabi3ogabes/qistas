<x-layouts.app :title="__('Instalment tools')" section="tools">
    <x-page-head :title="__('Instalment tools')">
        <x-slot:subtitle>{{ __('Choose how the extra tools you have behave.') }}</x-slot:subtitle>
    </x-page-head>

    {{-- The business's rule for its phones: Qistas opens only after a fingerprint, a face or the phone's PIN. --}}
    <section class="card settings-card" aria-labelledby="app-lock-title">
        <header class="card-head"><h2 id="app-lock-title">{{ __('Phone security') }}</h2></header>
        @if ($canEdit)
            <form method="post" action="{{ route('app.settings.security') }}" class="settings-list">
                @csrf
                @method('PUT')
                <div class="setting-row setting-row-switch">
                    <div class="setting-text">
                        <label class="setting-label" for="require-app-lock">{{ __('Require the app lock') }}</label>
                        <p class="field-hint" id="require-app-lock-hint">{{ __('Everyone in your business must unlock Qistas with a fingerprint, their face or the phone’s PIN before it shows your books.') }}</p>
                    </div>
                    <div class="setting-control">
                        <input type="hidden" name="require_app_lock" value="0">
                        <input id="require-app-lock" class="switch" type="checkbox" role="switch" name="require_app_lock" value="1" @checked($requireAppLock) aria-describedby="require-app-lock-hint">
                    </div>
                </div>
                <div class="settings-actions"><button class="btn" type="submit">{{ __('Save') }}</button></div>
            </form>
        @else
            <div class="settings-list">
                <div class="setting-row setting-row-switch">
                    <div class="setting-text">
                        <p class="setting-label">{{ __('Require the app lock') }}</p>
                        <p class="field-hint">{{ __('Only an owner or a manager can change this.') }}</p>
                    </div>
                    <div class="setting-control"><span @class(['badge', 'badge-ok' => $requireAppLock])>{{ $requireAppLock ? __('On') : __('Off') }}</span></div>
                </div>
            </div>
        @endif
    </section>

    @if ($tools === [])
        <section class="card card-pad">
            <p>{{ __('No instalment tools are available yet.') }}</p>
            <p class="field-hint">{{ __('Tools appear here when they are switched on for your workspace.') }}</p>
        </section>
    @else
        @unless ($canEdit)
            <x-alert type="info" :message="__('Only an owner or a manager can change these. You can see how they are set.')" />
        @endunless

        {{-- One form for every tool: what is changed together is saved together, with one press. --}}
        <form class="card settings-card" method="post" action="{{ route('app.settings.tools.update.all') }}" aria-labelledby="tools-title" novalidate>
            @csrf
            @method('PUT')
            <header class="card-head"><h2 id="tools-title">{{ __('Instalment tools') }}</h2></header>
            <div class="settings-list">
                @foreach ($tools as $tool)
                    @php
                        $id = 'tool-'.str_replace('.', '-', $tool['key']);
                        $field = 'values['.$tool['key'].']';
                        $error = $errors->first('values.'.$tool['key']);
                        // A key holds a dot (late_fees.amount), so what was typed is read from the list, not by a dotted path.
                        $typed = old('values');
                        $current = is_array($typed) && array_key_exists($tool['key'], $typed) ? $typed[$tool['key']] : $tool['value'];
                        $hint = $tool['help'] !== '' ? $id.'-hint' : null;
                    @endphp
                    <div @class(['setting-row', 'setting-row-switch' => $tool['type'] === 'switch'])>
                        <div class="setting-text">
                            <label class="setting-label" for="{{ $id }}">{{ $tool['label'] }}</label>
                            @if ($hint)<p class="field-hint" id="{{ $hint }}">{{ $tool['help'] }}</p>@endif
                            @if ($error)<p class="field-error" id="{{ $id }}-error" role="alert">{{ $error }}</p>@endif
                        </div>
                        <div class="setting-control field">
                            @if ($tool['type'] === 'switch')
                                <input type="hidden" name="{{ $field }}" value="0">
                                <input id="{{ $id }}" class="switch" type="checkbox" role="switch" name="{{ $field }}" value="1" @checked((bool) $current) @disabled(! $canEdit) @if ($hint) aria-describedby="{{ $hint }}" @endif>
                            @elseif ($tool['type'] === 'select')
                                <select id="{{ $id }}" name="{{ $field }}" @disabled(! $canEdit) @if ($error) aria-invalid="true" @endif @if ($hint || $error) aria-describedby="{{ trim($hint.' '.($error ? $id.'-error' : '')) }}" @endif>
                                    @foreach ($tool['options'] as $option)
                                        <option value="{{ $option['value'] }}" @selected((string) $current === $option['value'])>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            @elseif ($tool['type'] === 'amount')
                                <input id="{{ $id }}" type="text" inputmode="decimal" dir="ltr" autocomplete="off" name="{{ $field }}" value="{{ $current }}" @disabled(! $canEdit) @if ($error) aria-invalid="true" @endif @if ($hint || $error) aria-describedby="{{ trim($hint.' '.($error ? $id.'-error' : '')) }}" @endif>
                            @else
                                <input id="{{ $id }}" type="number" inputmode="numeric" name="{{ $field }}" value="{{ $current }}" @disabled(! $canEdit) @if ($error) aria-invalid="true" @endif @if ($hint || $error) aria-describedby="{{ trim($hint.' '.($error ? $id.'-error' : '')) }}" @endif>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($canEdit)
                <div class="settings-actions settings-actions-sticky">
                    <p class="settings-actions-note">{{ __('Settings apply from now on; past records stay as they are.') }}</p>
                    <button class="btn" type="submit">{{ __('Save changes') }}</button>
                </div>
            @endif
        </form>
    @endif
</x-layouts.app>
