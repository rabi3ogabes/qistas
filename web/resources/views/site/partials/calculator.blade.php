@php
    $config = [
        'url' => route('schedule.preview'),
        'csrf' => csrf_token(),
        'locale' => app()->getLocale().'-u-nu-latn',
        'currency' => $currency,
        'initial' => $initialSchedule,
        'messages' => ['unavailable' => __('The calculator is not available right now. Please try again in a moment.')],
    ];
@endphp
<form class="card calc" x-data="planCalculator" data-config="{{ json_encode($config) }}" :data-loading="isLoading" @submit.prevent aria-labelledby="calc-title" novalidate>
    <h2 id="calc-title" class="display">{{ __('Try an instalment plan') }}</h2>

    <div class="calc-fields">
        <div class="field field-wide">
            <label for="calc-price">{{ __('Price') }}</label>
            <input id="calc-price" type="text" inputmode="decimal" autocomplete="off" x-model="price" @input="changed">
        </div>
        <div class="field">
            <label for="calc-down">{{ __('Down payment') }}</label>
            <input id="calc-down" type="text" inputmode="decimal" autocomplete="off" x-model="down" @input="changed">
        </div>
        <div class="field">
            <label for="calc-markup">{{ __('Markup, %') }}</label>
            <input id="calc-markup" type="text" inputmode="decimal" autocomplete="off" x-model="markup" @input="changed">
        </div>
        <div class="field">
            <label for="calc-count">{{ __('Instalments') }}</label>
            <select id="calc-count" x-model="count" @change="changed">
                @foreach ([3, 6, 9, 12, 18, 24, 36] as $n)
                    <option value="{{ $n }}" @selected($n === 6)>{{ $n }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="calc-currency">{{ __('Currency') }}</label>
            <select id="calc-currency" x-model="currency">
                @foreach ($currencies as $code)
                    <option value="{{ $code }}" @selected($code === $currency)>{{ $code }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="calc-result" aria-live="polite">
        <p class="field-error" x-show="hasError" x-text="error" role="alert"></p>

        <div x-show="hasResult">
            <p class="calc-each">
                <span class="calc-big money" x-text="each">{{ $initialEach }}</span>
                <span class="calc-sub">{{ __('per month') }}</span>
            </p>

            <dl class="calc-totals">
                <div><dt>{{ __('Instalments') }}</dt><dd class="money" x-text="times">{{ count($initialSchedule['installments']) }}</dd></div>
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
    </div>

    <a class="btn btn-gold btn-block calc-cta" href="{{ route('register') }}">{{ __('Start free') }}</a>
</form>
