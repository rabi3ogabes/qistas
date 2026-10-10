{{-- One contract's statement: its terms, what is owed and late, the schedule, what was sold and its account. --}}
@extends('documents.layout')

@php
    use App\Domain\Schedule\Frequencies;
    use App\Support\Format;
    use App\Support\Money;

    $money = fn (string $amount): string => Format::money($amount, $currency);
    $late = $options->shows('overdue');
@endphp

@section('meta')
    {{ __('Statement') }}<br>{{ $issuedAt->translatedFormat('j M Y') }}
@endsection

@section('content')
    <h1>{{ __('Statement of account') }}</h1>
    <p class="muted">{{ $options->word('contract') }} {{ $contract->reference() }}@if ($contract->title) · {{ $contract->title }}@endif</p>

    <table class="facts">
        <tr><td class="label">{{ $options->word('customer') }}</td><td><strong>{{ $customer->name }}</strong>@if ($customer->phone)<br><span class="muted">{{ $customer->phone }}</span>@endif</td></tr>
        <tr><td class="label">{{ __('Contract date') }}</td><td>{{ $contract->start_date->translatedFormat('j M Y') }}</td></tr>
        @unless ($contract->isOpen())
            <tr><td class="label">{{ __('Price') }}</td><td>{{ $money($contract->principal) }}</td></tr>
            @if (Money::isPositive($contract->discount_amount))
                <tr><td class="label">{{ __('Discount') }}</td><td>{{ $money(Money::sub('0', $contract->discount_amount)) }}</td></tr>
            @endif
            @if ($contract->tax_amount !== null)
                <tr><td class="label">{{ __('Tax in the price') }}</td><td>{{ $money($contract->tax_amount) }} ({{ rtrim(rtrim((string) $contract->tax_percent, '0'), '.') }}%)</td></tr>
            @endif
            @if (Money::isPositive($contract->down_payment))
                <tr><td class="label">{{ __('Down payment') }}</td><td>{{ $money($contract->down_payment) }}</td></tr>
            @endif
            @if (Money::isPositive($contract->markup_amount))
                <tr><td class="label">{{ __('Markup') }}</td><td>{{ $money($contract->markup_amount) }}</td></tr>
            @endif
            <tr><td class="label">{{ __('Total to repay') }}</td><td>{{ $money($contract->total) }}</td></tr>
            @if ($contract->installment_count > 0)
                <tr><td class="label">{{ __('Instalments') }}</td><td>{{ $contract->installment_count }} · {{ Frequencies::label($contract->frequency) }}</td></tr>
            @endif
        @endunless
        @if ($options->shows('cost') && $contract->cost_price !== null)
            <tr><td class="label">{{ __('What it cost you') }}</td><td>{{ $money($contract->cost_price) }}</td></tr>
            <tr><td class="label">{{ __('Margin') }}</td><td>{{ $money($margin) }}</td></tr>
        @endif
    </table>

    <table class="owed"><tr>
        <td>
            <strong>{{ __('Still owed') }}</strong>
            @if ($late && Money::isPositive($overdue))<br><span class="late small">{{ __('Late today: :amount', ['amount' => $money($overdue)]) }}</span>@endif
            @if (Money::isPositive($paid))<br><span class="muted small">{{ __('Paid so far: :amount', ['amount' => $money($paid)]) }}</span>@endif
        </td>
        <td class="figure">{{ $money($owed) }}</td>
    </tr></table>

    @unless ($options->summary)
        @if ($options->shows('schedule') && $schedule !== [])
            <h2>{{ __('Schedule') }}</h2>
            <table class="grid">
                <thead><tr>
                    <th>#</th><th>{{ __('Due date') }}</th>
                    <th class="num">{{ __('Amount') }}</th><th class="num">{{ __('Paid') }}</th>
                    @if ($late)<th class="num">{{ __('Overdue') }}</th>@endif
                </tr></thead>
                <tbody>
                    @foreach ($schedule as $row)
                        <tr>
                            <td>{{ $row['number'] }}</td>
                            <td>{{ $row['due_date']->translatedFormat('j M Y') }}</td>
                            <td class="num">{{ Format::amount($row['amount']) }}</td>
                            <td class="num">{{ Format::amount($row['paid']) }}</td>
                            @if ($late)<td class="num late">{{ $row['overdue'] === null ? '' : Format::amount($row['overdue']) }}</td>@endif
                        </tr>
                    @endforeach
                    @if ($late)
                        <tr class="sum"><td colspan="4">{{ __('Overdue') }}</td><td class="num late">{{ Format::amount($overdue) }}</td></tr>
                    @endif
                </tbody>
            </table>
        @endif

        @if ($contract->items->isNotEmpty())
            <h2>{{ __('What was sold') }}</h2>
            <table class="grid">
                <thead><tr><th>{{ __('Item') }}</th><th>{{ __('Serial or IMEI') }}</th><th class="num">{{ __('Quantity') }}</th><th class="num">{{ __('Price') }}</th></tr></thead>
                <tbody>
                    @foreach ($contract->items as $item)
                        <tr>
                            <td>{{ $item->name }}</td>
                            <td dir="ltr">{{ $item->serial ?? '' }}</td>
                            <td class="num">{{ $item->quantity }}</td>
                            <td class="num">{{ $item->price === null ? '' : Format::amount($item->price) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <h2>{{ __('Account') }}</h2>
        @include('documents._account', ['statement' => $statement, 'showContract' => false])
    @endunless

    @include('documents._closing')
@endsection
