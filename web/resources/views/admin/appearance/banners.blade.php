@php
    $surfaces = [
        'website' => [__('Website'), 'globe', __('Under the header of every public page.')],
        'webapp' => [__('Web app'), 'monitor', __('At the top of the dashboard.')],
        'mobile' => [__('Android app'), 'smartphone', __('A card on the app’s dashboard.')],
    ];
    $tones = ['gold' => __('Gold'), 'navy' => __('Navy'), 'sand' => __('Sand'), 'sky' => __('Sky')];
    $limits = ['title' => 80, 'message' => 240, 'cta_label' => 30];
    $today = now()->toDateString();

    // Where a banner stands today, as the website would decide it.
    $stateOf = function (array $banner) use ($today): array {
        if (! ($banner['enabled'] ?? false)) {
            return ['off', __('Off')];
        }
        if (! empty($banner['starts_on']) && $today < $banner['starts_on']) {
            return ['scheduled', __('Starts :date', ['date' => \Illuminate\Support\Carbon::parse($banner['starts_on'])->translatedFormat('j M')])];
        }
        if (! empty($banner['ends_on']) && $today > $banner['ends_on']) {
            return ['ended', __('Ended')];
        }

        return ['on', __('Showing')];
    };

    // The first surface and language with a mistake open first, so the message is in view.
    $firstError = collect($errors->keys())->first(fn ($key) => str_starts_with($key, 'banners.'));
    $openSurface = $firstError ? explode('.', $firstError)[1] ?? 'website' : 'website';
@endphp
<section id="banners" class="studio-panel" aria-labelledby="banners-title">
    <header class="studio-panel-head">
        <h2 id="banners-title">{{ __('Welcome banners') }}</h2>
        <p>{{ __('A short message for each place, in each language. English is needed; a language without words of its own shows the English ones.') }}</p>
    </header>

    <div class="tabs surface-tabs" role="tablist" aria-label="{{ __('Where the banner shows') }}" data-tabs="surface">
        @foreach ($surfaces as $surface => [$label, $icon])
            @php [$state, $stateLabel] = $stateOf($banners[$surface] ?? []); @endphp
            <button type="button" role="tab" id="tab-{{ $surface }}" aria-controls="banner-{{ $surface }}" aria-selected="{{ $surface === $openSurface ? 'true' : 'false' }}" data-tab="{{ $surface }}">
                <x-icon :name="$icon" :size="16" />
                <span>{{ $label }}</span>
                <span class="banner-state" data-state="{{ $state }}" data-banner-state="{{ $surface }}">{{ $stateLabel }}</span>
            </button>
        @endforeach
    </div>

    @foreach ($surfaces as $surface => [$label, $icon, $where])
        @php
            $banner = $banners[$surface] ?? [];
            $key = "banners.{$surface}";
            $enabled = (bool) old("{$key}.enabled", $banner['enabled'] ?? false);
            $dismissible = (bool) old("{$key}.dismissible", $banner['dismissible'] ?? true);
            $withPicture = (bool) old("{$key}.image", $banner['image'] ?? false);
            $tone = (string) old("{$key}.tone", $banner['tone'] ?? 'gold');
            $errorLanguage = collect($errors->keys())->first(fn ($k) => str_starts_with($k, "{$key}.text."));
            $openLanguage = $errorLanguage ? explode('.', $errorLanguage)[3] ?? 'en' : 'en';
        @endphp
        <div class="banner-editor" id="banner-{{ $surface }}" role="tabpanel" aria-labelledby="tab-{{ $surface }}" data-panel="{{ $surface }}" data-banner-editor="{{ $surface }}">
            <div class="banner-top">
                <p class="field-hint">{{ $where }}</p>
                <label class="switch">
                    <input type="hidden" name="{{ "banners[{$surface}][enabled]" }}" value="0">
                    <input type="checkbox" role="switch" name="{{ "banners[{$surface}][enabled]" }}" value="1" @checked($enabled) data-b="enabled">
                    <span class="switch-track" aria-hidden="true"><span class="switch-thumb"></span></span>
                    <span>{{ __('Show this banner') }}</span>
                </label>
            </div>

            <div class="tabs lang-tabs" role="tablist" aria-label="{{ __('Language') }}" data-tabs="lang-{{ $surface }}">
                @foreach ($languages as $code => $native)
                    <button type="button" role="tab" id="tab-{{ $surface }}-{{ $code }}" aria-controls="words-{{ $surface }}-{{ $code }}" aria-selected="{{ $code === $openLanguage ? 'true' : 'false' }}" data-tab="{{ $code }}" lang="{{ $code }}">
                        {{ $native }}@if ($code === 'en')<span class="lang-needed" aria-label="{{ __('needed') }}">*</span>@endif
                    </button>
                @endforeach
            </div>

            @foreach ($languages as $code => $native)
                @php $dir = \App\Support\Locale::direction($code); @endphp
                <div class="banner-words" id="words-{{ $surface }}-{{ $code }}" role="tabpanel" aria-labelledby="tab-{{ $surface }}-{{ $code }}" data-panel="{{ $code }}" data-lang="{{ $code }}">
                    @foreach (['title' => __('Title'), 'message' => __('Message'), 'cta_label' => __('Button words')] as $field => $fieldLabel)
                        @php
                            $name = "{$key}.text.{$code}.{$field}";
                            $id = "b-{$surface}-{$code}-{$field}";
                            $value = (string) old($name, $banner['text'][$code][$field] ?? '');
                        @endphp
                        <div class="field">
                            <label for="{{ $id }}">{{ $fieldLabel }}@if ($code !== 'en') <span class="field-optional">{{ __('optional') }}</span>@endif</label>
                            @if ($field === 'message')
                                <textarea id="{{ $id }}" name="{{ "banners[{$surface}][text][{$code}][{$field}]" }}" rows="2" maxlength="{{ $limits[$field] }}" lang="{{ $code }}" dir="{{ $dir }}" @error($name) aria-invalid="true" @enderror data-b="{{ $field }}" data-lang="{{ $code }}">{{ $value }}</textarea>
                            @else
                                <input id="{{ $id }}" type="text" name="{{ "banners[{$surface}][text][{$code}][{$field}]" }}" value="{{ $value }}" maxlength="{{ $limits[$field] }}" lang="{{ $code }}" dir="{{ $dir }}" autocomplete="off" @error($name) aria-invalid="true" @enderror data-b="{{ $field }}" data-lang="{{ $code }}">
                            @endif
                            <p class="field-count" dir="ltr" aria-hidden="true" data-count>{{ mb_strlen($value) }}/{{ $limits[$field] }}</p>
                            @error($name)<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            @endforeach

            <div class="banner-grid">
                <div class="field banner-link">
                    @php $linkName = "{$key}.cta_url"; @endphp
                    <label for="b-{{ $surface }}-link">{{ __('Button link') }}</label>
                    <input id="b-{{ $surface }}-link" type="text" name="{{ "banners[{$surface}][cta_url]" }}" value="{{ old($linkName, $banner['cta_url'] ?? '') }}" placeholder="/pricing" maxlength="300" dir="ltr" autocomplete="off" inputmode="url" @error($linkName) aria-invalid="true" @enderror data-b="cta_url">
                    <p class="field-hint">{{ __('A page of this site, like /pricing, or a secure address. Leave it empty for no button.') }}</p>
                    @error($linkName)<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <fieldset class="field tone-field">
                    <legend class="field-label">{{ __('Tone') }}</legend>
                    <div class="tones">
                        @foreach ($tones as $value => $toneLabel)
                            <label class="tone" data-tone="{{ $value }}">
                                <input type="radio" name="{{ "banners[{$surface}][tone]" }}" value="{{ $value }}" @checked($tone === $value) data-b="tone">
                                <span class="tone-sample" aria-hidden="true"></span>
                                <span>{{ $toneLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error("{$key}.tone")<p class="field-error">{{ $message }}</p>@enderror
                </fieldset>

                @unless ($eventMode ?? false)
                <div class="field">
                    <label for="b-{{ $surface }}-starts">{{ __('First day') }} <span class="field-optional">{{ __('optional') }}</span></label>
                    <input id="b-{{ $surface }}-starts" type="date" name="{{ "banners[{$surface}][starts_on]" }}" value="{{ old("{$key}.starts_on", $banner['starts_on'] ?? '') }}" @error("{$key}.starts_on") aria-invalid="true" @enderror data-b="starts_on">
                    @error("{$key}.starts_on")<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="b-{{ $surface }}-ends">{{ __('Last day') }} <span class="field-optional">{{ __('optional') }}</span></label>
                    <input id="b-{{ $surface }}-ends" type="date" name="{{ "banners[{$surface}][ends_on]" }}" value="{{ old("{$key}.ends_on", $banner['ends_on'] ?? '') }}" @error("{$key}.ends_on") aria-invalid="true" @enderror data-b="ends_on">
                    @error("{$key}.ends_on")<p class="field-error">{{ $message }}</p>@enderror
                </div>
                @endunless

                <div class="banner-options">
                    <label class="check">
                        <input type="hidden" name="{{ "banners[{$surface}][dismissible]" }}" value="0">
                        <input type="checkbox" name="{{ "banners[{$surface}][dismissible]" }}" value="1" @checked($dismissible) data-b="dismissible">
                        <span>{{ __('Visitors can close it') }}</span>
                    </label>
                    <label class="check">
                        <input type="hidden" name="{{ "banners[{$surface}][image]" }}" value="0">
                        <input type="checkbox" name="{{ "banners[{$surface}][image]" }}" value="1" @checked($withPicture) data-b="image">
                        <span>{{ __('Show the banner picture') }} <span class="field-hint">{{ __('(chosen under Pictures)') }}</span></span>
                    </label>
                </div>
            </div>
            @error($key.'.text')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    @endforeach
</section>
