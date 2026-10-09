{{-- A sample document: a title, a customer, a table of lines and an optional QR code. It proves the layout, the fonts and the
     Arabic shaping, and is what `php artisan qistas:pdf-preview` draws for the eye to check. Every value is escaped. --}}
@extends('documents.layout')

@section('meta')
    {{ $reference ?? '' }}
@endsection

@section('content')
    <h1>{{ $title }}</h1>
    <p class="muted">{{ __('Customer') }}: <strong>{{ $customer }}</strong></p>

    <table class="grid">
        <thead>
            <tr><th>{{ __('Description') }}</th><th class="num">{{ __('Amount') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($lines as [$label, $amount])
                <tr><td>{{ $label }}</td><td class="num">{{ $amount }}</td></tr>
            @endforeach
        </tbody>
    </table>

    @if (! empty($verifyUrl))
        <div class="qr">
            <img src="{{ \App\Documents\QrCode::dataUri($verifyUrl) }}" style="width: 70pt; height: 70pt">
            <div class="muted">{{ __('Scan to check this document') }}</div>
        </div>
    @endif
@endsection
