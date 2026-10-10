{{-- A customer's statement: every contract with what is owed and late today, then the period's account. --}}
@extends('documents.layout')

@php
    use App\Support\Format;
    use App\Support\Money;

    $money = fn (string $amount): string => Format::money($amount, $currency);
    $late = $options->shows('overdue');
    $statusLabel = ['active' => __('Active'), 'settled' => __('Settled'), 'cancelled' => __('Cancelled')];
@endphp

@section('meta')
    {{ __('Statement') }}<br>{{ $issuedAt->translatedFormat('j M Y') }}
@endsection

@section('content')
    <h1>{{ __('Statement of account') }}</h1>
    <p class="muted">
        @if ($options->from !== null)
            {{ __('From :from to :to', ['from' => $options->from->translatedFormat('j M Y'), 'to' => ($options->to ?? $issuedAt)->translatedFormat('j M Y')]) }}
        @else
            {{ __('Up to :date', ['date' => ($options->to ?? $issuedAt)->translatedFormat('j M Y')]) }}
        @endif
    </p>

    <table class="facts">
        <tr><td class="label">{{ $options->word('customer') }}</td><td><strong>{{ $customer->name }}</strong>@if ($customer->phone)<br><span class="muted">{{ $customer->phone }}</span>@endif</td></tr>
    </table>

    <table class="owed"><tr>
        <td>
            <strong>{{ __('Still owed') }}</strong>
            @if ($late && Money::isPositive($overdue))<br><span class="late small">{{ __('Late today: :amount', ['amount' => $money($overdue)]) }}</span>@endif
        </td>
        <td class="figure">{{ $money($owed) }}</td>
    </tr></table>

    <h2>{{ __('Contracts') }}</h2>
    <table class="grid">
        <thead><tr>
            <th>{{ $options->word('contract') }}</th><th>{{ __('Status') }}</th>
            <th class="num">{{ __('Total') }}</th><th class="num">{{ __('Still owed') }}</th>
            @if ($late)<th class="num">{{ __('Overdue') }}</th>@endif
        </tr></thead>
        <tbody>
            @foreach ($contracts as $row)
                <tr>
                    <td>{{ $row['contract']->reference() }}@if ($row['contract']->title)<br><span class="muted small">{{ $row['contract']->title }}</span>@endif</td>
                    <td>{{ $statusLabel[$row['contract']->status] ?? $row['contract']->status }}</td>
                    <td class="num">{{ $row['sold'] === null ? '' : Format::amount($row['sold']) }}</td>
                    <td class="num">{{ Format::amount($row['owed']) }}</td>
                    @if ($late)<td class="num late">{{ Money::isPositive($row['overdue']) ? Format::amount($row['overdue']) : '' }}</td>@endif
                </tr>
            @endforeach
            <tr class="sum">
                <td colspan="3">{{ __('Total') }}</td>
                <td class="num">{{ Format::amount($owed) }}</td>
                @if ($late)<td class="num late">{{ Format::amount($overdue) }}</td>@endif
            </tr>
        </tbody>
    </table>

    @unless ($options->summary)
        <h2>{{ __('Account') }}</h2>
        @include('documents._account', ['statement' => $statement, 'showContract' => count($contracts) > 1])
    @endunless

    @include('documents._closing')
@endsection
