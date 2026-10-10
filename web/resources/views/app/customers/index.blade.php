@php
    use App\Entitlements\Feature;
    use App\Support\Format;

    $sorts = ['name' => __('Name'), 'balance' => __('What they owe'), 'next_due' => __('Next due date'), 'activity' => __('Last activity')];
    // The list as it is now, so a filter keeps the others.
    $here = array_filter(['q' => $term, 'sort' => $sort === 'name' ? null : $sort, 'tag' => $tag?->id]);
@endphp
<x-layouts.app :title="__('Customers')" section="customers">
    <x-page-head :title="__('Customers')">
        <x-slot:subtitle>{{ $total }} {{ Feature::Customers->unit($total) }}</x-slot:subtitle>
        @can('create', \App\Models\Customer::class)
            <a class="btn" href="{{ route('app.customers.create') }}"><x-icon name="plus" :size="18" /> {{ __('Add customer') }}</a>
        @endcan
    </x-page-head>

    @if ($total > 0)
        <div class="list-tools">
            <form class="search" method="GET" action="{{ route('app.customers.index') }}" role="search">
                <label class="sr-only" for="q">{{ __('Search customers') }}</label>
                <x-icon name="search" :size="20" />
                <input id="q" type="search" name="q" value="{{ $term }}" placeholder="{{ __('Search by name, phone or email') }}" autocomplete="off" enterkeyhint="search">
                @if ($sort !== 'name')<input type="hidden" name="sort" value="{{ $sort }}">@endif
                @if ($tag)<input type="hidden" name="tag" value="{{ $tag->id }}">@endif
                @if ($term !== '')<a class="btn btn-ghost btn-sm" href="{{ route('app.customers.index', array_diff_key($here, ['q' => 1])) }}">{{ __('Clear') }}</a>@endif
            </form>
            <form class="list-sort" method="GET" action="{{ route('app.customers.index') }}">
                @if ($term !== '')<input type="hidden" name="q" value="{{ $term }}">@endif
                @if ($tag)<input type="hidden" name="tag" value="{{ $tag->id }}">@endif
                <label for="sort">{{ __('Sort by') }}</label>
                <select id="sort" name="sort" data-autosubmit>
                    @foreach ($sorts as $key => $label)
                        <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <noscript><button class="btn btn-quiet btn-sm" type="submit">{{ __('Sort') }}</button></noscript>
            </form>
        </div>

        @if ($tags->isNotEmpty())
            <nav class="tag-filter" aria-label="{{ __('Show customers with a tag') }}">
                <a class="tag-chip" data-colour="none" href="{{ route('app.customers.index', array_diff_key($here, ['tag' => 1])) }}" @if (! $tag) aria-current="true" @endif>{{ __('All') }}</a>
                @foreach ($tags as $option)
                    <a class="tag-chip" data-colour="{{ $option->colour }}" href="{{ route('app.customers.index', ['tag' => $option->id] + $here) }}" @if ($tag?->id === $option->id) aria-current="true" @endif>{{ $option->name }}</a>
                @endforeach
                <a class="link tag-manage" href="{{ route('app.tags.index') }}"><x-icon name="tag" :size="16" /> {{ __('Manage tags') }}</a>
            </nav>
        @elseif (\App\Entitlements\Entitlements::for(app(\App\Tenancy\CurrentTenant::class)->get())->check(Feature::CustomerTags)->enabled())
            <p class="tag-filter"><a class="link tag-manage" href="{{ route('app.tags.index') }}"><x-icon name="tag" :size="16" /> {{ __('Group customers with tags') }}</a></p>
        @endif
    @endif

    <section class="card">
        @if ($customers->isEmpty())
            <div class="empty">
                <span class="empty-icon"><x-icon name="users" :size="24" /></span>
                @if ($term !== '' || $tag)
                    <h3>{{ $term !== '' ? __('No customers match “:term”', ['term' => $term]) : __('No customers with this tag yet') }}</h3>
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
                            <tr @if ($customer->pinned_at) data-pinned="true" @endif>
                                <td data-label="{{ __('Customer') }}">
                                    <span class="customer-cell">
                                        <a class="cell-link" href="{{ route('app.customers.show', $customer) }}">
                                            @if ($customer->pinned_at)<x-icon name="pin" :size="14" class="pin-mark" :label="__('Pinned')" />@endif{{ $customer->name }}
                                        </a>
                                        @if ($tags->isNotEmpty() && $customer->tags->isNotEmpty())
                                            <span class="tag-list">@foreach ($customer->tags as $customerTag)<span class="tag-chip tag-chip-sm" data-colour="{{ $customerTag->colour }}">{{ $customerTag->name }}</span>@endforeach</span>
                                        @endif
                                    </span>
                                </td>
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
