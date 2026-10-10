{{-- A receipt for money that came in. On a till roll it is one narrow column; on A4 or A5 it uses the page frame. --}}
@php
    use App\Support\Format;
    use App\Support\Money;
    use App\Support\PaymentMethods;

    $money = fn (string $amount): string => Format::money($amount, $currency);
    $roll = $options->isRoll();
@endphp
@extends($roll ? 'documents.roll' : 'documents.layout')

@section('meta')
    {{ __('Receipt') }}<br>{{ $number }}
@endsection

@section('content')
    <h1>{{ __('Receipt') }}</h1>
    <p class="muted">{{ $number }}</p>
    @if ($voided)<p style="margin-top: 6pt"><span class="stamp">{{ __('Voided') }}</span></p>@endif

    <table class="owed"><tr>
        <td><strong>{{ __('Amount received') }}</strong></td>
        <td class="figure">{{ $money($amount) }}</td>
    </tr></table>

    <table class="facts">
        <tr><td class="label">{{ $options->word('customer') }}</td><td><strong>{{ $customer->name }}</strong></td></tr>
        <tr><td class="label">{{ $options->word('contract') }}</td><td>{{ $contract->reference() }}@if ($contract->title)<br><span class="muted small">{{ $contract->title }}</span>@endif</td></tr>
        <tr><td class="label">{{ __('Paid on') }}</td><td>{{ $paidOn->translatedFormat('j M Y') }}</td></tr>
        <tr><td class="label">{{ __('Recorded') }}</td><td>{{ $recordedAt->translatedFormat('j M Y') }}, {{ $recordedAt->format('H:i') }}</td></tr>
        <tr><td class="label">{{ __('Method') }}</td><td>{{ $payment->type === 'down_payment' ? __('Down payment') : PaymentMethods::label($payment->method) }}</td></tr>
        @if ($payment->createdBy)<tr><td class="label">{{ __('Received by') }}</td><td>{{ $payment->createdBy->name }}</td></tr>@endif
        @if ($payment->note)<tr><td class="label">{{ __('Note') }}</td><td>{{ $payment->note }}</td></tr>@endif
    </table>

    @if ($covered !== [])
        <h2>{{ __('What it paid') }}</h2>
        <table class="grid">
            <thead><tr><th>{{ $options->word('instalment') }}</th><th>{{ __('Due date') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
            <tbody>
                @foreach ($covered as $row)
                    <tr>
                        <td>{{ $row['number'] === null ? '' : '#'.$row['number'] }}</td>
                        <td>{{ $row['due_date']?->translatedFormat('j M Y') }}</td>
                        <td class="num">{{ Format::amount($row['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="facts">
        <tr><td class="label">{{ __('Still owed after this payment') }}</td><td><strong>{{ $money(Money::isNegative($owedAfter) ? '0' : $owedAfter) }}</strong></td></tr>
    </table>

    @include('documents._closing')
@endsection
