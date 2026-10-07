@props(['name', 'size' => 20, 'label' => null])
<svg {{ $attributes->class('i') }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif>{!! \App\Support\Icons::markup($name) !!}</svg>
