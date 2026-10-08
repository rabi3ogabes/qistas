{{-- Across the top of every page of an admin's test workspace: it is sample data, and the way out is one click. --}}
<div class="test-banner" role="note">
    <strong>{{ __('Test workspace') }}</strong>
    <span class="test-banner-note">{{ __('Sample data. Nothing here is real, and no customer sees it.') }}</span>
    <div class="tool-actions">
        <a class="btn btn-quiet btn-sm" href="{{ route('admin.home') }}">{{ __('Admin') }}</a>
        <form method="POST" action="{{ route('admin.test.reset') }}">
            @csrf
            <button class="btn btn-quiet btn-sm">{{ __('Start again') }}</button>
        </form>
        <form method="POST" action="{{ route('admin.test.leave') }}">
            @csrf
            <button class="btn btn-quiet btn-sm">{{ __('Leave test mode') }}</button>
        </form>
    </div>
</div>
