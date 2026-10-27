{{-- Pro by card: the checkout (spec 2026-10-27 §4.1). Plan Task 9 designs it; the data attributes are what its script reads. --}}
<x-layouts.app :title="__('Upgrade to :plan', ['plan' => $plan->name])" section="billing">
    <x-page-head :title="__('Upgrade to :plan', ['plan' => $plan->name])" />

    <section class="card card-pad"
        data-card-checkout
        data-session-url="{{ route('app.billing.card.session') }}"
        data-plan="{{ $plan->key }}"
        data-period="{{ $period }}"
        data-attempt="{{ $attempt }}">
        <div id="card-express"></div>
        <div id="card-payment"></div>
        <p data-card-error role="alert"></p>
    </section>
</x-layouts.app>
