@props(['variant' => 'lockup', 'height' => 32, 'brand' => true, 'onDark' => false])
@php
    // The logo chosen in the admin's Appearance page, when there is one (and its version for dark backgrounds);
    // otherwise the drawn Qistas logo. The admin console passes brand=false and always shows the Qistas logo. On a dark
    // panel (on-dark) only the dark-background version is used when there is one. Never fails a page.
    $look = $brand ? rescue(fn () => app(\App\Theme\Appearance::class)->live(), null, false) : null;

    // A picture with the width that keeps its proportions at this height (a reset's height:auto would otherwise win).
    $pictureOf = function (string $slot) use ($look, $height): ?array {
        $url = $look?->imageUrl($slot);
        $size = $look?->imageSize($slot);

        return $url === null ? null : ['url' => $url, 'width' => $size ? (int) round($height * $size[0] / max(1, $size[1])) : null];
    };
    $light = $pictureOf('logo');
    $dark = $light ? $pictureOf('logo_dark') : null;

    if ($onDark && $dark) {
        [$light, $dark] = [$dark, null];
    }

    // The lockup is 1107 x 205.5 and the symbol 160 x 226; the width follows the height.
    $ratio = $variant === 'symbol' ? 160 / 226 : 1107.07 / 205.5;
@endphp
@if ($light)
    <img {{ $attributes->class(['q-logo q-logo-img', 'q-logo-light' => $dark !== null]) }} src="{{ $light['url'] }}" alt="{{ config('qistas.app_name') }}"@if ($light['width']) width="{{ $light['width'] }}"@endif height="{{ $height }}" decoding="async">
    @if ($dark)
        <img {{ $attributes->class('q-logo q-logo-img q-logo-dark') }} src="{{ $dark['url'] }}" alt="" aria-hidden="true"@if ($dark['width']) width="{{ $dark['width'] }}"@endif height="{{ $height }}" decoding="async">
    @endif
@else
    <svg {{ $attributes->class('q-logo') }} width="{{ round($height * $ratio) }}" height="{{ $height }}" role="img" aria-label="{{ config('qistas.app_name') }}">
        <use href="#q-{{ $variant }}"/>
    </svg>
@endif
