{{-- The logo inside the preview: the draft's picture when there is one, otherwise the drawn mark in the preview's colours. --}}
<span class="sp-logo" data-sp-logo>
    <img src="{{ $logo ?? '' }}" alt="" @unless ($logo) hidden @endunless data-sp-logo-img>
    <span @if ($logo) hidden @endif data-sp-logo-drawn><x-logo :height="20" :brand="false" /></span>
</span>
