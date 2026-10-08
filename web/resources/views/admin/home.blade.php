@php
    $plan = $sandbox?->currentPlan();
    $trend = $figures['signups_week'] - $figures['signups_prev_week'];
@endphp
<x-layouts.admin :title="__('Admin area')" section="overview">
    <x-page-head :title="__('Overview')">
        <x-slot:subtitle>{{ now()->translatedFormat('l, j F Y') }}</x-slot:subtitle>
        <span class="mode-pill" data-on="{{ $testOn ? 'true' : 'false' }}" role="status">{{ $testOn ? __('Test mode is on') : __('Test mode is off') }}</span>
    </x-page-head>

    <section aria-labelledby="glance-title">
        <h2 id="glance-title" class="admin-h2">{{ __('The platform at a glance') }}</h2>
        <p class="admin-lead">{{ __('Real businesses and people only. Demo accounts, test workspaces and staff are not counted.') }}</p>

        <dl class="stat-grid">
            <div class="stat">
                <dt>{{ __('Businesses') }}</dt>
                <dd>{{ number_format($figures['workspaces']) }}</dd>
                <p class="stat-note">{{ __(':free on Free · :pro on Pro', ['free' => number_format($figures['free']), 'pro' => number_format($figures['pro'])]) }}</p>
            </div>
            <div class="stat">
                <dt>{{ __('People') }}</dt>
                <dd>{{ number_format($figures['people']) }}</dd>
                <p class="stat-note">
                    {{ __(':count new in 7 days', ['count' => number_format($figures['signups_week'])]) }}
                    @if ($trend !== 0)<span class="stat-trend" data-up="{{ $trend > 0 ? 'true' : 'false' }}">{{ $trend > 0 ? '+' : '−' }}{{ abs($trend) }}</span>@endif
                </p>
            </div>
            <div class="stat">
                <dt>{{ __('Customers they manage') }}</dt>
                <dd>{{ number_format($figures['customers']) }}</dd>
                <p class="stat-note">{{ __('Across every business') }}</p>
            </div>
            <div class="stat">
                <dt>{{ __('Running contracts') }}</dt>
                <dd>{{ number_format($figures['active_contracts']) }}</dd>
                <p class="stat-note">{{ __('Instalment plans being repaid') }}</p>
            </div>
        </dl>
    </section>

    <section aria-labelledby="try-title">
        <h2 id="try-title" class="admin-h2">{{ __('Try the product') }}</h2>
        <p class="admin-lead">{{ __('Try the product the way a customer does, without touching real data.') }}</p>

        <div class="admin-tools">
            <section class="tool" aria-labelledby="tool-dashboard">
                <div class="tool-head">
                    <span class="tool-icon"><x-icon name="chart" :size="22" /></span>
                    <div>
                        <h3 id="tool-dashboard">{{ __('Dashboard') }}</h3>
                        <p>{{ __('Open a sample business with customers, contracts and payments, and use the dashboard as a customer would. Nothing in it is real, and no customer ever sees it.') }}</p>
                    </div>
                </div>

                <div class="tool-actions">
                    <form method="POST" action="{{ route('admin.test.open') }}">
                        @csrf
                        <x-button>{{ __('Test the dashboard') }}</x-button>
                    </form>
                    @if ($sandbox)
                        <form method="POST" action="{{ route('admin.test.reset') }}">
                            @csrf
                            <x-button variant="quiet">{{ __('Start again') }}</x-button>
                        </form>
                    @endif
                    @if ($testOn)
                        <form method="POST" action="{{ route('admin.test.leave') }}">
                            @csrf
                            <x-button variant="quiet">{{ __('Leave test mode') }}</x-button>
                        </form>
                    @endif
                </div>

                @if ($sandbox)
                    <div>
                        <p class="field-hint" id="plan-hint">{{ __('Plan to try') }}</p>
                        <div class="plan-switch" role="group" aria-labelledby="plan-hint">
                            @foreach ($plans as $option)
                                <form method="POST" action="{{ route('admin.test.plan') }}">
                                    @csrf
                                    <input type="hidden" name="plan" value="{{ $option->key }}">
                                    <button type="submit" aria-pressed="{{ $plan?->key === $option->key ? 'true' : 'false' }}">{{ $option->name }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            <section class="tool" aria-labelledby="tool-app">
                <div class="tool-head">
                    <span class="tool-icon"><x-icon name="download" :size="22" /></span>
                    <div>
                        <h3 id="tool-app">{{ __('App') }}</h3>
                        <p>{{ __('Sign in to the Qistas app with your admin email and password. While test mode is on, the app opens the same test workspace, so you can try it against sample data.') }}</p>
                    </div>
                </div>

                <div class="tool-actions">
                    @if ($testOn)
                        @if ($appDownloadUrl)
                            <a class="btn btn-primary" href="{{ $appDownloadUrl }}" rel="noopener">{{ __('Get the app') }}</a>
                        @endif
                    @else
                        <form method="POST" action="{{ route('admin.test.open') }}">
                            @csrf
                            <input type="hidden" name="to" value="admin">
                            <x-button>{{ __('Test the app') }}</x-button>
                        </form>
                    @endif
                </div>

                <dl class="tool-facts">
                    <div><dt>{{ __('Test mode') }}</dt><dd>{{ $testOn ? __('On') : __('Off') }}</dd></div>
                    <div><dt>{{ __('Server address') }}</dt><dd dir="ltr">{{ $apiUrl }}</dd></div>
                    @if ($sandbox)
                        <div><dt>{{ __('Test workspace') }}</dt><dd>{{ $sandbox->name }}</dd></div>
                    @endif
                </dl>

                @if (! $testOn)
                    <p class="field-hint">{{ __('This turns test mode on first, so the app has sample data to show.') }}</p>
                @endif
            </section>
        </div>
    </section>

    <section aria-labelledby="health-title">
        <h2 id="health-title" class="admin-h2">{{ __('Is everything set up?') }}</h2>

        <ul class="health">
            @foreach ($health as $check)
                <li class="health-row" data-status="{{ $check['status'] }}">
                    <span class="health-dot" aria-hidden="true"></span>
                    <div>
                        <p class="health-title">{{ $check['title'] }}
                            <span class="health-state">{{ match ($check['status']) { 'ok' => __('Good'), 'warn' => __('Needs attention'), default => __('Off') } }}</span>
                        </p>
                        <p class="health-detail">{{ $check['detail'] }}</p>
                    </div>
                    @if ($check['key'] === 'demo' && $check['status'] === 'ok')
                        <form method="POST" action="{{ route('admin.demo.prune') }}">
                            @csrf
                            <x-button variant="quiet" class="btn-sm">{{ __('Clear expired demos') }}</x-button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
</x-layouts.admin>
