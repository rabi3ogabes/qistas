@php
    use App\Support\Format;

    $money = fn (string $amount) => Format::money($amount, $currency);
    $overdue = $metrics['overdue'] !== '0.00';
    $steps = [
        ['key' => 'customer', 'label' => __('Add your first customer'), 'href' => url('/app/customers/create'), 'cta' => __('Add a customer')],
        ['key' => 'contract', 'label' => __('Open a contract with an instalment plan'), 'href' => url('/app/contracts/create'), 'cta' => __('Open a contract')],
        ['key' => 'payment', 'label' => __('Record the first payment'), 'href' => url('/app/contracts'), 'cta' => __('Record a payment')],
    ];
@endphp
<x-layouts.app :title="__('Dashboard')" section="dashboard">
    <x-page-head :title="__('Dashboard')">
        <x-slot:subtitle>{{ now()->translatedFormat('l, j F Y') }}</x-slot:subtitle>
    </x-page-head>

    <x-welcome-banner surface="webapp" />

    @if ($gettingStarted)
        <section class="card card-pad checklist" aria-labelledby="start-title">
            <h2 id="start-title" class="checklist-title">{{ __('Get started') }}</h2>
            <ol>
                @php($primaryTaken = false)
                @foreach ($steps as $step)
                    @php($done = $stepsDone[$step['key']])
                    <li data-done="{{ $done ? 'true' : 'false' }}">
                        <span class="check-dot" aria-hidden="true">@if ($done)<x-icon name="check" :size="16" />@endif</span>
                        <span class="step-label">{{ $step['label'] }}</span>
                        @unless ($done)
                            {{-- Only the next step to take is emphasised. --}}
                            <a @class(['btn', 'btn-sm', 'btn-quiet' => $primaryTaken]) href="{{ $step['href'] }}">{{ $step['cta'] }}</a>
                            @php($primaryTaken = true)
                        @endunless
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <section class="summary card" aria-label="{{ __('Overview') }}">
        <div class="figure figure-lead">
            <p class="figure-label">{{ __('Outstanding') }}</p>
            <p class="figure-value money">{{ $money($metrics['outstanding']) }}</p>
            <p class="figure-note">{{ __('Still to collect on running contracts') }}</p>
        </div>
        <div class="figure" @if ($overdue) data-tone="danger" @endif>
            <p class="figure-label">{{ __('Overdue') }}</p>
            <p class="figure-value money">{{ $money($metrics['overdue']) }}</p>
            <p class="figure-note">{{ $overdue ? __('Past its due date') : __('Nothing is late') }}</p>
        </div>
        <div class="figure">
            <p class="figure-label">{{ __('Collected this month') }}</p>
            <p class="figure-value money">{{ $money($metrics['collected_this_month']) }}</p>
            <p class="figure-note">
                @if ($metrics['collection_rate'] !== null)
                    {{ __(':rate% of this month’s instalments are paid', ['rate' => $metrics['collection_rate']]) }}
                @else
                    {{ __('No instalments fall due this month') }}
                @endif
            </p>
        </div>
        <div class="figure">
            <p class="figure-label">{{ __('Active customers') }}</p>
            <p class="figure-value money">{{ $metrics['active_customers'] }}</p>
            <p class="figure-note">{{ __('With at least one running contract') }}</p>
        </div>
    </section>

    <section class="card due" aria-labelledby="due-title">
        <div class="card-head">
            <h2 id="due-title">{{ __('Due today') }}</h2>
        </div>
        @if ($metrics['due_today'] === [])
            <div class="empty">
                <span class="empty-icon"><x-icon name="calendar" :size="24" /></span>
                <p>{{ __('Nothing is due today.') }}</p>
            </div>
        @else
            <ul class="rows">
                @foreach ($metrics['due_today'] as $row)
                    <li>
                        <a class="row-main" href="{{ url('/app/contracts/'.$row['contract_id']) }}">
                            <span class="row-title">{{ $row['customer_name'] }}</span>
                            <span class="row-sub">{{ $row['contract_reference'] }}</span>
                        </a>
                        <span class="money row-amount">{{ $money($row['amount_due']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.app>
