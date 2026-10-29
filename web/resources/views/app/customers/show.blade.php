@php
    use App\Domain\Schedule\Frequencies;
    use App\Models\Installment;
    use App\Support\Format;
    use App\Support\Money;
    use App\Support\PaymentMethods;
    use Illuminate\Support\Str;
    use App\Support\Dates;

    $statusTone = ['active' => 'badge-info', 'late' => 'badge-bad', 'settled' => 'badge-ok', 'cancelled' => 'badge-bad'];
    $statusLabel = ['active' => __('Active'), 'late' => __('Late'), 'settled' => __('Settled'), 'cancelled' => __('Cancelled')];
    $tagLabel = ['advance' => __('Advance'), 'refund' => __('Refund'), 'early_discount' => __('Early-payment discount'), 'unpaid' => __('Unpaid')];
    $whatsapp = $customer->whatsappUrl();
    $stateOf = fn ($contract) => $contract->status === 'active' && Money::isPositive($overdue[$contract->id] ?? '0') ? 'late' : $contract->status;
    $pick = fn ($contract, array $more = []) => route('app.customers.show', [$customer, 'contract' => $contract->id, ...$more]);
    $describe = fn (array $row): string => match ($row['kind']) {
        'sale' => $chosen->title !== null ? __('Sold: :what', ['what' => $chosen->title]) : __('Sale on the contract'),
        'down_payment' => __('Down payment'),
        'payment' => __('Payment').' ('.PaymentMethods::label($row['transaction']->method).')',
        'reversal' => __('Payment voided'),
        'charge' => __('They took'),
        'charge_reversal' => __('Taken back off the balance'),
        'cancelled' => __('Contract cancelled'),
        'early_rebate' => $row['credit'] !== null ? __('Early settlement discount') : __('Early settlement discount voided'),
        'late_fee' => __('Late fee'),
        'late_fee_waived' => __('Late fee waived'),
        default => $row['kind'],
    };
    $current = $contracts->whereNull('archived_at');
    $archived = $contracts->whereNotNull('archived_at');
    $line = 0;
@endphp
<x-layouts.app :title="$customer->name" section="customers">
    <x-page-head :title="$customer->name">
        <x-slot:subtitle><a class="link" href="{{ route('app.customers.index') }}">{{ __('All customers') }}</a></x-slot:subtitle>
        <x-document-menu id="customer-statement" :action="route('app.customers.statement', $customer)" :label="__('Statement')" :period="true" :sections="['overdue', 'signature']" :filters="$customer->contracts()" />
        @can('update', $customer)
            <form method="POST" action="{{ route($customer->pinned_at ? 'app.customers.unpin' : 'app.customers.pin', $customer) }}">
                @csrf
                @if ($customer->pinned_at) @method('DELETE') @endif
                <button class="btn btn-quiet" type="submit" aria-pressed="{{ $customer->pinned_at ? 'true' : 'false' }}"><x-icon name="pin" :size="18" /> {{ $customer->pinned_at ? __('Unpin') : __('Pin to the top') }}</button>
            </form>
        @endcan
    </x-page-head>

    <div class="detail-layout desk">
        <section class="card card-pad stack" aria-labelledby="contact-title">
            <h2 id="contact-title" class="card-title">{{ __('Contact') }}</h2>

            <div class="contact-actions">
                <a class="btn btn-quiet btn-sm" href="{{ $customer->telUrl() }}"><x-icon name="message" :size="16" /> {{ __('Call') }}</a>
                @if ($whatsapp)<a class="btn btn-quiet btn-sm" href="{{ $whatsapp }}" rel="noopener" target="_blank"><x-icon name="send" :size="16" /> {{ __('WhatsApp') }}</a>@endif
                @if ($customer->email)<a class="btn btn-quiet btn-sm" href="mailto:{{ $customer->email }}"><x-icon name="send" :size="16" /> {{ __('Email') }}</a>@endif
            </div>

            @if ($customer->tags->isNotEmpty())
                <p class="tag-list">@foreach ($customer->tags as $customerTag)<span class="tag-chip" data-colour="{{ $customerTag->colour }}">{{ $customerTag->name }}</span>@endforeach</p>
            @endif
            <dl class="facts">
                <div><dt>{{ __('Phone') }}</dt><dd class="money" dir="ltr">{{ $customer->phone }}</dd></div>
                @if ($customer->phone_secondary)<div><dt>{{ __('Second phone') }}</dt><dd class="money" dir="ltr">{{ $customer->phone_secondary }}</dd></div>@endif
                @if ($customer->email)<div><dt>{{ __('Email') }}</dt><dd dir="ltr">{{ $customer->email }}</dd></div>@endif
                @if ($customer->job)<div><dt>{{ __('Job or employer') }}</dt><dd>{{ $customer->job }}</dd></div>@endif
                @if ($chosen?->investor)<div><dt>{{ __('Funded by') }}</dt><dd>{{ $chosen->investor->name }}</dd></div>@endif
                @if ($customer->address)<div><dt>{{ __('Address') }}</dt><dd>{{ $customer->address }}</dd></div>@endif
                @if ($customer->maskedNationalId())<div><dt>{{ __('National ID') }}</dt><dd class="money" dir="ltr">{{ $customer->maskedNationalId() }}</dd></div>@endif
                @if ($customer->notes)<div><dt>{{ __('Notes') }}</dt><dd class="prewrap">{{ $customer->notes }}</dd></div>@endif
                <div><dt>{{ __('Customer since') }}</dt><dd><x-day :date="$customer->created_at, 'j F Y'" /></dd></div>
            </dl>

            @can('delete', $customer)
                <details class="confirm">
                    <summary class="btn btn-ghost btn-sm danger-text">{{ __('Delete customer') }}</summary>
                    <form method="POST" action="{{ route('app.customers.destroy', $customer) }}" class="stack">
                        @csrf @method('DELETE')
                        <p>{{ __('This removes :name from your lists and frees a place on your plan. Their contracts and payments stay in your records.', ['name' => $customer->name]) }}</p>
                        @error('customer')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        <button class="btn btn-danger btn-sm">{{ __('Delete customer') }}</button>
                    </form>
                </details>
            @endcan
            @error('customer')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </section>

        <div class="stack desk-main">
            @if ($chosen === null && $contracts->isNotEmpty())
                <section class="card" aria-labelledby="contracts-title">
                    <div class="card-head"><h2 id="contracts-title">{{ __('Contracts') }}</h2></div>
                    <p class="muted archived-only">{{ __('Every contract for this customer is archived.') }}</p>
                    <details class="archived-contracts">
                        <summary class="link"><x-icon name="archive" :size="16" /> {{ __('Archived contracts: :count', ['count' => $archived->count()]) }}</summary>
                        <ul class="archived-list">
                            @foreach ($archived as $contract)
                                <li>
                                    <a class="cell-link" data-contract="{{ $contract->id }}" href="{{ $pick($contract) }}">{{ $contract->reference() }}</a>
                                    <span class="badge">{{ $statusLabel[$contract->status] ?? $contract->status }}</span>
                                    <a class="link" href="{{ url('/app/contracts/'.$contract->id) }}">{{ __('Full contract page') }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                </section>
            @elseif ($chosen === null)
                <section class="card" aria-labelledby="contracts-title">
                    <div class="card-head"><h2 id="contracts-title">{{ __('Contracts') }}</h2></div>
                    <div class="empty">
                        <span class="empty-icon"><x-icon name="fileText" :size="24" /></span>
                        <h3>{{ __('No contracts yet') }}</h3>
                        <p>{{ __('Open a contract to set up this customer’s instalment plan.') }}</p>
                        @can('create', \App\Models\Contract::class)
                            <a class="btn" href="{{ url('/app/contracts/create') }}?customer={{ $customer->id }}">{{ __('Open a contract') }}</a>
                        @endcan
                    </div>
                </section>
            @else
                {{-- The day's work in one row: take a payment, print, change, start another, put a finished one away. --}}
                <nav class="desk-actions" aria-label="{{ __('Actions') }}">
                    @if ($canPay)
                        <a class="btn" href="{{ $pick($chosen, ['pay' => 'next']) }}#pay"><x-icon name="wallet" :size="18" /> {{ __('Record a payment') }}</a>
                    @endif
                    <x-document-menu id="contract-statement" :action="route('app.contracts.statement', $chosen)" :label="__('Contract statement')" :sections="['overdue', 'schedule', 'signature', 'cost']" />
                    @can('update', $customer)
                        <a class="btn btn-quiet" href="{{ route('app.customers.edit', $customer) }}"><x-icon name="pencil" :size="18" /> {{ __('Edit') }}</a>
                    @endcan
                    @can('create', \App\Models\Contract::class)
                        <a class="btn btn-quiet" href="{{ url('/app/contracts/create') }}?customer={{ $customer->id }}"><x-icon name="plus" :size="18" /> {{ __('New contract') }}</a>
                    @endcan
                    @if ($canArchive)
                        <form method="POST" action="{{ route('app.contracts.archive', $chosen) }}">
                            @csrf
                            <button class="btn btn-quiet" type="submit"><x-icon name="archive" :size="18" /> {{ __('Archive') }}</button>
                        </form>
                    @endif
                    @if ($canPayAcross ?? false)
                        <a class="btn btn-quiet" href="{{ route('app.customers.payment.show', $customer) }}"><x-icon name="wallet" :size="18" /> {{ __('Take a payment across contracts') }}</a>
                    @endif
                </nav>

                <section class="card desk-account" aria-labelledby="account-title">
                    <div class="card-head desk-account-head">
                        <h2 id="account-title">{{ __('Transactions') }}</h2>
                        <a class="link" href="{{ url('/app/contracts/'.$chosen->id) }}">{{ __('Full contract page') }} <x-icon name="arrowUpRight" :size="14" /></a>
                    </div>

                    <ul class="contract-tabs" aria-label="{{ __('Contracts') }}">
                        @foreach ($current->isNotEmpty() ? $current : collect([$chosen]) as $contract)
                            @php $state = $stateOf($contract); @endphp
                            <li>
                                <a class="contract-tab" data-contract="{{ $contract->id }}" {!! $contract->is($chosen) ? 'aria-current="true"' : '' !!} href="{{ $pick($contract) }}">
                                    <span class="contract-tab-ref">{{ $contract->reference() }}</span>
                                    <span class="badge {{ $statusTone[$state] ?? '' }}">{{ $statusLabel[$state] ?? $state }}</span>
                                    <span class="contract-tab-owed money">{{ Format::money($contract->status === 'cancelled' ? '0' : ($owed[$contract->id] ?? '0'), $currency) }}</span>
                                </a>
                                <a class="contract-tab-open" href="{{ url('/app/contracts/'.$contract->id) }}" title="{{ __('Full contract page') }}"><x-icon name="arrowUpRight" :size="16" :label="__('Full contract page').': '.$contract->reference()" /></a>
                            </li>
                        @endforeach
                    </ul>
                    <p class="desk-account-meta">
                        @if ($chosen->title)<span>{{ $chosen->title }}</span>@endif
                        @if (! $chosen->isOpen())<span>{{ Frequencies::label($chosen->frequency) }}</span>@endif
                        <span>{{ __('Since :date', ['date' => Dates::day($chosen->start_date)]) }}</span>
                        @if ($currency)<span>{{ __('Amounts in :currency', ['currency' => $currency]) }}</span>@endif
                    </p>

                    @if ($canPay)
                        <details class="pay-panel" id="pay" {{ $payOpen ? 'open' : '' }}>
                            <summary class="pay-panel-head"><x-icon name="wallet" :size="18" /> {{ __('Record a payment on :contract', ['contract' => $chosen->reference()]) }}</summary>
                            <form method="POST" action="{{ route('app.contracts.payments.store', $chosen) }}" class="form pay-form" novalidate>
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', 'web-'.Str::uuid()) }}">
                                <input type="hidden" name="return_to" value="customer">
                                @error('contract')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror
                                @error('idempotency_key')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror

                                @if ($payFor !== null)
                                    <x-field name="amount" :label="__('Amount received')" :value="Money::add($payFor->remaining(), '0', 2)" inputmode="decimal" autocomplete="off" dir="ltr" required
                                             :hint="__('Instalment :number: :amount, due :date.', ['number' => $payFor->number, 'amount' => Format::money($payFor->remaining(), $currency), 'date' => Dates::day($payFor->due_date)])" />
                                @elseif (Money::isPositive($account->feesOwed))
                                    <x-field name="amount" :label="__('Amount received')" :value="Money::add($account->feesOwed, '0', 2)" inputmode="decimal" autocomplete="off" dir="ltr" required
                                             :hint="__('Every instalment is paid; :amount in late fees is still owed.', ['amount' => Format::money($account->feesOwed, $currency)])" />
                                @else
                                    <x-field name="amount" :label="__('Amount received')" inputmode="decimal" autocomplete="off" dir="ltr" required />
                                @endif

                                <div class="field">
                                    <label for="f-method">{{ __('How was it paid?') }}</label>
                                    <select id="f-method" name="method" required @error('method') aria-invalid="true" @enderror>
                                        @foreach (PaymentMethods::labels() as $method => $name)
                                            <option value="{{ $method }}" @selected(old('method', 'cash') === $method)>{{ $name }}</option>
                                        @endforeach
                                    </select>
                                    @error('method')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                </div>

                                <x-field name="paid_at" type="date" :label="__('Date received (optional)')" :hint="__('Leave empty if it was received today.')" />
                                <x-hijri-input for="paid_at" />
                                @if ($canTarget && ! $chosen->isOpen())
                                    <div class="field">
                                        <label for="f-apply">{{ __('Pay towards') }}</label>
                                        <select id="f-apply" name="apply_to" @error('apply_to') aria-invalid="true" @enderror>
                                            <option value="">{{ __('The oldest instalment first') }}</option>
                                            @foreach ($account->installments->reject(fn ($i) => in_array($i->status, Installment::CLOSED, true)) as $open)
                                                <option value="{{ $open->id }}" @selected(old('apply_to', $payFor?->id) === $open->id)>{{ Dates::day($open->due_date) }}, {{ Format::money($open->remaining(), $currency) }}</option>
                                            @endforeach
                                        </select>
                                        @error('apply_to')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                    </div>
                                @endif
                                @include('app.contracts._tag', ['id' => 'f-pay-tag'])
                                <x-field name="note" :label="__('Note (optional)')" autocomplete="off" :hint="__('A receipt number or a reference, for your records.')" />

                                <button class="btn">{{ __('Record payment') }}</button>
                            </form>
                        </details>
                    @endif

                    <div class="table-wrap">
                        <table class="table table-stack ledger" data-ledger>
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">{{ __('Date') }}</th>
                                    <th scope="col">{{ __('Details') }}</th>
                                    <th scope="col" class="num">{{ __('Added') }}</th>
                                    <th scope="col" class="num">{{ __('Paid') }}</th>
                                    <th scope="col" class="num">{{ __('Running balance') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($account->rows as $row)
                                    @if ($row['unpaid'] !== null)
                                        @php $due = $row['unpaid']; @endphp
                                        <tr class="ledger-unpaid" data-unpaid="{{ $due->id }}">
                                            <td colspan="6">
                                                <div class="ledger-unpaid-row">
                                                    <span class="ledger-unpaid-what"><x-icon name="alert" :size="16" /> {{ __('Instalment :number, due :date: :amount', ['number' => $due->number, 'date' => Dates::day($due->due_date), 'amount' => Format::money($due->remaining(), $currency)]) }}</span>
                                                    @if ($canPay)
                                                        <a class="ledger-pay" href="{{ $pick($chosen, ['pay' => $due->id]) }}#pay">{{ __('Unpaid. Record a payment') }}</a>
                                                    @else
                                                        <span class="badge badge-bad">{{ __('Unpaid') }}</span>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @else
                                        @php $transaction = $row['transaction']; @endphp
                                        <tr data-line="{{ $row['kind'] }}">
                                            <td data-label="#" class="money ledger-no">{{ ++$line }}</td>
                                            <td data-label="{{ __('Date') }}" class="money ledger-date"><x-day :date="$row['date']" /></td>
                                            <td data-label="{{ __('Details') }}" class="ledger-details">
                                                <span class="line-title">
                                                    {{ $describe($row) }}
                                                    @if ($transaction?->tag)<span class="badge">{{ $tagLabel[$transaction->tag] ?? $transaction->tag }}</span>@endif
                                                </span>
                                                @if ($transaction?->note && $row['kind'] !== 'reversal')<span class="cell-sub">{{ $transaction->note }}</span>@endif
                                            </td>
                                            <td data-label="{{ __('Added') }}" class="num money ledger-added">{{ $row['debit'] === null ? '' : Format::amount($row['debit']) }}</td>
                                            <td data-label="{{ __('Paid') }}" class="num money ledger-paid">{{ $row['credit'] === null ? '' : Format::amount($row['credit']) }}</td>
                                            <td data-label="{{ __('Running balance') }}" class="num money ledger-balance">{{ Format::amount($row['balance']) }}</td>
                                        </tr>
                                    @endif
                                @empty
                                    <tr><td colspan="6" class="muted">{{ __('Nothing recorded on this contract yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Where it stands, always in sight under the lines. --}}
                    <dl class="desk-totals" aria-label="{{ __('Where this contract stands') }}">
                        @if ($account->instalment !== null)
                            <div data-total="instalment"><dt>{{ __('Instalment') }}</dt><dd class="money">{{ Format::money($account->instalment, $currency) }}</dd></div>
                            <div data-total="paid-count"><dt>{{ __('Instalments paid') }}</dt><dd class="money">{{ $account->paidCount }}</dd></div>
                            <div data-total="left-count"><dt>{{ __('Instalments left') }}</dt><dd class="money">{{ $account->leftCount }}</dd></div>
                        @endif
                        <div data-total="paid"><dt>{{ __('Paid') }}</dt><dd class="money">{{ Format::money($account->paid, $currency) }}</dd></div>
                        <div data-total="left"><dt>{{ __('Still owed') }}</dt><dd class="money">{{ Format::money($account->left, $currency) }}</dd></div>
                        <div data-total="late" @if (Money::isPositive($account->late)) data-tone="danger" @endif><dt>{{ __('Late') }}</dt><dd class="money">{{ Format::money($account->late, $currency) }}</dd></div>
                    </dl>
                </section>

                @if ($archived->isNotEmpty())
                    <details class="card archived-contracts" {{ $chosen->archived_at !== null ? 'open' : '' }}>
                        <summary class="link"><x-icon name="archive" :size="16" /> {{ __('Archived contracts: :count', ['count' => $archived->count()]) }}</summary>
                        <ul class="archived-list">
                            @foreach ($archived as $contract)
                                <li>
                                    <a class="cell-link" data-contract="{{ $contract->id }}" {!! $contract->is($chosen) ? 'aria-current="true"' : '' !!} href="{{ $pick($contract) }}">{{ $contract->reference() }}</a>
                                    <span class="badge">{{ $statusLabel[$contract->status] ?? $contract->status }}</span>
                                    <a class="link" href="{{ url('/app/contracts/'.$contract->id) }}">{{ __('Full contract page') }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            @endif
        </div>
    </div>
</x-layouts.app>
