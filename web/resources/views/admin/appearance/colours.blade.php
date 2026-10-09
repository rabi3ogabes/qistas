@php
    use App\Theme\Color;
    use App\Theme\Presets;

    $presetNames = [
        'qistas' => __('Qistas'), 'emerald' => __('Emerald'), 'bordeaux' => __('Bordeaux'),
        'ocean' => __('Ocean'), 'graphite' => __('Graphite'), 'desert' => __('Desert rose'),
    ];
    // Each colour: its name, what it colours, and the contrast shown beside it.
    $fields = [
        'primary' => [__('Main colour'), __('Headers, the hero and the menu. The dark mode is made from it.'), __('Words on it')],
        'accent' => [__('Accent'), __('The finishing touch: highlights and the main call to action.'), __('Words on it')],
        'info' => [__('Interactive colour'), __('Links and the buttons that act.'), __('On the canvas')],
        'bg' => [__('Canvas'), __('The page behind everything. Keep it light.'), __('Words on it')],
    ];
    $current = collect(\App\Theme\Appearance::COLOURS)->mapWithKeys(fn ($name) => [$name => strtoupper((string) ($chosen[$name] ?? ''))])->filter()->all();
@endphp
<section id="colours" class="studio-panel" aria-labelledby="colours-title">
    <header class="studio-panel-head">
        <h2 id="colours-title">{{ __('Colours') }}</h2>
        <p>{{ ($eventMode ?? false) ? __('Leave a colour empty to keep the usual one: only the colours you set change during the event, the whole dark mode included.') : __('Start from a ready-made look or choose four colours. Everything else, the whole dark mode included, is made from them.') }}</p>
    </header>

    @unless ($eventMode ?? false)
    <div class="presets" role="group" aria-label="{{ __('Ready-made looks') }}">
        @foreach (Presets::LIST as $key => $preset)
            <button type="submit" class="preset" name="preset" value="{{ $key }}" data-preset='@json($preset['colours'])' aria-pressed="{{ $preset['colours'] == $current ? 'true' : 'false' }}">
                <span class="preset-swatches">
                    @foreach (Presets::swatches($key) as $hex)<x-swatch :hex="$hex" :size="18" />@endforeach
                </span>
                <span class="preset-name">{{ $presetNames[$key] }}</span>
            </button>
        @endforeach
    </div>
    @endunless

    <div class="colour-grid">
        @foreach ($fields as $name => [$label, $hint, $pairLabel])
            @php
                $value = (string) old("colours.{$name}", $chosen[$name] ?? '');
                $well = Color::isHex($value) ? strtolower(Color::normalise($value)) : strtolower($factory[$name]);
                $reading = $report['contrast'][$name];
                $invalid = $errors->has("colours.{$name}");
            @endphp
            <div class="colour-field" data-colour="{{ $name }}" data-factory="{{ $factory[$name] }}">
                <label class="colour-label" for="colour-{{ $name }}">{{ $label }}</label>
                <div class="colour-input">
                    <input class="colour-well" type="color" value="{{ $well }}" aria-label="{{ __('Pick the :colour', ['colour' => mb_strtolower($label)]) }}" data-colour-well>
                    <input id="colour-{{ $name }}" type="text" name="colours[{{ $name }}]" value="{{ $value }}" placeholder="{{ $factory[$name] }}" maxlength="7" spellcheck="false" autocomplete="off" dir="ltr" aria-describedby="colour-{{ $name }}-hint" @if ($invalid) aria-invalid="true" @endif data-colour-text>
                    <button type="button" class="colour-reset" data-colour-reset aria-label="{{ __('Use the Qistas colour') }}" title="{{ __('Use the Qistas colour') }}"><x-icon name="undo" :size="16" /></button>
                </div>
                <p id="colour-{{ $name }}-hint" class="field-hint">{{ $hint }}</p>
                <p class="colour-reading" data-reading data-pass="{{ $reading['pass'] ? 'yes' : 'no' }}">
                    <span>{{ $pairLabel }}</span>
                    <b data-ratio dir="ltr">{{ number_format($reading['ratio'], 1) }}:1</b>
                    <span class="colour-verdict" data-verdict>{{ $reading['pass'] ? __('Readable') : __('Adjusted when published') }}</span>
                </p>
                <p class="field-error" data-colour-error @unless ($invalid) hidden @endunless>{{ $errors->first("colours.{$name}") }}</p>
            </div>
        @endforeach
    </div>

    <div class="readability" data-readability aria-live="polite">
        @if ($report['changed'] === [])
            <p class="readability-ok"><x-icon name="check" :size="16" /> {{ __('Every text is readable in light and dark mode.') }}</p>
        @else
            <p>{{ __('When you publish, these colours will be adjusted so every text stays readable:') }}</p>
            <ul>
                @foreach ($report['changed'] as $move)
                    <li>
                        <span>{{ $tokenNames[$move['token']] ?? $move['token'] }} · {{ $modeNames[$move['mode']] }}</span>
                        <span class="studio-move" dir="ltr"><x-swatch :hex="$move['from']" /> {{ $move['from'] }} <x-icon name="arrowRight" :size="14" /> <x-swatch :hex="$move['to']" /> {{ $move['to'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
