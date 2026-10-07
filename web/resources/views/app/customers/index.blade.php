@php
    use App\Entitlements\Feature;
    use App\Support\Format;
@endphp
<x-layouts.app :title="__('Customers')" section="customers">
    <x-page-head :title="__('Customers')">
        <x-slot:subtitle>{{ $total }} {{ Feature::Customers->unit($total) }}</x-slot:subtitle>
        @can('create', \App\Models\Customer::class)
            <a class="btn" href="{{ route('app.customers.create') }}"><x-icon name="plus" :size="18" /> {{ __('Add customer') }}</a>
        @endcan
    </x-page-head>

    @if ($total > 0)
        <form class="search" method="GET" action="{{ route('app.customers.index') }}" role="search">
            <label class="sr-only" for="q">{{ __('Search customers') }}</label>
            <x-icon name="search" :size="20" />
            <input id="q" type="search" name="q" value="{{ $term }}" placeholder="{{ __('Search by name, phone or email') }}" autocomplete="off" enterkeyhint="search">
            @if ($term !== '')<a class="btn btn-ghost btn-sm" href="{{ route('app.customers.index') }}">{{ __('Clear') }}</a>@endif
        </form>
    @endif

    <section class="card">
        @if ($customers->isEmpty())
            <div class="empty">
                <span class="empty-icon"><x-icon name="users" :size="24" /></span>
                @if ($term !== '')
                    <h3>{{ __('No customers match “:term”', ['term' => $term]) }}</h3>
                    <p>{{ __('Check the spelling, or try part of their phone number.') }}</p>
                    <a class="btn btn-quiet" href="{{ route('app.customers.index') }}">{{ __('Clear search') }}</a>
                @else
                    <h3>{{ __('No customers yet') }}</h3>
                    <p>{{ __('Add the people you sell to on instalments. Contracts and payments belong to a customer.') }}</p>
                    @can('create', \App\Models\Customer::class)
                        <a class="btn" href="{{ route('app.customers.create') }}">{{ __('Add your first customer') }}</a>
                    @endcan
                @endif
            </div>
        @else
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Customer') }}</th>
                            <th scope="col">{{ __('Phone') }}</th>
                            <th scope="col" class="num">{{ __('Running contracts') }}</th>
                            <th scope="col" class="num">{{ __('Owes') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $customer)
                            <tr>
                                <td data-label="{{ __('Customer') }}"><a class="cell-link" href="{{ route('app.customers.show', $customer) }}">{{ $customer->name }}</a></td>
                                <td data-label="{{ __('Phone') }}"><span class="money" dir="ltr">{{ $customer->phone }}</span></td>
                                <td data-label="{{ __('Running contracts') }}" class="num money">{{ $balances[$customer->id]['running'] }}</td>
                                <td data-label="{{ __('Owes') }}" class="num money">{{ Format::money($balances[$customer->id]['owed'], $currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{ $customers->links() }}
</x-layouts.app>
