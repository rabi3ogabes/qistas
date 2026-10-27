{{-- Where a card subscription stands and the ways to change it (spec 2026-10-27 §4.3). Everything here was read from Stripe
     a moment ago (CardManagement::overview). The two steps that need Stripe.js (a new card, a payment the bank holds) are
     driven by resources/js/card-billing.js; the rest are plain forms that work without script. --}}
@php
    use App\Support\Dates;
    use App\Support\Format;

    $until = Dates::day($card['until'], 'j F Y');
    $amount = $card['next_amount'] !== null ? Format::money($card['next_amount'], strtoupper($card['currency'])) : null;
    $other = $card['period'] === 'year' ? 'month' : 'year';
    $brands = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express', 'discover' => 'Discover', 'diners' => 'Diners Club', 'jcb' => 'JCB', 'unionpay' => 'UnionPay', 'mada' => 'mada'];
    $wallets = ['apple_pay' => 'Apple Pay', 'google_pay' => 'Google Pay', 'link' => 'Link'];
    $method = $card['card'];
@endphp
<div class="card-plan"
    data-card-billing
    data-setup-url="{{ route('app.billing.card.setup') }}"
    data-confirm-url="{{ route('app.billing.card.confirm') }}"
    data-preview-url="{{ route('app.billing.card.switch.preview') }}"
    data-failed="{{ __('Card payments are briefly unavailable. Nothing was charged; try again in a minute.') }}"
    data-text-now="{{ __('Charged now: :amount, with the unused time of this month taken off.') }}"
    data-text-later="{{ __('From :date you pay :amount a month. Nothing changes before then.') }}">

    @if ($card['needs_action'])
        <div class="alert alert-warning card-alert" role="status">
            <span>{{ __('Your bank asks you to confirm the payment.') }}</span>
            <button type="button" class="btn btn-sm" data-card-confirm>{{ __('Confirm payment') }}</button>
        </div>
    @elseif ($card['status'] === 'past_due')
        <div class="alert alert-error card-alert" role="status">
            <span>{{ __('We could not take the last payment. Update your card; Pro stays on while Stripe tries again.') }}</span>
            <button type="button" class="btn btn-sm" data-open-sheet="card-change-sheet" data-card-change>{{ __('Update card') }}</button>
        </div>
    @endif

    <p class="card-status">
        @if ($card['status'] === 'trialing')
            {{ $card['period'] === 'year'
                ? __('Free trial until :date, then :amount a year.', ['date' => Dates::day($card['trial_end'] ?? $card['until'], 'j F Y'), 'amount' => (string) $amount])
                : __('Free trial until :date, then :amount a month.', ['date' => Dates::day($card['trial_end'] ?? $card['until'], 'j F Y'), 'amount' => (string) $amount]) }}
        @elseif ($card['cancel_at_period_end'])
            {{ __('Ends on :date. Renewal is off.', ['date' => $until]) }}
        @elseif ($amount !== null)
            {{ __('Renews on :date for :amount.', ['date' => $until, 'amount' => $amount]) }}
        @else
            {{ __('Until :date.', ['date' => $until]) }}
        @endif
    </p>
    @if ($card['scheduled_period'])
        <p class="card-note">{{ $card['scheduled_period'] === 'month' ? __('Monthly from :date.', ['date' => $until]) : __('Yearly from :date.', ['date' => $until]) }}</p>
    @endif

    <div class="card-method">
        <x-icon name="card" :size="22" />
        <div>
            @if ($method)
                <p class="card-method-name">{{ __(':brand ending :last4', ['brand' => $brands[$method->brand] ?? ucfirst($method->brand), 'last4' => $method->last4]) }}@if ($method->wallet && isset($wallets[$method->wallet])) <span class="badge">{{ $wallets[$method->wallet] }}</span>@endif</p>
                <p class="card-method-sub">{{ __('Expires :month/:year', ['month' => sprintf('%02d', $method->expMonth), 'year' => $method->expYear]) }}</p>
            @else
                <p class="card-method-name">{{ __('No card on file') }}</p>
            @endif
        </div>
        <button type="button" class="btn btn-quiet btn-sm" data-open-sheet="card-change-sheet" data-card-change>{{ __('Change card') }}</button>
    </div>

    <div class="card-actions">
        @if ($card['scheduled_period'])
            <form method="POST" action="{{ route('app.billing.card.switch') }}">
                @csrf
                <input type="hidden" name="period" value="{{ $card['period'] }}">
                <button class="btn btn-quiet btn-sm">{{ $card['period'] === 'year' ? __('Keep yearly') : __('Keep monthly') }}</button>
            </form>
        @elseif (! $card['cancel_at_period_end'])
            <button type="button" class="btn btn-quiet btn-sm" data-open-sheet="card-switch-sheet" data-card-switch data-period="{{ $other }}">{{ $other === 'month' ? __('Switch to monthly') : __('Switch to yearly') }}</button>
        @endif

        @if ($card['cancel_at_period_end'])
            <form method="POST" action="{{ route('app.billing.card.resume') }}">
                @csrf
                <button class="btn btn-gold btn-sm">{{ __('Resume renewal') }}</button>
            </form>
        @else
            <button type="button" class="btn btn-quiet btn-sm card-cancel" data-open-sheet="card-cancel-sheet">{{ __('Cancel renewal') }}</button>
        @endif
    </div>

    {{-- A new card: Stripe's secure field in a sheet. --}}
    <dialog class="sheet" id="card-change-sheet" aria-labelledby="card-change-title">
        <div class="sheet-body">
            <h2 id="card-change-title" class="sheet-title display">{{ __('Change card') }}</h2>
            <p class="muted">{{ __('The new card is charged from the next payment on. Nothing is charged now.') }}</p>
            <div id="card-setup" class="checkout-element">
                <div class="skeleton checkout-skeleton" aria-hidden="true"></div>
            </div>
            <p class="alert alert-error" data-card-setup-error role="alert" hidden></p>
            <div class="sheet-actions">
                <button type="button" class="btn btn-gold btn-block" data-card-setup-save disabled>{{ __('Save card') }}</button>
                <button type="button" class="btn btn-quiet btn-block" data-close-sheet>{{ __('Keep the current card') }}</button>
            </div>
        </div>
    </dialog>

    @unless ($card['cancel_at_period_end'] || $card['scheduled_period'])
        <dialog class="sheet" id="card-switch-sheet" aria-labelledby="card-switch-title">
            <form method="POST" action="{{ route('app.billing.card.switch') }}" class="sheet-body">
                @csrf
                <input type="hidden" name="period" value="{{ $other }}">
                <h2 id="card-switch-title" class="sheet-title display">{{ $other === 'month' ? __('Switch to monthly') : __('Switch to yearly') }}</h2>
                <p data-card-switch-preview>{{ $other === 'month' ? __('Monthly starts when the year you paid for ends.') : __('Yearly starts now; the unused time of this month is taken off the first charge.') }}</p>
                <div class="sheet-actions">
                    <button class="btn btn-gold btn-block">{{ $other === 'month' ? __('Switch to monthly') : __('Switch to yearly') }}</button>
                    <button type="button" class="btn btn-quiet btn-block" data-close-sheet>{{ __('Keep it as it is') }}</button>
                </div>
            </form>
        </dialog>

        <dialog class="sheet" id="card-cancel-sheet" aria-labelledby="card-cancel-title">
            <form method="POST" action="{{ route('app.billing.card.cancel') }}" class="sheet-body">
                @csrf
                <h2 id="card-cancel-title" class="sheet-title display">{{ __('Cancel renewal') }}</h2>
                <p>{{ __('Pro stays until :date. You won\'t be charged again, and nothing is deleted.', ['date' => $until]) }}</p>
                <fieldset class="field choice">
                    <legend class="field-label">{{ __('What made you decide? (optional)') }}</legend>
                    @foreach (['too_expensive' => __('It costs too much'), 'missing_feature' => __('Something I need is missing'), 'not_using' => __('I am not using it enough'), 'switching' => __('I am moving to another tool'), 'other' => __('Something else')] as $value => $label)
                        <label class="choice-item"><input type="radio" name="reason" value="{{ $value }}"> <span>{{ $label }}</span></label>
                    @endforeach
                </fieldset>
                <div class="field">
                    <label for="card-cancel-details">{{ __('Anything to add? (optional)') }}</label>
                    <textarea id="card-cancel-details" name="details" rows="3" maxlength="500"></textarea>
                </div>
                <div class="sheet-actions">
                    <button class="btn btn-danger btn-block">{{ __('Cancel renewal') }}</button>
                    <button type="button" class="btn btn-quiet btn-block" data-close-sheet>{{ __('Keep Pro') }}</button>
                </div>
            </form>
        </dialog>
    @endunless
</div>
