@props(['variant' => 'lockup', 'height' => 32, 'brand' => true, 'onDark' => false])
@php
    // The logo chosen in the admin's Appearance page, when there is one (and its version for dark backgrounds);
    // otherwise the drawn Qistas logo. The admin console passes brand=false and always shows the Qistas logo. On a dark
    // panel (on-dark) only the dark-background version is used when there is one. Never fails a page.
    $look = $brand ? rescue(fn () => app(\App\Theme\Appearance::class)->live(), null, false) : null;
    $picture = $look?->imageUrl('logo');
    $darkPicture = $picture ? $look?->imageUrl('logo_dark') : null;

    if ($onDark && $darkPicture) {
        [$picture, $darkPicture] = [$darkPicture, null];
    }

    // The lockup is 1107 x 205.5 and the symbol 160 x 226; the width follows the height.
    $ratio = $variant === 'symbol' ? 160 / 226 : 1107.07 / 205.5;
@endphp
@if ($picture)
    <img {{ $attributes->class(['q-logo q-logo-img', 'q-logo-light' => $darkPicture !== null]) }} src="{{ $picture }}" alt="{{ config('qistas.app_name') }}" height="{{ $height }}" decoding="async">
    @if ($darkPicture)
        <img {{ $attributes->class('q-logo q-logo-img q-logo-dark') }} src="{{ $darkPicture }}" alt="" aria-hidden="true" height="{{ $height }}" decoding="async">
    @endif
@else
    <svg {{ $attributes->class('q-logo') }} width="{{ round($height * $ratio) }}" height="{{ $height }}" role="img" aria-label="{{ config('qistas.app_name') }}">
        <use href="#q-{{ $variant }}"/>
    </svg>
@endif
