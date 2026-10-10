@php
    use App\Domain\Investors\MainInvestor;
    use App\Models\Investor;
    use App\Models\Transaction;
    use App\Support\Format;
    use App\Support\Money;
    use App\Support\PaymentMethods;
    use Illuminate\Support\Str;

    $customer = $contract->customer;
    $running = $contract->status === 'active';
    $inCredit = Money::isNegative($balance);
    $overLimit = $contract->credit_limit !== null && Money::cmp($balance, $contract->credit_limit) > 0;
    $tagLabel = ['advance' => __('Advance'), 'refund' => __('Refund'), 'early_discount' => __('Early-payment discount'), 'unpaid' => __('Unpaid')];
    $statusLabel = ['active' => __('Active'), 'settled' => __('Settled'), 'cancelled' => __('Cancelled')];
@endphp
<x-layouts.app :title="$contract->reference()" section="contracts">
    <x-page-head :title="$contract->reference()">
        <x-slot:subtitle>
            <span class="badge badge-pro">{{ __('Open') }}</span>
            @if (! $running)<span class="badge">{{ $statusLabel[$contract->status] ?? $contract->status }}</span>@endif
            @if ($customer->trashed())
                {{ $customer->name }}
            @else
                <a class="link" href="{{ route('app.customers.show', $customer) }}">{{ $customer->name }}</a>
            @endif
            · <a class="link" href="{{ route('app.contracts.index') }}">{{ __('All contracts') }}</a>
        </x-slot:subtitle>
    </x-page-head>

    <section class="card summary summary-trio" aria-label="{{ __('Where this contract stands') }}">
        <div class="figure figure-lead" @if ($overLimit) data-tone="danger" @endif>
            <p class="figure-label">{{ $inCredit ? __('In credit') : __('Balance') }}</p>
            <p class="figure-value money">{{ Format::money($inCredit ? Money::sub('0', $balance, 2) : $balance, $currency) }}</p>
            <p class="figure-note">
                @if ($inCredit)
                    {{ __('They paid ahead; the next things they take come off this.') }}
                @elseif ($contract->credit_limit !== null)
                    {{ $overLimit ? __('Past the credit limit of :limit', ['limit' => Format::money($contract->credit_limit, $currency)]) : __('Credit limit: :limit', ['limit' => Format::money($contract->credit_limit, $currency)]) }}
                @else
                    {{ __('What they took, less what they paid') }}
                @endif
            </p>
        </div>
        <div class="figure">
            <p class="figure-label">{{ __('They took') }}</p>
            <p class="figure-value money">{{ Format::money($took, $currency) }}</p>
        </div>
        <div class="figure">
            <p class="figure-label">{{ __('They paid') }}</p>
            <p class="figure-value money">{{ Format::money($paid, $currency) }}</p>
        </div>
    </section>

    <div class="detail-layout">
        <div class="stack">
            @if ($running)
                @can('create', Transaction::class)
                    <section class="card card-pad stack tab-entry" data-direction="took" aria-labelledby="took-title">
                        <h2 id="took-title" class="card-title"><span class="tab-arrow" aria-hidden="true">+</span> {{ __('They took') }}</h2>
                        @if ($canCharge)
                            <form method="POST" action="{{ route('app.contracts.charges.store', $contract) }}" class="form" novalidate>
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', 'web-'.Str::uuid()) }}">
                                <x-field name="amount" id="took-amount" bag="took" :label="__('Amount')" inputmode="decimal" autocomplete="off" dir="ltr" required :hint="__('Adds to what they owe.')" />
                                @include('app.contracts._tag', ['id' => 'took-tag', 'bag' => 'took'])
                                <x-field name="note" id="took-note" bag="took" :label="__('What they took (optional)')" autocomplete="off" maxlength="1000" />
                                <button class="btn">{{ __('Add to their balance') }}</button>
                            </form>
                        @else
                            <p class="muted">{{ __('Adding to an open balance is switched off for the moment. Payments still come off it.') }}</p>
                        @endif
                    </section>

                    <section class="card card-pad stack tab-entry" data-direction="paid" aria-labelledby="paid-title">
                        <h2 id="paid-title" class="card-title"><span class="tab-arrow" aria-hidden="true">−</span> {{ __('They paid') }}</h2>
                        <form method="POST" action="{{ route('app.contracts.payments.store', $contract) }}" class="form" novalidate>
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', 'web-'.Str::uuid()) }}">
                            <x-field name="amount" id="paid-amount" :label="__('Amount received')" inputmode="decimal" autocomplete="off" dir="ltr" required />
                            <div class="field">
                                <label for="f-method">{{ __('How was it paid?') }}</label>
                                <select id="f-method" name="method" required>
                                    @foreach (PaymentMethods::labels() as $method => $name)
                                        <option value="{{ $method }}" @selected(old('method', 'cash') === $method)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @include('app.contracts._tag', ['id' => 'paid-tag', 'bag' => 'default'])
                            <x-field name="note" id="paid-note" :label="__('Note (optional)')" autocomplete="off" maxlength="1000" />
                            <button class="btn btn-gold">{{ __('Take off their balance') }}</button>
                        </form>
                    </section>
                @endcan
            @endif

            <section class="card card-pad stack" aria-labelledby="terms-title">
                <h2 id="terms-title" class="card-title">{{ __('Terms') }}</h2>
                <dl class="facts">
                    <div><dt>{{ __('Customer') }}</dt><dd>{{ $customer->name }}</dd></div>
                    <div><dt>{{ __('Opened') }}</dt><dd class="money">{{ $contract->start_date->translatedFormat('j M Y') }}</dd></div>
                    <div><dt>{{ __('Credit limit') }}</dt><dd class="money">{{ $contract->credit_limit === null ? __('None') : Format::money($contract->credit_limit, $currency) }}</dd></div>
                    @if ($conversion)
                        <div><dt>{{ __('Became open') }}</dt><dd class="money">{{ $conversion->created_at->translatedFormat('j M Y') }}</dd></div>
                    @endif
                    @if ($contract->investor !== null && auth()->user()->can('viewAny', Investor::class))
                        <div><dt>{{ __('Funded by') }}</dt><dd><a class="link" href="{{ route('app.investors.show', $contract->investor) }}">{{ MainInvestor::displayName($contract->investor) }}</a></dd></div>
                    @endif
                    @if ($contract->notes)<div><dt>{{ __('Notes') }}</dt><dd class="prewrap">{{ $contract->notes }}</dd></div>@endif
                    @if ($contract->cancelled_at)<div><dt>{{ __('Cancelled on') }}</dt><dd class="money">{{ $contract->cancelled_at->translatedFormat('j M Y') }}</dd></div>@endif
                </dl>

                @if ($running)
                    @can('cancel', $contract)
                        <details class="confirm">
                            <summary class="btn btn-ghost btn-sm danger-text">{{ __('Cancel contract') }}</summary>
                            <form method="POST" action="{{ route('app.contracts.cancel', $contract) }}" class="form">
                                @csrf
                                <p>{{ __('The contract stops taking lines. Every line stays in your records.') }}</p>
                                <x-reason-field id="cancel-reason" />
                                <button class="btn btn-danger btn-sm">{{ __('Cancel this contract') }}</button>
                            </form>
                        </details>
                    @endcan
                @endif
                @error('contract')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </section>
        </div>

        <div class="stack">
            <section class="card" aria-labelledby="ledger-title">
                <div class="card-head"><h2 id="ledger-title">{{ __('Their account') }}</h2><span class="row-sub">{{ __('Newest first') }}</span></div>
                @if ($lines->isEmpty())
                    <div class="empty">
                        <span class="empty-icon"><x-icon name="wallet" :size="24" /></span>
                        <h3>{{ __('Nothing on the account yet') }}</h3>
                        <p>{{ __('What they take and what they pay appear here, with the balance after each line.') }}</p>
                    </div>
                @else
                    <div class="table-wrap">
                        <table class="table table-stack tab-ledger">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Date') }}</th>
                                    <th scope="col">{{ __('Details') }}</th>
                                    <th scope="col" class="num">{{ __('Amount') }}</th>
                                    <th scope="col" class="num">{{ __('Balance') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lines as $line)
                                    @php
                                        $isCharge = in_array($line->type, Transaction::CHARGES, true);
                                        $isReversal = in_array($line->type, ['reversal', 'charge_reversal'], true);
                                        $voided = isset($reversed[$line->id]);
                                        $after = $balances[$line->id] ?? null;
                                        $title = match ($line->type) {
                                            'charge' => __('They took'),
                                            'charge_reversal' => __('Taken back off the balance'),
                                            'reversal' => __('Payment voided'),
                                            'down_payment' => __('Down payment'),
                                            default => __('They paid'),
                                        };
                                    @endphp
                                    <tr data-line="{{ $isCharge ? 'took' : 'paid' }}" @if ($after === null) class="voided-row" @endif>
                                        <td data-label="{{ __('Date') }}" class="money">{{ $line->paid_at->translatedFormat('j M Y') }}</td>
                                        <td data-label="{{ __('Details') }}">
                                            <span class="line-title">
                                                {{ $title }}
                                                @if ($line->tag)<span class="badge">{{ $tagLabel[$line->tag] ?? $line->tag }}</span>@endif
                                                @if ($voided)<span class="badge badge-bad">{{ __('Voided') }}</span>@endif
                                                @if ($after === null)<span class="badge">{{ __('Before it became open') }}</span>@endif
                                            </span>
                                            @if ($line->note && ! $isReversal)<span class="cell-sub">{{ $line->note }}</span>@endif
                                            @include('app.payments._who', ['line' => $line])
                                            @if (! $voided && ! $isReversal && $after !== null && in_array($line->type, ['payment', 'charge'], true))
                                                @can('void', $line)
                                                    <details class="confirm confirm-inline">
                                                        <summary class="link">{{ $isCharge ? __('Void this line') : __('Void this payment') }}</summary>
                                                        <form method="POST" action="{{ route('app.payments.void', $line) }}" class="form">
                                                            @csrf
                                                            <p>{{ __('A reversal is added to the account; nothing is deleted.') }}</p>
                                                            <x-reason-field :id="'void-reason-'.$line->id" />
                                                            <button class="btn btn-danger btn-sm">{{ __('Void') }}</button>
                                                        </form>
                                                    </details>
                                                @endcan
                                            @endif
                                        </td>
                                        <td data-label="{{ __('Amount') }}" @class(['num', 'money', 'voided' => $voided]) data-sign="{{ $isCharge === Money::isPositive($line->amount) ? 'took' : 'paid' }}">
                                            {{ $isCharge === Money::isPositive($line->amount) ? '+' : '−' }}{{ Format::money(Money::isNegative($line->amount) ? Money::sub('0', $line->amount, 2) : $line->amount, $currency) }}
                                        </td>
                                        <td data-label="{{ __('Balance') }}" class="num money">{{ $after === null ? '—' : Format::money($after, $currency) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @if ($installments->isNotEmpty())
                <details class="card card-pad confirm">
                    <summary class="card-title">{{ __('The schedule before it became open') }}</summary>
                    <ul class="rows">
                        @foreach ($installments as $installment)
                            <li>
                                <span class="row-main"><span class="row-title money">{{ $installment->due_date->translatedFormat('j M Y') }}</span><span class="row-sub">{{ $installment->status === 'paid' ? __('Paid') : __('Superseded') }}</span></span>
                                <span class="row-amount money">{{ Format::money($installment->amount, $currency) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
    </div>
</x-layouts.app>
