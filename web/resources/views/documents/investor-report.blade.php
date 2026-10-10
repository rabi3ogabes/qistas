{{-- An investor's account: their figures, the contracts they fund and the wallet line by line. --}}
@extends('documents.layout')

@php
    use App\Models\InvestorEntry;
    use App\Support\Format;
    use App\Support\Money;

    $money = fn (string $amount): string => Format::money($amount, $currency);
    $late = $options->shows('overdue');
    $label = fn (InvestorEntry $entry): string => match ($entry->type) {
        'deposit' => __('Money put in'),
        'withdrawal' => __('Money taken out'),
        'funding_out' => __('Funded a contract'),
        'funding_back' => __('Back from a cancelled contract'),
        'principal_back' => __('Principal back from a payment'),
        'profit_share' => __('Profit from a payment'),
        'commission' => Money::isNegative($entry->reverses_entry_id === null ? $entry->amount : Money::sub('0', $entry->amount)) ? __('Commission to the business') : __('Commission from a partner'),
        default => $entry->type,
    };
@endphp

@section('meta')
    {{ __('Report') }}<br>{{ $issuedAt->translatedFormat('j M Y') }}
@endsection

@section('content')
    <h1>{{ __(':investor report', ['investor' => $options->word('investor')]) }}</h1>
    <p class="muted"><strong>{{ $investor->name }}</strong></p>

    <table class="owed"><tr>
        <td><strong>{{ __('In the wallet') }}</strong></td>
        <td class="figure">{{ $money($summary['wallet']) }}</td>
    </tr></table>

    <table class="facts">
        <tr><td class="label">{{ __('Out in contracts') }}</td><td>{{ $money($summary['out_in_contracts']) }}</td></tr>
        <tr><td class="label">{{ __('Profit earned') }}</td><td>{{ $money($summary['profit_earned']) }}</td></tr>
        <tr><td class="label">{{ __('Profit still expected') }}</td><td>{{ $money($summary['profit_expected']) }}</td></tr>
        <tr><td class="label">{{ __('Contracts') }}</td><td>{{ $summary['contracts'] }}</td></tr>
    </table>

    @if ($contracts !== [])
        <h2>{{ __('Contracts funded') }}</h2>
        <table class="grid">
            <thead><tr>
                <th>{{ $options->word('contract') }}</th><th>{{ $options->word('customer') }}</th>
                <th class="num">{{ __('Still owed') }}</th>
                @if ($late)<th class="num">{{ __('Overdue') }}</th>@endif
            </tr></thead>
            <tbody>
                @foreach ($contracts as $row)
                    <tr>
                        <td>{{ $row['contract']->reference() }}</td>
                        <td>{{ $row['contract']->customer?->name }}</td>
                        <td class="num">{{ Format::amount($row['owed']) }}</td>
                        @if ($late)<td class="num late">{{ Money::isPositive($row['overdue']) ? Format::amount($row['overdue']) : '' }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @unless ($options->summary)
        <h2>{{ __('Wallet') }}</h2>
        <table class="grid">
            <thead><tr>
                <th>{{ __('Date') }}</th><th>{{ __('Details') }}</th>
                <th class="num">{{ __('Amount') }}</th><th class="num">{{ __('Running balance') }}</th>
            </tr></thead>
            <tbody>
                @if ($options->from !== null)
                    <tr class="opening"><td>{{ $options->from->translatedFormat('j M Y') }}</td><td>{{ __('Balance brought forward') }}</td><td class="num"></td><td class="num">{{ Format::amount($opening) }}</td></tr>
                @endif
                @forelse ($entries as $row)
                    <tr>
                        <td>{{ $row['entry']->occurred_on->translatedFormat('j M Y') }}</td>
                        <td>{{ $label($row['entry']) }}@if ($row['entry']->contract)<br><span class="muted small">{{ $row['entry']->contract->reference() }}</span>@endif</td>
                        <td class="num">{{ Format::amount($row['amount']) }}</td>
                        <td class="num">{{ Format::amount($row['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">{{ __('Nothing in this period.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    @endunless

    @include('documents._closing')
@endsection
