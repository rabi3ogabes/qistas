@php
    use App\Theme\Color;

    // Names a person understands, for the colours the contrast repair can move.
    $tokenNames = [
        'ink' => __('Text'), 'inkMuted' => __('Quiet text'), 'onPrimary' => __('Text on the main colour'),
        'onAction' => __('Button text'), 'onAccent' => __('Text on the accent'), 'accentText' => __('Accent text'),
        'info' => __('Interactive colour'), 'onInfo' => __('Text on the interactive colour'), 'positive' => __('Paid'),
        'warning' => __('Due'), 'danger' => __('Overdue'), 'logoInk' => __('Logo'),
    ];
    $modeNames = ['light' => __('Light mode'), 'dark' => __('Dark mode')];
    $repaired = session('repaired');

    // What the page's script says, in the language of the page.
    $text = [
        'uploading' => __('Uploading…'), 'uploaded' => __('Saved in the draft.'), 'tooBig' => __('That picture is larger than 2 MB.'),
        'wrongType' => __('Choose a PNG or JPEG picture.'), 'uploadFailed' => __('The picture could not be uploaded. Try again.'),
        'replace' => __('Replace'), 'choose' => __('Choose a picture'), 'removed' => __('Removed when you save.'),
        'readable' => __('Readable'), 'adjusted' => __('Adjusted when published'), 'notHex' => __('Use a colour written like #0B1F44.'),
        'allReadable' => __('Every text is readable in light and dark mode.'),
        'willAdjust' => __('When you publish, these colours will be adjusted so every text stays readable:'),
        'becomes' => __(':from becomes :to'), 'tokens' => $tokenNames, 'modes' => $modeNames,
        'unsaved' => __('Unsaved changes. Save the draft or publish.'), 'draft' => __('You have changes that are not published yet.'),
        'showPreview' => __('Show'), 'hidePreview' => __('Hide'),
        'asEvent' => __('A visitor from :country on :date sees “:event”.'), 'asUsual' => __('A visitor from :country on :date sees the usual look.'),
        'anywhere' => __('anywhere else'),
        'showing' => __('Showing'), 'off' => __('Off'), 'starts' => __('Starts :date'), 'ended' => __('Ended'),
        'cancel' => __('Cancel'),
        'discardTitle' => __('Throw away unpublished changes?'), 'discardBody' => __('The draft goes back to what everyone sees now. Pictures you uploaded stay in history.'), 'discard' => __('Discard'),
        'restoreTitle' => __('Publish version :version again?'), 'restoreBody' => __('Everyone sees it at once. It becomes a new version, so nothing in the history is lost.'), 'restore' => __('Restore'),
    ];
@endphp
<x-layouts.admin :title="__('Appearance')" section="appearance">
    <x-page-head :title="__('Appearance')">
        <x-slot:subtitle>{{ __('The colours, pictures and welcome banners of the website, the web app and the Android app. Change the draft, see it in the preview, then publish.') }}</x-slot:subtitle>

        <p class="studio-live">
            @if ($liveVersion)
                <span class="studio-live-dot" aria-hidden="true"></span>
                {{ __('Live: version :version', ['version' => $liveVersion->version]) }}
                <time datetime="{{ $liveVersion->published_at?->toIso8601String() }}">{{ $liveVersion->published_at?->translatedFormat('j M Y') }}</time>
            @else
                {{ __('Live: the Qistas look') }}
            @endif
        </p>
    </x-page-head>

    @unless ($canChange)
        <x-alert type="info" :message="__('Only a super admin can change the look. You can see how it is set.')" />
    @endunless
    @error('publish')<x-alert type="error" :message="$message" />@enderror
    @if ($errors->any() && ! $errors->has('publish'))
        <x-alert type="error" :message="__('Some fields need attention. Nothing was saved.')" />
    @endif
    @if (is_array($repaired) && $repaired !== [])
        <div class="alert alert-info studio-repaired" role="status">
            <p>{{ __('To keep every text readable, these colours were adjusted:') }}</p>
            <ul>
                @foreach ($repaired as $move)
                    <li>
                        <span>{{ $tokenNames[$move['token']] ?? $move['token'] }} · {{ $modeNames[$move['mode']] ?? $move['mode'] }}</span>
                        <span class="studio-move"><x-swatch :hex="$move['from']" /> {{ $move['from'] }} <x-icon name="arrowRight" :size="14" class="flip-rtl" /> <x-swatch :hex="$move['to']" /> {{ $move['to'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="studio" data-studio
         data-text='@json($text)'
         data-palette='@json($report['tokens'])'
         data-palette-url="{{ route('admin.appearance.palette') }}"
         data-picture-url="{{ route('admin.appearance.picture', ['slot' => '__slot__']) }}"
         data-today="{{ now()->toDateString() }}"
         data-can-change="{{ $canChange ? 'yes' : 'no' }}">

        <div class="studio-editor">
            <nav class="studio-jump" aria-label="{{ __('Sections') }}">
                <a href="#colours"><x-icon name="palette" :size="16" /> {{ __('Colours') }}</a>
                <a href="#pictures"><x-icon name="image" :size="16" /> {{ __('Pictures') }}</a>
                <a href="#banners"><x-icon name="megaphone" :size="16" /> {{ __('Welcome banners') }}</a>
                <a href="#events"><x-icon name="calendar" :size="16" /> {{ __('Events') }}</a>
                <a href="#history"><x-icon name="history" :size="16" /> {{ __('History') }}</a>
            </nav>

            <form id="studio-form" method="post" action="{{ route('admin.appearance.draft') }}" enctype="multipart/form-data" novalidate data-studio-form>
                @csrf
                {{-- Pressing Enter in a field saves the draft: this is the form's first submit button, so it is the default. --}}
                <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Save draft') }}</button>

                <fieldset class="studio-fields" @disabled(! $canChange)>
                    <legend class="sr-only">{{ __('Appearance') }}</legend>
                    @include('admin.appearance.colours')
                    @include('admin.appearance.pictures')
                    @include('admin.appearance.banners')
                </fieldset>

                <div class="studio-bar" data-studio-bar>
                    <p class="studio-state" data-state="{{ $unpublished ? 'draft' : 'live' }}" aria-live="polite" data-publish-state>
                        <span class="studio-state-dot" aria-hidden="true"></span>
                        <span data-state-text>{{ $unpublished ? __('You have changes that are not published yet.') : __('Everything is published.') }}</span>
                    </p>
                    @if ($canChange)
                        <div class="studio-actions">
                            <label class="sr-only" for="publish-note">{{ __('What changed? (optional)') }}</label>
                            <input id="publish-note" class="studio-note" type="text" name="note" maxlength="200" value="{{ old('note') }}" placeholder="{{ __('What changed? (optional)') }}" autocomplete="off">
                            @if ($unpublished)
                                <button type="submit" form="studio-discard" class="btn btn-ghost btn-sm" data-discard>{{ __('Discard') }}</button>
                            @endif
                            <button type="submit" class="btn btn-quiet btn-sm" data-save>{{ __('Save draft') }}</button>
                            <button type="submit" class="btn btn-sm studio-publish" formaction="{{ route('admin.appearance.publish') }}" data-publish>{{ __('Publish') }}</button>
                        </div>
                    @endif
                </div>
            </form>

            @if ($canChange)
                <form id="studio-discard" method="post" action="{{ route('admin.appearance.discard') }}" hidden>@csrf</form>
            @endif

            @include('admin.appearance.events')
            @include('admin.appearance.history')
        </div>

        @include('admin.appearance.preview')
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
