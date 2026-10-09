{{--
    The look this visitor sees, in a page's <head>, after the site's own styles: the published look, with today's event
    when one is meant for them. The phone's status bar takes the canvas colour, and when there are chosen colours the
    stylesheet of colour variables is linked by an address that names the version and the event, so a new look is a new
    address. Never fails a page: if the look cannot be read, the factory look is used.
--}}
@php
    $look = rescue(fn () => app(\App\Theme\Appearance::class)->current(request()), null, false);
    $tokens = $look?->tokens() ?? \App\Theme\ThemeEngine::BASE;
@endphp
<meta name="theme-color" media="(prefers-color-scheme: light)" content="{{ $tokens['light']['bg'] }}">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ $tokens['dark']['bg'] }}">
@if ($look?->hasCustomColours())
    <link rel="stylesheet" href="{{ route('theme.css', $look->cssQuery()) }}">
@endif
