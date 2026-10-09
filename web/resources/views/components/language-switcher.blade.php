{{--
    The language button: a pill of its own (a globe and the language in its own script) that opens a short, calm list of
    the five languages. It is a control in its own right, never an item in an account menu. Every choice is a plain
    link, so it works without JavaScript; the small script only adds Escape, arrow keys and closing on an outside click.
--}}
@props(['placement' => 'down'])
@php
    $options = \App\Support\Locale::options();
    $current = app()->getLocale();
    $name = $options[$current] ?? $current;
@endphp
<details class="lang" data-lang-switcher data-placement="{{ $placement }}">
    <summary class="lang-btn" aria-label="{{ __('Language: :name', ['name' => $name]) }}">
        <x-icon name="globe" :size="18" class="lang-globe" />
        <span class="lang-name" lang="{{ $current }}">{{ $name }}</span>
        <span class="lang-code" aria-hidden="true">{{ strtoupper($current) }}</span>
        <x-icon name="chevronDown" :size="14" class="lang-chevron" />
    </summary>
    <div class="lang-panel">
        <p class="lang-title">{{ __('Choose your language') }}</p>
        <ul class="lang-list">
            @foreach ($options as $code => $label)
                <li>
                    <a class="lang-option" lang="{{ $code }}" hreflang="{{ $code }}" href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" @if ($code === $current) aria-current="true" @endif>
                        <span class="lang-option-name">{{ $label }}</span>
                        <span class="lang-option-code" aria-hidden="true">{{ strtoupper($code) }}</span>
                        @if ($code === $current)<x-icon name="check" :size="16" class="lang-check" />@endif
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</details>
