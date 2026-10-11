{{-- Win Plan PP6: settle early. The payoff is in front of the person before anything is written. --}}
@php
    use App\Support\Format;
    use App\Support\Money;
    use App\Support\PaymentMethods;
    use Illuminate\Support\Str;

    $money = fn (string $amount) => Format::money($amount, $currency);
@endphp
<x-layouts.app :title="__('Settle early')" section="contracts">
    <x-page-head :title="__('Settle early')">
        <x-slot:subtitle>
            <a class="link" href="{{ route('app.contracts.show', $contract) }}" dir="ltr">{{ $contract->reference() }}</a>
            {{ $contract->customer->name }}
        </x-slot:subtitle>
    </x-page-head>

    <div class="detail-layout">
        <section class="card card-pad stack settle-quote" aria-labelledby="payoff-title">
            <p class="figure-label" id="payoff-title">{{ __('To settle today') }}</p>
            <p class="settle-payoff money">{{ $money($quote->payoff) }}</p>
            @if (Money::isPositive($quote->rebate))
                <p class="settle-saves">{{ __('The customer saves :amount.', ['amount' => $money($quote->rebate)]) }}</p>
            @endif
            <dl class="facts">
                <div><dt>{{ __('Still owed') }}</dt><dd class="money">{{ $money($quote->remaining) }}</dd></div>
                <div><dt>{{ __('Profit already earned') }}</dt><dd class="money">{{ $money($quote->earnedProfit) }}</dd></div>
                <div><dt>{{ __('Profit not yet earned') }}</dt><dd class="money">{{ $money($quote->unearnedProfit) }}</dd></div>
                <div><dt>{{ __('Early settlement discount') }}</dt><dd class="money">−{{ $money($quote->rebate) }}</dd></div>
            </dl>
            @if ($quote->capped)
                <p class="alert alert-warn" role="status">{{ __('A discount cannot be more than the profit not yet earned, so it was kept to :amount.', ['amount' => $money($quote->rebate)]) }}</p>
            @endif

            <form method="GET" action="{{ route('app.contracts.settle.show', $contract) }}" class="form settle-rebate">
                <div class="field">
                    <label for="rebate-type">{{ __('Discount') }}</label>
                    <select id="rebate-type" name="rebate_type" data-autosubmit>
                        <option value="none" @selected($quote->rebateType === 'none')>{{ __('No discount') }}</option>
                        <option value="percent" @selected($quote->rebateType === 'percent')>{{ __('A percent of the profit not yet earned') }}</option>
                        <option value="fixed" @selected($quote->rebateType === 'fixed')>{{ __('An amount') }}</option>
                    </select>
                </div>
                <x-field name="rebate_value" :label="__('How much')" :value="$quote->rebateType === 'none' ? '' : rtrim(rtrim($quote->rebateValue, '0'), '.')" inputmode="decimal" dir="ltr" autocomplete="off" />
                <button class="btn btn-quiet btn-sm" type="submit">{{ __('Show the payoff') }}</button>
            </form>
        </section>

        <section class="card card-pad stack" aria-labelledby="confirm-title">
            <h2 id="confirm-title" class="card-title">{{ __('Take the payoff and settle') }}</h2>
            <p class="muted">{{ __('One payment of the payoff is recorded, what the discount covers is waived, and the contract is settled. Voiding that payment brings everything back.') }}</p>
            <form method="POST" action="{{ route('app.contracts.settle.store', $contract) }}" class="form">
                @csrf
                <input type="hidden" name="rebate_type" value="{{ $quote->rebateType }}">
                <input type="hidden" name="rebate_value" value="{{ $quote->rebateValue }}">
                <input type="hidden" name="payoff" value="{{ $quote->payoff }}">
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', 'web-settle-'.Str::uuid()) }}">
                @error('payoff')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror
                @error('contract')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror
                <div class="field">
                    <label for="settle-method">{{ __('How was it paid?') }}</label>
                    <select id="settle-method" name="method" required>
                        @foreach (PaymentMethods::labels() as $method => $name)
                            <option value="{{ $method }}" @selected(old('method', 'cash') === $method)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-field name="note" :label="__('Note (optional)')" autocomplete="off" maxlength="500" />
                <button class="btn">{{ __('Settle for :amount', ['amount' => $money($quote->payoff)]) }}</button>
            </form>
        </section>
    </div>
</x-layouts.app>
