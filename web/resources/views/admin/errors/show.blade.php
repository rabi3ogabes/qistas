{{-- One issue: what it is, how often and since when, who dealt with it, the last five times it happened with their (cleaned)
     traces, and the last 30 days. Everything stored is escaped here, the trace included. Nothing stored is ever used as a link. --}}
@php
    $sources = \App\Models\ErrorIssue::sourceLabels();
    $kinds = \App\Models\ErrorIssue::kindLabels();
    $states = \App\Models\ErrorIssue::statusLabels();
    $platforms = ['server' => __('Server'), 'web' => __('Website'), 'android' => 'Android', 'ios' => 'iOS'];
    $devices = ['desktop' => __('Desktop'), 'mobile' => __('Phone'), 'tablet' => __('Tablet')];
    $day = fn ($moment) => $moment?->translatedFormat('j M Y');

    // What a browser or a phone reported is never checked, so the page says so; everything else came from our own server.
    $reporter = match (true) {
        $issue->source === 'site' && $issue->kind === 'script' => __('Reported by a browser'),
        $issue->source === 'app' && $issue->kind === 'crash' => __('Reported by a phone'),
        default => null,
    };

    $who = fn (?string $id) => $id === null ? null : ($staff[$id] ?? __('Someone no longer on the team'));
    $peak = max(1, ...$days);
    $slot = 10;
    $height = 60;
    $first = now()->subDays(count($days) - 1);
@endphp
<x-layouts.admin :title="$issue->title" section="errors">
    <div class="stack">
        <a class="err-back" href="{{ $backUrl }}"><x-icon name="chevronLeft" :size="18" class="flip" /> {{ __('All errors') }}</a>

        <header class="page-head">
            <div>
                <h1 class="display err-h1" dir="auto">{{ $issue->title }}</h1>
                <p class="page-sub err-head">
                    <span class="err-src" data-source="{{ $issue->source }}">{{ $sources[$issue->source] ?? $issue->source }}</span>
                    <span class="err-kind">{{ $kinds[$issue->kind] ?? $issue->kind }}</span>
                    <span @class(['badge', 'badge-ok' => $issue->status === 'resolved', 'badge-warn' => $issue->status === 'open'])>{{ $states[$issue->status] ?? $issue->status }}</span>
                    @if ($issue->isNew())<span class="badge badge-info" data-mark="new">{{ __('New') }}</span>@endif
                    @if ($issue->hasComeBack())<span class="badge badge-warn" data-mark="back">{{ __('Came back') }}</span>@endif
                    @if ($growing)<span class="badge badge-bad" data-mark="growing">{{ __('Growing') }}</span>@endif
                </p>
            </div>
        </header>

        @if ($reporter)
            <p class="alert alert-info" role="note"><strong>{{ $reporter }}.</strong> {{ __('It comes from a visitor’s own device and cannot be checked, so it never sends an instant e-mail.') }}</p>
        @endif

        <section class="card card-pad stack" aria-labelledby="err-summary-title">
            <h2 id="err-summary-title" class="card-title">{{ __('What we know') }}</h2>
            <dl class="facts err-facts">
                <div><dt>{{ __('Times seen') }}</dt><dd class="money">{{ number_format($issue->occurrences) }}</dd></div>
                <div><dt>{{ __('People it touched') }}</dt><dd class="money">{{ $issue->peopleLabel() }}</dd></div>
                <div>
                    <dt>{{ __('First seen') }}</dt>
                    <dd>@include('admin.errors._time', ['moment' => $issue->first_seen_at, 'ago' => true])@if ($issue->first_version)<span class="cell-sub">{{ __('Version :version', ['version' => $issue->first_version]) }}</span>@endif</dd>
                </div>
                <div>
                    <dt>{{ __('Last seen') }}</dt>
                    <dd>@include('admin.errors._time', ['moment' => $issue->last_seen_at, 'ago' => true])@if ($issue->last_version)<span class="cell-sub">{{ __('Version :version', ['version' => $issue->last_version]) }}</span>@endif</dd>
                </div>
                @php
                    // What was decided, or that it came back: one line, whichever applies.
                    $decision = match (true) {
                        $issue->status === 'resolved' && $issue->resolved_at !== null => __('Resolved by :name on :date', ['name' => $who($issue->resolved_by) ?? __('Someone no longer on the team'), 'date' => $day($issue->resolved_at)]),
                        $issue->status === 'ignored' && $issue->ignored_at !== null => __('Ignored by :name on :date', ['name' => $who($issue->ignored_by) ?? __('Someone no longer on the team'), 'date' => $day($issue->ignored_at)]),
                        $issue->status === 'open' && $issue->reopened_at !== null && $issue->reopened_version !== null => __('Came back in :version on :date', ['version' => $issue->reopened_version, 'date' => $day($issue->reopened_at)]),
                        $issue->status === 'open' && $issue->reopened_at !== null => __('Came back on :date', ['date' => $day($issue->reopened_at)]),
                        default => null,
                    };
                @endphp
                @if ($decision)
                    <div class="err-fact-wide"><dt>{{ __('Status') }}</dt><dd>{{ $decision }}</dd></div>
                @endif
            </dl>

            <form method="POST" action="{{ route('admin.errors.status', $issue) }}" class="err-actions">
                @csrf
                @method('PUT')
                @if ($back)<input type="hidden" name="back" value="{{ $back }}">@endif
                <button type="submit" name="status" value="resolved" class="btn btn-sm btn-gold" @disabled($issue->status === 'resolved')>{{ __('Resolve') }}</button>
                @if ($issue->status === 'ignored')
                    <button type="submit" name="status" value="ignored" class="btn btn-sm btn-quiet" disabled>{{ __('Ignore') }}</button>
                @else
                    {{-- One more step before ignoring, with no script (the site's content security policy forbids an inline confirm):
                         the real button is only inside the opened details. --}}
                    <details class="err-ignore">
                        <summary class="btn btn-sm btn-quiet">{{ __('Ignore…') }}</summary>
                        <div class="err-ignore-body">
                            <p>{{ __('New sightings will not be counted while this is ignored. You can reopen it later.') }}</p>
                            <button type="submit" name="status" value="ignored" class="btn btn-sm btn-quiet">{{ __('Ignore') }}</button>
                        </div>
                    </details>
                @endif
                <button type="submit" name="status" value="open" class="btn btn-sm btn-quiet" @disabled($issue->status === 'open')>{{ __('Reopen') }}</button>
            </form>
            <p class="field-hint">{{ __('Resolve hides it until it happens again, and then it comes back on its own. Ignore hides it for good: new sightings are no longer counted and never raise an alert.') }}</p>
            @include('admin.errors._legend')
        </section>

        <section class="card card-pad stack" aria-labelledby="err-days-title">
            <h2 id="err-days-title" class="card-title">{{ __('Last 30 days') }}</h2>
            <svg class="err-days" viewBox="0 0 {{ count($days) * $slot - 2 }} {{ $height }}" preserveAspectRatio="none" role="img" focusable="false"
                aria-label="{{ __('Last 30 days, oldest first: :counts', ['counts' => implode(', ', $days)]) }}">
                @foreach ($days as $i => $count)
                    @php $tall = $count > 0 ? max(2, (int) round($count / $peak * $height)) : 1; @endphp
                    <rect x="{{ $i * $slot }}" y="{{ $height - $tall }}" width="8" height="{{ $tall }}" rx="1" @if ($count === 0) fill-opacity=".3" @endif>
                        <title>{{ $first->copy()->addDays($i)->translatedFormat('j M') }}: {{ number_format($count) }}</title>
                    </rect>
                @endforeach
            </svg>
            <div class="err-days-axis" aria-hidden="true">
                <span>{{ $first->translatedFormat('j M') }}</span>
                <span>{{ __('Most in one day: :count', ['count' => number_format(max($days))]) }}</span>
                <span>{{ now()->translatedFormat('j M') }}</span>
            </div>
        </section>

        <section class="card" aria-labelledby="err-samples-title">
            <div class="card-head">
                <h2 id="err-samples-title">{{ __('Latest sightings') }}</h2>
                <span class="badge">{{ $samples->count() }}</span>
            </div>
            <p class="field-hint err-samples-note">{{ __('The last five times it happened, newest first. Personal details were removed before anything was kept.') }}</p>

            @forelse ($samples as $sample)
                <article class="err-sample" aria-label="{{ $sample->occurred_at->translatedFormat('j M Y, H:i') }}">
                    <p class="err-sample-meta">
                        @include('admin.errors._time', ['moment' => $sample->occurred_at])
                        @if ($sample->version)<span>{{ __('Version :version', ['version' => $sample->version]) }}</span>@endif
                        <span>{{ $platforms[$sample->platform] ?? $sample->platform }}@if ($sample->device), {{ $devices[$sample->device] ?? $sample->device }}@endif</span>
                        @if ($sample->method || $sample->route)<span dir="ltr" class="err-route">{{ trim($sample->method.' '.$sample->route) }}</span>@endif
                        @if ($sample->status)<span>{{ __('HTTP :status', ['status' => $sample->status]) }}</span>@endif
                    </p>
                    @if ($sample->message !== '')<p class="err-message" dir="auto">{{ $sample->message }}</p>@endif
                    @if ($sample->trace !== '')
                        <pre class="err-trace" dir="ltr" role="region" tabindex="0" aria-label="{{ __('Trace of sighting :n', ['n' => $loop->iteration]) }}">{{ $sample->trace }}</pre>
                    @else
                        <p class="field-hint">{{ __('No trace was kept for this one.') }}</p>
                    @endif
                </article>
            @empty
                <div class="empty empty-quiet">
                    <h3>{{ __('No sighting is kept') }}</h3>
                    <p>{{ $keptDays === 1 ? __('Sightings are kept for 1 day. The counts above are kept as long as the issue is.') : __('Sightings are kept for :days days. The counts above are kept as long as the issue is.', ['days' => $keptDays]) }}</p>
                </div>
            @endforelse
        </section>
    </div>
</x-layouts.admin>
