{{-- The money that came in over a period, with the total for each way of paying. --}}
@extends('documents.layout')

@php
    use App\Support\Format;
    use App\Support\PaymentMethods;
@endphp

@section('meta')
    {{ __('Report') }}<br>{{ $issuedAt->translatedFormat('j M Y') }}
@endsection

@section('content')
    <h1>{{ __('Payments report') }}</h1>
    <p class="muted">{{ __('From :from to :to', ['from' => $from->translatedFormat('j M Y'), 'to' => $to->translatedFormat('j M Y')]) }}</p>

    <table class="owed"><tr>
        <td><strong>{{ __('Received') }}</strong><br><span class="muted small">{{ __('Payments: :count', ['count' => $lines->count()]) }}</span></td>
        <td class="figure">{{ Format::money($total, $currency) }}</td>
    </tr></table>

    @unless ($options->summary)
        <table class="grid" style="margin-top: 14pt">
            <thead><tr>
                <th>{{ __('Date') }}</th><th>{{ $options->word('customer') }}</th><th>{{ $options->word('contract') }}</th>
                <th>{{ __('Method') }}</th><th>{{ __('Recorded by') }}</th><th class="num">{{ __('Amount') }}</th>
            </tr></thead>
            <tbody>
                @forelse ($lines as $line)
                    <tr>
                        <td>{{ $line->paid_at->translatedFormat('j M Y') }}</td>
                        <td>{{ $line->contract?->customer?->name }}</td>
                        <td>{{ $line->contract?->reference() }}</td>
                        <td>{{ $line->type === 'reversal' ? __('Payment voided') : PaymentMethods::label($line->method) }}</td>
                        <td>{{ $line->createdBy?->name }}</td>
                        <td class="num">{{ Format::amount($line->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('Nothing in this period.') }}</td></tr>
                @endforelse
                <tr class="sum"><td colspan="5">{{ __('Total') }}</td><td class="num">{{ Format::amount($total) }}</td></tr>
            </tbody>
        </table>
    @endunless

    @if ($byMethod !== [])
        <h2>{{ __('By way of paying') }}</h2>
        <table class="grid">
            <tbody>
                @foreach ($byMethod as $method => $amount)
                    <tr><td>{{ PaymentMethods::label($method) }}</td><td class="num">{{ Format::amount($amount) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('documents._closing')
@endsection
