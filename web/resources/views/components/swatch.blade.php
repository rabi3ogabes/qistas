{{-- A round sample of a colour. Drawn with SVG fill, so it needs no inline style. The colour is always a checked #RRGGBB. --}}
@props(['hex', 'size' => 14])
<svg {{ $attributes->class('swatch') }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="7.5" fill="{{ $hex }}"/></svg>
