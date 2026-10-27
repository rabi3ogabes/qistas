{{-- Free workspace, card payments on: the way to Pro is one tap away (spec 2026-10-27 §4.1). --}}
@php
    use App\Support\Format;
    use App\Support\Money;
@endphp
<section class="card card-pad card-offer" aria-labelledby="card-offer-title" style="margin-top:1rem">
    <div class="card-offer-head">
        <h2 id="card-offer-title" class="display card-offer-title">{{ __('Move to Pro') }}</h2>
        @if ($trialDays > 0)<span class="badge badge-ok">{{ __(':days-day free trial', ['days' => $trialDays]) }}</span>@endif
    </div>
    <p class="muted">{{ __('Pay by card, Apple Pay or Google Pay. Cancel any time; nothing is ever deleted.') }}</p>

    @foreach ($paidPlans as $plan)
        @php
            $offer = $offers->first(fn ($o) => $o->plan->is($plan));
            $yearly = $plan->price_yearly !== null && Money::isPositive($plan->price_yearly);
            $monthly = $plan->price_monthly !== null && Money::isPositive($plan->price_monthly);
        @endphp
        <div class="card-offer-plan">
            <p class="card-offer-price">
                @if ($monthly)<span class="money">{{ Format::money($plan->price_monthly, $plan->currency) }}</span> <span class="muted">{{ __('a month') }}</span>@endif
                @if ($monthly && $yearly)<span class="card-offer-sep" aria-hidden="true">·</span>@endif
                @if ($yearly)<span class="money">{{ Format::money($plan->price_yearly, $plan->currency) }}</span> <span class="muted">{{ __('a year') }}</span>@endif
                @if ($offer?->yearlySavingPercent())<span class="checkout-saving">{{ __('Save :percent%', ['percent' => $offer->yearlySavingPercent()]) }}</span>@endif
            </p>
            <div class="card-offer-actions">
                <a class="btn btn-gold" href="{{ route('app.billing.checkout', ['plan' => $plan->key, 'period' => $yearly ? 'year' : 'month']) }}">{{ __('Pay by card') }}</a>
                @if ($monthly && $yearly)
                    <a class="btn btn-quiet" href="{{ route('app.billing.checkout', ['plan' => $plan->key, 'period' => 'month']) }}">{{ __('Pay monthly') }}</a>
                @endif
            </div>
        </div>
    @endforeach
</section>
