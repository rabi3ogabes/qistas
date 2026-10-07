@php
    use App\Entitlements\Feature;

    $initial = [
        'type' => old('type', 'scheduled'),
        'price' => old('principal', ''),
        'down' => old('down_payment', ''),
        'markupType' => old('markup_type', 'none'),
        'markupValue' => old('markup_value', ''),
        'count' => old('installment_count', '6'),
        'frequency' => old('frequency', 'monthly'),
        'firstDue' => old('first_due_date', today()->addMonthNoOverflow()->format('Y-m-d')),
    ];
    $config = [
        'url' => route('app.contracts.preview'),
        'csrf' => csrf_token(),
        'locale' => app()->getLocale().'-u-nu-latn',
        'currency' => $currency,
        'initial' => $initial,
        'messages' => ['unavailable' => __('The preview is not available right now. You can still open the contract.')],
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
                    </fieldset>

                    <x-field name="principal" :label="__('Price')" :hint="__('The full price of what is being sold, in :currency.', ['currency' => $currency])" inputmode="decimal" autocomplete="off" dir="ltr" required x-model="price" @input="changed" />

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

                        <x-field name="installment_count" type="number" :label="__('Number of instalments')" :value="$initial['count']" inputmode="numeric" min="1" max="120" required x-model="count" @input="changed" />

                        <div class="field">
                            <label for="f-frequency">{{ __('How often') }}</label>
                            <select id="f-frequency" name="frequency" x-model="frequency" @change="changed">
                                <option value="monthly" @selected(old('frequency', 'monthly') === 'monthly')>{{ __('Every month') }}</option>
                                <option value="biweekly" @selected(old('frequency') === 'biweekly')>{{ __('Every two weeks') }}</option>
                                <option value="weekly" @selected(old('frequency') === 'weekly')>{{ __('Every week') }}</option>
                            </select>
                            @error('frequency')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <x-field name="start_date" type="date" :label="__('Contract date')" :value="$today" required />

                    <div class="contents" x-show="isScheduled">
                        <x-field name="first_due_date" type="date" :label="__('First instalment due')" :value="$initial['firstDue']" required x-model="firstDue" @input="changed" />
                    </div>

                    <x-field wide type="textarea" name="notes" :label="__('Notes (optional)')" rows="2" :hint="__('For your team only. The customer never sees this.')" />
                </div>
            </section>

            <aside class="card card-pad stack planner-preview" aria-live="polite" aria-label="{{ __('Plan preview') }}">
                <h2 class="card-title">{{ __('What the customer will pay') }}</h2>

                <p class="muted" x-show="isEmpty">{{ __('Enter the price to see the schedule.') }}</p>
                <p class="field-error" x-show="hasError" x-text="error" role="alert"></p>

                <div x-show="isCashSale" class="stack">
                    <p class="calc-each"><span class="calc-big money" x-text="cashTotal"></span></p>
                    <p class="muted">{{ __('Paid in full on the day of the contract.') }}</p>
                </div>

                <div x-show="hasResult" class="stack">
                    <p class="calc-each">
                        <span class="calc-big money" x-text="each"></span>
                        <span class="calc-sub">{{ __('per instalment') }}</span>
                    </p>
                    <dl class="calc-totals">
                        <div><dt>{{ __('Instalments') }}</dt><dd class="money" x-text="times"></dd></div>
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
