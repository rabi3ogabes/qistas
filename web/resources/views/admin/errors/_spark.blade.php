{{-- A row of bars, one per day, oldest first, drawn as inline SVG (no script, nothing to load). $counts is a list of whole
     numbers; $label is what a screen reader hears instead of the picture. Mirrored in right-to-left languages by CSS, so
     "oldest first" still starts at the start of the line. --}}
@php
    $top = max(1, ...$counts);
    $bars = count($counts);
    $slot = 8;
    $height = 20;
@endphp
<svg class="err-spark" viewBox="0 0 {{ $bars * $slot - 2 }} {{ $height }}" role="img" aria-label="{{ $label }}" focusable="false">
    @foreach ($counts as $i => $count)
        @php $tall = $count > 0 ? max(2, (int) round($count / $top * $height)) : 1; @endphp
        <rect x="{{ $i * $slot }}" y="{{ $height - $tall }}" width="6" height="{{ $tall }}" rx="1" @if ($count === 0) fill-opacity=".3" @endif />
    @endforeach
</svg>
