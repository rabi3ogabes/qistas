@php
    use App\Support\Countries;
    use Illuminate\Support\Carbon;

    $tokenNames = [
        'ink' => __('Text'), 'inkMuted' => __('Quiet text'), 'onPrimary' => __('Text on the main colour'),
        'onAction' => __('Button text'), 'onAccent' => __('Text on the accent'), 'accentText' => __('Accent text'),
        'info' => __('Interactive colour'), 'onInfo' => __('Text on the interactive colour'), 'positive' => __('Paid'),
        'warning' => __('Due'), 'danger' => __('Overdue'), 'logoInk' => __('Logo'),
    ];
    $modeNames = ['light' => __('Light mode'), 'dark' => __('Dark mode')];
    $repaired = session('repaired');
    $state = $event?->stateAt(now()) ?? 'new';
    $stateLabel = match ($state) {
        'new' => __('New'), 'draft' => __('Draft'), 'live' => __('On now'), 'ended' => __('Ended'), default => __('Scheduled'),
    };
    $scheduled = $event?->status === 'scheduled';
    $title = $event?->name ?? __('New event');

    $countriesChosen = (array) old('countries', $form['countries'] ?? []);
    // A saved event or a ready-made one with no countries is for everyone; a blank new event asks.
    $everyone = (bool) old('everyone', ($form['countries'] ?? []) === [] && ($event !== null || ! empty($form['preset'])));
    $surfacesChosen = (array) old('surfaces', $form['surfaces'] ?? []);
    $gulf = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'];
    $orderedCountries = array_replace(array_flip(array_values(array_intersect($gulf, array_keys($countries)))), $countries);
    $placeNames = ['website' => [__('Website'), 'globe'], 'webapp' => [__('Web app'), 'monitor'], 'mobile' => [__('Android app'), 'smartphone']];
    $zoneOf = collect((array) config('qistas.timezones'))->all();

    $text = [
        'uploading' => __('Uploading…'), 'uploaded' => __('Added to the event. Save to keep it.'), 'tooBig' => __('That picture is larger than 2 MB.'),
        'wrongType' => __('Choose a PNG or JPEG picture.'), 'uploadFailed' => __('The picture could not be uploaded. Try again.'),
        'replace' => __('Replace'), 'choose' => __('Choose a picture'), 'removed' => __('Removed when you save.'),
        'readable' => __('Readable'), 'adjusted' => __('Adjusted when published'), 'notHex' => __('Use a colour written like #0B1F44.'),
        'allReadable' => __('Every text is readable in light and dark mode.'),
        'willAdjust' => __('When you schedule, these colours will be adjusted so every text stays readable:'),
        'becomes' => __(':from becomes :to'), 'tokens' => $tokenNames, 'modes' => $modeNames,
        'unsaved' => __('Unsaved changes. Save or schedule.'), 'draft' => __('Unsaved changes. Save or schedule.'),
        'showPreview' => __('Show'), 'hidePreview' => __('Hide'),
        'showing' => __('Showing'), 'off' => __('Off'), 'starts' => __('Starts :date'), 'ended' => __('Ended'),
        'cancel' => __('Cancel'),
        'oneDay' => __('1 day'), 'days' => __(':days days'),
        'summary' => __(':length, from midnight on :from to midnight after :to, :zone time.'),
        'everyone' => __('Everyone, in every country.'), 'nobody' => __('Choose at least one country, or Everyone.'),
        'zoneOf' => $zoneOf,
    ];
@endphp
<x-layouts.admin :title="$title" section="appearance">
    <x-page-head :title="$title">
        <x-slot:subtitle>{{ __('A look for a national day or a season, for the countries and the days you choose. Everyone else keeps the usual look.') }}</x-slot:subtitle>

        <span class="event-state event-state-head" data-state="{{ $state }}">{{ $stateLabel }}</span>
        <a class="btn btn-ghost btn-sm" href="{{ route('admin.appearance.index') }}#events"><x-icon name="chevronLeft" :size="16" class="flip-rtl" /> {{ __('All events') }}</a>
    </x-page-head>

    @unless ($canChange)
        <x-alert type="info" :message="__('Only a super admin can change the look. You can see how it is set.')" />
    @endunless
    @error('schedule')<x-alert type="error" :message="$message" />@enderror
    @if ($errors->any() && ! $errors->has('schedule'))
        <x-alert type="error" :message="__('Some fields need attention. Nothing was saved.')" />
    @endif
    @if (is_array($repaired) && $repaired !== [])
        <div class="alert alert-info studio-repaired" role="status">
            <p>{{ __('To keep every text readable, these colours were adjusted:') }}</p>
            <ul>
                @foreach ($repaired as $move)
                    <li>
                        <span>{{ $tokenNames[$move['token']] ?? $move['token'] }} · {{ $modeNames[$move['mode']] ?? $move['mode'] }}</span>
                        <span class="studio-move" dir="ltr"><x-swatch :hex="$move['from']" /> {{ $move['from'] }} <x-icon name="arrowRight" :size="14" /> <x-swatch :hex="$move['to']" /> {{ $move['to'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="studio" data-studio data-event-editor
         data-text='@json($text)'
         data-palette='@json($report['tokens'])'
         data-base-colours='@json($baseColours)'
         data-palette-url="{{ route('admin.appearance.palette') }}"
         data-picture-url="{{ route('admin.appearance.events.picture', ['slot' => '__slot__']) }}"
         data-today="{{ now('Asia/Riyadh')->toDateString() }}"
         data-can-change="{{ $canChange ? 'yes' : 'no' }}">

        <div class="studio-editor">
            <nav class="studio-jump" aria-label="{{ __('Sections') }}">
                <a href="#event-basics"><x-icon name="calendar" :size="16" /> {{ __('The event') }}</a>
                <a href="#colours"><x-icon name="palette" :size="16" /> {{ __('Colours') }}</a>
                <a href="#pictures"><x-icon name="image" :size="16" /> {{ __('Pictures') }}</a>
                <a href="#banners"><x-icon name="megaphone" :size="16" /> {{ __('Welcome banners') }}</a>
            </nav>

            <form id="studio-form" method="post" action="{{ $event ? route('admin.appearance.events.update', $event) : route('admin.appearance.events.store') }}" enctype="multipart/form-data" novalidate data-studio-form>
                @csrf
                @if ($event) @method('PUT') @endif
                <input type="hidden" name="preset" value="{{ $form['preset'] ?? '' }}">
                {{-- Pressing Enter in a field saves: this is the form's first submit button, so it is the default. --}}
                <button type="submit" name="action" value="save" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Save') }}</button>

                <fieldset class="studio-fields" @disabled(! $canChange)>
                    <legend class="sr-only">{{ $title }}</legend>

                    <section id="event-basics" class="studio-panel" aria-labelledby="event-basics-title">
                        <header class="studio-panel-head">
                            <h2 id="event-basics-title">{{ __('The event') }}</h2>
                            <p>{{ __('Its name, who sees it, when, and where.') }}</p>
                        </header>

                        <div class="event-fields">
                            <div class="field">
                                <label for="event-name">{{ __('Name') }}</label>
                                <input id="event-name" type="text" name="name" value="{{ old('name', $form['name'] ?? '') }}" maxlength="80" required autocomplete="off" @error('name') aria-invalid="true" @enderror>
                                <p class="field-hint">{{ __('For you and your team; visitors see the banner, not this name.') }}</p>
                                @error('name')<p class="field-error">{{ $message }}</p>@enderror
                            </div>

                            <fieldset class="field event-countries" data-countries>
                                <legend class="field-label">{{ __('Who sees it') }}</legend>
                                <label class="switch">
                                    <input type="checkbox" role="switch" name="everyone" value="1" @checked($everyone) data-everyone>
                                    <span class="switch-track" aria-hidden="true"><span class="switch-thumb"></span></span>
                                    <span>{{ __('Everyone, in every country') }}</span>
                                </label>
                                <div class="country-picker" data-country-picker @if ($everyone) hidden @endif>
                                    <label class="sr-only" for="country-filter">{{ __('Find a country') }}</label>
                                    <input id="country-filter" type="search" class="country-filter" placeholder="{{ __('Find a country') }}" autocomplete="off" data-country-filter>
                                    <div class="country-chips">
                                        @foreach ($orderedCountries as $code => $name)
                                            <label class="country-chip" data-country-name="{{ mb_strtolower($name.' '.$code) }}">
                                                <input type="checkbox" name="countries[]" value="{{ $code }}" @checked(in_array($code, $countriesChosen, true)) data-country>
                                                <span>{{ $name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <p class="field-hint">{{ __('A signed-in person counts as their business’s country; a visitor counts as the country they browse from.') }}</p>
                                </div>
                                @error('countries')<p class="field-error">{{ $message }}</p>@enderror
                                @error('countries.*')<p class="field-error">{{ $message }}</p>@enderror
                            </fieldset>

                            <div class="event-dates">
                                <div class="field">
                                    <label for="event-starts">{{ __('First day') }}</label>
                                    <input id="event-starts" type="date" name="starts_on" value="{{ old('starts_on', $form['starts_on'] ?? '') }}" required @error('starts_on') aria-invalid="true" @enderror data-starts>
                                    @error('starts_on')<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                <div class="field">
                                    <label for="event-ends">{{ __('Last day') }}</label>
                                    <input id="event-ends" type="date" name="ends_on" value="{{ old('ends_on', $form['ends_on'] ?? '') }}" required @error('ends_on') aria-invalid="true" @enderror data-ends>
                                    @error('ends_on')<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                <div class="event-lengths" role="group" aria-label="{{ __('How long') }}">
                                    @foreach ([1 => __('1 day'), 2 => __(':days days', ['days' => 2]), 3 => __(':days days', ['days' => 3]), 7 => __('A week')] as $days => $label)
                                        <button type="button" class="btn btn-quiet btn-sm" data-length="{{ $days }}">{{ $label }}</button>
                                    @endforeach
                                </div>
                                <div class="field event-zone">
                                    <label for="event-zone">{{ __('Time zone') }}</label>
                                    <select id="event-zone" name="timezone" @error('timezone') aria-invalid="true" @enderror data-zone>
                                        @foreach ($zones as $zone)
                                            <option value="{{ $zone }}" @selected(old('timezone', $form['timezone'] ?? 'Asia/Riyadh') === $zone)>{{ str_replace('_', ' ', $zone) }}</option>
                                        @endforeach
                                    </select>
                                    @error('timezone')<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                <p class="event-summary" data-event-summary aria-live="polite"></p>
                            </div>

                            <fieldset class="field event-places">
                                <legend class="field-label">{{ __('Where') }}</legend>
                                <div class="event-place-options">
                                    @foreach ($placeNames as $place => [$placeLabel, $icon])
                                        <label class="tone event-place">
                                            <input type="checkbox" name="surfaces[]" value="{{ $place }}" @checked(in_array($place, $surfacesChosen, true)) data-place>
                                            <x-icon :name="$icon" :size="16" />
                                            <span>{{ $placeLabel }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('surfaces')<p class="field-error">{{ $message }}</p>@enderror
                            </fieldset>
                        </div>
                    </section>

                    @include('admin.appearance.colours', ['eventMode' => true])
                    @include('admin.appearance.pictures', ['eventMode' => true])
                    @include('admin.appearance.banners', ['eventMode' => true])
                </fieldset>

                <div class="studio-bar" data-studio-bar>
                    <p class="studio-state" data-state="{{ $scheduled ? 'live' : 'draft' }}" aria-live="polite" data-publish-state>
                        <span class="studio-state-dot" aria-hidden="true"></span>
                        <span data-state-text>{{ match ($state) {
                            'new' => __('Not saved yet.'),
                            'draft' => __('A draft: nobody sees it.'),
                            'live' => __('On now: visitors in its countries see it.'),
                            'ended' => __('Ended: nobody sees it any more.'),
                            default => __('Scheduled: it shows on its days.'),
                        } }}</span>
                    </p>
                    @if ($canChange)
                        <div class="studio-actions">
                            @if ($event)
                                <button type="submit" form="event-delete" class="btn btn-ghost btn-sm" data-confirm="{{ __('Delete :event?', ['event' => $event->name]) }}" data-confirm-body="{{ __('It disappears at once for everyone and cannot be brought back.') }}" data-confirm-label="{{ __('Delete') }}">{{ __('Delete') }}</button>
                            @endif
                            @if ($scheduled)
                                <button type="submit" form="event-stop" class="btn btn-quiet btn-sm" data-confirm="{{ __('Stop :event?', ['event' => $event->name]) }}" data-confirm-body="{{ __('Nobody sees it from now on. It stays as a draft you can schedule again.') }}" data-confirm-label="{{ __('Stop') }}">{{ __('Stop') }}</button>
                                <button type="submit" name="action" value="save" class="btn btn-sm studio-publish" data-save>{{ __('Save changes') }}</button>
                            @else
                                <button type="submit" name="action" value="save" class="btn btn-quiet btn-sm" data-save>{{ __('Save draft') }}</button>
                                <button type="submit" name="action" value="schedule" class="btn btn-sm studio-publish" data-publish>{{ __('Schedule event') }}</button>
                            @endif
                        </div>
                    @endif
                </div>
            </form>

            @if ($event && $canChange)
                <form id="event-stop" method="post" action="{{ route('admin.appearance.events.stop', $event) }}" hidden>@csrf</form>
                <form id="event-delete" method="post" action="{{ route('admin.appearance.events.destroy', $event) }}" hidden>@csrf @method('DELETE')</form>
            @endif
        </div>

        @include('admin.appearance.preview', ['previewAs' => false])
    </div>

    <dialog class="studio-dialog" data-studio-dialog aria-labelledby="studio-dialog-title">
        <form method="dialog">
            <h2 id="studio-dialog-title" data-dialog-title></h2>
            <p data-dialog-body></p>
            <div class="studio-dialog-actions">
                <button class="btn btn-quiet btn-sm" value="cancel">{{ __('Cancel') }}</button>
                <button class="btn btn-sm" value="confirm" data-dialog-confirm></button>
            </div>
        </form>
    </dialog>
</x-layouts.admin>
