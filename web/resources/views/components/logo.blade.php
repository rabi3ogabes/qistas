@props(['variant' => 'lockup', 'height' => 32])
@php
    // The lockup is 1107 x 205.5 and the symbol 160 x 226; the width follows the height.
    $ratio = $variant === 'symbol' ? 160 / 226 : 1107.07 / 205.5;
@endphp
<svg {{ $attributes->class('q-logo') }} width="{{ round($height * $ratio) }}" height="{{ $height }}" role="img" aria-label="{{ config('qistas.app_name') }}">
    <use href="#q-{{ $variant }}"/>
</svg>
