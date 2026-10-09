@php
    $labels = collect($board['groups'])->flatMap(fn ($group) => $group['features'])->pluck('label', 'key')->all();
    $summary = $board['summary'];
    $presets = [
        'dark_launch' => [__('Dark launch'), __('Switch every new feature off.')],
        'essentials' => [__('Essentials'), __('Only the essential features on, the rest off.')],
        'full' => [__('Full programme'), __('Switch every new feature on.')],
        'restore_previous' => [__('Restore previous'), __('Go back to how things were before the last preset.')],
    ];
    // What the page's script says to the admin, translated here so it follows the language of the page.
    $text = [
        'undo' => __('Undo'), 'cancel' => __('Cancel'), 'confirm' => __('Confirm'), 'reason' => __('Reason'),
        'saved' => __('Saved.'), 'failed' => __('That did not work. Nothing was changed.'),
        'reasonHint' => __('Give a reason (a few words are enough).'),
        'reduceTitle' => __('Reduce :feature?'), 'usedBy' => __('Workspaces that used it in the last 30 days: :count'),
        'stops' => __('These need it and will stop with it: :features'), 'offBehaviour' => __('What happens'),
        'changed' => __(':feature is now :state.'), 'previewTitle' => __('Apply “:preset”?'),
        'nothingChanges' => __('Nothing would change.'), 'willChange' => __(':feature: :from to :to'),
        'pauseTitle' => __('Pause all automation?'), 'pauseBody' => __('Everything that reaches out to customers is switched off at once. Nothing is deleted, and you can switch each one back on.'),
        'noMatch' => __('No feature matches.'), 'on' => __('On'), 'beta' => __('Beta'), 'off' => __('Off'),
    ];
@endphp
<x-layouts.admin :title="__('Feature control')" section="features">
    <x-page-head :title="__('Feature control')">
        <x-slot:subtitle>{{ __('What the platform has on, off or in beta. A switch never deletes anything: it decides what is offered and what keeps running.') }}</x-slot:subtitle>

        @if ($canChange && $anySwitchable)
            <details class="menu fc-presets">
                <summary class="btn btn-quiet btn-sm">{{ __('Presets') }} <x-icon name="chevronDown" :size="16" /></summary>
                <div class="menu-panel" role="group" aria-label="{{ __('Presets') }}">
                    @foreach ($presets as $preset => [$name, $about])
                        <form method="post" action="{{ route('admin.features.presets.apply', $preset) }}" data-preset="{{ $preset }}" data-preset-name="{{ $name }}" data-preview="{{ route('admin.features.presets.preview', $preset) }}">
                            @csrf
                            <strong>{{ $name }}</strong>
                            <span class="fc-quiet">{{ $about }}</span>
                            <input type="text" name="reason" maxlength="500" placeholder="{{ __('Reason') }}" aria-label="{{ __('Reason') }}" required>
                            <button class="btn btn-quiet btn-sm" type="submit">{{ __('Apply') }}</button>
                        </form>
                    @endforeach
                </div>
            </details>
        @endif
        @if ($canChange && $anyAutomation)
            <form class="fc-pause" method="post" action="{{ route('admin.features.pause') }}" data-pause>
                @csrf
                <input type="text" name="reason" maxlength="500" placeholder="{{ __('Reason') }}" aria-label="{{ __('Reason') }}" required>
                <button class="btn btn-danger btn-sm" type="submit">{{ __('Pause all automation') }}</button>
            </form>
        @endif
    </x-page-head>

    @unless ($canChange)
        <x-alert type="info" :message="__('Only a super admin can change these. You can see how everything is set.')" />
    @endunless
    @error('reason')<x-alert type="error" :message="$message" />@enderror

    <div class="fc" data-cockpit data-text='@json($text)' data-features-url="{{ route('admin.features.index') }}">
        <div class="fc-bar" data-summary data-on="{{ $summary['on'] }}" data-beta="{{ $summary['beta'] }}" data-off="{{ $summary['off'] }}">
            <p class="fc-counts" aria-live="polite">
                <span data-count="on"><b>{{ $summary['on'] }}</b> {{ __('On') }}</span>
                <span data-count="beta"><b>{{ $summary['beta'] }}</b> {{ __('Beta') }}</span>
                <span data-count="off"><b>{{ $summary['off'] }}</b> {{ __('Off') }}</span>
            </p>
            <div class="fc-find">
                <label class="sr-only" for="fc-search">{{ __('Search features') }}</label>
                <input id="fc-search" type="search" placeholder="{{ __('Search features') }}" autocomplete="off" data-search>
                <div class="fc-filters" role="group" aria-label="{{ __('Show') }}">
                    @foreach (['all' => __('All'), 'on' => __('On'), 'beta' => __('Beta'), 'off' => __('Off'), 'used' => __('In use'), 'attention' => __('Needs attention')] as $filter => $label)
                        <button type="button" data-filter="{{ $filter }}" aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        @foreach ($board['groups'] as $group)
            @php
                $counts = collect($group['features'])->countBy('state');
            @endphp
            <details class="fc-group" data-group="{{ $group['key'] }}" open>
                <summary>
                    <h2>{{ $group['letter'] ? $group['letter'].' · ' : '' }}{{ $group['label'] }}</h2>
                    <span class="fc-group-counts">{{ __('On') }} {{ $counts['on'] ?? 0 }} · {{ __('Beta') }} {{ $counts['beta'] ?? 0 }} · {{ __('Off') }} {{ $counts['off'] ?? 0 }}</span>
                </summary>
                <ul class="fc-list">
                    @foreach ($group['features'] as $card)
                        @include('admin.features.row', ['card' => $card, 'canChange' => $canChange, 'labels' => $labels])
                    @endforeach
                </ul>
            </details>
        @endforeach

        <p class="fc-empty" data-empty hidden>{{ __('No feature matches.') }}</p>

        <div class="fc-toasts" data-toasts role="status" aria-live="polite"></div>

        <dialog class="fc-dialog" data-dialog aria-labelledby="fc-dialog-title">
            <form method="dialog">
                <h2 id="fc-dialog-title" data-dialog-title></h2>
                <div class="fc-dialog-body" data-dialog-body></div>
                <label class="fc-dialog-reason">{{ __('Reason') }}
                    <textarea rows="2" maxlength="500" data-dialog-reason></textarea>
                </label>
                <p class="fc-error" role="alert" data-dialog-error hidden></p>
                <div class="fc-dialog-actions">
                    <button class="btn btn-quiet" value="cancel" data-dialog-cancel>{{ __('Cancel') }}</button>
                    <button class="btn btn-gold" value="confirm" data-dialog-confirm>{{ __('Confirm') }}</button>
                </div>
            </form>
        </dialog>
    </div>
</x-layouts.admin>
