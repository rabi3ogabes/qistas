{{-- Win Plan PP6: change the plan, with the old and the new schedule side by side before anything is written. --}}
@php
    use App\Support\Format;
    use Illuminate\Support\Str;

    $type = $change['type'] ?? 'reschedule';
    $types = [
        'reschedule' => [__('A new count or amount for what is left'), __('Spread the unpaid instalments again.')],
        'skip' => [__('Skip a month'), __('That instalment and every one after it move one step later.')],
        'move' => [__('Move one date'), __('One instalment gets a new date; the others stay.')],
    ];
    $money = fn (string $amount) => Format::money($amount, $currency);
@endphp
<x-layouts.app :title="__('Change the payment plan')" section="contracts">
    <x-page-head :title="__('Change the payment plan')">
        <x-slot:subtitle>
            <a class="link" href="{{ route('app.contracts.show', $contract) }}" dir="ltr">{{ $contract->reference() }}</a>
            {{ $contract->customer->name }}
        </x-slot:subtitle>
    </x-page-head>

    <div class="detail-layout">
        <section class="card card-pad stack" aria-labelledby="change-title">
            <h2 id="change-title" class="card-title">{{ __('What changes') }}</h2>
            <p class="muted">{{ __('Only the instalments nobody has paid towards change. Paid ones stay exactly as they are, and the total to repay never moves.') }}</p>
            @if ($untouched->isEmpty())
                <p class="alert alert-warn" role="status">{{ __('Every instalment has been paid towards; there is nothing left to change.') }}</p>
            @else
                <form method="GET" action="{{ route('app.contracts.plan.show', $contract) }}" class="form stack plan-form">
                    <fieldset class="field choice">
                        <legend class="field-label">{{ __('How') }}</legend>
                        @foreach ($types as $key => [$label, $hint])
                            <label class="choice-item">
                                <input type="radio" name="type" value="{{ $key }}" @checked($type === $key)>
                                <span><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                            </label>
                        @endforeach
                    </fieldset>

                    <div class="plan-fields" data-for="reschedule">
                        <x-field name="count" type="number" :label="__('Number of instalments')" :value="$change['count'] ?? ''" min="1" max="600" inputmode="numeric" :hint="__('Or give an amount for each instead.')" />
                        <x-field name="amount" :label="__('Or an amount for each')" :value="$change['amount'] ?? ''" inputmode="decimal" dir="ltr" autocomplete="off" />
                        <x-field name="first_due_date" type="date" :label="__('First new instalment')" :value="$change['first_due_date'] ?? ''" :hint="__('Then :frequency, like the rest of the contract.', ['frequency' => Str::lower($frequency)])" />
                    </div>
                    <div class="plan-fields" data-for="skip move">
                        <div class="field">
                            <label for="plan-installment">{{ __('Which instalment') }}</label>
                            <select id="plan-installment" name="installment_id">
                                @foreach ($untouched as $installment)
                                    <option value="{{ $installment->id }}" @selected(($change['installment_id'] ?? '') === $installment->id)>{{ $installment->due_date->translatedFormat('j M Y') }}, {{ $money($installment->amount) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="plan-fields" data-for="move">
                        <x-field name="due_date" type="date" :label="__('New date (to move one)')" :value="$change['due_date'] ?? ''" />
                    </div>
                    @foreach ($previewErrors as $messages)
                        <p class="field-error" role="alert">{{ $messages[0] }}</p>
                    @endforeach
                    <button class="btn btn-quiet" type="submit">{{ __('Preview') }}</button>
                </form>
            @endif
        </section>

        @if ($preview !== null)
            <section class="card card-pad stack" aria-labelledby="compare-title">
                <h2 id="compare-title" class="card-title">{{ __('Before and after') }}</h2>
                <div class="plan-compare">
                    <div>
                        <h3 class="plan-compare-title">{{ __('Now') }}</h3>
                        <ol class="plan-rows">
                            @foreach ($preview['old_schedule'] as $row)
                                <li><span class="money">{{ \Illuminate\Support\Carbon::parse($row['due_date'])->translatedFormat('j M Y') }}</span><span class="money">{{ $money($row['amount']) }}</span></li>
                            @endforeach
                        </ol>
                    </div>
                    <div>
                        <h3 class="plan-compare-title">{{ __('After the change') }}</h3>
                        <ol class="plan-rows plan-rows-new">
                            @foreach ($preview['new_schedule'] as $row)
                                <li><span class="money">{{ \Illuminate\Support\Carbon::parse($row['due_date'])->translatedFormat('j M Y') }}</span><span class="money">{{ $money($row['amount']) }}</span></li>
                            @endforeach
                        </ol>
                    </div>
                </div>
                <form method="POST" action="{{ route('app.contracts.plan.store', $contract) }}" class="form">
                    @csrf
                    @foreach (['type', 'count', 'amount', 'first_due_date', 'installment_id', 'due_date'] as $key)
                        @if (($change[$key] ?? '') !== '')<input type="hidden" name="{{ $key }}" value="{{ $change[$key] }}">@endif
                    @endforeach
                    <x-reason-field id="plan-reason" required />
                    <button class="btn">{{ __('Change the payment plan') }}</button>
                </form>
            </section>
        @endif
    </div>
</x-layouts.app>
