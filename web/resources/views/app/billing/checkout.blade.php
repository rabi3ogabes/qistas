{{-- Pro by card: the checkout (spec 2026-10-27 §4.1). The left column is the decision, read in plain words: the plan, the
     period, what happens when, and the total; the right column is Stripe's secure fields, wearing the Qistas palette. On a
     phone the decision comes first. Stripe.js is loaded by resources/js/card-checkout.js, never by a tag here. --}}
@php
    use App\Support\Dates;
    use App\Support\Format;

    $money = fn (?string $amount) => $amount === null ? '' : Format::money($amount, $plan->currency);
    $periodWord = $period === 'year' ? __('a year') : __('a month');
    $charged = $money($price);
@endphp
<x-layouts.app :title="__('Upgrade to :plan', ['plan' => $plan->name])" section="billing">
    <x-page-head :title="__('Upgrade to :plan', ['plan' => $plan->name])">
        <x-slot:subtitle>{{ __('Pay by card, Apple Pay or Google Pay. Cancel any time.') }}</x-slot:subtitle>
    </x-page-head>

    <div class="checkout"
        data-card-checkout
        data-session-url="{{ route('app.billing.card.session') }}"
        data-plan="{{ $plan->key }}"
        data-period="{{ $period }}"
        data-attempt="{{ $attempt }}"
        data-failed="{{ __('Card payments are briefly unavailable. Nothing was charged; try again in a minute.') }}"
        data-promo-applied="{{ __('Code applied.') }}">

        <section class="card checkout-summary" aria-labelledby="checkout-plan">
            <h2 id="checkout-plan" class="display checkout-plan">Qistas {{ $plan->name }}</h2>

            @if ($prices['month'] !== null && $prices['year'] !== null)
                <nav class="checkout-periods" aria-label="{{ __('How often to pay') }}">
                    <a href="{{ route('app.billing.checkout', ['plan' => $plan->key, 'period' => 'month']) }}" @if ($period === 'month') aria-current="page" @endif>{{ __('Monthly') }}</a>
                    <a href="{{ route('app.billing.checkout', ['plan' => $plan->key, 'period' => 'year']) }}" @if ($period === 'year') aria-current="page" @endif>
                        {{ __('Yearly') }}
                        @if ($saving)<span class="checkout-saving">{{ __('Save :percent%', ['percent' => $saving]) }}</span>@endif
                    </a>
                </nav>
            @endif

            <p class="checkout-price"><span class="money">{{ $charged }}</span> <span class="checkout-per">{{ $periodWord }}</span></p>

            <ol class="checkout-timeline">
                @if ($trialEnd)
                    <li><span class="checkout-when">{{ __('Today') }}</span><span>{{ __('Free trial starts. Nothing to pay today.') }}</span></li>
                    <li><span class="checkout-when">{{ Dates::day($trialEnd, 'j F Y') }}</span><span>{{ __('First charge of :amount', ['amount' => $charged]) }}</span></li>
                    <li><span class="checkout-when">{{ __('Any time before') }}</span><span>{{ __('Cancel in Plan and billing and you pay nothing.') }}</span></li>
                @else
                    <li><span class="checkout-when">{{ __('Today') }}</span><span>{{ __(':amount charged to your card', ['amount' => $charged]) }}</span></li>
                    <li><span class="checkout-when">{{ Dates::day($renewsOn, 'j F Y') }}</span><span>{{ __('Renews at the same price, until you cancel') }}</span></li>
                @endif
            </ol>

            @if ($unlocked !== [])
                <h3 class="checkout-list-title">{{ __('What :plan opens', ['plan' => $plan->name]) }}</h3>
                <ul class="pro-list checkout-list">
                    @foreach ($unlocked as $line)
                        <li><x-icon name="check" :size="18" /><span>{{ $line }}</span></li>
                    @endforeach
                </ul>
            @endif

            @if ($promotionCodes)
                <form class="field checkout-promo" data-card-promo novalidate>
                    <label for="checkout-promo-code">{{ __('Promotion code') }}</label>
                    <div class="checkout-promo-row">
                        <input id="checkout-promo-code" name="code" autocomplete="off" autocapitalize="characters" spellcheck="false" dir="ltr">
                        <button type="submit" class="btn btn-quiet">{{ __('Apply') }}</button>
                    </div>
                    <p class="field-hint" data-card-promo-message role="status"></p>
                </form>
            @endif

            <div class="checkout-total">
                <span>{{ __('Due today') }}</span>
                <strong class="money" data-card-total>{{ $trialEnd ? Format::money('0', $plan->currency) : $charged }}</strong>
            </div>
        </section>

        <section class="card checkout-pay" aria-labelledby="checkout-pay-title">
            <h2 id="checkout-pay-title" class="checkout-pay-title">{{ __('Payment') }}</h2>

            <div class="checkout-express" data-card-express hidden>
                <div id="card-express"></div>
                <p class="checkout-or"><span>{{ __('or pay by card') }}</span></p>
            </div>

            <div id="card-payment" class="checkout-element">
                <div class="skeleton checkout-skeleton" aria-hidden="true"></div>
                <div class="skeleton checkout-skeleton is-short" aria-hidden="true"></div>
            </div>

            <p class="checkout-email">{{ __('Receipts go to :email', ['email' => $email]) }}</p>

            <p class="alert alert-error checkout-error" data-card-error role="alert" hidden></p>

            <button type="button" class="btn btn-gold btn-block checkout-button" data-card-pay disabled>
                {{ $trialEnd ? __('Start my free trial') : __('Pay :amount', ['amount' => $charged]) }}
            </button>

            <p class="checkout-terms">
                {{ $period === 'year' ? __('Renews every year until you cancel. Cancel any time in Plan and billing.') : __('Renews every month until you cancel. Cancel any time in Plan and billing.') }}
            </p>
            <p class="checkout-secure"><x-icon name="lock" :size="16" /><span>{{ __('Payments are handled by Stripe. Qistas never sees your card number.') }}</span></p>
        </section>
    </div>
</x-layouts.app>
