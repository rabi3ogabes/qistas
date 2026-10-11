@php
    // The plan it opens with: the example, or (on the calculator page) the one in the address. $share adds the link
    // that carries the plan (Win Plan PP15).
    $plan ??= ['price' => '1200', 'down' => '200', 'markup' => '10', 'count' => '6'];
    $share ??= false;
    $counts = array_values(array_unique([3, 6, 9, 12, 18, 24, 36, (int) $plan['count']]));
    sort($counts);
    $rows = $initialSchedule['installments'];
    $last = $rows === [] ? null : \Illuminate\Support\Carbon::parse(end($rows)['due_date']);
    $config = [
        'url' => route('schedule.preview'),
        'csrf' => csrf_token(),
        'locale' => app()->getLocale().'-u-nu-latn',
        'currency' => $currency,
        'initial' => $initialSchedule,
        'plan' => $plan,
        'share' => $share ? route('calculator') : null,
        'messages' => [
            'unavailable' => __('The calculator is not available right now. Please try again in a moment.'),
            'copied' => __('Link copied'),
            'share' => __('Copy the link to this plan'),
        ],
    ];
@endphp
<form class="card calc" x-data="planCalculator" data-config="{{ json_encode($config) }}" :data-loading="isLoading" @submit.prevent aria-labelledby="calc-title" novalidate>
    <h2 id="calc-title" class="display">{{ $share ? __('Your plan') : __('Try an instalment plan') }}</h2>

    <div class="calc-fields">
        <div class="field field-wide">
            <label for="calc-price">{{ __('Price') }}</label>
            <input id="calc-price" type="text" inputmode="decimal" autocomplete="off" x-model="price" @input="changed" value="{{ $plan['price'] }}">
        </div>
        <div class="field">
            <label for="calc-down">{{ __('Down payment') }}</label>
            <input id="calc-down" type="text" inputmode="decimal" autocomplete="off" x-model="down" @input="changed" value="{{ $plan['down'] }}">
        </div>
        <div class="field">
            <label for="calc-markup">{{ __('Markup, %') }}</label>
            <input id="calc-markup" type="text" inputmode="decimal" autocomplete="off" x-model="markup" @input="changed" value="{{ $plan['markup'] }}">
        </div>
        <div class="field">
            <label for="calc-count">{{ __('Instalments') }}</label>
            <select id="calc-count" x-model="count" @change="changed">
                @foreach ($counts as $n)
                    <option value="{{ $n }}" @selected($n === (int) $plan['count'])>{{ $n }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="calc-currency">{{ __('Currency') }}</label>
            <select id="calc-currency" x-model="currency" @change="moved">
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
                <div><dt>{{ __('Instalments') }}</dt><dd class="money" x-text="times">{{ count($rows) }}</dd></div>
                <div><dt>{{ __('Markup') }}</dt><dd class="money" x-text="markupAmount">{{ \App\Support\Format::money($initialSchedule['markup'], $currency) }}</dd></div>
                <div><dt>{{ __('Total to repay') }}</dt><dd class="money" x-text="total">{{ \App\Support\Format::money($initialSchedule['total'], $currency) }}</dd></div>
                @if ($share && $last)
                    <div><dt>{{ __('Last instalment') }}</dt><dd class="money" x-text="lastDate">{{ $last->translatedFormat('j M Y') }}</dd></div>
                @endif
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

    @if ($share)
        <div class="calc-share">
            <button class="btn btn-quiet btn-block" type="button" @click="copy"><x-icon name="copy" :size="18" /> <span x-text="shareLabel">{{ __('Copy the link to this plan') }}</span></button>
        </div>
    @endif
    <a class="btn btn-gold btn-block calc-cta" href="{{ route('register') }}">{{ __('Start free') }}</a>
</form>
