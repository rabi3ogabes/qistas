{{-- Opens by itself when something sent the person back with a plan message (EntitlementException::render). --}}
@props(['upgrade' => null])
@if (is_array($upgrade))
    <dialog class="sheet" data-auto-open aria-labelledby="upgrade-title">
        <div class="sheet-body">
            <h2 id="upgrade-title" class="sheet-title display">
                {{ ($upgrade['code'] ?? '') === 'feature_locked' ? __('Not included in your plan') : __('You have reached your plan limit') }}
            </h2>
            <p>{{ $upgrade['message'] ?? '' }}</p>
            <div class="sheet-actions">
                <a class="btn btn-gold btn-block" href="{{ $upgrade['upgrade_url'] ?? url('/app/billing') }}">{{ __('See plans and upgrade') }}</a>
                <button type="button" class="btn btn-quiet btn-block" data-close-sheet>{{ __('Not now') }}</button>
            </div>
        </div>
    </dialog>
@endif
