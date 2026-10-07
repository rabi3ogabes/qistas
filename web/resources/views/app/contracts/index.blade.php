@php
    use App\Entitlements\Feature;
    use App\Support\Format;

    $statusTone = ['active' => 'badge-info', 'late' => 'badge-bad', 'settled' => 'badge-ok', 'cancelled' => ''];
    $statusLabel = ['active' => __('Active'), 'late' => __('Late'), 'settled' => __('Settled'), 'cancelled' => __('Cancelled')];
    $lists = ['active' => __('Active'), 'late' => __('Late'), 'settled' => __('Settled'), 'cancelled' => __('Cancelled'), 'all' => __('All')];
@endphp
<x-layouts.app :title="__('Contracts')" section="contracts">
    <x-page-head :title="__('Contracts')">
        <x-slot:subtitle>{{ $counts['active'] }} {{ Feature::ActiveContracts->unit($counts['active']) }}</x-slot:subtitle>
        @can('create', \App\Models\Contract::class)
            <a class="btn" href="{{ route('app.contracts.create') }}"><x-icon name="plus" :size="18" /> {{ __('Open a contract') }}</a>
        @endcan
    </x-page-head>

    @if ($counts['all'] > 0)
        <nav class="tabs" aria-label="{{ __('Choose a list of contracts') }}">
            @foreach ($lists as $key => $name)
                <a class="tabs-item" href="{{ route('app.contracts.index', ['status' => $key]) }}" @if ($key === $view && $term === '') aria-current="page" @endif>
                    {{ $name }} <span class="tabs-count money">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </nav>

        <form class="search" method="GET" action="{{ route('app.contracts.index') }}" role="search">
            <label class="sr-only" for="q">{{ __('Search contracts') }}</label>
            <x-icon name="search" :size="20" />
            <input id="q" type="search" name="q" value="{{ $term }}" placeholder="{{ __('Search by reference or customer') }}" autocomplete="off" enterkeyhint="search">
            @if (request()->filled('status'))<input type="hidden" name="status" value="{{ $view }}">@endif
            @if ($term !== '')<a class="btn btn-ghost btn-sm" href="{{ route('app.contracts.index', request()->filled('status') ? ['status' => $view] : []) }}">{{ __('Clear') }}</a>@endif
        </form>
    @endif

    <section class="card">
        @if ($contracts->isEmpty())
            <div class="empty">
                <span class="empty-icon"><x-icon name="fileText" :size="24" /></span>
                @if ($counts['all'] === 0)
                    <h3>{{ __('No contracts yet') }}</h3>
                    <p>{{ __('A contract is an instalment plan for one customer. Open your first to see the schedule and take payments.') }}</p>
                    @can('create', \App\Models\Contract::class)
                        <a class="btn" href="{{ route('app.contracts.create') }}">{{ __('Open your first contract') }}</a>
                    @endcan
                @elseif ($term !== '')
                    <h3>{{ __('No contracts match “:term”', ['term' => $term]) }}</h3>
                    <p>{{ __('Try the reference, such as C-0012, or part of the customer’s name or phone.') }}</p>
                    <a class="btn btn-quiet" href="{{ route('app.contracts.index', ['status' => 'all']) }}">{{ __('Show all contracts') }}</a>
                @else
                    <h3>{{ __('No contracts in this list') }}</h3>
                    <p>{{ __('Choose another list above, or open a new contract.') }}</p>
                    <a class="btn btn-quiet" href="{{ route('app.contracts.index', ['status' => 'all']) }}">{{ __('Show all contracts') }}</a>
                @endif
            </div>
        @else
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Contract') }}</th>
                            <th scope="col">{{ __('Customer') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col">{{ __('Next instalment') }}</th>
                            <th scope="col" class="num">{{ __('Total') }}</th>
                            <th scope="col" class="num">{{ __('Owes') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contracts as $contract)
                            @php
                                $row = $progress[$contract->id];
                                $state = $contract->status === 'active' && $row['late'] ? 'late' : $contract->status;
                                $next = $contract->status === 'active' ? $row['next'] : null;
                            @endphp
                            <tr data-status="{{ $state }}">
                                <td data-label="{{ __('Contract') }}"><a class="cell-link" href="{{ route('app.contracts.show', $contract) }}">{{ $contract->reference() }}</a></td>
                                <td data-label="{{ __('Customer') }}">{{ $contract->customer?->name }}</td>
                                <td data-label="{{ __('Status') }}"><span class="badge {{ $statusTone[$state] ?? '' }}">{{ $statusLabel[$state] ?? $state }}</span></td>
                                <td data-label="{{ __('Next instalment') }}">
                                    @if ($next)
                                        <span>
                                            <span class="money">{{ $next->due_date->translatedFormat('j M Y') }}</span>
                                            <span class="cell-sub money">{{ Format::money($next->remaining(), $currency) }}</span>
                                        </span>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('Total') }}" class="num money">{{ Format::money($contract->total, $currency) }}</td>
                                <td data-label="{{ __('Owes') }}" class="num money">{{ Format::money($contract->status === 'cancelled' ? '0' : $row['owed'], $currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{ $contracts->links() }}
</x-layouts.app>
