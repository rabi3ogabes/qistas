{{-- The public status page (Sprint 2, D10): states and times in plain words, nothing private. --}}
@php
    $rows = [
        ['title' => __('Website and app'), 'detail' => __('The website, the web app and the phone app’s connection.'), 'ok' => true, 'state' => __('Answering'), 'when' => null],
        ['title' => __('Database'), 'detail' => __('Where every workspace’s records are kept.'), 'ok' => $status['database'], 'state' => $status['database'] ? __('Answering') : __('Not answering'), 'when' => null],
        [
            'title' => __('Scheduled work'),
            'detail' => __('Instalment alerts, late fees, the morning summary and each business’s nightly copy.'),
            'ok' => $status['scheduler'] !== 'delayed',
            'state' => match ($status['scheduler']) { 'running' => __('Running'), 'delayed' => __('Delayed'), default => __('Not started yet') },
            'when' => $status['last_run'],
        ],
    ];
@endphp
<x-layouts.site :title="__('Service status')" :description="__('Whether Qistas is answering right now, and when scheduled work last ran.')" path="/status">
    <div class="container prose-page">
        <h1 class="status-title">{{ __('Service status') }}</h1>

        <p class="status-banner" data-ok="{{ $status['ok'] ? 'true' : 'false' }}" role="status">
            <x-icon :name="$status['ok'] ? 'check' : 'alert'" :size="20" />
            <span>{{ $status['ok'] ? __('All systems running') : __('Some work is delayed') }}</span>
        </p>

        <ul class="status-list" role="list">
            @foreach ($rows as $row)
                <li>
                    <div>
                        <h2>{{ $row['title'] }}</h2>
                        <p>{{ $row['detail'] }}</p>
                        @if ($row['when'])
                            <p class="status-when"><time datetime="{{ $row['when']->toIso8601String() }}">{{ __('Last ran :time', ['time' => $row['when']->diffForHumans()]) }}</time></p>
                        @endif
                    </div>
                    <span class="badge {{ $row['ok'] ? 'badge-ok' : 'badge-warn' }}">{{ $row['state'] }}</span>
                </li>
            @endforeach
        </ul>

        @unless ($status['ok'])
            <p class="status-note">{{ __('Nothing is lost while work is delayed: alerts and late fees catch up as soon as it runs again.') }}</p>
        @endunless
        <p class="status-checked"><time datetime="{{ $checked->toIso8601String() }}">{{ __('Checked :time', ['time' => $checked->utc()->format('H:i').' UTC']) }}</time></p>
    </div>
</x-layouts.site>
