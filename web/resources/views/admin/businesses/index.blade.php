{{-- Every real business: who owns it, its plan today and how many customers it keeps. Never its customers or money. --}}
<x-layouts.admin :title="__('Businesses')" section="businesses">
    <x-page-head :title="__('Businesses')">
        <x-slot:subtitle>{{ __('Every business on Qistas, its owner and its plan. Demo accounts and test workspaces are not listed.') }}</x-slot:subtitle>
    </x-page-head>

    <div class="biz-tools">
        <form class="search" method="GET" action="{{ route('admin.businesses.index') }}" role="search">
            <label class="sr-only" for="q">{{ __('Search businesses') }}</label>
            <x-icon name="search" :size="20" />
            <input id="q" type="search" name="q" value="{{ $term }}" placeholder="{{ __('Search by business name or owner’s email') }}" autocomplete="off" enterkeyhint="search">
            @if ($planKey)<input type="hidden" name="plan" value="{{ $planKey }}">@endif
            @if ($term !== '')<a class="btn btn-ghost btn-sm" href="{{ route('admin.businesses.index', array_filter(['plan' => $planKey])) }}">{{ __('Clear') }}</a>@endif
        </form>
        <nav class="biz-filters" aria-label="{{ __('Show') }}">
            <a href="{{ route('admin.businesses.index', array_filter(['q' => $term])) }}" @if ($planKey === null) aria-current="true" @endif>{{ __('All') }}</a>
            @foreach ($plans as $plan)
                <a href="{{ route('admin.businesses.index', array_filter(['q' => $term, 'plan' => $plan->key])) }}" @if ($planKey === $plan->key) aria-current="true" @endif>{{ $plan->name }}</a>
            @endforeach
        </nav>
    </div>

    <section class="card">
        @if ($businesses->isEmpty())
            <div class="empty">
                <span class="empty-icon"><x-icon name="layers" :size="24" /></span>
                @if ($term !== '' || $planKey)
                    <h3>{{ __('No business matches') }}</h3>
                    <p>{{ __('Check the spelling, or try part of the owner’s email.') }}</p>
                    <a class="btn btn-quiet" href="{{ route('admin.businesses.index') }}">{{ __('Show every business') }}</a>
                @else
                    <h3>{{ __('No businesses yet') }}</h3>
                    <p>{{ __('Businesses appear here as soon as someone signs up.') }}</p>
                @endif
            </div>
        @else
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Business') }}</th>
                            <th scope="col">{{ __('Plan') }}</th>
                            <th scope="col" class="num">{{ __('Customers') }}</th>
                            <th scope="col">{{ __('Signed up') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($businesses as $business)
                            <tr>
                                <td data-label="{{ __('Business') }}">
                                    <span class="biz-cell">
                                        <a class="cell-link" href="{{ route('admin.businesses.show', $business) }}">{{ $business->name }}</a>
                                        @if ($business->owner)<span class="cell-sub" dir="ltr">{{ $business->owner->email }}</span>@endif
                                    </span>
                                </td>
                                <td data-label="{{ __('Plan') }}"><span class="biz-cell">@include('admin.businesses._plan', ['plan' => $business->subscription?->isCurrent() ? $business->subscription->plan : $freePlan, 'subscription' => $business->subscription])</span></td>
                                <td data-label="{{ __('Customers') }}" class="num money">{{ number_format((int) $business->customers_count) }}</td>
                                <td data-label="{{ __('Signed up') }}" class="money">{{ $business->created_at->translatedFormat('j M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{ $businesses->links() }}
</x-layouts.admin>
