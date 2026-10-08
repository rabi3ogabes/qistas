{{-- Shown only while the app runs as a throw-away demo (config qistas.demo): nothing stored here is kept. --}}
@if (config('qistas.demo'))
    <div class="demo-banner" role="note">
        <strong>{{ __('Demo') }}</strong>
        <span>{{ __('This is a demo: what you add is temporary and may disappear at any time.') }}</span>
    </div>
@endif
