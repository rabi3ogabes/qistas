{{-- A promissory note for what is still owed on a contract's instalments: the amount in figures and in words, the due date,
     the drawer, the guarantors and the signatures. The wording is a draft until a lawyer approves it (the switch stays off). --}}
@extends('documents.layout')

@php
    use App\Support\Format;
    use App\Support\Dates;

    $ids = $options->seesIdentities();
@endphp

@section('meta')
    {{ __('Promissory note') }}<br>{{ Dates::day($issuedAt) }}
@endsection

@section('content')
    <h1>{{ __('Promissory note') }}</h1>
    <p class="muted">{{ $options->word('contract') }} {{ $contract->reference() }}</p>

    <table class="owed"><tr>
        <td><strong>{{ __('Amount') }}</strong><br><span class="muted small">{{ $inWords }}</span></td>
        <td class="figure">{{ Format::money($amount, $currency) }}</td>
    </tr></table>

    <table class="facts">
        <tr><td class="label">{{ __('Date of issue') }}</td><td>{{ Dates::day($issuedAt) }}</td></tr>
        @if (! empty($profile->fields['address']))
            <tr><td class="label">{{ __('Place of issue') }}</td><td>{{ $profile->fields['address'] }}</td></tr>
        @endif
        <tr><td class="label">{{ __('Due on') }}</td><td><strong>{{ Dates::day($due) }}</strong></td></tr>
        <tr><td class="label">{{ __('To the order of') }}</td><td><strong>{{ $shop }}</strong></td></tr>
    </table>

    <p style="margin-top: 12pt">{{ __('I, :name, promise to pay without condition to the order of :shop the sum of :amount (:words) on :date.', [
        'name' => $customer->name,
        'shop' => $shop,
        'amount' => Format::money($amount, $currency),
        'words' => $inWords,
        'date' => Dates::day($due),
    ]) }}</p>

    <h2>{{ __('The drawer') }}</h2>
    <table class="facts">
        <tr>
            <td class="label">{{ __('Name') }}</td>
            <td>
                <strong>{{ $customer->name }}</strong>
                @if ($customer->phone)<br><span class="muted">{{ $customer->phone }}</span>@endif
                @if ($ids && $customer->maskedNationalId())<br><span class="muted">{{ __('ID number') }} {{ $customer->maskedNationalId() }}</span>@endif
                @if ($customer->address)<br><span class="muted">{{ $customer->address }}</span>@endif
            </td>
        </tr>
        <tr>
            <td class="label">{{ __('Signature') }}</td>
            <td>
                @if ($customerSignature)
                    <img src="{{ $customerSignature->dataUri() }}" style="height: 40pt">
                @else
                    <br><br><span class="muted">____________________________</span>
                @endif
            </td>
        </tr>
    </table>

    @if ($guarantors->isNotEmpty())
        <h2>{{ __('Guarantors') }}</h2>
        <p class="muted small">{{ __('Each guarantor below guarantees the payment of this note.') }}</p>
        <table class="facts">
            @foreach ($guarantors as $guarantor)
                <tr>
                    <td class="label">{{ $guarantor->name }}</td>
                    <td>
                        <span class="muted">{{ $guarantor->phone }}</span>
                        @if ($ids && $guarantor->maskedNationalId())<br><span class="muted">{{ __('ID number') }} {{ $guarantor->maskedNationalId() }}</span>@endif
                        <br><br><span class="muted">____________________________</span>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @include('documents._closing')
@endsection
