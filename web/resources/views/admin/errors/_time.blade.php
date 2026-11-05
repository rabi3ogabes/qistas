{{-- A moment as a time element: the short date and time as text, the exact UTC time as the tooltip and as the machine-readable
     value, and, when $ago is set, how long ago it was in the language of the page (the request sets Carbon's language). --}}
@php($ago = $ago ?? false)
<time datetime="{{ $moment->toIso8601String() }}" title="{{ $moment->copy()->utc()->format('Y-m-d H:i') }} UTC">{{ $moment->translatedFormat('j M Y, H:i') }}</time>@if ($ago)<span class="cell-sub">{{ $moment->diffForHumans() }}</span>@endif
