{{--
    One feature: what it is, who uses it, its switch, and (folded away) its plans, early access and history.
    The switch is a plain form of three buttons, so it works with no JavaScript; the page's script improves it.
--}}
@php
    $key = $card['key'];
    $locked = $card['locked'];
    $disabled = $locked || ! $canChange;
    $states = ['off' => __('Off'), 'beta' => __('Beta'), 'on' => __('On')];
    $typeLabel = ['toggle' => __('On or off'), 'limit' => __('Limit'), 'quota' => __('Monthly allowance')][$card['type']] ?? $card['type'];
    $names = fn (array $keys) => collect($keys)->map(fn ($k) => $labels[$k] ?? $k)->implode(', ');
@endphp
<li class="fc-row"
    data-feature="{{ $key }}"
    data-state="{{ $card['state'] }}"
    data-locked="{{ $locked ? 'true' : 'false' }}"
    data-usage="{{ $card['usage_30d'] }}"
    data-depends-on="{{ implode(' ', $card['depends_on']) }}"
    data-required-by="{{ implode(' ', $card['required_by']) }}"
    data-dependency-problem="{{ $card['dependency_problem'] ? 'true' : 'false' }}"
    data-search="{{ mb_strtolower($card['label'].' '.$card['description'].' '.$key) }}">

    <div class="fc-info">
        <h3 class="fc-name">{{ $card['label'] }}</h3>
        <p class="fc-desc">{{ $card['description'] }}</p>
        <ul class="fc-meta">
            <li>{{ $typeLabel }}</li>
            @if ($card['usage_30d'] === 0)
                <li>{{ __('Not used in the last 30 days') }}</li>
            @elseif ($card['usage_30d'] === 1)
                <li>{{ __('Used by 1 workspace in the last 30 days') }}</li>
            @else
                <li>{{ __('Used by :count workspaces in the last 30 days', ['count' => $card['usage_30d']]) }}</li>
            @endif
            @if ($card['depends_on'] !== [])
                <li @class(['fc-flag' => $card['dependency_problem']])>{{ __('Needs: :features', ['features' => $names($card['depends_on'])]) }}@if ($card['dependency_problem']) · {{ __('not on') }}@endif</li>
            @endif
            @if ($card['required_by'] !== [])
                <li>{{ __('Needed by: :features', ['features' => $names($card['required_by'])]) }}</li>
            @endif
        </ul>
    </div>

    <form class="fc-switch" method="post" action="{{ route('admin.features.state', $key) }}" data-state-form>
        @csrf
        @method('PUT')
        <div class="fc-seg" role="radiogroup" aria-label="{{ __('Switch for :feature', ['feature' => $card['label']]) }}" data-state="{{ $card['state'] }}" @if ($disabled) aria-disabled="true" @endif>
            @foreach ($states as $value => $label)
                <button type="submit" name="state" value="{{ $value }}" role="radio" data-state="{{ $value }}"
                    aria-checked="{{ $card['state'] === $value ? 'true' : 'false' }}"
                    tabindex="{{ $card['state'] === $value ? '0' : '-1' }}"
                    @disabled($disabled)>{{ $label }}</button>
            @endforeach
        </div>
        @if ($locked)
            <p class="fc-note">{{ __('Core') }}</p>
        @elseif ($canChange)
            <input class="fc-reason" type="text" name="reason" maxlength="500" autocomplete="off" data-needed="{{ $card['usage_30d'] > 0 ? 'true' : 'false' }}"
                placeholder="{{ __('Reason, needed to reduce a feature that is in use') }}" aria-label="{{ __('Reason') }}">
        @endif
        @error('state')<p class="fc-error" role="alert">{{ $message }}</p>@enderror
    </form>

    <details class="fc-more">
        <summary>{{ __('Plans, early access and history') }}</summary>

        <div class="fc-panel">
            <p class="fc-off">{{ $card['off_behaviour'] }}</p>

            <h4>{{ __('Included in plans') }}</h4>
            <ul class="fc-plans">
                @foreach ($card['plans'] as $plan)
                    <li data-plan="{{ $plan['key'] }}">
                        <form method="post" action="{{ route('admin.features.plan', ['key' => $key, 'plan' => $plan['key']]) }}" data-plan-form>
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="enabled" value="{{ $plan['enabled'] ? '0' : '1' }}">
                            <button class="fc-chip" type="submit" aria-pressed="{{ $plan['enabled'] ? 'true' : 'false' }}" @disabled(! $canChange)>{{ $plan['name'] }}</button>
                        </form>
                        @if ($card['type'] !== 'toggle' && $plan['enabled'])
                            <form class="fc-limit" method="post" action="{{ route('admin.features.plan', ['key' => $key, 'plan' => $plan['key']]) }}" data-plan-form>
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="enabled" value="1">
                                <label>
                                    <span class="sr-only">{{ __('Limit on :plan', ['plan' => $plan['name']]) }}</span>
                                    <input type="number" name="limit" min="0" inputmode="numeric" value="{{ $plan['limit'] }}" placeholder="{{ __('Unlimited') }}" @disabled(! $canChange)>
                                </label>
                                <span class="fc-unit">{{ $card['unit'] }}</span>
                                @if ($canChange)<button class="btn btn-quiet btn-sm" type="submit">{{ __('Set') }}</button>@endif
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
            @error('limit')<p class="fc-error" role="alert">{{ $message }}</p>@enderror

            @unless ($locked)
                <h4>{{ __('Early access') }}</h4>
                @if ($card['beta'] === [])
                    <p class="fc-quiet">{{ __('No workspace has early access.') }}</p>
                @else
                    <ul class="fc-beta">
                        @foreach ($card['beta'] as $grant)
                            <li>
                                <span><strong>{{ $grant['workspace']['name'] }}</strong> · {{ $grant['reason'] }}@if ($grant['expires_at']) · {{ __('until :date', ['date' => \Illuminate\Support\Carbon::parse($grant['expires_at'])->translatedFormat('j M Y')]) }}@endif</span>
                                @if ($canChange)
                                    <form method="post" action="{{ route('admin.features.beta.destroy', ['key' => $key, 'override' => $grant['id']]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-quiet btn-sm" type="submit">{{ __('Take away') }}</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canChange)
                    <form class="fc-grant" method="post" action="{{ route('admin.features.beta.store', $key) }}">
                        @csrf
                        <label>{{ __('Workspace id or owner e-mail') }}<input type="text" name="workspace" required autocomplete="off"></label>
                        <label>{{ __('Reason') }}<input type="text" name="reason" required maxlength="500"></label>
                        <label>{{ __('Until (optional)') }}<input type="date" name="expires_at"></label>
                        <button class="btn btn-quiet btn-sm" type="submit">{{ __('Let in') }}</button>
                    </form>
                    @error('workspace')<p class="fc-error" role="alert">{{ $message }}</p>@enderror
                @endif
            @endunless

            <h4>{{ __('Recent changes') }}</h4>
            @if ($card['history'] === [])
                <p class="fc-quiet">{{ __('No changes yet.') }}</p>
            @else
                <ol class="fc-history">
                    @foreach ($card['history'] as $change)
                        <li>
                            <time datetime="{{ $change['at'] }}">{{ \Illuminate\Support\Carbon::parse($change['at'])->translatedFormat('j M Y, H:i') }}</time>
                            <span>{{ $change['by'] ?? __('Command line') }}: {{ __(':from to :to', ['from' => $states[$change['from']] ?? $change['from'], 'to' => $states[$change['to']] ?? $change['to']]) }}@if ($change['undo']) ({{ __('undone') }})@endif</span>
                            @if ($change['reason'])<q>{{ $change['reason'] }}</q>@endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </details>
</li>
