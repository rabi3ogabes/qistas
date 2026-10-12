@php
    use App\Reports\Ageing;
    use App\Support\Format;

    $role = auth()->user()?->roleIn(app(\App\Tenancy\CurrentTenant::class)->id());
    $periods = ['this_month' => __('This month'), 'last_3_months' => __('3 months'), 'last_12_months' => __('12 months'), 'this_year' => __('This year')];
@endphp
<x-layouts.app :title="__('Reports')" section="reports">
    <x-page-head :title="__('Reports')">
        <x-slot:subtitle>{{ __('Profit as money comes in, what is owed and how late, and what the schedules say is coming.') }}</x-slot:subtitle>
        @if (! $locked && ($role?->canExport() ?? false))
            <a class="btn btn-quiet btn-sm" href="{{ route('app.reports.export', $filters->query()) }}"><x-icon name="download" :size="16" /> {{ __('Download Excel') }}</a>
        @endif
    </x-page-head>

    @if ($locked)
        {{-- Win Plan K8: a Free workspace sees what it would get, and the way to it. --}}
        <section class="card card-pad stack reports-locked" aria-labelledby="locked-title">
            <span class="reports-locked-icon"><x-icon name="chart" :size="26" /></span>
            <h2 id="locked-title" class="card-title">{{ __('Reports come with Pro') }}</h2>
            <p>{{ __('See what the next 90 days should bring in, who is late and by how much, and your profit month by month, by investor and by product, as money comes in. Every table downloads to Excel.') }}</p>
            <p><a class="btn btn-gold" href="{{ $locked['upgrade_url'] ?? url('/app/billing') }}">{{ __('See Pro') }}</a></p>
        </section>
    @else
        <nav class="tabs" aria-label="{{ __('Choose the period') }}">
            @foreach ($periods as $key => $label)
                <a class="tabs-item" href="{{ route('app.reports', array_filter(['period' => $key, 'investor_id' => $filters->investorId, 'product_id' => $filters->productId])) }}" @if ($filters->period === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        <form class="card card-pad report-filters" method="get" action="{{ route('app.reports') }}">
            <input type="hidden" name="period" value="{{ $filters->period === 'custom' ? 'custom' : $filters->period }}">
            @if ($investors->isNotEmpty())
                <div class="field">
                    <label for="f-investor">{{ __('Investor') }}</label>
                    <select id="f-investor" name="investor_id">
                        <option value="">{{ __('All investors') }}</option>
                        @foreach ($investors as $investor)
                            <option value="{{ $investor->id }}" @selected($filters->investorId === $investor->id)>{{ \App\Domain\Investors\MainInvestor::displayName($investor) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($products->isNotEmpty())
                <div class="field">
                    <label for="f-product">{{ __('Product') }}</label>
                    <select id="f-product" name="product_id">
                        <option value="">{{ __('All products') }}</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected($filters->productId === $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <details class="report-dates" @if ($filters->period === 'custom') open @endif>
                <summary class="link">{{ __('Your own dates') }}</summary>
                <div class="report-dates-fields">
                    <x-field name="from" type="date" :label="__('From')" :value="$filters->from->format('Y-m-d')" />
                    <x-field name="to" type="date" :label="__('To')" :value="$filters->to->format('Y-m-d')" />
                </div>
            </details>
            <button class="btn btn-sm" type="submit">{{ __('Show') }}</button>
        </form>

        {{-- The forecast: what the schedules say is coming. --}}
        <section class="card report" aria-labelledby="forecast-title">
            <div class="card-head"><h2 id="forecast-title">{{ __('Cash forecast') }}</h2><span class="muted">{{ __('Expected: what falls due, after what is already paid') }}</span></div>
            <div class="summary summary-trio report-figures">
                <div class="figure figure-lead"><p class="figure-label">{{ __('Expected in the next 30 days') }}</p><p class="figure-value money">{{ Format::money($forecast['windows']['days_30'], $currency) }}</p></div>
                <div class="figure"><p class="figure-label">{{ __('Next 60 days') }}</p><p class="figure-value money">{{ Format::money($forecast['windows']['days_60'], $currency) }}</p></div>
                <div class="figure"><p class="figure-label">{{ __('Next 90 days') }}</p><p class="figure-value money">{{ Format::money($forecast['windows']['days_90'], $currency) }}</p></div>
            </div>
            <div class="card-pad report-chart">{!! $forecastChart !!}</div>
            <p class="card-pad report-note">
                {{ __('Already due and not paid: :amount.', ['amount' => Format::money($forecast['already_due'], $currency)]) }}
                @if (\App\Support\Money::isPositive($forecast['late_fees'])) {{ __('Late fees owed: :amount.', ['amount' => Format::money($forecast['late_fees'], $currency)]) }} @endif
            </p>
        </section>

        {{-- Ageing: every unpaid amount in one bucket, by how late it is. --}}
        <section class="card report" aria-labelledby="ageing-title">
            <div class="card-head"><h2 id="ageing-title">{{ __('Ageing') }}</h2><span class="muted money">{{ __('Owed in all: :amount', ['amount' => Format::money($ageing['total'], $currency)]) }}</span></div>
            <div class="card-pad report-chart">{!! $ageingChart !!}</div>
            @if ($ageingRows === [])
                <p class="card-pad muted">{{ __('Nothing is owed on the running contracts.') }}</p>
            @else
                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Contract') }}</th>
                                @foreach (Ageing::BUCKETS as $bucket)<th scope="col" class="num">{{ $bucketLabels[$bucket] }}</th>@endforeach
                                <th scope="col" class="num">{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ageingRows as $row)
                                <tr>
                                    <td data-label="{{ __('Contract') }}"><a class="cell-link" href="{{ route('app.contracts.show', $row['id']) }}">{{ $row['reference'] }}</a><span class="cell-sub">{{ $row['customer']['name'] }}</span></td>
                                    @foreach (Ageing::BUCKETS as $bucket)
                                        <td data-label="{{ $bucketLabels[$bucket] }}" @class(['num', 'money', 'muted' => \App\Support\Money::isZero($row[$bucket])])>{{ \App\Support\Money::isZero($row[$bucket]) ? '—' : Format::money($row[$bucket], $currency) }}</td>
                                    @endforeach
                                    <td data-label="{{ __('Total') }}" class="num money"><strong>{{ Format::money($row['total'], $currency) }}</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (count($ageing['contracts']) > count($ageingRows))
                    <p class="card-pad report-note">{{ __('The :count most overdue contracts are shown; the Excel file has every one.', ['count' => count($ageingRows)]) }}</p>
                @endif
            @endif
        </section>

        {{-- Profit as money comes in, by the investor ledger's rule. --}}
        <section class="card report" aria-labelledby="profit-title">
            <div class="card-head"><h2 id="profit-title">{{ __('Profit as money comes in') }}</h2><span class="muted">{{ \App\Support\Dates::day($filters->from) }} – {{ \App\Support\Dates::day($filters->to) }}</span></div>
            <div class="summary summary-trio report-figures">
                <div class="figure figure-lead"><p class="figure-label">{{ __('Profit in the period') }}</p><p class="figure-value money">{{ Format::money($profit['total'], $currency) }}</p></div>
                <div class="figure"><p class="figure-label">{{ __('Collected of what fell due') }}</p><p class="figure-value money">{{ $profit['collection_rate'] === null ? '—' : $profit['collection_rate'].'%' }}</p></div>
                <div class="figure">
                    <p class="figure-label">{{ __('Late fees collected') }}</p>
                    <p class="figure-value money">{{ Format::money($profit['late_fees']['shop'], $currency) }}</p>
                    @if (\App\Support\Money::isPositive($profit['late_fees']['charity']))<p class="figure-note">{{ __('And :amount for charity, kept apart', ['amount' => Format::money($profit['late_fees']['charity'], $currency)]) }}</p>@endif
                </div>
            </div>
            <div class="card-pad report-chart">{!! $profitChart !!}</div>
            <div class="report-split">
                <div>
                    <h3 class="report-subtitle">{{ __('By investor') }}</h3>
                    @forelse ($profit['investors'] as $row)
                        <p class="report-line"><span>{{ $row['name'] }}</span><span class="money">{{ Format::money($row['profit'], $currency) }}</span></p>
                    @empty
                        <p class="muted">{{ __('No profit in this period yet.') }}</p>
                    @endforelse
                </div>
                <div>
                    <h3 class="report-subtitle">{{ __('By product') }}</h3>
                    @forelse ($profit['products'] as $row)
                        <p class="report-line"><span>{{ $row['name'] }}</span><span class="money">{{ Format::money($row['profit'], $currency) }}</span></p>
                    @empty
                        <p class="muted">{{ __('No profit in this period yet.') }}</p>
                    @endforelse
                </div>
            </div>
        </section>
    @endif
</x-layouts.app>
