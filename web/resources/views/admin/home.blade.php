@php
    $plan = $sandbox?->currentPlan();
@endphp
<x-layouts.auth :title="__('Admin')" :heading="__('Admin')" :lead="__('Try the product the way a customer does, without touching real data.')" wide>
    <p class="mode-pill" data-on="{{ $testOn ? 'true' : 'false' }}" role="status">{{ $testOn ? __('Test mode is on') : __('Test mode is off') }}</p>

    <x-alert type="warning" :message="session('warning')" />

    <div class="admin-tools">
        <section class="tool" aria-labelledby="tool-dashboard">
            <div class="tool-head">
                <span class="tool-icon"><x-icon name="chart" :size="22" /></span>
                <div>
                    <h2 id="tool-dashboard">{{ __('Dashboard') }}</h2>
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
                    <h2 id="tool-app">{{ __('App') }}</h2>
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
</x-layouts.auth>
