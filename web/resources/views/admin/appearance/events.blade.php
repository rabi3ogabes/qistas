@php
    // The ready-made events' names, written out so they are translated with the rest of the page.
    $eventNames = [
        'saudi_national_day' => __('Saudi National Day'), 'saudi_founding_day' => __('Saudi Founding Day'),
        'uae_national_day' => __('UAE National Day'), 'kuwait_national_day' => __('Kuwait National Day'),
        'qatar_national_day' => __('Qatar National Day'), 'bahrain_national_day' => __('Bahrain National Day'),
        'oman_national_day' => __('Oman National Day'), 'white_friday' => __('White Friday'),
        'ramadan' => __('Ramadan'), 'eid_al_fitr' => __('Eid al-Fitr'), 'eid_al_adha' => __('Eid al-Adha'),
    ];
    $placeNames = ['website' => __('Website'), 'webapp' => __('Web app'), 'mobile' => __('Android app')];
    $day = fn (string $date): string => \Illuminate\Support\Carbon::parse($date)->translatedFormat('j M Y');
    $length = fn (int $days): string => $days === 1 ? __('1 day') : __(':days days', ['days' => $days]);
@endphp
<section id="events" class="studio-panel events-panel" aria-labelledby="events-title">
    <header class="studio-panel-head events-head">
        <div>
            <h2 id="events-title">{{ __('Seasonal events') }}</h2>
            <p>{{ __('Dress the product for a national day or a season, for the countries and the days you choose. Everyone else keeps the usual look.') }}</p>
        </div>
    </header>

    @if ($canChange)
        <details class="event-new">
            <summary class="btn btn-sm"><x-icon name="plus" :size="16" /> {{ __('New event') }}</summary>
            <div class="event-new-body">
                <p class="field-hint">{{ __('Start from a ready-made event: its countries, colours, dates and greetings in five languages are filled in for you.') }}</p>
                <div class="event-presets">
                    @foreach ($eventPresets as $preset)
                        <a class="event-preset" href="{{ route('admin.appearance.events.create', ['preset' => $preset['key']]) }}">
                            <span class="preset-swatches" aria-hidden="true">
                                @foreach (['primary', 'accent', 'bg'] as $token)<x-swatch :hex="$preset['colours'][$token]" :size="16" />@endforeach
                            </span>
                            <span class="event-preset-name">{{ $eventNames[$preset['key']] }}</span>
                            <span class="event-preset-meta">
                                {{ $preset['countries'] === [] ? __('Everyone') : implode(', ', $preset['countries']) }}
                                ·
                                @if ($preset['dates'])
                                    <time datetime="{{ $preset['dates'][0] }}">{{ $day($preset['dates'][0]) }}</time>
                                @else
                                    {{ __('Set the dates each year') }}
                                @endif
                            </span>
                        </a>
                    @endforeach
                    <a class="event-preset event-preset-blank" href="{{ route('admin.appearance.events.create') }}">
                        <x-icon name="plus" :size="18" />
                        <span class="event-preset-name">{{ __('A blank event') }}</span>
                        <span class="event-preset-meta">{{ __('Choose everything yourself') }}</span>
                    </a>
                </div>
            </div>
        </details>
    @endif

    <figure class="event-timeline-wrap">
        <figcaption class="sr-only">{{ __('The next twelve months') }}</figcaption>
        <div class="event-months" aria-hidden="true">
            @foreach ($timeline['months'] as $month)<span>{{ $month['label'] }}</span>@endforeach
        </div>
        <svg class="event-timeline" viewBox="0 0 {{ \App\Theme\EventCalendar::WIDTH }} 40" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            @foreach ($timeline['months'] as $month)
                <line class="tl-month" x1="{{ $month['x'] }}" x2="{{ $month['x'] }}" y1="0" y2="40" />
            @endforeach
            @foreach ($timeline['bars'] as $bar)
                <rect class="tl-bar @if ($bar['draft']) tl-draft @endif" data-event="{{ $bar['id'] }}" x="{{ $bar['x'] }}" y="12" width="{{ $bar['width'] }}" height="16" rx="3" fill="{{ $bar['colour'] }}"><title>{{ $bar['name'] }}</title></rect>
            @endforeach
            <line class="tl-today" data-today x1="{{ $timeline['today'] }}" x2="{{ $timeline['today'] }}" y1="0" y2="40" />
        </svg>
        <p class="event-timeline-key" aria-hidden="true"><span class="tl-key-today"></span> {{ __('Today') }} <span class="tl-key-draft"></span> {{ __('Draft') }}</p>
    </figure>

    @if ($eventRows === [])
        <p class="studio-empty">{{ __('No events yet. Start from a ready-made one, like Saudi National Day.') }}</p>
    @else
        <ul class="event-list">
            @foreach ($eventRows as $row)
                @php
                    $event = $row['event'];
                    $colours = $event->pins()['light'] ?? [];
                @endphp
                <li class="event-row" data-state="{{ $row['state'] }}">
                    <span class="preset-swatches" aria-hidden="true">
                        <x-swatch :hex="$colours['primary'] ?? $live->tokens()['light']['primary']" :size="20" />
                        <x-swatch :hex="$colours['accent'] ?? $live->tokens()['light']['accent']" :size="20" />
                    </span>
                    <div class="event-body">
                        <p class="event-title">
                            <a href="{{ route('admin.appearance.events.edit', $event) }}">{{ $event->name }}</a>
                            <span class="event-state" data-state="{{ $row['state'] }}">{{ $row['label'] }}</span>
                        </p>
                        <p class="event-meta">
                            <time datetime="{{ $event->starts_on->toDateString() }}">{{ $day($event->starts_on->toDateString()) }}</time>@if ($event->days() > 1) – <time datetime="{{ $event->ends_on->toDateString() }}">{{ $day($event->ends_on->toDateString()) }}</time>@endif
                            · {{ $length($event->days()) }}
                            · {{ $row['countries'] === [] ? __('Everyone') : implode(', ', $row['countries']) }}
                            · {{ implode(', ', array_map(fn ($place) => $placeNames[$place] ?? $place, $event->surfaces ?? [])) }}
                        </p>
                        @foreach ($row['overlaps'] as $note)
                            <p class="event-overlap"><x-icon name="alert" :size="15" /> {{ $note }}</p>
                        @endforeach
                    </div>
                    <a class="btn btn-quiet btn-sm" href="{{ route('admin.appearance.events.edit', $event) }}">{{ $canChange ? __('Edit') : __('View') }}</a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
