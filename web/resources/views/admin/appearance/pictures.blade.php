@php
    use App\Theme\BrandImages;

    $slots = [
        'logo' => [__('Logo'), __('Shown on light backgrounds. A PNG with a transparent background looks best.'), __('The drawn Qistas logo is used.')],
        'logo_dark' => [__('Logo for dark backgrounds'), __('Used in dark mode and on dark panels. Optional.'), __('The logo above is used.')],
        'hero' => [__('Website hero picture'), __('Behind the headline of the home page, under a veil of your main colour.'), __('The plain colour gradient is used.')],
        'banner' => [__('Banner picture'), __('A small picture beside a welcome banner that asks for it.'), __('No picture.')],
    ];
@endphp
<section id="pictures" class="studio-panel" aria-labelledby="pictures-title">
    <header class="studio-panel-head">
        <h2 id="pictures-title">{{ __('Pictures') }}</h2>
        <p>{{ __('PNG or JPEG, up to 2 MB. Every picture is cleaned of hidden data and resized before it is used.') }}</p>
    </header>

    <div class="pic-grid">
        @foreach ($slots as $slot => [$label, $hint, $empty])
            @php
                $picture = $pictures[$slot] ?? null;
                $url = $picture ? route('brand.asset', ['asset' => $picture->id]) : null;
                [$maxWidth, $maxHeight] = BrandImages::SLOTS[$slot];
                $invalid = $errors->has("pictures.{$slot}");
            @endphp
            <div class="pic" data-pic="{{ $slot }}" data-has="{{ $url ? 'yes' : 'no' }}">
                <div class="pic-frame" data-frame="{{ $slot }}">
                    <img src="{{ $url ?? '' }}" alt="{{ $label }}" @if ($picture) width="{{ $picture->width }}" height="{{ $picture->height }}" @endif @unless ($url) hidden @endunless data-pic-img>
                    <span class="pic-empty" @if ($url) hidden @endif data-pic-empty><x-icon name="image" :size="20" /> {{ $empty }}</span>
                </div>
                <div class="pic-body">
                    <p class="pic-title">{{ $label }}</p>
                    <p class="field-hint">{{ $hint }}</p>
                    <p class="pic-meta" dir="ltr" data-pic-meta>{{ $picture ? $picture->width.' × '.$picture->height : __('Up to :width × :height', ['width' => $maxWidth, 'height' => $maxHeight]) }}</p>
                    <div class="pic-actions">
                        <label class="btn btn-quiet btn-sm pic-choose">
                            <x-icon name="upload" :size="16" />
                            <span data-pic-choose-label>{{ $url ? __('Replace') : __('Choose a picture') }}</span>
                            <input class="sr-only" type="file" name="pictures[{{ $slot }}]" accept="image/png,image/jpeg" @if ($invalid) aria-invalid="true" @endif data-pic-input>
                        </label>
                        <label class="check pic-remove" @unless ($url) hidden @endunless data-pic-remove-wrap>
                            <input type="checkbox" name="remove[{{ $slot }}]" value="1" @checked(old("remove.{$slot}")) data-pic-remove>
                            <span>{{ __('Remove') }}</span>
                        </label>
                    </div>
                    <p class="pic-status" data-pic-status aria-live="polite"></p>
                    <p class="field-error" data-pic-error @unless ($invalid) hidden @endunless>{{ $errors->first("pictures.{$slot}") }}</p>
                </div>
            </div>
        @endforeach
    </div>
</section>
