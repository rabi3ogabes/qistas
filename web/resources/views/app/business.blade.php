{{-- Who the documents come from, and how they look by default (Win Plan PP8). Owners and managers edit; everyone else reads. --}}
@php
    use App\Documents\DocumentPreferences;

    $f = $profile->fields;
    $details = array_filter([
        $f['phone'],
        $f['address'],
        $f['cr_number'] ? __('CR :number', ['number' => $f['cr_number']]) : null,
        $f['vat_number'] ? __('VAT :number', ['number' => $f['vat_number']]) : null,
    ]);
    $sectionLabels = [
        'cost' => [__('What it cost you'), __('Only shown to people who see the business’s money, and only when asked.')],
        'overdue' => [__('Overdue column'), __('What is late today, next to what is owed.')],
        'schedule' => [__('Schedule'), __('Every instalment of a contract, with what was paid.')],
        'signature' => [__('Signature'), __('Your signature at the end of each document.')],
    ];
    $wordLabels = ['customer' => __('Customer'), 'contract' => __('Contract'), 'investor' => __('Investor'), 'instalment' => __('Instalment')];
@endphp
<x-layouts.app :title="__('Business profile')" section="business">
    <x-page-head :title="__('Business profile')">
        <x-slot:subtitle>{{ __('Who your statements and receipts come from, and how they look.') }}</x-slot:subtitle>
    </x-page-head>

    @unless ($canEdit)
        <x-alert type="info" :message="__('Only an owner or a manager can change these. You can see how they are set.')" />
    @endunless

    <div class="business-grid">
        <div class="stack">
            <section class="card card-pad" aria-labelledby="who-title">
                <h2 id="who-title" class="card-title">{{ __('Your business') }}</h2>
                @if ($canEdit)
                    <form method="POST" action="{{ route('app.settings.business.update') }}" class="form form-grid" novalidate>
                        @csrf
                        @method('PUT')
                        <x-field name="name_ar" :label="__('Name in Arabic')" :value="$f['name_ar']" dir="rtl" lang="ar" maxlength="120" autocomplete="organization" />
                        <x-field name="name_en" :label="__('Name in English')" :value="$f['name_en']" dir="ltr" lang="en" maxlength="120" autocomplete="organization" />
                        <x-field name="phone" type="tel" :label="__('Phone')" :value="$f['phone']" inputmode="tel" dir="ltr" maxlength="40" />
                        <x-field name="cr_number" :label="__('Commercial registration (CR)')" :value="$f['cr_number']" dir="ltr" maxlength="40" />
                        <x-field wide name="address" :label="__('Address')" :value="$f['address']" maxlength="255" />
                        <x-field name="vat_number" :label="__('VAT number')" :value="$f['vat_number']" dir="ltr" maxlength="40" />
                        <x-field wide name="footer" :label="__('Footer line')" :value="$f['footer']" maxlength="255"
                                 :hint="$branded ? __('Shown at the foot of every page, e.g. Thank you for your trust.') : __('Shown at the foot of every page with Pro. On Free the footer says the document was made with Qistas.')" />
                        <div class="form-actions field-wide"><button class="btn btn-gold" type="submit">{{ __('Save') }}</button></div>
                    </form>
                @else
                    <dl class="facts">
                        @foreach (['name_ar' => __('Name in Arabic'), 'name_en' => __('Name in English'), 'phone' => __('Phone'), 'address' => __('Address'), 'cr_number' => __('Commercial registration (CR)'), 'vat_number' => __('VAT number'), 'footer' => __('Footer line')] as $key => $label)
                            <div><dt>{{ $label }}</dt><dd>{{ $f[$key] ?? '—' }}</dd></div>
                        @endforeach
                    </dl>
                @endif
            </section>

            <section class="card card-pad" aria-labelledby="logo-title">
                <h2 id="logo-title" class="card-title">{{ __('Logo') }}</h2>
                <p class="field-hint">{{ $branded ? __('Printed at the top of every document.') : __('Kept now and printed at the top of every document with Pro.') }}</p>
                <div class="asset-row">
                    <div class="asset-frame" data-empty="{{ $logo ? 'false' : 'true' }}">
                        @if ($logo)<img src="{{ $logo }}" alt="{{ __('Your logo') }}">@else<span>{{ __('No logo yet') }}</span>@endif
                    </div>
                    @if ($canEdit)
                        <div class="stack">
                            <form method="POST" action="{{ route('app.settings.business.assets.store', 'logo') }}" enctype="multipart/form-data" class="asset-form">
                                @csrf
                                <label class="btn btn-quiet" for="logo-file">{{ $logo ? __('Replace the logo') : __('Choose a logo') }}</label>
                                <input id="logo-file" class="sr-only" type="file" name="file" accept="image/png,image/jpeg" data-autosubmit>
                                <noscript><button class="btn" type="submit">{{ __('Upload') }}</button></noscript>
                            </form>
                            @if ($logo)
                                <form method="POST" action="{{ route('app.settings.business.assets.destroy', 'logo') }}">@csrf @method('DELETE')<button class="btn btn-ghost" type="submit">{{ __('Remove') }}</button></form>
                            @endif
                            <p class="field-hint">{{ __('PNG or JPEG, up to 2 MB. A wide logo on a plain background prints best.') }}</p>
                        </div>
                    @endif
                </div>
                @error('file')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </section>

            <section class="card card-pad" aria-labelledby="signature-title">
                <h2 id="signature-title" class="card-title">{{ __('Signature') }}</h2>
                @if ($signature)
                    <div class="asset-frame asset-frame-wide"><img src="{{ $signature }}" alt="{{ __('Your signature') }}"></div>
                @endif
                @if ($canEdit)
                    <form method="POST" action="{{ route('app.settings.business.assets.store', 'signature') }}" class="signature-pad" data-signature-pad>
                        @csrf
                        <input type="hidden" name="drawing" value="">
                        <canvas aria-label="{{ __('Sign here with your finger, a pen or the mouse') }}" role="img"></canvas>
                        <p class="field-hint">{{ __('Sign here with your finger, a pen or the mouse.') }}</p>
                        <div class="form-actions">
                            <button class="btn btn-gold" type="submit" data-signature-save disabled>{{ $signature ? __('Replace the signature') : __('Save the signature') }}</button>
                            <button class="btn btn-ghost" type="button" data-signature-clear>{{ __('Start again') }}</button>
                        </div>
                    </form>
                    @if ($signature)
                        <form method="POST" action="{{ route('app.settings.business.assets.destroy', 'signature') }}">@csrf @method('DELETE')<button class="btn btn-ghost" type="submit">{{ __('Remove the signature') }}</button></form>
                    @endif
                @elseif (! $signature)
                    <p class="field-hint">{{ __('No signature yet.') }}</p>
                @endif
            </section>

            <section class="card card-pad" aria-labelledby="choices-title">
                <h2 id="choices-title" class="card-title">{{ __('How documents look') }}</h2>
                <p class="field-hint">{{ __('Every statement, report and receipt starts from these. You can change them for one document when you make it.') }}</p>
                <form method="POST" action="{{ route('app.settings.documents.update') }}" class="form form-grid" novalidate>
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label for="f-paper">{{ __('Paper') }}</label>
                        <select id="f-paper" name="paper" @disabled(! $canEdit)>
                            <option value="a4" @selected($preferences->paper === 'a4')>A4</option>
                            <option value="a5" @selected($preferences->paper === 'a5')>A5</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="f-text">{{ __('Text size') }}</label>
                        <select id="f-text" name="text" @disabled(! $canEdit)>
                            <option value="small" @selected($preferences->text === 'small')>{{ __('Small') }}</option>
                            <option value="normal" @selected($preferences->text === 'normal')>{{ __('Normal') }}</option>
                            <option value="large" @selected($preferences->text === 'large')>{{ __('Large') }}</option>
                        </select>
                    </div>
                    <fieldset class="field field-wide">
                        <legend>{{ __('Sections') }}</legend>
                        @foreach ($sectionLabels as $section => [$label, $hint])
                            <input type="hidden" name="sections[{{ $section }}]" value="0">
                            <label class="check">
                                <input type="checkbox" name="sections[{{ $section }}]" value="1" @checked($preferences->sections[$section]) @disabled(! $canEdit)>
                                <span>{{ $label }} <span class="field-hint">{{ $hint }}</span></span>
                            </label>
                        @endforeach
                    </fieldset>
                    <fieldset class="field field-wide">
                        <legend>{{ __('Your own words') }}</legend>
                        <p class="field-hint">{{ __('Call things what you call them, for example Partner instead of Investor. Leave a box empty to keep the usual word.') }}</p>
                        <div class="form-grid">
                            @foreach ($wordLabels as $term => $label)
                                <x-field :name="'wording['.$term.']'" :id="'f-word-'.$term" :label="$label" :value="$preferences->wording[$term] ?? ''" :placeholder="$label" maxlength="{{ DocumentPreferences::WORD_LENGTH }}" :disabled="! $canEdit" />
                            @endforeach
                        </div>
                    </fieldset>
                    @if ($canEdit)
                        <div class="form-actions field-wide"><button class="btn btn-gold" type="submit">{{ __('Save') }}</button></div>
                    @endif
                </form>
            </section>
        </div>

        {{-- How the top of a document will look with what is saved now. --}}
        <aside class="letterhead-preview" aria-label="{{ __('How the top of your documents looks') }}">
            <p class="field-hint">{{ __('The top of your documents') }}</p>
            <div class="letterhead">
                <div class="letterhead-head">
                    <div>
                        @if ($branded && $logo)
                            <img src="{{ $logo }}" alt="" class="letterhead-logo">
                        @else
                            <strong class="letterhead-name">{{ $profile->name(app()->getLocale()) }}</strong>
                        @endif
                        @if ($details !== [])<p class="letterhead-details">{{ implode('  |  ', $details) }}</p>@endif
                    </div>
                    <span class="letterhead-meta">{{ __('Statement') }}</span>
                </div>
                <p class="letterhead-title">{{ __('Statement of account') }}</p>
                <div class="letterhead-lines" aria-hidden="true"><span></span><span></span><span></span></div>
                <p class="letterhead-foot">{{ $branded ? ($f['footer'] ?? '') : __('Made with Qistas') }}</p>
            </div>
        </aside>
    </div>
</x-layouts.app>
