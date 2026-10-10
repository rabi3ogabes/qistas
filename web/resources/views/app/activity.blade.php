{{-- The activity log (Win Plan PP10): what the business's people did, newest first, filtered by person, kind and day. --}}
@php
    $kinds = ['customers' => __('Customers'), 'contracts' => __('Contracts'), 'payments' => __('Payments'), 'investors' => __('Investors'), 'products' => __('Products'), 'team' => __('Team'), 'settings' => __('Settings')];
    $icons = ['customers' => 'users', 'contracts' => 'fileText', 'payments' => 'wallet', 'investors' => 'trendUp', 'products' => 'layers', 'team' => 'userPlus', 'settings' => 'sliders'];
    $filtered = array_filter($filters) !== [];
@endphp
<x-layouts.app :title="__('Activity log')" section="activity">
    <x-page-head :title="__('Activity log')">
        <x-slot:subtitle>{{ __('Who added, changed, recorded or removed what, newest first.') }}</x-slot:subtitle>
    </x-page-head>

    <form class="card card-pad activity-filters" method="GET" action="{{ route('app.activity') }}">
        <div class="field">
            <label for="f-user">{{ __('Person') }}</label>
            <select id="f-user" name="user">
                <option value="">{{ __('Everyone') }}</option>
                @foreach ($people as $person)
                    <option value="{{ $person['id'] }}" @selected(($filters['user'] ?? '') === $person['id'])>{{ $person['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="f-kind">{{ __('What') }}</label>
            <select id="f-kind" name="kind">
                <option value="">{{ __('Everything') }}</option>
                @foreach ($kinds as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['kind'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field"><label for="f-from">{{ __('From') }}</label><input id="f-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" max="{{ today()->format('Y-m-d') }}"></div>
        <div class="field"><label for="f-to">{{ __('To') }}</label><input id="f-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" max="{{ today()->format('Y-m-d') }}"></div>
        <div class="activity-filter-actions">
            <button class="btn" type="submit">{{ __('Show') }}</button>
            @if ($filtered)<a class="btn btn-ghost" href="{{ route('app.activity') }}">{{ __('Clear') }}</a>@endif
        </div>
    </form>

    <section class="card">
        @if ($entries->isEmpty())
            <div class="empty">
                <span class="empty-icon"><x-icon name="history" :size="24" /></span>
                <h3>{{ $filtered ? __('Nothing matches these filters') : __('Nothing yet') }}</h3>
                <p>{{ $filtered ? __('Try another person, kind or day.') : __('What your team does appears here as it happens.') }}</p>
            </div>
        @else
            <ol class="activity-list">
                @foreach ($entries as $entry)
                    <li class="activity-item">
                        <span class="activity-icon" data-kind="{{ $entry['kind'] }}" aria-hidden="true"><x-icon :name="$icons[$entry['kind']] ?? 'history'" :size="18" /></span>
                        <div class="activity-body">
                            <p class="activity-what">{{ $entry['summary'] }}</p>
                            <p class="cell-sub">
                                {{ $entry['person']['name'] ?? __('Qistas') }}
                                · <time datetime="{{ $entry['at'] }}">{{ \Illuminate\Support\Carbon::parse($entry['at'])->translatedFormat('j M Y, H:i') }}</time>
                            </p>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    {{ $page->links() }}
</x-layouts.app>
