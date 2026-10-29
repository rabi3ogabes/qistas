{{-- The day's other payments and what is worth a look, as the server worked them out (DayTotals): shown the same whether the
     day is open or closed. A name or a note is whatever a person typed, so it is only ever printed escaped, and never a
     customer's phone or ID: the figures carry none. --}}
@php
    use App\Support\Money;
    use App\Support\PaymentMethods;

    $kinds = [
        'void' => __('Payment voided'),
        'waived_fee' => __('Late fee waived'),
        'back_dated' => __('Back-dated payment'),
        'opened' => __('Contract opened'),
        'cancelled' => __('Contract cancelled'),
    ];
@endphp

<section class="card" aria-labelledby="other-title">
    <div class="card-head"><h2 id="other-title">{{ __('Other payments') }}</h2></div>
    @if ($figures['non_cash'] === [])
        <div class="empty empty-quiet">
            <span class="empty-icon"><x-icon name="card" :size="24" /></span>
            <p>{{ __('No other payments recorded for this day.') }}</p>
        </div>
    @else
        <ul class="rows">
            @foreach ($figures['non_cash'] as $line)
                @php($voided = Money::isNegative($line['amount']))
                <li data-line="{{ $voided ? 'reversal' : 'payment' }}">
                    <span class="row-main">
                        <span class="row-title" dir="auto">{{ $line['customer'] }}</span>
                        <span class="row-sub">
                            @if ($voided)<span class="danger-text">{{ __('Payment voided') }}</span>@else<span>{{ PaymentMethods::label($line['method']) }}</span>@endif
                            @if ($line['note'])<span dir="auto"> · {{ $line['note'] }}</span>@endif
                        </span>
                    </span>
                    <span class="money row-amount">{{ $money($line['amount']) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>

<section class="card" aria-labelledby="watch-title">
    <div class="card-head"><h2 id="watch-title">{{ __('Worth a look') }}</h2></div>
    @if ($figures['watch'] === [])
        <div class="empty empty-quiet">
            <span class="empty-icon"><x-icon name="shield" :size="24" /></span>
            <p>{{ __('Nothing out of the ordinary on this day.') }}</p>
        </div>
    @else
        <ul class="rows">
            @foreach ($figures['watch'] as $item)
                <li data-kind="{{ $item['kind'] }}">
                    <span class="row-main">
                        <span class="row-title" dir="auto">{{ $item['label'] }}</span>
                        <span class="row-sub">{{ $kinds[$item['kind']] }}</span>
                    </span>
                    @if ($item['amount'] !== null)<span class="money row-amount">{{ $money($item['amount']) }}</span>@endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
