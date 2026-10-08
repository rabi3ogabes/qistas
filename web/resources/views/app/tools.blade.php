<x-layouts.app :title="__('Instalment tools')" section="tools">
    <x-page-head :title="__('Instalment tools')">
        <x-slot:subtitle>{{ __('Choose how the extra tools you have behave.') }}</x-slot:subtitle>
    </x-page-head>

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
