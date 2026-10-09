{{--
    The chosen look, in a page's <head>, after the site's own styles: the phone's status bar takes the canvas colour,
    and when the admin has chosen colours, the stylesheet of colour variables is linked (by its versioned address, so a
    new look is a new address). Never fails a page: if the look cannot be read, the factory look is used.
--}}
@php
    $look = rescue(fn () => app(\App\Theme\Appearance::class)->live(), null, false);
    $tokens = $look?->tokens() ?? \App\Theme\ThemeEngine::BASE;
@endphp
<meta name="theme-color" media="(prefers-color-scheme: light)" content="{{ $tokens['light']['bg'] }}">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ $tokens['dark']['bg'] }}">
@if ($look?->hasCustomColours())
    <link rel="stylesheet" href="{{ route('theme.css', ['v' => $look->version()]) }}">
@endif
