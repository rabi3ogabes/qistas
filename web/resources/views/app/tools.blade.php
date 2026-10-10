<x-layouts.app :title="__('Instalment tools')" section="tools">
    <x-page-head :title="__('Instalment tools')">
        <x-slot:subtitle>{{ __('Choose how the extra tools you have behave.') }}</x-slot:subtitle>
    </x-page-head>

    {{-- The business's rule for its phones: Qistas opens only after a fingerprint, a face or the phone's PIN. --}}
    <section class="card card-pad" style="margin-bottom:1rem" aria-labelledby="app-lock-title">
        <h2 id="app-lock-title" class="card-title" style="margin-bottom:.75rem">{{ __('Phone security') }}</h2>
        @if ($canEdit)
            <form method="post" action="{{ route('app.settings.security') }}">
                @csrf
                @method('PUT')
                <div class="field">
                    <input type="hidden" name="require_app_lock" value="0">
                    <label for="require-app-lock">
                        <input id="require-app-lock" type="checkbox" name="require_app_lock" value="1" @checked($requireAppLock) aria-describedby="require-app-lock-hint">
                        {{ __('Require the app lock') }}
                    </label>
                    <p class="field-hint" id="require-app-lock-hint">{{ __('Everyone in your business must unlock Qistas with a fingerprint, their face or the phone’s PIN before it shows your books.') }}</p>
                </div>
                <button class="btn btn-gold" type="submit">{{ __('Save') }}</button>
            </form>
        @else
            <p>{{ __('Require the app lock') }}: <strong>{{ $requireAppLock ? __('On') : __('Off') }}</strong></p>
            <p class="field-hint">{{ __('Only an owner or a manager can change this.') }}</p>
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

        @foreach ($tools as $tool)
            @php
                $id = 'tool-'.str_replace('.', '-', $tool['key']);
                $bag = 'tool-'.$tool['key'];
                $error = $errors->getBag($bag)->first('value');
                $current = old('value', $tool['value']);
            @endphp
            <form class="card card-pad" style="margin-bottom:1rem" method="post" action="{{ route('app.settings.tools.update', $tool['key']) }}">
                @csrf
                @method('PUT')
                <div class="field">
                    @if ($tool['type'] === 'switch')
                        <input type="hidden" name="value" value="0">
                        <label for="{{ $id }}">
                            <input id="{{ $id }}" type="checkbox" name="value" value="1" @checked((bool) $current) @disabled(! $canEdit) @if ($tool['help'] !== '') aria-describedby="{{ $id }}-hint" @endif>
                            {{ $tool['label'] }}
                        </label>
                    @else
                        <label for="{{ $id }}">{{ $tool['label'] }}</label>
                        @if ($tool['type'] === 'select')
                            <select id="{{ $id }}" name="value" @disabled(! $canEdit) @if ($error) aria-invalid="true" @endif @if ($tool['help'] !== '') aria-describedby="{{ $id }}-hint" @endif>
                                @foreach ($tool['options'] as $option)
                                    <option value="{{ $option['value'] }}" @selected((string) $current === $option['value'])>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        @else
                            <input id="{{ $id }}" type="number" inputmode="numeric" name="value" value="{{ $current }}" @disabled(! $canEdit) @if ($error) aria-invalid="true" @endif @if ($tool['help'] !== '') aria-describedby="{{ $id }}-hint" @endif>
                        @endif
                    @endif
                    @if ($tool['help'] !== '')<p class="field-hint" id="{{ $id }}-hint">{{ $tool['help'] }}</p>@endif
                    @if ($error)<p class="field-error" role="alert">{{ $error }}</p>@endif
                </div>
                @if ($canEdit)
                    <button class="btn btn-gold" type="submit">{{ __('Save') }}</button>
                @endif
            </form>
        @endforeach
    @endif
</x-layouts.app>
