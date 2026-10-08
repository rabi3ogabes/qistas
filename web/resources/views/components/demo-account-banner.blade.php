{{-- Across the top of a demo visitor's workspace: it is sample data that goes away, and a real account is one press away. --}}
<div class="test-banner" role="note">
    <strong>{{ __('Demo workspace') }}</strong>
    <span class="test-banner-note">{{ __('Sample data, cleared a few hours after you started. Nothing here is real.') }}</span>
    <form method="POST" action="{{ route('demo.leave') }}">
        @csrf
        <button class="btn btn-gold btn-sm">{{ __('Create my free account') }}</button>
    </form>
</div>
