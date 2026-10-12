{{-- The trial run of an import (Win Plan K9): what each row would do, every problem by row and column, the columns as
     they are taken (and chosen again), then one confirm. Once done: what was brought in. --}}
@php
    use App\Import\Fields;
    use App\Support\Dates;
    use App\Support\Format;

    $currency = app(\App\Tenancy\CurrentTenant::class)->get()?->currency ?? '';
    $done = $import->isDone();
    $summary = $import->summary;
    $actions = ['new' => __('New customer'), 'existing' => __('Added to a customer you have'), 'skip' => __('Nothing to add')];
@endphp
<x-layouts.app :title="$import->filename" section="customers">
    <x-page-head :title="$import->filename">
        <x-slot:subtitle>
            <a class="link" href="{{ route('app.import') }}">{{ __('Bring your customers in') }}</a> ·
            {{ $done ? __('Brought in on :date', ['date' => Dates::day($import->confirmed_at)]) : __('Trial run: nothing is saved yet') }}
        </x-slot:subtitle>
    </x-page-head>

    <section class="card summary summary-trio" aria-label="{{ __('What the file holds') }}">
        <div class="figure">
            <p class="figure-label">{{ $done ? __('Brought in') : __('Ready to bring in') }}</p>
            <p class="figure-value money">{{ $summary['ready'] ?? 0 }}</p>
            <p class="figure-note">{{ __('Rows read: :count', ['count' => $summary['rows'] ?? 0]) }}</p>
        </div>
        <div class="figure">
            <p class="figure-label">{{ __('New customers') }}</p>
            <p class="figure-value money">{{ $summary['customers'] ?? 0 }}</p>
            <p class="figure-note">{{ __('Contracts: :count', ['count' => $summary['contracts'] ?? 0]) }}</p>
        </div>
        <div class="figure">
            <p class="figure-label">{{ __('Balances brought in') }}</p>
            <p class="figure-value money">{{ Format::money((string) ($summary['balance'] ?? '0'), $currency) }}</p>
            <p class="figure-note @if (($summary['problems'] ?? 0) > 0) late @endif">{{ __('Rows with a problem: :count', ['count' => $summary['problems'] ?? 0]) }}</p>
        </div>
    </section>

    @unless ($done)
        <section class="card" aria-labelledby="import-columns-title">
            <div class="card-head"><h2 id="import-columns-title">{{ __('Columns') }}</h2></div>
            <form method="POST" action="{{ route('app.import.update', $import) }}" class="card-pad stack">
                @csrf
                @method('PUT')
                <div class="import-columns">
                    @foreach ($import->headers as $index => $header)
                        <div class="field">
                            <label for="column-{{ $index }}">{{ $header !== '' ? $header : __('Column :number', ['number' => $index + 1]) }}</label>
                            <select id="column-{{ $index }}" name="mapping[{{ $index }}]">
                                <option value="">{{ __('Leave out') }}</option>
                                @foreach ($fields as $field)
                                    <option value="{{ $field }}" @selected(($import->mapping[$index] ?? null) === $field)>{{ Fields::label($field) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
                <div class="form-actions"><button class="btn btn-quiet btn-sm" type="submit">{{ __('Save the columns') }}</button></div>
            </form>
        </section>
    @endunless

    <section class="card" aria-labelledby="import-rows-title">
        <div class="card-head"><h2 id="import-rows-title">{{ __('Rows') }}</h2></div>
        <div class="table-wrap">
            <table class="table table-stack import-rows">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Row') }}</th>
                        <th scope="col">{{ __('Customer') }}</th>
                        <th scope="col">{{ __('What happens') }}</th>
                        <th scope="col">{{ __('Contract') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($import->rows as $row)
                        <tr @if ($row['problems'] !== []) data-state="problem" @endif>
                            <td data-label="{{ __('Row') }}" class="money">{{ $row['row'] }}</td>
                            <td data-label="{{ __('Customer') }}">
                                <strong>{{ $row['name'] ?? '—' }}</strong>
                                @if ($row['phone'])<br><span class="cell-sub money" dir="ltr">{{ $row['phone'] }}</span>@endif
                            </td>
                            <td data-label="{{ __('What happens') }}">
                                @if ($row['problems'] !== [])
                                    <span class="badge badge-bad">{{ __('Not brought in') }}</span>
                                    <ul class="import-problems" role="list">
                                        @foreach ($row['problems'] as $problem)
                                            <li><strong>{{ $problem['column'] }}</strong>: {{ $problem['message'] }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <span class="badge {{ $row['action'] === 'skip' ? 'badge-info' : 'badge-ok' }}">{{ $actions[$row['action']] ?? $row['action'] }}</span>
                                    @if ($row['note'])<p class="cell-sub">{{ $row['note'] }}</p>@endif
                                @endif
                            </td>
                            <td data-label="{{ __('Contract') }}">
                                @if ($row['contract'] === null)
                                    <span class="muted">—</span>
                                @elseif ($row['contract']['type'] === 'open')
                                    {{ __('Open account, owing :amount', ['amount' => Format::money($row['contract']['balance'], $currency)]) }}
                                @else
                                    {{ __('Instalments: :count, the first on :date', ['count' => $row['contract']['installments'], 'date' => Dates::day(\Carbon\CarbonImmutable::parse($row['contract']['first_due']))]) }}
                                    <br><span class="cell-sub">{{ __('Paid :paid, still owed :owed', ['paid' => Format::money($row['contract']['paid'], $currency), 'owed' => Format::money($row['contract']['balance'], $currency)]) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @unless ($done)
        <section class="card card-pad stack" aria-labelledby="import-confirm-title">
            <h2 id="import-confirm-title">{{ __('Bring them in') }}</h2>
            @if (($summary['ready'] ?? 0) > 0)
                <p>{{ __('Rows with a problem are left out. Fix them in the file and bring it in again; customers already brought in are recognised by their phone.') }}</p>
                <form method="POST" action="{{ route('app.import.confirm', $import) }}">
                    @csrf
                    <button class="btn btn-gold" type="submit"><x-icon name="check" :size="18" /> {{ __('Bring in the rows that are ready: :count', ['count' => $summary['ready']]) }}</button>
                </form>
            @else
                <p class="muted">{{ __('No row is ready yet. Fix the problems in the file, or choose the columns above.') }}</p>
            @endif
        </section>
    @endunless
</x-layouts.app>
