@php
    use App\Domain\Investors\MainInvestor;
    use App\Domain\Schedule\Frequencies;
    use App\Entitlements\Feature;
    use App\Support\Money;

    // With flexible schedules on: every rhythm from daily to yearly, or the shop's own dates; otherwise the basic three.
    $frequencies = Frequencies::labels($flexible);
    $frequency = old('frequency', 'monthly');

    // The rows already typed, when the form comes back with a problem.
    $customRows = collect(old('custom_schedule', []))->filter(fn ($row) => is_array($row))
        ->map(fn (array $row) => ['due_date' => (string) ($row['due_date'] ?? ''), 'amount' => (string) ($row['amount'] ?? '')])
        ->values()->all();

    // What was sold, as typed, when the form comes back with a problem (Win Plan PP7).
    $items = collect(old('items', []))->filter(fn ($row) => is_array($row))
        ->map(fn (array $row) => array_map(fn ($v) => (string) ($v ?? ''), array_intersect_key($row + ['product_id' => '', 'name' => '', 'quantity' => '1', 'serial' => '', 'price' => '', 'cost' => ''], array_flip(['product_id', 'name', 'quantity', 'serial', 'price', 'cost']))))
        ->values()->all();

    $initial = [
        'type' => old('type', 'scheduled'),
        'discountType' => old('discount_type', 'none'),
        'discountValue' => old('discount_value', ''),
        'items' => $items,
        'price' => old('principal', ''),
        'down' => old('down_payment', ''),
        'markupType' => old('markup_type', 'none'),
        'markupValue' => old('markup_value', ''),
        'count' => old('installment_count', '6'),
        'frequency' => array_key_exists($frequency, $frequencies) ? $frequency : 'monthly',
        'firstDue' => old('first_due_date', today()->addMonthNoOverflow()->format('Y-m-d')),
        'customRows' => $customRows,
    ];
    $config = [
        'url' => route('app.contracts.preview'),
        'csrf' => csrf_token(),
        'locale' => app()->getLocale().'-u-nu-latn',
        'currency' => $currency,
        'initial' => $initial,
        'messages' => ['unavailable' => __('The preview is not available right now. You can still open the contract.')],
        'labels' => ['date' => __('Due date'), 'amount' => __('Amount'), 'remove' => __('Remove this date'), 'item' => __('Item'), 'removeItem' => __('Remove this item'), 'useTotal' => __('Use the items’ total:')],
        'products' => $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->default_price === null ? '' : Money::add($p->default_price, '0', 2), 'cost' => $p->cost === null ? '' : Money::add($p->cost, '0', 2)])->values()->all(),
    ];
@endphp
<x-layouts.app :title="__('Open a contract')" section="contracts">
    <x-page-head :title="__('Open a contract')">
        <x-slot:subtitle><a class="link" href="{{ route('app.contracts.index') }}">{{ __('All contracts') }}</a></x-slot:subtitle>
    </x-page-head>

    @if ($customers->isEmpty())
        <section class="card card-pad stack narrow">
            <h2 class="sheet-title display">{{ __('Add a customer first') }}</h2>
            <p>{{ __('Every contract belongs to a customer. Add the person you are selling to, then come back to set up their plan.') }}</p>
            <div class="form-actions">
                <a class="btn" href="{{ route('app.customers.create') }}">{{ __('Add your first customer') }}</a>
            </div>
        </section>
    @elseif (! $usage->allows())
        {{-- No point filling in a form that would be refused: say so first, and offer the way forward. --}}
        <section class="card card-pad stack narrow">
            <h2 class="sheet-title display">{{ __('You have reached your plan limit') }}</h2>
            <x-meter :label="__('Active contracts')" :entitlement="$usage" />
            <p>{{ __('Your plan includes :allowance. Upgrade to open more, or wait until a contract is settled to free up a place.', ['allowance' => Feature::ActiveContracts->summary(true, $usage->limit())]) }}</p>
            <div class="form-actions">
                <a class="btn btn-gold" href="{{ url('/app/billing') }}">{{ __('See plans and upgrade') }}</a>
                <a class="btn btn-quiet" href="{{ route('app.contracts.index') }}">{{ __('Back to contracts') }}</a>
            </div>
        </section>
    @else
        <form class="planner" method="POST" action="{{ route('app.contracts.store') }}" novalidate
              x-data="contractPlanner" data-config="{{ json_encode($config) }}" :data-loading="isLoading">
            @csrf

            <section class="card card-pad stack planner-fields" aria-label="{{ __('Contract details') }}">
                <x-meter :label="__('Active contracts')" :entitlement="$usage" />

                @error('schedule')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror

                <div class="form form-grid">
                    <div class="field field-wide">
                        <label for="f-customer_id">{{ __('Customer') }}</label>
                        <select id="f-customer_id" name="customer_id" required @error('customer_id') aria-invalid="true" aria-describedby="f-customer_id-error" @enderror>
                            <option value="">{{ __('Choose a customer') }}</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}" @selected(old('customer_id', $selected) === $customer->id)>{{ $customer->name }} · {{ $customer->phone }}</option>
                            @endforeach
                        </select>
                        @error('customer_id')<p class="field-error" id="f-customer_id-error" role="alert">{{ $message }}</p>@enderror
                    </div>

                    @if ($investors->count() > 1)
                        {{-- Who funds it: the business's own capital unless a partner is chosen (Win Plan PP3). --}}
                        <div class="field field-wide">
                            <label for="f-investor_id">{{ __('Funded by') }}</label>
                            <select id="f-investor_id" name="investor_id" aria-describedby="f-investor_id-hint" @error('investor_id') aria-invalid="true" @enderror>
                                @foreach ($investors as $investor)
                                    <option value="{{ $investor->id }}" @selected(old('investor_id', $investors->first()->id) === $investor->id)>{{ MainInvestor::displayName($investor) }}</option>
                                @endforeach
                            </select>
                            <p class="field-hint" id="f-investor_id-hint">{{ __('Each payment’s principal and profit go to them as the customer pays.') }}</p>
                            @error('investor_id')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    @if ($details)
                        {{-- Win Plan PP7: what was sold, so profit is real and an IMEI dispute takes ten seconds. --}}
                        <fieldset class="field field-wide sold" x-show="!isOpen">
                            <legend class="field-label">{{ __('What was sold') }}</legend>
                            <x-field name="title" :label="__('In a line (optional)')" :hint="__('Shown on the contract and its documents, e.g. iPhone 16 Pro, 256 GB.')" autocomplete="off" maxlength="120" />
                            <ol class="sold-items">
                                <template x-for="(item, i) in items" :key="item.key">
                                    <li class="sold-item">
                                        <span class="plan-date-number" aria-hidden="true" x-text="i + 1"></span>
                                        <div class="sold-item-fields">
                                            @if ($products->isNotEmpty())
                                                <select class="sold-product" x-model="item.product_id" @change="pickProduct(i)" :name="itemName(i, 'product_id')" :aria-label="rowLabel('item', i)">
                                                    <option value="">{{ __('Pick a product (optional)') }}</option>
                                                    @foreach ($products as $product)
                                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                            <input type="text" class="sold-name" x-model="item.name" :name="itemName(i, 'name')" placeholder="{{ __('What it is') }}" maxlength="120" :aria-label="rowLabel('item', i)">
                                            <input type="text" inputmode="numeric" class="sold-qty" x-model="item.quantity" :name="itemName(i, 'quantity')" dir="ltr" aria-label="{{ __('Quantity') }}">
                                            <input type="text" class="sold-serial" x-model="item.serial" :name="itemName(i, 'serial')" placeholder="{{ __('Serial or IMEI') }}" dir="ltr" autocomplete="off" maxlength="60" aria-label="{{ __('Serial or IMEI') }}">
                                            <input type="text" inputmode="decimal" class="sold-price" x-model="item.price" :name="itemName(i, 'price')" placeholder="{{ __('Price') }}" dir="ltr" autocomplete="off" aria-label="{{ __('Price') }}">
                                            <input type="text" inputmode="decimal" class="sold-cost" x-model="item.cost" :name="itemName(i, 'cost')" placeholder="{{ __('Cost') }}" dir="ltr" autocomplete="off" aria-label="{{ __('Cost') }}">
                                        </div>
                                        <button type="button" class="plan-date-remove" @click="removeItem(i)" :aria-label="rowLabel('removeItem', i)"><x-icon name="x" :size="16" /></button>
                                    </li>
                                </template>
                            </ol>
                            <button type="button" class="btn btn-quiet btn-sm plan-dates-add" @click="addItem" x-show="canAddItem"><x-icon name="plus" :size="16" /> {{ __('Add an item') }}</button>
                            @if ($errors->has('items') || $errors->has('items.*'))
                                <p class="field-error" role="alert">{{ $errors->first('items') ?: $errors->first('items.*') }}</p>
                            @endif
                        </fieldset>
                    @endif

                    <fieldset class="field field-wide choice">
                        <legend class="field-label">{{ __('How will they pay?') }}</legend>
                        <label class="choice-item">
                            <input type="radio" name="type" value="scheduled" x-model="type" @change="changed">
                            <span><strong>{{ __('In instalments') }}</strong><small>{{ __('A schedule of payments over time') }}</small></span>
                        </label>
                        <label class="choice-item">
                            <input type="radio" name="type" value="cash" x-model="type" @change="changed">
                            <span><strong>{{ __('Cash sale') }}</strong><small>{{ __('Paid in full on the day') }}</small></span>
                        </label>
                        @if ($open)
                            <label class="choice-item">
                                <input type="radio" name="type" value="open" x-model="type" @change="changed">
                                <span><strong>{{ __('Open account') }}</strong><small>{{ __('A running balance, no schedule') }}</small></span>
                            </label>
                        @endif
                    </fieldset>

                    <div class="contents" x-show="!isOpen">
                        <div class="field">
                            <x-field name="principal" :label="__('Price')" :hint="__('The full price of what is being sold, in :currency.', ['currency' => $currency])" inputmode="decimal" autocomplete="off" dir="ltr" required x-model="price" x-bind:disabled="isOpen" @input="changed" />
                            @if ($details)
                                <button type="button" class="link sold-total" x-show="offersItemsTotal" x-cloak @click="useItemsTotal" x-text="itemsTotalLabel"></button>
                            @endif
                        </div>
                        @if ($details)
                            {{-- A discount at sale (Win Plan PP6): off the price before the down payment. --}}
                            <div class="field">
                                <label for="f-discount_type">{{ __('Discount (optional)') }}</label>
                                <div class="discount-row">
                                    <select id="f-discount_type" name="discount_type" x-model="discountType" @change="changed">
                                        <option value="none">{{ __('No discount') }}</option>
                                        <option value="fixed">{{ __('An amount') }}</option>
                                        <option value="percent">{{ __('A percentage') }}</option>
                                    </select>
                                    <input type="text" name="discount_value" inputmode="decimal" dir="ltr" autocomplete="off" x-model="discountValue" @input="changed" x-show="hasDiscount" x-bind:disabled="!hasDiscount" aria-label="{{ __('Discount') }}">
                                </div>
                                @error('discount_value')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>

                    @if ($open)
                        {{-- A running tab (Win Plan PP4): what they owe today, and how far it may grow before the page warns. --}}
                        <div class="contents" x-show="isOpen" x-cloak>
                            <x-field name="opening_balance" :label="__('What they owe today (optional)')" :hint="__('Becomes the first line of their account.')" inputmode="decimal" autocomplete="off" dir="ltr" x-bind:disabled="!isOpen" />
                            <x-field name="credit_limit" :label="__('Credit limit (optional)')" :hint="__('Past it, the page warns. It never refuses.')" inputmode="decimal" autocomplete="off" dir="ltr" x-bind:disabled="!isOpen" />
                        </div>
                    @endif

                    <div class="contents" x-show="isScheduled">
                        <x-field name="down_payment" :label="__('Down payment (optional)')" :hint="__('Paid today, before the instalments start.')" inputmode="decimal" autocomplete="off" dir="ltr" x-model="down" @input="changed" />

                        <div class="field">
                            <label for="f-markup_type">{{ __('Markup') }}</label>
                            <select id="f-markup_type" name="markup_type" x-model="markupType" @change="changed">
                                <option value="none" @selected(old('markup_type', 'none') === 'none')>{{ __('No markup') }}</option>
                                <option value="percent" @selected(old('markup_type') === 'percent')>{{ __('A percentage') }}</option>
                                <option value="fixed" @selected(old('markup_type') === 'fixed')>{{ __('A fixed amount') }}</option>
                            </select>
                            @error('markup_type')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="contents" x-show="hasMarkup">
                            <x-field name="markup_value" :label="__('Markup value')" :hint="__('A percentage is taken on the amount being financed.')" inputmode="decimal" autocomplete="off" dir="ltr" x-model="markupValue" @input="changed" />
                        </div>

                        <div class="field">
                            <label for="f-frequency">{{ __('How often') }}</label>
                            <select id="f-frequency" name="frequency" x-model="frequency" @change="frequencyChanged">
                                @foreach ($frequencies as $value => $label)
                                    <option value="{{ $value }}" @selected($initial['frequency'] === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('frequency')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="contents" x-show="!isCustom">
                            <x-field name="installment_count" type="number" :label="__('Number of instalments')" :value="$initial['count']" inputmode="numeric" min="1" :max="$flexible ? 600 : 120" required x-model="count" x-bind:disabled="isCustom" @input="changed" />
                        </div>
                    </div>

                    <x-field name="start_date" type="date" :label="__('Contract date')" :value="$today" required />

                    <div class="contents" x-show="isScheduled && !isCustom">
                        <x-field name="first_due_date" type="date" :label="__('First instalment due')" :value="$initial['firstDue']" required x-model="firstDue" x-bind:disabled="isCustom" @input="changed" />
                    </div>

                    @if ($flexible)
                        {{-- The shop's own dates: one line per payment. The preview checks that they make the total. --}}
                        <div class="field field-wide plan-dates" x-show="isScheduled && isCustom" x-cloak>
                            <span class="field-label" id="plan-dates-label">{{ __('Payment dates') }}</span>
                            <p class="field-hint" id="plan-dates-hint">{{ __('One line for each payment, with its date and amount. Together they must make the total to repay.') }}</p>
                            <ol class="plan-dates-list" aria-labelledby="plan-dates-label" aria-describedby="plan-dates-hint">
                                <template x-for="(row, i) in customRows" :key="row.key">
                                    <li class="plan-date">
                                        <span class="plan-date-number" aria-hidden="true" x-text="i + 1"></span>
                                        <input type="date" dir="ltr" :name="rowName(i, 'due_date')" x-model="row.due_date" @input="changed" :disabled="!isCustom" :aria-label="rowLabel('date', i)">
                                        <input type="text" inputmode="decimal" autocomplete="off" dir="ltr" placeholder="0.00" :name="rowName(i, 'amount')" x-model="row.amount" @input="changed" :disabled="!isCustom" :aria-label="rowLabel('amount', i)">
                                        <button type="button" class="plan-date-remove" @click="removeRow(i)" x-show="customRows.length > 1" :aria-label="rowLabel('remove', i)"><x-icon name="x" :size="16" /></button>
                                    </li>
                                </template>
                            </ol>
                            <button type="button" class="btn btn-quiet btn-sm plan-dates-add" @click="addRow"><x-icon name="plus" :size="16" /> {{ __('Add a date') }}</button>
                            @if ($errors->has('custom_schedule') || $errors->has('custom_schedule.*'))
                                <p class="field-error" role="alert">{{ $errors->first('custom_schedule') ?: $errors->first('custom_schedule.*') }}</p>
                            @endif
                        </div>

                        <div class="contents" x-show="isScheduled">
                            <x-field name="grace_days" type="number" :label="__('Grace days')" :hint="__('Days after a due date before the instalment counts as late. With 0 it is late the next day.')" value="0" inputmode="numeric" min="0" max="90" x-bind:disabled="!isScheduled" />
                        </div>
                    @endif

                    @if ($details)
                        <div class="contents" x-show="!isOpen">
                            <x-field name="own_reference" :label="__('Your contract number (optional)')" :hint="__('Your own number, if you keep one. Otherwise contracts are numbered C-0001, C-0002 and on.')" autocomplete="off" dir="ltr" maxlength="40" />
                            <x-field name="tax_percent" :label="__('Tax in the price, % (optional)')" :hint="__('Shown on documents. The price already includes it.')" inputmode="decimal" autocomplete="off" dir="ltr" />
                            <x-field name="cost_price" :label="__('What it cost you (optional)')" :hint="__('Left empty, the items’ costs are added up. Only your team sees it.')" inputmode="decimal" autocomplete="off" dir="ltr" />
                        </div>
                    @endif

                    <x-field wide type="textarea" name="notes" :label="__('Notes (optional)')" rows="2" :hint="__('For your team only. The customer never sees this.')" />
                </div>
            </section>

            <aside class="card card-pad stack planner-preview" aria-live="polite" aria-label="{{ __('Plan preview') }}">
                <h2 class="card-title">{{ __('What the customer will pay') }}</h2>

                <p class="muted" x-show="isEmpty">{{ __('Enter the price to see the schedule.') }}</p>
                <div class="stack" x-show="isOpen" x-cloak>
                    <p>{{ __('An open account has no schedule. On its page you add what they take and what they pay, and the balance after each line is always right.') }}</p>
                </div>
                <p class="muted" x-show="needsDates" x-cloak>{{ __('Add the payment dates to see the schedule.') }}</p>
                <p class="field-error" x-show="hasError" x-text="error" role="alert"></p>

                <div x-show="isCashSale" class="stack">
                    <p class="calc-each"><span class="calc-big money" x-text="cashTotal"></span></p>
                    <p class="muted">{{ __('Paid in full on the day of the contract.') }}</p>
                </div>

                <div x-show="hasResult" class="stack">
                    <p class="calc-each">
                        <span class="calc-big money" x-text="each"></span>
                        <span class="calc-sub" x-show="!isCustom">{{ __('per instalment') }}</span>
                        <span class="calc-sub" x-show="isCustom" x-cloak>{{ __('first payment') }}</span>
                    </p>
                    <dl class="calc-totals">
                        <div><dt>{{ __('Instalments') }}</dt><dd class="money" x-text="times"></dd></div>
                        <div><dt>{{ __('Last payment') }}</dt><dd x-text="lastDue"></dd></div>
                        <div x-show="hasDiscountOff" x-cloak><dt>{{ __('Discount') }}</dt><dd class="money" x-text="discountOff"></dd></div>
                        <div><dt>{{ __('Financed') }}</dt><dd class="money" x-text="financed"></dd></div>
                        <div><dt>{{ __('Markup') }}</dt><dd class="money" x-text="markupAmount"></dd></div>
                        <div><dt>{{ __('Total to repay') }}</dt><dd class="money" x-text="total"></dd></div>
                    </dl>
                    <div class="calc-schedule scroll">
                        <table>
                            <thead><tr><th scope="col">{{ __('Due date') }}</th><th scope="col">{{ __('Amount') }}</th></tr></thead>
                            <tbody>
                                <template x-for="row in rows" :key="row.number">
                                    <tr><td x-text="row.date"></td><td class="money" x-text="row.amount"></td></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p class="calc-balance"><x-icon name="check" :size="18" /> {{ __('The instalments add up to exactly the total, to the cent.') }}</p>
                </div>
            </aside>

            <div class="form-actions planner-actions">
                <button class="btn">{{ __('Open contract') }}</button>
                <a class="btn btn-ghost" href="{{ route('app.contracts.index') }}">{{ __('Cancel') }}</a>
            </div>
        </form>
    @endif
</x-layouts.app>
