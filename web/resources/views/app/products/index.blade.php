@php
    use App\Support\Format;
@endphp
<x-layouts.app :title="__('Products')" section="products">
    <x-page-head :title="__('Products')" :subtitle="__('What you sell, to pick from when you open a contract. No stock is counted.')">
        @if ($archived)
            <a class="btn btn-quiet" href="{{ route('app.products.index') }}">{{ __('Back to the list') }}</a>
        @else
            <a class="btn btn-quiet" href="{{ route('app.products.index', ['archived' => 1]) }}">{{ __('Archived') }}</a>
        @endif
    </x-page-head>

    <div class="detail-layout">
        @if ($canManage && ! $archived)
            <section class="card card-pad stack" aria-labelledby="add-title">
                <h2 id="add-title" class="card-title">{{ __('Add a product') }}</h2>
                <form method="POST" action="{{ route('app.products.store') }}" class="form" novalidate>
                    @csrf
                    <x-field name="name" :label="__('Name')" autocomplete="off" required maxlength="120" />
                    <x-field name="default_price" :label="__('Price (optional)')" :hint="__('Filled in when you pick it, in :currency.', ['currency' => $currency])" inputmode="decimal" autocomplete="off" dir="ltr" />
                    <x-field name="cost" :label="__('What it costs you (optional)')" inputmode="decimal" autocomplete="off" dir="ltr" />
                    <x-field name="sku" :label="__('Code (optional)')" autocomplete="off" dir="ltr" maxlength="60" />
                    <button class="btn">{{ __('Add product') }}</button>
                </form>
            </section>
        @endif

        <section class="card" aria-labelledby="list-title">
            <div class="card-head"><h2 id="list-title">{{ $archived ? __('Archived products') : __('Your products') }}</h2></div>
            @if ($products->isEmpty())
                <div class="empty">
                    <span class="empty-icon"><x-icon name="layers" :size="22" /></span>
                    <h3>{{ $archived ? __('Nothing archived') : __('No products yet') }}</h3>
                    <p>{{ $archived ? __('Products you archive wait here, and stay on the contracts that sold them.') : __('Add what you sell most, with its price, and pick it when you open a contract.') }}</p>
                </div>
            @else
                <ul class="rows">
                    @foreach ($products as $product)
                        <li>
                            <span class="row-main">
                                <span class="row-title">{{ $product->name }}</span>
                                <span class="row-sub">
                                    @if ($product->sku)<span dir="ltr">{{ $product->sku }}</span>@endif
                                    @if ($product->cost !== null) {{ __('Cost :amount', ['amount' => Format::money($product->cost, $currency)]) }}@endif
                                </span>
                            </span>
                            <span class="row-amount money">{{ $product->default_price === null ? '—' : Format::money($product->default_price, $currency) }}</span>
                            @if ($canManage)
                                <form method="POST" action="{{ route('app.products.update', $product) }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="archived" value="{{ $archived ? 0 : 1 }}">
                                    <button class="btn btn-ghost btn-sm">{{ $archived ? __('Bring back') : __('Archive') }}</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-layouts.app>
