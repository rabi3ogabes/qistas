@php
    use App\Entitlements\Feature;
    use App\Support\Format;

    // The example ledger always looks current: instalments fall on the 1st of the next three months.
    $d1 = now()->startOfMonth()->addMonth();
    $dates = [$d1, $d1->copy()->addMonth(), $d1->copy()->addMonths(2)];
    $fmt = fn (string $a) => Format::money($a, $currency);
    $freeOffer = $offers->first();
    $freeHighlights = $freeOffer
        ? collect([Feature::Customers, Feature::ActiveContracts])->map(fn ($f) => $freeOffer->feature($f))->filter(fn ($r) => $r['enabled'])->pluck('summary')
        : collect();

    // The hero picture an admin chose in Appearance, behind the headline under a veil of the hero colours.
    $look = rescue(fn () => app(\App\Theme\Appearance::class)->current(request()), null, false);
    $heroPicture = $look?->imageUrl('hero');
    [$heroWidth, $heroHeight] = $look?->imageSize('hero') ?? [null, null];
@endphp
<x-layouts.site :title="__('Every instalment, to the cent.')" path="/">
    <section class="hero{{ $heroPicture ? ' hero-has-pic' : '' }}">
        @if ($heroPicture)
            <img class="hero-pic" src="{{ $heroPicture }}" alt="" width="{{ $heroWidth ?? 1600 }}" height="{{ $heroHeight ?? 900 }}" decoding="async" fetchpriority="high">
        @endif
        <div class="container hero-grid">
            <div>
                <h1 class="display">{{ __('Every instalment, to the cent.') }}</h1>
                <p class="hero-lead">
                    {{ __('Qistas keeps your customers, contracts and payments in order, in Arabic and English.') }}
                    {{ $freeCustomers !== null ? trans_choice('site.free_first_customers', $freeCustomers) : __('Free to start, no card needed.') }}
                </p>
                <div class="hero-actions">
                    <a class="btn btn-gold btn-lg" href="{{ route('register') }}">{{ __('Start free') }}</a>
                    <a class="btn btn-on-dark btn-lg" href="{{ route('pricing') }}">{{ __('See pricing') }}</a>
                </div>
            </div>
            @include('site.partials.calculator')
        </div>
    </section>

    <section id="how" class="section">
        <div class="container how-grid">
            <div>
                <h2 class="display">{{ __('Payments land on the oldest instalment first.') }}</h2>
                <p class="section-lead">{{ __('Record what a customer paid and Qistas applies it from the oldest unpaid instalment forward, so you always see exactly what was settled. A mistake is undone with a reversal, never by editing history.') }}</p>
            </div>

            <div class="card ledger" role="group" aria-labelledby="ledger-title">
                <div class="ledger-head">
                    <h3 id="ledger-title">{{ __('A contract, after one payment') }}</h3>
                    <span class="badge">{{ __('Example data') }}</span>
                </div>
                <div class="scroll">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Due date') }}</th>
                            <th scope="col" class="num">{{ __('Instalment') }}</th>
                            <th scope="col" class="num">{{ __('Paid') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>{{ $dates[0]->isoFormat('D MMM') }}</td>
                            <td class="num money">{{ $fmt('100') }}</td>
                            <td class="num money">{{ $fmt('100') }}</td>
                            <td><span class="badge badge-ok">{{ __('Paid') }}</span></td>
                        </tr>
                        <tr>
                            <td>{{ $dates[1]->isoFormat('D MMM') }}</td>
                            <td class="num money">{{ $fmt('100') }}</td>
                            <td class="num money">{{ $fmt('50') }}</td>
                            <td><span class="badge badge-warn">{{ __('Partly paid') }}</span></td>
                        </tr>
                        <tr>
                            <td>{{ $dates[2]->isoFormat('D MMM') }}</td>
                            <td class="num money">{{ $fmt('100') }}</td>
                            <td class="num money">{{ $fmt('0') }}</td>
                            <td><span class="badge">{{ __('Upcoming') }}</span></td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <div class="ledger-apply">
                    <p><span>{{ __('Payment received') }}:</span> <span class="money">{{ $fmt('150') }}</span></p>
                    <p>{{ __('Applied to the oldest instalments first') }}: <span class="money">{{ $dates[0]->isoFormat('D MMM') }} {{ $fmt('100') }}</span>, <span class="money">{{ $dates[1]->isoFormat('D MMM') }} {{ $fmt('50') }}</span></p>
                </div>
            </div>
        </div>
    </section>

    <section id="plans" class="section section-alt">
        <div class="container">
            <h2 class="display">{{ __('Start free. Upgrade when you need more.') }}</h2>
            <p class="section-lead">{{ __('Every plan shows exactly what it includes. Change your mind at any time; your records stay yours.') }}</p>
            @include('site.partials.plans', ['interactive' => false])
            <p style="margin-top:1.5rem"><a class="link" href="{{ route('pricing') }}">{{ __('Compare plans in detail') }}</a></p>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <h2 class="display">{{ __('Built to be trusted with money.') }}</h2>
            <dl class="principles">
                <div>
                    <dt>{{ __('Exact to the cent') }}</dt>
                    <dd>{{ __('Amounts are stored and calculated as exact decimals, never as rounded floating-point numbers, so instalments always add up to the total.') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Your language, laid out properly') }}</dt>
                    <dd>{{ __('Arabic is set right to left throughout, not mirrored as an afterthought. Switch language at any time; amounts always use the digits you typed.') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Safe by design') }}</dt>
                    <dd>{{ __('Each business’s data is kept apart from every other’s. National IDs are encrypted, sign-in can require a second step, and sensitive actions are written to an audit log.') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Made for the phone in your hand') }}</dt>
                    <dd>{{ __('The whole product works in a phone’s browser, with large tap targets and numbers that line up. The iPhone and Android app is on its way.') }}</dd>
                </div>
            </dl>
        </div>
    </section>

    <section id="faq" class="section section-alt">
        <div class="container">
            <h2 class="display">{{ __('Questions people ask first') }}</h2>
            <div class="faq">
                <details>
                    <summary>{{ __('Is it really free?') }} <x-icon name="chevronDown" :size="20" /></summary>
                    <div class="faq-answer">
                        <p>{{ __('Yes. The Free plan has no time limit and needs no card.') }}</p>
                        @if ($freeHighlights->isNotEmpty())
                            <ul>@foreach ($freeHighlights as $line)<li>{{ $line }}</li>@endforeach</ul>
                        @endif
                    </div>
                </details>
                <details>
                    <summary>{{ __('What happens if I reach the free limit?') }} <x-icon name="chevronDown" :size="20" /></summary>
                    <div class="faq-answer"><p>{{ __('Nothing breaks. You keep every record and can still record payments. You just cannot add more customers or contracts until you upgrade or free up a place.') }}</p></div>
                </details>
                <details>
                    <summary>{{ __('Can I go back from Pro to Free?') }} <x-icon name="chevronDown" :size="20" /></summary>
                    <div class="faq-answer"><p>{{ __('Yes, and nothing is deleted. If you are over the Free limits you keep everything you have and simply cannot add more until you are back under.') }}</p></div>
                </details>
                <details>
                    <summary>{{ __('How do I pay for Pro?') }} <x-icon name="chevronDown" :size="20" /></summary>
                    <div class="faq-answer"><p>{{ __('By card, monthly or yearly. Cancel at any time and you keep Pro until the end of the period you paid for.') }}</p></div>
                </details>
                <details>
                    <summary>{{ __('Which payment methods can I record?') }} <x-icon name="chevronDown" :size="20" /></summary>
                    <div class="faq-answer"><p>{{ __('Cash, bank transfer, card and cheque, or anything else under “other”.') }}</p></div>
                </details>
                <details>
                    <summary>{{ __('Is there a mobile app?') }} <x-icon name="chevronDown" :size="20" /></summary>
                    <div class="faq-answer"><p>{{ __('The iPhone and Android app is in development. Qistas works in your phone’s browser today, with the same account.') }}</p></div>
                </details>
                <details>
                    <summary>{{ __('Is my data safe?') }} <x-icon name="chevronDown" :size="20" /></summary>
                    <div class="faq-answer"><p>{{ __('Your data is kept apart from every other business, national IDs are encrypted, and you can turn on two-step sign-in. Every sensitive action is recorded in an audit log.') }}</p></div>
                </details>
            </div>
        </div>
    </section>

    <section class="cta-band">
        <div class="container cta-band-inner">
            <h2 class="display">{{ __('Open your free workspace.') }}</h2>
            <p>{{ __('It takes a minute. No card needed.') }}</p>
            <a class="btn btn-gold btn-lg" href="{{ route('register') }}">{{ __('Start free') }}</a>
        </div>
    </section>
</x-layouts.site>
