<x-layouts.app :title="__('Add customer')" section="customers">
    <x-page-head :title="__('Add customer')" />

    <div class="narrow">
        @if (! $usage->allows())
            {{-- No point filling in a form that would be refused: say so first, and offer the way forward. --}}
            <section class="card card-pad stack">
                <h2 class="sheet-title display">{{ __('You have reached your plan limit') }}</h2>
                <x-meter :label="__('Customers')" :entitlement="$usage" />
                <p>{{ __('Your plan includes :allowance. Upgrade to add more, or delete a customer you no longer need to free up a place.', ['allowance' => \App\Entitlements\Feature::Customers->summary(true, $usage->limit())]) }}</p>
                <div class="form-actions">
                    <a class="btn btn-gold" href="{{ url('/app/billing') }}">{{ __('See plans and upgrade') }}</a>
                    <a class="btn btn-quiet" href="{{ route('app.customers.index') }}">{{ __('Back to customers') }}</a>
                </div>
            </section>
        @else
            <section class="card card-pad stack">
                <x-meter :label="__('Customers')" :entitlement="$usage" />
                @include('app.customers._form', ['action' => route('app.customers.store'), 'method' => 'POST'])
            </section>
        @endif
    </div>
</x-layouts.app>
