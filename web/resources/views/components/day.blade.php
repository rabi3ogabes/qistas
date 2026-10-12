{{-- A date as the workspace shows it (Win Plan CT26): the leading calendar, and the other one beneath it when the
     owner has Hijri dates on. --}}
@props(['date', 'style' => 'j M Y'])
@php
    [$lead, $other] = \App\Support\Dates::parts($date, $style);
@endphp
<span {{ $attributes->class(['day']) }}>{{ $lead }}@if ($other !== null)<span class="day-other">{{ $other }}</span>@endif</span>
