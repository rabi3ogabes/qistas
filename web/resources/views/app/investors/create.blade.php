<x-layouts.app :title="__('Add an investor')" section="investors">
    <x-page-head :title="__('Add an investor')">
        <x-slot:subtitle><a class="link" href="{{ route('app.investors.index') }}">{{ __('All investors') }}</a></x-slot:subtitle>
    </x-page-head>

    <div class="narrow">
        <section class="card card-pad stack">
            <p class="muted">{{ __('A partner who funds some of your contracts. Their money in, money out and profit are kept apart from the business’s own.') }}</p>
            <form method="POST" action="{{ route('app.investors.store') }}" class="form form-grid" novalidate>
                @csrf
                @error('investor')<p class="alert alert-error field-wide" role="alert">{{ $message }}</p>@enderror

                <x-field wide name="name" :label="__('Name')" autocomplete="off" required maxlength="120" />
                <x-field name="opening_capital" :label="__('Money they start with (optional)')" :hint="__('In :currency. Recorded as their first deposit.', ['currency' => $currency])" inputmode="decimal" autocomplete="off" dir="ltr" />
                <x-field name="commission_percent" :label="__('Commission (optional)')" :hint="__('The share of their profit that goes to the business, as a percent.')" inputmode="decimal" autocomplete="off" dir="ltr" />
                <x-field wide name="commercial_registration" :label="__('Commercial registration (optional)')" autocomplete="off" dir="ltr" maxlength="60" />
                <x-field wide type="textarea" name="notes" :label="__('Notes (optional)')" rows="2" :hint="__('For your team only.')" />

                <div class="form-actions">
                    <button class="btn">{{ __('Add investor') }}</button>
                    <a class="btn btn-ghost" href="{{ route('app.investors.index') }}">{{ __('Cancel') }}</a>
                </div>
            </form>
        </section>
    </div>
</x-layouts.app>
