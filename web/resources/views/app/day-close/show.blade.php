{{-- The end of the day (daily-habit wave A, H4; ruling C7). Open: the cash to count, the other payments and what to look at,
     then the sign-off. Closed: the seal, the sentence to share, the PDF, and for an owner or a manager the way to reopen.
     Every figure arrives from the server as a string and is only formatted here: nothing is worked out in this page, and
     the live difference in the open form is a hint from the script that the server's own answer replaces on submit. --}}
@php
    use App\Support\Dates;
    use App\Support\Format;
    use Carbon\CarbonImmutable;

    $date = CarbonImmutable::parse($day, 'UTC');
    $money = fn (string $amount) => Format::money($amount, $currency);
    $reopened = $close !== null && $close->status === 'reopened';
@endphp
<x-layouts.app :title="__('Day close')" section="day-close">
    <x-page-head :title="__('Day close')">
        <x-slot:subtitle>
            {{ Dates::day($date, 'l, j F Y') }}
            @unless ($isToday)
                · <a class="link" href="{{ route('app.day-close.show') }}">{{ __('Back to today') }}</a>
            @endunless
        </x-slot:subtitle>
        @if ($closed)
            <div class="seal" data-seal role="img" aria-label="{{ __('Closed') }}">
                <x-icon name="check" :size="22" />
                <span class="seal-word" aria-hidden="true">{{ __('Closed') }}</span>
            </div>
        @endif
    </x-page-head>

    <div class="dc stack" data-state="{{ $closed ? 'closed' : 'open' }}">
        @if ($closed)
            {{-- What the action refused about the day (a second close from another phone, a reopening that is too late). --}}
            @error('day')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror

            <section class="card card-pad stack dc-share" aria-labelledby="share-title" x-data="copyText" data-text="{{ $summary }}" data-copied="{{ __('Copied') }}">
                <h2 id="share-title" class="card-title">{{ __('The day in one line') }}</h2>
                <p class="dc-sentence" id="dc-sentence">{{ $summary }}</p>
                <div class="row-actions">
                    {{-- Hidden until the script has started: without it there is nothing for the button to do. --}}
                    <button type="button" class="btn btn-gold btn-sm" x-cloak x-on:click="copy" aria-describedby="dc-sentence">
                        <span class="dc-copy-label" x-show="idle"><x-icon name="copy" :size="16" /> {{ __('Copy the sentence') }}</span>
                        <span class="dc-copy-label" x-show="done" x-cloak><x-icon name="check" :size="16" /> {{ __('Copied') }}</span>
                    </button>
                    <a class="btn btn-quiet btn-sm" href="{{ route('app.day-close.summary', $close) }}" target="_blank" rel="noopener">
                        <x-icon name="fileText" :size="16" /> {{ __('Signed summary (PDF)') }}
                    </a>
                </div>
                <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>
            </section>

            <section class="card card-pad stack" aria-labelledby="cash-title">
                <h2 id="cash-title" class="card-title">{{ __('Cash') }}</h2>
                <dl class="facts dc-facts">
                    <div><dt>{{ __('Expected cash') }}</dt><dd class="money">{{ $money($close->expected_cash) }}</dd></div>
                    <div><dt>{{ __('Counted cash') }}</dt><dd class="money">{{ $money($close->counted_cash) }}</dd></div>
                    <div><dt>{{ __('Difference') }}</dt><dd class="dc-tone" data-tone="{{ $difference['tone'] }}">{{ $difference['words'] }}</dd></div>
                    <div><dt>{{ __('Closed by') }}</dt><dd dir="auto">{{ $close->closedBy?->name ?? __('Someone no longer on the team') }}</dd></div>
                    <div><dt>{{ __('Closed at') }}</dt><dd class="money">{{ Dates::day($closedAt) }}, {{ $closedAt->format('H:i') }}</dd></div>
                    @if ($close->note)<div><dt>{{ __('Note') }}</dt><dd class="prewrap" dir="auto">{{ $close->note }}</dd></div>@endif
                </dl>
            </section>

            @include('app.day-close._lists')

            @if ($canReopen)
                <section class="card card-pad stack" aria-label="{{ __('Reopen this day') }}">
                    <details class="confirm confirm-inline" data-reopen @if ($errors->has('reason')) open @endif>
                        <summary class="btn btn-quiet btn-sm">{{ __('Reopen this day') }}</summary>
                        <form method="POST" action="{{ route('app.day-close.reopen', $close) }}" class="form" novalidate>
                            @csrf
                            <p class="muted">{{ __('The close stays on record as it was signed. The day opens again so it can be corrected, then closed once more.') }}</p>
                            <x-field name="reason" type="textarea" :label="__('Reason')" rows="2" maxlength="{{ $maxReason }}" required
                                     :hint="__('At least :min characters.', ['min' => $minReason])" />
                            <button class="btn btn-danger btn-sm">{{ __('Reopen the day') }}</button>
                        </form>
                    </details>
                </section>
            @endif
        @else
            <form method="POST" action="{{ route('app.day-close.store') }}" class="stack" novalidate
                  x-data="dayCount" data-config="{{ json_encode($config) }}">
                @csrf
                <input type="hidden" name="day" value="{{ $day }}">

                @if ($reopened)
                    <p class="alert alert-info" role="status">
                        {{ __('This day was reopened. Close it again when the figures are right.') }}
                        @if ($close->reopen_reason)<span class="dc-why" dir="auto">{{ __('Why') }}: {{ $close->reopen_reason }}</span>@endif
                    </p>
                @endif

                <section class="card card-pad stack" aria-labelledby="cash-title">
                    <h2 id="cash-title" class="card-title">{{ __('Cash') }}</h2>
                    <div class="dc-expected">
                        <p class="figure-label">{{ __('Expected cash') }}</p>
                        <p class="figure-value money">{{ $money($figures['expected_cash']) }}</p>
                        @if ($figures['by_method'] === [])
                            <p class="figure-note">{{ $isToday ? __('Nothing collected yet today.') : __('Nothing was collected on this day.') }}</p>
                        @endif
                    </div>
                    <p class="muted">{{ __('Count the notes and coins in the till and enter the total.') }}</p>
                    <x-field name="counted_cash" :label="__('Counted cash')" inputmode="decimal" dir="ltr" autocomplete="off" required x-model="counted" x-on:change="announce"
                             :hint="__('Digits and one decimal point, for example 3100.50.')" />
                    {{-- What the eye sees follows every key. A screen reader would speak a phrase per digit, so it is not read out; it
                         is told through the region below, which is always there and is filled once the field has been left. --}}
                    <p class="dc-difference" data-tone="" x-bind:data-tone="tone" x-show="hasHint" x-cloak aria-hidden="true">
                        <span class="figure-label">{{ __('Difference') }}</span>
                        <strong x-text="hint"></strong>
                    </p>
                    <p class="sr-only" role="status" aria-live="polite" aria-atomic="true" x-text="announced"></p>
                </section>

                @include('app.day-close._lists')

                <section class="card card-pad stack" aria-labelledby="sign-title">
                    <h2 id="sign-title" class="card-title">{{ __('Sign off') }}</h2>
                    <x-field name="note" type="textarea" :label="__('Note (optional)')" rows="2" maxlength="{{ $maxNote }}" />
                    @error('day')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror
                    <button class="btn btn-lg btn-block">{{ __('Close the day') }}</button>
                    <p class="field-hint">{{ __('Closing keeps the figures as they are now. An owner or a manager can reopen the day.') }}</p>
                </section>
            </form>
        @endif

        <section class="card card-pad" aria-label="{{ __('Close an earlier day') }}">
            <details class="confirm confirm-inline">
                <summary class="btn btn-ghost btn-sm">{{ __('Close an earlier day') }}</summary>
                <form method="GET" action="{{ route('app.day-close.show') }}" class="form dc-day-form">
                    <x-field name="day" type="date" bag="chooser" :label="__('Day')" :value="$day" min="{{ $earliest }}" max="{{ $latestDay }}" />
                    <button class="btn btn-quiet btn-sm">{{ __('Show this day') }}</button>
                </form>
            </details>
        </section>

        <section class="card" aria-labelledby="history-title">
            <div class="card-head"><h2 id="history-title">{{ __('History') }}</h2></div>
            @if ($history->isEmpty())
                <div class="empty empty-quiet">
                    <span class="empty-icon"><x-icon name="history" :size="24" /></span>
                    <p>{{ __('Closed days will be listed here.') }}</p>
                </div>
            @else
                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Date') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col">{{ __('Difference') }}</th>
                                <th scope="col">{{ __('Closed by') }}</th>
                                <th scope="col"><span class="sr-only">{{ __('Signed summary (PDF)') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $row)
                                <tr data-history="{{ $row['id'] }}">
                                    <td data-label="{{ __('Date') }}"><x-day :date="$row['day']" /></td>
                                    <td data-label="{{ __('Status') }}">
                                        <span @class(['badge', 'badge-ok' => $row['status'] === 'closed', 'badge-warn' => $row['status'] !== 'closed'])>{{ $row['status'] === 'closed' ? __('Closed') : __('Reopened') }}</span>
                                    </td>
                                    <td data-label="{{ __('Difference') }}" class="dc-tone" data-tone="{{ $row['difference']['tone'] }}">{{ $row['difference']['words'] }}</td>
                                    <td data-label="{{ __('Closed by') }}" dir="auto">{{ $row['closed_by'] ?? __('Someone no longer on the team') }}</td>
                                    <td>
                                        <a class="link line-receipt" href="{{ $row['summary_url'] }}" target="_blank" rel="noopener"><x-icon name="fileText" :size="14" /> {{ __('Signed summary (PDF)') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{ $history->links() }}
    </div>
</x-layouts.app>
