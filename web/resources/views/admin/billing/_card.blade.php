{{-- Card payments (Stripe), spec 2026-10-27 §8: where the connection stands, the two keys (saved and connected in one
     step), Connect again and Disconnect, the checkout's options, and every paid price checked against what Stripe can
     charge. Keys are never shown back whole. Staff look; a super admin changes. --}}
@php
    use App\Support\Format;

    $account = $card['account'];
    $periodName = ['month' => __('Monthly'), 'year' => __('Yearly')];
@endphp
<section class="card card-pad stack stripe" aria-labelledby="stripe-title" style="margin-top:1rem">
    <div class="stripe-head">
        <h2 id="stripe-title" class="card-title">{{ __('Card payments (Stripe)') }}</h2>
        @if ($card['ready'])
            <span class="badge {{ $card['mode'] === 'live' ? 'badge-pro' : 'badge-ok' }}">{{ __('Ready to take cards · :mode', ['mode' => $card['mode'] === 'live' ? __('Live mode') : __('Test mode')]) }}</span>
        @elseif ($card['secret'])
            <span class="badge badge-warn">{{ __('Keys saved, not connected') }}</span>
        @else
            <span class="badge badge-warn">{{ __('Not connected yet') }}</span>
        @endif
    </div>
    <p class="field-hint">{{ __('Owners subscribe on the website by card, Apple Pay or Google Pay. Paste the two keys from Stripe and Qistas sets up the rest: nothing to configure in Stripe.') }}</p>

    @if ($card['ready'] && ! $card['switched_on'])
        <p class="alert alert-info">{{ __('Ready, but Pro by card is switched off in Feature control, so owners do not see it yet.') }} <a class="link" href="{{ route('admin.features.index') }}">{{ __('Open Feature control') }}</a></p>
    @endif
    @if ($card['from_env'])
        <p class="alert alert-info">{{ __('The server’s environment sets the Stripe keys (STRIPE_SECRET), so keys saved here are not used.') }}</p>
    @endif
    @error('stripe')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror

    @if ($account)
        <dl class="bill-facts stripe-account">
            <div><dt>{{ __('Account') }}</dt><dd>{{ $account['name'] ?? __('No business name yet') }} <span class="cell-sub" dir="ltr">{{ $account['id'] }}</span></dd></div>
            <div><dt>{{ __('Country') }}</dt><dd>{{ $account['country'] !== '' ? (\Locale::getDisplayRegion('-'.$account['country'], app()->getLocale()) ?: $account['country']) : '—' }}</dd></div>
            <div><dt>{{ __('Currency') }}</dt><dd dir="ltr">{{ $account['currency'] !== '' ? strtoupper($account['currency']) : '—' }}</dd></div>
            <div><dt>{{ __('Charges') }}</dt><dd>{{ $account['charges_enabled'] ? __('Enabled') : __('Not enabled yet') }}</dd></div>
        </dl>
        @if (! $account['charges_enabled'])
            <p class="alert alert-warning">{{ __('Stripe has not enabled charges on this account yet. Finish activating it in Stripe’s dashboard, then connect again.') }}</p>
        @endif
        @if (! $account['wallets'])
            <p class="field-hint">{{ __('Apple Pay and Google Pay are off: Stripe could not register :domain. Connect again to retry.', ['domain' => parse_url((string) config('app.url'), PHP_URL_HOST)]) }}</p>
        @endif
        <p class="field-hint stripe-webhook">{!! __('Stripe tells Qistas about every payment and renewal at :url', ['url' => '<bdi dir="ltr">'.e($card['webhook_url']).'</bdi>']) !!}</p>
    @endif

    <form method="POST" action="{{ route('admin.billing.card.keys') }}" class="form stack" novalidate>
        @csrf
        @method('PUT')
        <fieldset class="stack bill-group" @disabled(! $canDecide)>
            <legend>{{ __('Keys') }}</legend>
            <x-field name="secret_key" type="password" :label="__('Secret key')" dir="ltr" autocomplete="off" spellcheck="false" placeholder="sk_test_…"
                :hint="$card['secret'] ? __('Saved: :key. Paste both keys again only to replace them.', ['key' => $card['secret']]) : __('In Stripe: Developers, API keys. It starts with sk_test_ or sk_live_ and is stored encrypted.')" />
            <x-field name="publishable_key" type="password" :label="__('Publishable key')" dir="ltr" autocomplete="off" spellcheck="false" placeholder="pk_test_…"
                :hint="__('From the same page and the same mode. Test keys take test cards only; switch to live keys when Stripe has verified the account.')" />
            @if ($canDecide)
                <div><button class="btn btn-gold" type="submit">{{ __('Save and connect') }}</button></div>
            @endif
        </fieldset>
    </form>

    @if ($canDecide && $card['secret'])
        <div class="stripe-actions">
            <form method="POST" action="{{ route('admin.billing.card.connect') }}">
                @csrf
                <button class="btn btn-quiet btn-sm" type="submit">{{ __('Connect again') }}</button>
            </form>
            <details class="bill-reject">
                <summary class="btn btn-ghost btn-sm">{{ __('Disconnect') }}</summary>
                <form method="POST" action="{{ route('admin.billing.card.disconnect') }}" class="stack">
                    @csrf
                    <p class="field-hint">
                        {{ match ($card['running']) {
                            0 => __('No card subscription is running.'),
                            1 => __('One card subscription is running: it keeps renewing in Stripe, but Qistas stops hearing about it until you connect again.'),
                            default => __('Card subscriptions running: :count. They keep renewing in Stripe, but Qistas stops hearing about them until you connect again.', ['count' => $card['running']]),
                        } }}
                        {{ __('The keys are forgotten; the options below stay.') }}
                    </p>
                    <div><button class="btn btn-sm" type="submit">{{ __('Disconnect Stripe') }}</button></div>
                </form>
            </details>
        </div>
        <p class="field-hint">{{ __('Connect again checks the account and makes a new webhook, for example after the site moves to a new address.') }}</p>
    @endif

    <form method="POST" action="{{ route('admin.billing.card.settings') }}" class="form stack" novalidate>
        @csrf
        @method('PUT')
        <fieldset class="stack bill-group" @disabled(! $canDecide)>
            <legend>{{ __('Checkout') }}</legend>
            <x-field name="trial_days" type="number" :label="__('Free trial, in days')" :value="$card['trial_days']" min="0" max="90" inputmode="numeric" class="stripe-days"
                :hint="__('0 for no trial. The card is saved at the start and charged when the trial ends; each workspace has one trial, ever.')" />
            <label class="check">
                <input type="hidden" name="promotion_codes" value="0">
                <input type="checkbox" name="promotion_codes" value="1" @checked(old('promotion_codes', $card['promotion_codes']))>
                <span>{{ __('Let owners enter a promotion code (codes are made in Stripe)') }}</span>
            </label>
            <label class="check">
                <input type="hidden" name="collect_tax_id" value="0">
                <input type="checkbox" name="collect_tax_id" value="1" @checked(old('collect_tax_id', $card['collect_tax_id']))>
                <span>{{ __('Ask businesses for their tax number') }}</span>
            </label>
            <label class="check">
                <input type="hidden" name="automatic_tax" value="0">
                <input type="checkbox" name="automatic_tax" value="1" @checked(old('automatic_tax', $card['automatic_tax']))>
                <span>{{ __('Work out tax with Stripe Tax (set it up in Stripe first; prices already include it)') }}</span>
            </label>
            @if ($canDecide)
                <div><button class="btn btn-gold" type="submit">{{ __('Save card options') }}</button></div>
            @endif
        </fieldset>
    </form>

    <div class="stack stripe-check">
        <h3 class="stripe-check-title">{{ __('Price check') }}</h3>
        <p class="field-hint">{{ __('Each paid plan is sold at its own price, in its own currency. A subscriber keeps the price they joined at until they change how often they pay.') }}</p>
        <ul class="stripe-prices">
            @foreach ($cardPrices as $row)
                <li>
                    <span class="stripe-price-name">{{ __(':plan, :period', ['plan' => $row['plan'], 'period' => $periodName[$row['period']]]) }}</span>
                    @if ($row['price'] === null)
                        <span class="cell-sub">{{ __('Not sold this way') }}</span>
                    @else
                        {{-- A price Stripe would refuse is shown exactly as stored, not rounded into one it would take. --}}
                        <span class="money" dir="ltr">{{ $row['problem'] ? rtrim(rtrim($row['price'], '0'), '.').' '.$row['currency'] : Format::money($row['price'], $row['currency']) }}</span>
                        @if ($row['problem'])
                            <span class="stripe-price-state"><span class="badge badge-bad">{{ __('Cannot be charged') }}</span> <span class="cell-sub">{{ $row['problem'] }}</span></span>
                        @else
                            <span class="stripe-price-state"><span class="badge badge-ok">{{ __('Ready') }}</span></span>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    @unless ($canDecide)
        <p class="field-hint">{{ __('Only a super admin can change these.') }}</p>
    @endunless
</section>
