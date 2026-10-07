{{-- The plans, straight from what the admin has switched on. $interactive adds the monthly/yearly switch. --}}
@php($interactive ??= false)
<div @if ($interactive) x-data="billingInterval" @endif>
    @if ($interactive && $offers->contains(fn ($o) => $o->yearlyPrice() !== null && ! $o->isFree()))
        <div class="interval" role="group" aria-label="{{ __('Billing period') }}">
            <button type="button" @click="setMonthly" :aria-pressed="isMonthly">{{ __('Monthly') }}</button>
            <button type="button" @click="setYearly" :aria-pressed="isYearly">{{ __('Yearly') }}</button>
        </div>
    @endif

    <div class="plans">
        @foreach ($offers as $offer)
            @php($paid = ! $offer->isFree())
            <article class="card plan" data-featured="{{ $paid ? 'true' : 'false' }}">
                <h3 class="plan-name">{{ $offer->plan->name }}</h3>

                <p class="plan-price">
                    @if ($offer->isFree())
                        <span class="plan-amount display">{{ __('Free') }}</span>
                        <span class="plan-per">{{ __('no time limit') }}</span>
                    @elseif ($offer->monthlyPrice() === null)
                        <span class="plan-amount display">{{ __('Custom') }}</span>
                    @else
                        <span class="plan-amount money" @if ($interactive) x-show="isMonthly" @endif>{{ \App\Support\Format::money($offer->monthlyPrice(), $offer->plan->currency) }}</span>
                        @if ($interactive && $offer->yearlyPrice() !== null)
                            <span class="plan-amount money" x-show="isYearly" x-cloak>{{ \App\Support\Format::money($offer->yearlyPrice(), $offer->plan->currency) }}</span>
                        @endif
                        <span class="plan-per" @if ($interactive) x-show="isMonthly" @endif>{{ __('per month') }}</span>
                        @if ($interactive && $offer->yearlyPrice() !== null)
                            <span class="plan-per" x-show="isYearly" x-cloak>{{ __('per year') }}</span>
                        @endif
                    @endif
                </p>

                @if ($interactive && $paid && $offer->yearlySavingPercent())
                    <p class="plan-saving" x-show="isYearly" x-cloak>{{ __('Save :percent% compared with paying monthly.', ['percent' => $offer->yearlySavingPercent()]) }}</p>
                @endif

                <ul class="plan-list">
                    @foreach ($offer->features() as $row)
                        <li data-included="{{ $row['enabled'] ? 'true' : 'false' }}">
                            <x-icon :name="$row['enabled'] ? 'check' : 'x'" :size="18" />
                            @if ($row['enabled'])
                                <span>{{ $row['summary'] }}</span>
                            @else
                                <span>{{ $row['feature']->label() }} <span class="sr-only">— {{ __('Not included') }}</span></span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <a class="btn btn-lg {{ $paid ? 'btn-gold' : '' }}" href="{{ route('register') }}">
                    {{ $paid ? __('Start free, upgrade anytime') : __('Start free') }}
                </a>
            </article>
        @endforeach
    </div>
</div>
