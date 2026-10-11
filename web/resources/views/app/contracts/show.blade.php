@php
    use App\Domain\Investors\MainInvestor;
    use App\Domain\Schedule\Frequencies;
    use App\Models\Investor;
    use App\Models\Transaction;
    use App\Support\Format;
    use App\Support\Money;
    use App\Support\PaymentMethods;
    use Illuminate\Support\Str;

    $customer = $contract->customer;
    $late = $contract->status === 'active' && $next?->isOverdue();
    $state = $late ? 'late' : $contract->status;
    $statusTone = ['active' => 'badge-info', 'late' => 'badge-bad', 'settled' => 'badge-ok', 'cancelled' => ''];
    $statusLabel = ['active' => __('Active'), 'late' => __('Late'), 'settled' => __('Settled'), 'cancelled' => __('Cancelled')];
    $installmentLabel = ['paid' => __('Paid'), 'overdue' => __('Overdue'), 'partial' => __('Partly paid'), 'upcoming' => __('Upcoming'), 'superseded' => __('Superseded'), 'waived' => __('Waived')];
    $installmentTone = ['paid' => 'badge-ok', 'overdue' => 'badge-bad', 'partial' => 'badge-warn', 'upcoming' => '', 'superseded' => '', 'waived' => ''];
    $amendmentLabel = ['reschedule' => __('New count or amount'), 'skip' => __('Month skipped'), 'move' => __('Date moved')];
    $rebate = $contract->adjustments->reduce(fn (string $sum, $adjustment) => Money::add($sum, (string) $adjustment->amount, 2), '0.00');
    $markupPercent = rtrim(rtrim($contract->markup_value, '0'), '.');
    // Late fees (Win Plan K4) can be all that is left to pay.
    $canTakePayment = $contract->status !== 'cancelled' && ($next !== null || Money::isPositive($feesOwed));
    $feeLabel = ['open' => __('Owed'), 'paid' => __('Paid'), 'waived' => __('Waived')];
    $feeTone = ['open' => 'badge-bad', 'paid' => 'badge-ok', 'waived' => ''];
    $feeWhen = ['once' => __('once'), 'daily' => __('every day it is late'), 'weekly' => __('every week it is late'), 'monthly' => __('every month it is late')];
@endphp
<x-layouts.app :title="$contract->reference()" section="contracts">
    <x-page-head :title="$contract->reference()">
        <x-slot:subtitle>
            <span class="badge {{ $statusTone[$state] ?? '' }}" data-status="{{ $state }}">{{ $statusLabel[$state] ?? $state }}</span>
            @if ($customer->trashed())
                {{ $customer->name }}
            @else
                <a class="link" href="{{ route('app.customers.show', $customer) }}">{{ $customer->name }}</a>
            @endif
            · <a class="link" href="{{ route('app.contracts.index') }}">{{ __('All contracts') }}</a>
        </x-slot:subtitle>
        <x-document-menu id="contract-statement" :action="route('app.contracts.statement', $contract)" :label="__('Statement')" :sections="['overdue', 'schedule', 'signature', 'cost']" />
    </x-page-head>

    <section class="card summary summary-trio" aria-label="{{ __('Where this contract stands') }}">
        <div class="figure figure-lead" @if ($late) data-tone="danger" @endif>
            <p class="figure-label">{{ __('Still owed') }}</p>
            <p class="figure-value money">{{ Format::money($owed, $currency) }}</p>
            @if ($paidAheadUntil !== null)
                <p class="figure-note">{{ __('Paid ahead until :date', ['date' => $paidAheadUntil->translatedFormat('j M Y')]) }}</p>
            @endif
            @if (Money::isPositive($feesOwed))
                <p class="figure-note">{{ __('Includes :amount in late fees', ['amount' => Format::money($feesOwed, $currency)]) }}</p>
            @endif
        </div>
        <div class="figure">
            <p class="figure-label">{{ __('Paid on instalments') }}</p>
            <p class="figure-value money">{{ Format::money($paid, $currency) }}</p>
        </div>
        <div class="figure">
            <p class="figure-label">{{ __('Total to repay') }}</p>
            <p class="figure-value money">{{ Format::money($contract->total, $currency) }}</p>
        </div>
    </section>

    <div class="detail-layout contract-layout">
        <div class="stack">
            @can('create', Transaction::class)
                @if ($canTakePayment)
                    <section class="card card-pad stack" aria-labelledby="pay-title">
                        <h2 id="pay-title" class="card-title">{{ __('Record a payment') }}</h2>
                        <form method="POST" action="{{ route('app.contracts.payments.store', $contract) }}" class="form" novalidate>
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', 'web-'.Str::uuid()) }}">
                            @error('contract')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror
                            @error('idempotency_key')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror

                            @if ($next !== null)
                                <x-field name="amount" :label="__('Amount received')" :value="Money::add($next->remaining(), '0', 2)" inputmode="decimal" autocomplete="off" dir="ltr" required
                                         :hint="__('Next instalment: :amount, due :date.', ['amount' => Format::money($next->remaining(), $currency), 'date' => $next->due_date->translatedFormat('j M Y')])" />
                            @else
                                <x-field name="amount" :label="__('Amount received')" :value="Money::add($feesOwed, '0', 2)" inputmode="decimal" autocomplete="off" dir="ltr" required
                                         :hint="__('Every instalment is paid; :amount in late fees is still owed.', ['amount' => Format::money($feesOwed, $currency)])" />
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
                            @if ($canTarget)
                                {{-- Win Plan PP6: the instalment it covers first; whatever is over goes to the oldest. --}}
                                <div class="field">
                                    <label for="f-apply">{{ __('Pay towards') }}</label>
                                    <select id="f-apply" name="apply_to" @error('apply_to') aria-invalid="true" @enderror>
                                        <option value="">{{ __('The oldest instalment first') }}</option>
                                        @foreach ($installments->reject(fn ($i) => in_array($i->status, \App\Models\Installment::CLOSED, true)) as $open)
                                            <option value="{{ $open->id }}" @selected(old('apply_to') === $open->id)>{{ $open->due_date->translatedFormat('j M Y') }}, {{ Format::money($open->remaining(), $currency) }}</option>
                                        @endforeach
                                    </select>
                                    @error('apply_to')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                </div>
                            @endif
                            <x-field name="note" :label="__('Note (optional)')" autocomplete="off" :hint="__('A receipt number or a reference, for your records.')" />

                            <button class="btn">{{ __('Record payment') }}</button>
                        </form>
                    </section>
                @endif
            @endcan

            <section class="card card-pad stack" aria-labelledby="terms-title">
                <h2 id="terms-title" class="card-title">{{ __('Terms') }}</h2>
                <dl class="facts">
                    <div><dt>{{ __('Customer') }}</dt><dd>{{ $customer->name }}</dd></div>
                    @if ($contract->title)<div><dt>{{ __('What was sold') }}</dt><dd>{{ $contract->title }}</dd></div>@endif
                    <div><dt>{{ __('Contract date') }}</dt><dd class="money">{{ $contract->start_date->translatedFormat('j M Y') }}</dd></div>
                    <div><dt>{{ __('Price') }}</dt><dd class="money">{{ Format::money($contract->principal, $currency) }}</dd></div>
                    @if (Money::isPositive($contract->discount_amount))
                        <div><dt>{{ __('Discount') }}</dt><dd class="money">−{{ Format::money($contract->discount_amount, $currency) }}@if ($contract->discount_type === 'percent') <span class="muted">({{ rtrim(rtrim($contract->discount_value, '0'), '.') }}%)</span>@endif</dd></div>
                    @endif
                    @if ($contract->tax_amount !== null)
                        <div><dt>{{ __('Tax in the price') }}</dt><dd class="money">{{ Format::money($contract->tax_amount, $currency) }} <span class="muted">({{ rtrim(rtrim($contract->tax_percent, '0'), '.') }}%)</span></dd></div>
                    @endif
                    @if ($contract->type === 'scheduled')
                        <div><dt>{{ __('Down payment') }}</dt><dd class="money">{{ Format::money($contract->down_payment, $currency) }}</dd></div>
                        <div><dt>{{ __('Financed') }}</dt><dd class="money">{{ Format::money($contract->financed, $currency) }}</dd></div>
                        <div>
                            <dt>{{ __('Markup') }}</dt>
                            <dd class="money">{{ Format::money($contract->markup_amount, $currency) }}@if ($contract->markup_type === 'percent') <span class="muted">({{ $markupPercent }}%)</span>@endif</dd>
                        </div>
                        <div><dt>{{ __('Instalments') }}</dt><dd class="money">{{ $contract->installment_count }}</dd></div>
                        <div><dt>{{ __('How often') }}</dt><dd>{{ Frequencies::label($contract->frequency) }}</dd></div>
                        @if ($contract->grace_days > 0)<div><dt>{{ __('Grace days') }}</dt><dd class="money">{{ $contract->grace_days }}</dd></div>@endif
                    @else
                        <div><dt>{{ __('Payment terms') }}</dt><dd>{{ __('Paid in full on the day of the contract.') }}</dd></div>
                    @endif
                    @if ($contract->cost_price !== null && auth()->user()->roleIn($contract->tenant_id)?->seesInvestors())
                        {{-- What it cost and what it earns, for the people who run the money (not collectors). --}}
                        <div><dt>{{ __('What it cost you') }}</dt><dd class="money">{{ Format::money($contract->cost_price, $currency) }}</dd></div>
                        <div><dt>{{ __('Margin') }}</dt><dd class="money">{{ Format::money(Money::sub(Money::add(Money::sub($contract->principal, $contract->discount_amount), $contract->markup_amount), $contract->cost_price, 2), $currency) }}</dd></div>
                    @endif
                    @if ($contract->investor !== null && auth()->user()->can('viewAny', Investor::class))
                        <div><dt>{{ __('Funded by') }}</dt><dd><a class="link" href="{{ route('app.investors.show', $contract->investor) }}">{{ MainInvestor::displayName($contract->investor) }}</a></dd></div>
                    @endif
                    @if ($contract->notes)<div><dt>{{ __('Notes') }}</dt><dd class="prewrap">{{ $contract->notes }}</dd></div>@endif
                    @if ($contract->cancelled_at)<div><dt>{{ __('Cancelled on') }}</dt><dd class="money">{{ $contract->cancelled_at->translatedFormat('j M Y') }}</dd></div>@endif
                    @if (Money::isPositive($rebate))<div><dt>{{ __('Early settlement discount') }}</dt><dd class="money">−{{ Format::money($rebate, $currency) }}</dd></div>@endif
                </dl>

                @if ($canSettle || $canAmend)
                    <div class="contract-actions">
                        @if ($canSettle)<a class="btn btn-quiet btn-sm" href="{{ route('app.contracts.settle.show', $contract) }}">{{ __('Settle early') }}</a>@endif
                        @if ($canAmend)<a class="btn btn-quiet btn-sm" href="{{ route('app.contracts.plan.show', $contract) }}">{{ __('Change the payment plan') }}</a>@endif
                    </div>
                @endif

                @if ($conversion !== null && Money::isPositive($conversion['late_fees']))
                    <p class="field-hint">{{ __('To make this contract open, first collect or waive its late fees (:amount).', ['amount' => Format::money($conversion['late_fees'], $currency)]) }}</p>
                @elseif ($conversion !== null)
                    {{-- Win Plan PP4: what becoming open does, in numbers, before anything changes. --}}
                    <details class="confirm">
                        <summary class="btn btn-quiet btn-sm">{{ __('Turn into an open contract') }}</summary>
                        <form method="POST" action="{{ route('app.contracts.convert', $contract) }}" class="form">
                            @csrf
                            <p>{{ __('The customer keeps a running balance instead of a schedule. This is exactly what happens:') }}</p>
                            <ul class="convert-outcome">
                                <li>{{ __('Unpaid instalments set aside, kept in the history: :count', ['count' => $conversion['superseded']]) }}</li>
                                <li>{{ __('Opening balance of the open contract: :amount', ['amount' => Format::money($conversion['opening_balance'], $currency)]) }}</li>
                            </ul>
                            <button class="btn btn-sm">{{ __('Make it open') }}</button>
                        </form>
                    </details>
                @endif
                @if ($contract->status === 'active')
                    @can('cancel', $contract)
                        <details class="confirm">
                            <summary class="btn btn-ghost btn-sm danger-text">{{ __('Cancel contract') }}</summary>
                            <form method="POST" action="{{ route('app.contracts.cancel', $contract) }}" class="form">
                                @csrf
                                <p>{{ __('The contract stops taking payments and frees its place on your plan. Its schedule and every payment stay in your records.') }}</p>
                                <x-reason-field id="cancel-reason" />
                                <button class="btn btn-danger btn-sm">{{ __('Cancel this contract') }}</button>
                            </form>
                        </details>
                    @endcan
                @endif
                @if ($contract->status !== 'active')
                    @can('archive', $contract)
                        <form method="POST" action="{{ route($contract->archived_at ? 'app.contracts.unarchive' : 'app.contracts.archive', $contract) }}">
                            @csrf
                            <button class="btn btn-ghost btn-sm" type="submit"><x-icon name="archive" :size="16" /> {{ $contract->archived_at ? __('Bring back to the lists') : __('Archive') }}</button>
                        </form>
                        @unless ($contract->archived_at)<p class="field-hint">{{ __('Archiving takes a finished contract out of your lists. It still counts for investors and stays in every report.') }}</p>@endunless
                    @endcan
                @endif
                @error('contract')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </section>
        </div>

        <div class="stack">
            @if ($contract->items->isNotEmpty())
                <section class="card" aria-labelledby="sold-title">
                    <div class="card-head"><h2 id="sold-title">{{ __('What was sold') }}</h2></div>
                    <div class="table-wrap">
                        <table class="table table-stack">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Item') }}</th>
                                    <th scope="col">{{ __('Serial or IMEI') }}</th>
                                    <th scope="col" class="num">{{ __('Quantity') }}</th>
                                    <th scope="col" class="num">{{ __('Price') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($contract->items as $item)
                                    <tr>
                                        <td data-label="{{ __('Item') }}">{{ $item->name }}</td>
                                        <td data-label="{{ __('Serial or IMEI') }}" class="money" dir="ltr">{{ $item->serial ?? '—' }}</td>
                                        <td data-label="{{ __('Quantity') }}" class="num money">{{ $item->quantity }}</td>
                                        <td data-label="{{ __('Price') }}" class="num money">{{ $item->price === null ? '—' : Format::money($item->price, $currency) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            <section class="card" aria-labelledby="schedule-title">
                <div class="card-head"><h2 id="schedule-title">{{ __('Schedule') }}</h2></div>
                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">{{ __('Due date') }}</th>
                                <th scope="col" class="num">{{ __('Amount') }}</th>
                                <th scope="col" class="num">{{ __('Paid') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($installments as $installment)
                                @php $installmentState = $installment->displayState(); @endphp
                                <tr data-state="{{ $installmentState }}">
                                    <td data-label="#" class="money">{{ $loop->iteration }}</td>
                                    <td data-label="{{ __('Due date') }}" class="money">{{ $installment->due_date->translatedFormat('j M Y') }}</td>
                                    <td data-label="{{ __('Amount') }}" class="num money">{{ Format::money($installment->amount, $currency) }}</td>
                                    <td data-label="{{ __('Paid') }}" class="num money">{{ Money::isZero($installment->paid_amount) ? '—' : Format::money($installment->paid_amount, $currency) }}</td>
                                    <td data-label="{{ __('Status') }}"><span class="badge {{ $installmentTone[$installmentState] }}">{{ $installmentLabel[$installmentState] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($lateFeePolicy !== null || $lateFees->isNotEmpty())
                {{-- Win Plan K4: each fee its own line, never added to an instalment; an owner or manager waives one, saying why. --}}
                <section class="card" aria-labelledby="fees-title">
                    <div class="card-head"><h2 id="fees-title">{{ __('Late fees') }}</h2></div>
                    @if ($lateFeePolicy !== null)
                        <p class="card-pad fee-policy">
                            {{ __(':fee, :when, from the day after the grace days.', [
                                'fee' => $lateFeePolicy->type === 'percent' ? __(':percent% of what is still owed on the instalment', ['percent' => rtrim(rtrim($lateFeePolicy->amount, '0'), '.')]) : Format::money($lateFeePolicy->amount, $currency),
                                'when' => $feeWhen[$lateFeePolicy->frequency] ?? $lateFeePolicy->frequency,
                            ]) }}
                            @if ($lateFeePolicy->capPerInstallment !== null) {{ __('At most :amount per instalment.', ['amount' => Format::money($lateFeePolicy->capPerInstallment, $currency)]) }} @endif
                            @if ($lateFeePolicy->capPerContract !== null) {{ __('At most :amount for the contract.', ['amount' => Format::money($lateFeePolicy->capPerContract, $currency)]) }} @endif
                            @if ($lateFeePolicy->charity) {{ __('The fees go to charity.') }} @endif
                        </p>
                    @endif
                    @if ($lateFees->isEmpty())
                        <p class="card-pad muted">{{ __('No late fees so far.') }}</p>
                    @else
                        <div class="table-wrap">
                            <table class="table table-stack">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('Charged on') }}</th>
                                        <th scope="col">{{ __('Instalment') }}</th>
                                        <th scope="col" class="num">{{ __('Amount') }}</th>
                                        <th scope="col" class="num">{{ __('Paid') }}</th>
                                        <th scope="col">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lateFees as $fee)
                                        <tr data-fee="{{ $fee->status }}">
                                            <td data-label="{{ __('Charged on') }}" class="money">{{ $fee->charged_on->translatedFormat('j M Y') }}</td>
                                            <td data-label="{{ __('Instalment') }}" class="money">{{ $fee->installment?->number }}</td>
                                            <td data-label="{{ __('Amount') }}" class="num money">{{ Format::money($fee->amount, $currency) }}</td>
                                            <td data-label="{{ __('Paid') }}" class="num money">{{ Money::isZero($fee->paid_amount) ? '—' : Format::money($fee->paid_amount, $currency) }}</td>
                                            <td data-label="{{ __('Status') }}">
                                                <span class="badge {{ $feeTone[$fee->status] ?? '' }}">{{ $feeLabel[$fee->status] ?? $fee->status }}</span>
                                                @if ($fee->charity)<span class="badge">{{ __('For charity') }}</span>@endif
                                                @if ($fee->status === 'waived')
                                                    <span class="cell-sub">{{ $fee->waived_reason }}</span>
                                                    @if ($fee->waived_at)<span class="cell-sub">{{ $fee->waivedBy ? __('Waived by :name on :date', ['name' => $fee->waivedBy->name, 'date' => $fee->waived_at->translatedFormat('j M Y')]) : __('Waived on :date', ['date' => $fee->waived_at->translatedFormat('j M Y')]) }}</span>@endif
                                                @elseif ($fee->status === 'open' && $contract->status !== 'cancelled')
                                                    @can('waiveFees', $contract)
                                                        <details class="confirm confirm-inline">
                                                            <summary class="link">{{ __('Waive this fee') }}</summary>
                                                            <form method="POST" action="{{ route('app.late-fees.waive', $fee) }}" class="form">
                                                                @csrf
                                                                <p>{{ __('The customer no longer owes what is left of this fee. What was paid on it stays paid.') }}</p>
                                                                <x-reason-field :id="'waive-reason-'.$fee->id" required />
                                                                <button class="btn btn-danger btn-sm">{{ __('Waive fee') }}</button>
                                                            </form>
                                                        </details>
                                                    @endcan
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif

            @if ($contract->amendments->isNotEmpty())
                {{-- Win Plan PP6: every change to the plan, with why and who, and what the schedule was. --}}
                <section class="card" aria-labelledby="plan-history-title">
                    <div class="card-head"><h2 id="plan-history-title">{{ __('Plan changes') }}</h2></div>
                    <ol class="plan-history">
                        @foreach ($contract->amendments->reverse() as $amendment)
                            <li>
                                <p class="plan-history-head"><strong>{{ $amendmentLabel[$amendment->type] ?? $amendment->type }}</strong> <span class="muted">{{ $amendment->created_at->translatedFormat('j M Y') }}@if ($amendment->approvedBy), {{ $amendment->approvedBy->name }}@endif</span></p>
                                <p class="prewrap">{{ $amendment->reason }}</p>
                                <details>
                                    <summary class="link">{{ __('Instalments before: :before. After: :after.', ['before' => count($amendment->old_schedule), 'after' => count($amendment->new_schedule)]) }}</summary>
                                    <div class="plan-compare">
                                        <ol class="plan-rows">@foreach ($amendment->old_schedule as $row)<li><span class="money">{{ \Illuminate\Support\Carbon::parse($row['due_date'])->translatedFormat('j M Y') }}</span><span class="money">{{ Format::money($row['amount'], $currency) }}</span></li>@endforeach</ol>
                                        <ol class="plan-rows plan-rows-new">@foreach ($amendment->new_schedule as $row)<li><span class="money">{{ \Illuminate\Support\Carbon::parse($row['due_date'])->translatedFormat('j M Y') }}</span><span class="money">{{ Format::money($row['amount'], $currency) }}</span></li>@endforeach</ol>
                                    </div>
                                </details>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            <section class="card" aria-labelledby="history-title">
                <div class="card-head"><h2 id="history-title">{{ __('Payments') }}</h2></div>
                @if ($lines->isEmpty())
                    <div class="empty">
                        <span class="empty-icon"><x-icon name="wallet" :size="24" /></span>
                        <h3>{{ __('No payments yet') }}</h3>
                        <p>{{ __('Payments you record appear here, newest first.') }}</p>
                    </div>
                @else
                    <div class="table-wrap">
                        <table class="table table-stack">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Date') }}</th>
                                    <th scope="col">{{ __('Details') }}</th>
                                    <th scope="col" class="num">{{ __('Amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lines as $line)
                                    @php
                                        $kind = match (true) {
                                            $line->type === 'reversal' => 'reversal',
                                            $line->type === 'down_payment' => 'down_payment',
                                            $line->getAttribute('voided') => 'voided',
                                            default => 'payment',
                                        };
                                    @endphp
                                    <tr data-line="{{ $kind }}">
                                        <td data-label="{{ __('Date') }}" class="money">{{ $line->paid_at->translatedFormat('j M Y') }}</td>
                                        <td data-label="{{ __('Details') }}">
                                            <span class="line-title">
                                                @if ($kind === 'reversal') {{ __('Payment voided') }}
                                                @elseif ($kind === 'down_payment') {{ __('Down payment') }}
                                                @else {{ PaymentMethods::label($line->method) }}
                                                @endif
                                                @if ($kind === 'voided')<span class="badge badge-bad">{{ __('Voided') }}</span>@endif
                                            </span>
                                            @if ($line->note && $kind !== 'reversal')<span class="cell-sub">{{ $line->note }}</span>@endif
                                            @include('app.payments._who', ['line' => $line])
                                            @if (in_array($line->type, ['payment', 'down_payment'], true))<a class="link line-receipt" href="{{ route('app.payments.receipt', $line) }}" target="_blank" rel="noopener"><x-icon name="fileText" :size="14" /> {{ __('Receipt') }}</a>@endif
                                            @if ($kind === 'payment')
                                                @can('void', $line)
                                                    <details class="confirm confirm-inline">
                                                        <summary class="link">{{ __('Void this payment') }}</summary>
                                                        <form method="POST" action="{{ route('app.payments.void', $line) }}" class="form">
                                                            @csrf
                                                            <p>{{ __('Use this when a payment was entered by mistake. A reversal is added to the history; nothing is deleted.') }}</p>
                                                            <x-reason-field :id="'void-reason-'.$line->id" />
                                                            <button class="btn btn-danger btn-sm">{{ __('Void payment') }}</button>
                                                        </form>
                                                    </details>
                                                @endcan
                                            @endif
                                        </td>
                                        <td data-label="{{ __('Amount') }}" @class(['num', 'money', 'voided' => $kind === 'voided'])>{{ Format::money($line->amount, $currency) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-layouts.app>
