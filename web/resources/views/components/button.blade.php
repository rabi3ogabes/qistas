@props(['variant' => 'primary', 'type' => 'submit'])
<button type="{{ $type }}" {{ $attributes->class(['btn', 'btn-'.$variant]) }}>{{ $slot }}</button>
