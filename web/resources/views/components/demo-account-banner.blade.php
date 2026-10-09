{{-- Across the top of a demo visitor's workspace: a real account is one press away. It carries no explanation on purpose: the demo says what it is on the way in. --}}
<div class="test-banner test-banner-end">
    <form method="POST" action="{{ route('demo.leave') }}">
        @csrf
        <button class="btn btn-gold btn-sm">{{ __('Create my free account') }}</button>
    </form>
</div>
