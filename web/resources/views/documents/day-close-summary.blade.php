{{-- One close of one day, signed off: the cash counted against the cash recorded, the day's money by way of paying and by
     person, and the non-cash payments one by one. Never a customer's ID or phone, only the name a payment was taken from. --}}
@extends('documents.layout')

@php
    use App\Support\Dates;
    use App\Support\Format;
    use App\Support\Money;
    use App\Support\PaymentMethods;

    $money = fn (string $amount): string => Format::money($amount, $currency);
    // A figure keeps its own direction in a right-to-left page, so a minus stays in front of what it takes from.
    $figure = fn (string $amount): string => '<span dir="ltr">'.e(Format::amount($amount)).'</span>';
@endphp

@section('meta')
    {{ __('Day summary') }}<br>{{ Dates::day($day) }}
@endsection

@section('content')
    <h1>{{ __('Day summary') }}</h1>
    <p class="muted">{{ $shop }}, {{ Dates::day($day) }}</p>
    @if ($reopened)<p style="margin-top: 6pt"><span class="stamp">{{ __('Reopened') }}</span></p>@endif

    <table class="owed"><tr>
        <td><strong>{{ __('Collected') }}</strong><br><span class="muted small">{{ __('Payments: :count', ['count' => $count]) }}</span></td>
        <td class="figure">{{ $money($collected) }}</td>
    </tr></table>

    <table class="facts">
        <tr><td class="label">{{ __('Closed by') }}</td><td><strong>{{ $closedBy ?? __('Someone no longer on the team') }}</strong></td></tr>
        <tr><td class="label">{{ __('Closed at') }}</td><td>{{ Dates::day($closedAt) }}, {{ $closedAt->format('H:i') }}</td></tr>
        <tr><td class="label">{{ __('Expected cash') }}</td><td>{{ $money($expected) }}</td></tr>
        <tr><td class="label">{{ __('Counted cash') }}</td><td>{{ $money($counted) }}</td></tr>
        @if (Money::isZero($difference))
            <tr><td class="label">{{ __('Difference') }}</td><td><strong>{{ $money('0.00') }}</strong></td></tr>
        @elseif (Money::isPositive($difference))
            <tr><td class="label">{{ __('Over by') }}</td><td><strong>{{ $money($difference) }}</strong></td></tr>
        @else
            <tr><td class="label">{{ __('Short by') }}</td><td class="late"><strong>{{ $money(ltrim($difference, '-')) }}</strong></td></tr>
        @endif
        @if ($note)<tr><td class="label">{{ __('Note') }}</td><td>{{ $note }}</td></tr>@endif
    </table>

    <h2>{{ __('By way of paying') }}</h2>
    @if ($byMethod === [])
        <p class="muted">{{ __('No payments on this day.') }}</p>
    @else
        <table class="grid">
            <tbody>
                @foreach ($byMethod as $method => $amount)
                    <tr><td>{{ PaymentMethods::label($method) }}</td><td class="num">{!! $figure($amount) !!}</td></tr>
                @endforeach
                <tr class="sum"><td>{{ __('Total') }}</td><td class="num">{!! $figure($collected) !!}</td></tr>
            </tbody>
        </table>
    @endif

    @if ($perMember !== [])
        <h2>{{ __('Cash by team member') }}</h2>
        <table class="grid">
            <thead><tr><th>{{ __('Recorded by') }}</th><th class="num">{{ __('Payments') }}</th><th class="num">{{ __('Cash') }}</th></tr></thead>
            <tbody>
                @foreach ($perMember as $member)
                    <tr><td>{{ $member['name'] }}</td><td class="num">{{ $member['count'] }}</td><td class="num">{!! $figure($member['cash']) !!}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($nonCash !== [])
        <h2>{{ __('Cards, transfers and other') }}</h2>
        <table class="grid">
            <thead><tr>
                <th>{{ __('Method') }}</th><th>{{ $options->word('customer') }}</th><th>{{ __('Note') }}</th><th class="num">{{ __('Amount') }}</th>
            </tr></thead>
            <tbody>
                @foreach ($nonCash as $line)
                    <tr>
                        <td>{{ Money::isNegative($line['amount']) ? __('Payment voided') : PaymentMethods::label($line['method']) }}</td>
                        <td>{{ $line['customer'] }}</td>
                        <td>{{ $line['note'] }}</td>
                        <td class="num">{!! $figure($line['amount']) !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($changedSince)
        <p class="late small" style="margin-top: 8pt">{{ __('Payments were recorded for this day after it was closed, so the list of other payments may show more than the signed figures.') }}</p>
    @endif

    @include('documents._closing')
@endsection
