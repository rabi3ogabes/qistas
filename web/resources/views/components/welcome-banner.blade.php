{{--
    The welcome banner the admin wrote for this place (website or webapp), in the visitor's language, between its dates.
    Its words are always shown as text. A banner the visitor closed stays closed on this device: the tiny script right
    after it reads that choice before the first paint, so it never flashes; closing is handled by resources/js/banner.js.
--}}
@props(['surface'])
@php
    $look = rescue(fn () => app(\App\Theme\Appearance::class)->current(request(), $surface), null, false);
    $banner = $look?->banner($surface, app()->getLocale());
    $external = $banner !== null && is_string($banner['cta_url']) && str_starts_with($banner['cta_url'], 'https://');
@endphp
@if ($banner)
<aside class="welcome" data-surface="{{ $surface }}" data-tone="{{ $banner['tone'] }}" data-welcome="{{ $banner['key'] }}" aria-label="{{ __('Announcement') }}">
    <div class="welcome-inner">
        @if ($banner['image_url'])
            <img class="welcome-pic" src="{{ $banner['image_url'] }}" alt="" loading="lazy" decoding="async">
        @endif
        <div class="welcome-words">
            <p class="welcome-title">{{ $banner['title'] }}</p>
            @if ($banner['message'] !== '')
                <p class="welcome-message">{{ $banner['message'] }}</p>
            @endif
        </div>
        @if ($banner['cta_url'])
            <a class="btn btn-sm welcome-cta" href="{{ $banner['cta_url'] }}" @if ($external) rel="noopener noreferrer" @endif>{{ $banner['cta_label'] }}</a>
        @endif
        @if ($banner['dismissible'])
            <button type="button" class="welcome-close" data-welcome-close aria-label="{{ __('Close') }}"><x-icon name="x" :size="18" /></button>
        @endif
    </div>
</aside>
<script @if ($nonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}" @endif>try{if(localStorage.getItem('q-welcome-{{ $banner['key'] }}')==='closed')document.currentScript.previousElementSibling.hidden=true}catch(e){}</script>
@endif
