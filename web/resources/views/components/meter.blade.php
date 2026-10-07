{{-- Usage of one limit: "3 of 5 customers", with a bar that warms as it fills. Unlimited plans show the count only. --}}
@props(['label', 'entitlement'])
@php
    $used = (int) $entitlement->used();
    $limit = $entitlement->limit();
    $unlimited = $entitlement->unlimited();
    $level = ! $entitlement->enabled() ? 'full' : ($limit === null || $limit === 0 ? 'ok' : ($used >= $limit ? 'full' : ($used / $limit >= 0.8 ? 'high' : 'ok')));
    $percent = $limit ? min(100, (int) round($used / $limit * 100)) : 0;
@endphp
<div class="meter" data-level="{{ $level }}">
    <div class="meter-head">
        <span class="meter-label">{{ $label }}</span>
        @if ($unlimited)
            <span class="meter-figures">{{ $used }} <span class="meter-unlimited">{{ __('Unlimited') }}</span></span>
        @elseif ($entitlement->enabled())
            <span class="meter-figures">{{ __(':used of :limit', ['used' => $used, 'limit' => $limit]) }}</span>
        @else
            <span class="meter-figures">{{ __('Not included') }}</span>
        @endif
    </div>
    @if (! $unlimited && $entitlement->enabled())
        <div class="meter-track" role="progressbar" aria-label="{{ $label }}" aria-valuemin="0" aria-valuemax="{{ $limit }}" aria-valuenow="{{ min($used, (int) $limit) }}">
            <div class="meter-fill" style="width: {{ $percent }}%"></div>
        </div>
    @endif
</div>
