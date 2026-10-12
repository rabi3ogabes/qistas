{{-- The contract as it was agreed: the parties, the guarantors, what was sold, the price and the plan, the schedule, the
     customer's signature. ID numbers masked, and only for those who see them. --}}
@extends('documents.layout')

@php
    use App\Domain\Schedule\Frequencies;
    use App\Support\Format;
    use App\Support\Money;
    use App\Support\Dates;

    $money = fn (string $amount): string => Format::money($amount, $currency);
    $ids = $options->seesIdentities();
@endphp

@section('meta')
    {{ $options->word('contract') }} {{ $contract->reference() }}<br>{{ Dates::day($issuedAt) }}
@endsection

@section('content')
    <h1>{{ __('Instalment contract') }}</h1>
    <p class="muted">{{ $options->word('contract') }} {{ $contract->reference() }} · {{ __('Contract date') }} {{ Dates::day($contract->start_date) }}</p>

    <h2>{{ __('The parties') }}</h2>
    <table class="facts">
        <tr><td class="label">{{ __('Seller') }}</td><td><strong>{{ $shop }}</strong></td></tr>
        <tr>
            <td class="label">{{ __('Buyer') }}</td>
            <td>
                <strong>{{ $customer->name }}</strong>
                @if ($customer->phone)<br><span class="muted">{{ $customer->phone }}</span>@endif
                @if ($ids && $customer->maskedNationalId())<br><span class="muted">{{ __('ID number') }} {{ $customer->maskedNationalId() }}</span>@endif
                @if ($customer->address)<br><span class="muted">{{ $customer->address }}</span>@endif
            </td>
        </tr>
        @foreach ($guarantors as $guarantor)
            <tr>
                <td class="label">{{ __('Guarantor') }}</td>
                <td>
                    <strong>{{ $guarantor->name }}</strong>@if ($guarantor->relation) <span class="muted">({{ $guarantor->relation }})</span>@endif
                    <br><span class="muted">{{ $guarantor->phone }}</span>
                    @if ($ids && $guarantor->maskedNationalId())<br><span class="muted">{{ __('ID number') }} {{ $guarantor->maskedNationalId() }}</span>@endif
                </td>
            </tr>
        @endforeach
    </table>

    @if ($contract->items->isNotEmpty())
        <h2>{{ __('What was sold') }}</h2>
        <table class="grid">
            <thead><tr><th>{{ __('Item') }}</th><th>{{ __('Serial or IMEI') }}</th><th class="num">{{ __('Quantity') }}</th><th class="num">{{ __('Price') }}</th></tr></thead>
            <tbody>
                @foreach ($contract->items as $item)
                    <tr>
                        <td>{{ $item->name }}</td>
                        <td>{{ $item->serial ?? '' }}</td>
                        <td class="num">{{ $item->quantity }}</td>
                        <td class="num">{{ $item->price === null ? '' : Format::amount($item->price) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('The price and the plan') }}</h2>
    <table class="facts">
        <tr><td class="label">{{ __('Price') }}</td><td>{{ $money($contract->principal) }}</td></tr>
        @if (Money::isPositive($contract->discount_amount))
            <tr><td class="label">{{ __('Discount') }}</td><td>{{ $money(Money::sub('0', $contract->discount_amount)) }}</td></tr>
        @endif
        @if (Money::isPositive($contract->down_payment))
            <tr><td class="label">{{ __('Down payment') }}</td><td>{{ $money($contract->down_payment) }}</td></tr>
        @endif
        @if (Money::isPositive($contract->markup_amount))
            <tr><td class="label">{{ __('Markup') }}</td><td>{{ $money($contract->markup_amount) }}</td></tr>
        @endif
        <tr><td class="label">{{ __('Total to repay') }}</td><td><strong>{{ $money($contract->total) }}</strong></td></tr>
        @if ($contract->installment_count > 0)
            <tr><td class="label">{{ __('Instalments') }}</td><td>{{ $contract->installment_count }} · {{ Frequencies::label($contract->frequency) }}</td></tr>
        @endif
    </table>

    @if ($schedule->isNotEmpty())
        <h2>{{ __('Schedule') }}</h2>
        <table class="grid">
            <thead><tr><th>#</th><th>{{ __('Due date') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
            <tbody>
                @foreach ($schedule as $installment)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ Dates::day($installment->due_date) }}</td>
                        <td class="num">{{ Format::amount($installment->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('What the buyer agrees to') }}</h2>
    <p>{{ __('The buyer has received what is listed above and will pay each instalment on its due date. Every payment is given a receipt.') }}</p>
    @if ($guarantors->isNotEmpty())
        <p>{{ __('The guarantors named above stand behind what the buyer owes under this contract.') }}</p>
    @endif

    <table class="facts" style="margin-top: 14pt">
        <tr>
            <td class="label">{{ __('The buyer’s signature') }}</td>
            <td>
                @if ($customerSignature)
                    <img src="{{ $customerSignature->dataUri() }}" style="height: 40pt"><br>
                    <span class="muted small">{{ $customerSignature->method === 'link'
                        ? __('Signed on their own phone on :date', ['date' => Dates::day($customerSignature->signed_at)])
                        : __('Signed in person on :date', ['date' => Dates::day($customerSignature->signed_at)]) }}</span>
                @else
                    <br><br><span class="muted">____________________________</span>
                @endif
            </td>
        </tr>
    </table>

    @include('documents._closing')
@endsection
