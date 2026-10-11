{{-- The instalment calculator for search (Win Plan PP15): the plan in the address, the contract engine's numbers, and
     the questions people ask, also given to search engines as FAQPage data. --}}
<x-layouts.site :title="__('Instalment calculator')" path="/installment-calculator"
    :description="__('Work out an instalment plan in seconds: each instalment, the total to repay and the last date, from the price, the down payment, the profit and the number of months. Free, in five languages.')">
    <x-slot:head>
        <script type="application/ld+json">{!! json_encode($faqData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    </x-slot:head>

    <section class="hero calc-hero">
        <div class="container hero-grid">
            <div>
                <h1 class="display">{{ __('Instalment calculator') }}</h1>
                <p class="hero-lead">{{ __('Type the price, the down payment, the profit and the number of months, and see each instalment, the total and the last date at once.') }}</p>
                <ul class="calc-points">
                    <li><x-icon name="check" :size="18" /> {{ __('The same engine that writes contracts in Qistas, to the cent.') }}</li>
                    <li><x-icon name="check" :size="18" /> {{ __('Send the plan to a customer: the link opens with the same numbers.') }}</li>
                    <li><x-icon name="check" :size="18" /> {{ __('Free, with no sign-up.') }}</li>
                </ul>
            </div>
            @include('site.partials.calculator', ['share' => true])
        </div>
    </section>

    <section class="section section-alt">
        <div class="container">
            <h2 class="display">{{ __('Questions about instalments') }}</h2>
            <div class="faq">
                @foreach ($faq as [$question, $answer])
                    <details>
                        <summary>{{ $question }} <x-icon name="chevronDown" :size="20" /></summary>
                        <div class="faq-answer"><p>{{ $answer }}</p></div>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    <section class="cta-band">
        <div class="container cta-band-inner">
            <h2 class="display">{{ __('Keep every instalment in order.') }}</h2>
            <p>{{ __('Turn a plan into a contract, take payments and see who is late, in Arabic and English. Free to start.') }}</p>
            <a class="btn btn-gold btn-lg" href="{{ route('register') }}">{{ __('Start free') }}</a>
        </div>
    </section>
</x-layouts.site>
