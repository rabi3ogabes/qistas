{{-- Win Plan PP6: one payment across all of a customer's contracts, the oldest instalment due first. --}}
@php
    use App\Support\Format;
    use App\Support\Money;
    use App\Support\PaymentMethods;
    use Illuminate\Support\Str;
    use App\Support\Dates;

    $owed = collect($progress)->reduce(fn (string $sum, array $row) => Money::add($sum, (string) $row['owed'], 2), '0.00');
@endphp
<x-layouts.app :title="__('Take a payment across contracts')" section="customers">
    <x-page-head :title="__('Take a payment across contracts')">
        <x-slot:subtitle><a class="link" href="{{ route('app.customers.show', $customer) }}">{{ $customer->name }}</a></x-slot:subtitle>
    </x-page-head>

    <div class="detail-layout">
        <section class="card card-pad stack" aria-labelledby="across-title">
            <h2 id="across-title" class="card-title">{{ __('One payment, the oldest instalment first') }}</h2>
            <p class="muted">{{ __('It pays whatever falls due first, whichever contract it belongs to. Each contract gets its own line in the ledger.') }}</p>
            <form method="POST" action="{{ route('app.customers.payment.store', $customer) }}" class="form">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', 'web-batch-'.Str::uuid()) }}">
                <x-field name="amount" :label="__('Amount received')" :value="$suggested" inputmode="decimal" dir="ltr" autocomplete="off" required :hint="__('Still owed on all contracts: :amount', ['amount' => Format::money($owed, $currency)])" />
                <div class="field">
                    <label for="across-method">{{ __('How was it paid?') }}</label>
                    <select id="across-method" name="method" required>
                        @foreach (PaymentMethods::labels() as $method => $name)
                            <option value="{{ $method }}" @selected(old('method', 'cash') === $method)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-field name="paid_at" type="date" :label="__('Date received (optional)')" :hint="__('Leave empty if it was received today.')" />
                <x-hijri-input for="paid_at" />
                <x-field name="note" :label="__('Note (optional)')" autocomplete="off" />
                <button class="btn">{{ __('Record payment') }}</button>
            </form>
        </section>

        <section class="card" aria-labelledby="running-title">
            <div class="card-head"><h2 id="running-title">{{ __('Running contracts') }}</h2></div>
            <ul class="rows">
                @foreach ($contracts as $contract)
                    <li>
                        <a class="row-main" href="{{ route('app.contracts.show', $contract) }}">
                            <span class="row-title" dir="ltr">{{ $contract->reference() }}</span>
                            @if (($progress[$contract->id]['next'] ?? null) !== null)
                                <span class="row-sub">{{ __('Next due :date', ['date' => Dates::day($progress[$contract->id]['next']->due_date)]) }}</span>
                            @endif
                        </a>
                        <span class="money row-amount">{{ Format::money((string) $progress[$contract->id]['owed'], $currency) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</x-layouts.app>
