{{-- Errors (error center): what is breaking on the website, the API, the database and the phone app, one row per issue, with
     a 7-day sparkline and the marks new / came back / growing. Everything shown was cleaned of personal details before it was
     stored, and is escaped here all the same. Nothing is ever built into a link except the page of the issue itself. --}}
@php
    $sources = \App\Models\ErrorIssue::sourceLabels();
    $kinds = \App\Models\ErrorIssue::kindLabels();
    $groups = [['status', __('Status')], ['source', __('Source')], ['show', __('Show')]];
@endphp
<x-layouts.admin :title="__('Errors')" section="errors">
    <x-page-head :title="__('Errors')">
        <x-slot:subtitle>{{ __('What is breaking on the website, the API, the database and the phone app. Nothing here names a customer or a business.') }}</x-slot:subtitle>
    </x-page-head>

    @unless ($recording)
        <x-alert type="warning" :message="__('Error recording is switched off (QISTAS_ERRORS). Nothing new is being recorded.')" />
    @endunless

    <div>
        <div class="err-tools">
            @foreach ($groups as [$group, $name])
                <div class="err-filter">
                    <p class="err-filter-name" id="err-filter-{{ $group }}">{{ $name }}</p>
                    <nav class="biz-filters" aria-labelledby="err-filter-{{ $group }}">
                        @foreach ($chips[$group] as $chip)
                            <a href="{{ $chip['href'] }}" @if ($chip['on']) aria-current="true" @endif>{{ $chip['label'] }}</a>
                        @endforeach
                    </nav>
                </div>
            @endforeach
        </div>

        <section class="card" aria-label="{{ __('Errors') }}">
            @if ($issues->isEmpty())
                <div class="empty">
                    <span class="empty-icon"><x-icon :name="$filtered ? 'search' : 'check'" :size="24" /></span>
                    @if ($filtered)
                        <h3>{{ __('No issue matches') }}</h3>
                        <p>{{ __('Try another filter, or show every open issue.') }}</p>
                        <a class="btn btn-quiet" href="{{ route('admin.errors.index') }}">{{ __('Show open issues') }}</a>
                    @else
                        <h3>{{ __('Nothing is broken right now') }}</h3>
                        <p>{{ __('A new error appears here as soon as the website, the API, the database or the app reports one.') }}</p>
                    @endif
                </div>
            @else
                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Issue') }}</th>
                                <th scope="col" class="num">{{ __('Times seen') }}</th>
                                <th scope="col">{{ __('First seen') }}</th>
                                <th scope="col">{{ __('Last seen') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($issues as $issue)
                                <tr data-issue="{{ $issue->id }}">
                                    <td data-label="{{ __('Issue') }}">
                                        <span class="biz-cell err-issue">
                                            <span class="err-head">
                                                <span class="err-src" data-source="{{ $issue->source }}">{{ $sources[$issue->source] ?? $issue->source }}</span>
                                                <span class="err-kind">{{ $kinds[$issue->kind] ?? $issue->kind }}</span>
                                            </span>
                                            <a class="cell-link err-title" dir="auto" href="{{ route('admin.errors.show', array_filter(['issue' => $issue, 'back' => $back])) }}">{{ $issue->title }}</a>
                                            @if ($issue->isNew() || $issue->hasComeBack() || $growing[$issue->id])
                                                <span class="err-marks">
                                                    @if ($issue->isNew())<span class="badge badge-info" data-mark="new">{{ __('New') }}</span>@endif
                                                    @if ($issue->hasComeBack())<span class="badge badge-warn" data-mark="back">{{ __('Came back') }}</span>@endif
                                                    @if ($growing[$issue->id])<span class="badge badge-bad" data-mark="growing">{{ __('Growing') }}</span>@endif
                                                </span>
                                            @endif
                                        </span>
                                    </td>
                                    <td data-label="{{ __('Times seen') }}" class="num">
                                        <span class="err-count">
                                            <span class="money">{{ number_format($issue->occurrences) }}</span>
                                            @include('admin.errors._spark', ['counts' => $weeks[$issue->id], 'label' => __('Last 7 days, oldest first: :counts', ['counts' => implode(', ', $weeks[$issue->id])])])
                                        </span>
                                    </td>
                                    <td data-label="{{ __('First seen') }}">
                                        @include('admin.errors._time', ['moment' => $issue->first_seen_at])
                                        @if ($issue->first_version)<span class="cell-sub">{{ __('Version :version', ['version' => $issue->first_version]) }}</span>@endif
                                    </td>
                                    <td data-label="{{ __('Last seen') }}">
                                        @include('admin.errors._time', ['moment' => $issue->last_seen_at, 'ago' => true])
                                        @if ($issue->last_version)<span class="cell-sub">{{ __('Version :version', ['version' => $issue->last_version]) }}</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @include('admin.errors._legend')
    </div>

    {{ $issues->links() }}

    <details class="card err-noise">
        <summary>
            <span class="err-noise-title">{{ __('Background noise (bots and browsers)') }}</span>
            <span class="err-noise-sum">{{ __('Last 7 days: :count', ['count' => number_format($noiseTotals['scanner'])]) }}</span>
            {{-- What was dropped is the part of the noise that was meant to be seen, so a closed card still says so. --}}
            @if ($noiseTotals['dropped'] > 0)<span class="err-noise-sum err-noise-drop">{{ __(':count dropped', ['count' => number_format($noiseTotals['dropped'])]) }}</span>@endif
        </summary>
        <div class="err-noise-body">
            <p class="field-hint">{{ __('Requests for things this site does not have, from bots probing for files such as .env and from browsers asking for icons, are only counted here. They never become an issue, a sample or an e-mail.') }}</p>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Day') }}</th>
                            <th scope="col" class="num">{{ __('Bots and browsers') }}</th>
                            <th scope="col" class="num">{{ __('Dropped') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($noise as $row)
                            <tr>
                                <th scope="row">{{ \Illuminate\Support\Carbon::parse($row['day'])->translatedFormat('j M Y') }}</th>
                                <td class="num money">{{ number_format($row['scanner']) }}</td>
                                <td class="num money">{{ number_format($row['dropped']) }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <th scope="row">{{ __('Last 7 days') }}</th>
                            <td class="num money"><strong>{{ number_format($noiseTotals['scanner']) }}</strong></td>
                            <td class="num money"><strong>{{ number_format($noiseTotals['dropped']) }}</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="field-hint">{{ __('Dropped means a report was over a limit, was a repeat of a busy error page, or was refused. It is counted so that nothing disappears unseen.') }}</p>
        </div>
    </details>
</x-layouts.admin>
