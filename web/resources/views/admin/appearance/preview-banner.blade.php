{{-- A welcome banner in the preview, drawn with the same styles as the real one, so what the admin sees is what ships.
     A banner that is switched off still shows here, dimmed, so its words can be seen while they are written. --}}
<div class="welcome sp-banner" data-surface="{{ $surface }}" data-tone="{{ $b['tone'] }}" lang="en" dir="ltr" @unless ($b['enabled']) data-off @endunless @if ($b['title'] === '') hidden @endif data-sp-banner="{{ $surface }}">
    <div class="welcome-inner">
        <img class="welcome-pic" src="{{ $bannerPicture ?? '' }}" alt="" @unless ($b['image'] && $bannerPicture) hidden @endunless data-sp-banner-pic>
        <div class="welcome-words">
            <p class="welcome-title" data-sp-title>{{ $b['title'] }}</p>
            <p class="welcome-message" @if ($b['message'] === '') hidden @endif data-sp-message>{{ $b['message'] }}</p>
        </div>
        <span class="btn btn-sm welcome-cta" @unless ($b['label'] !== '' && $b['link'] !== '') hidden @endunless data-sp-cta>{{ $b['label'] }}</span>
        <span class="welcome-close" aria-hidden="true" @unless ($b['dismissible']) hidden @endunless data-sp-close><x-icon name="x" :size="16" /></span>
    </div>
</div>
