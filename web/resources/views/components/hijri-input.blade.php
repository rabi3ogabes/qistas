{{-- The Hijri twin of a date field (Win Plan CT26), shown only while the workspace has Hijri dates on. Typing a Hijri
     day fills the date field with its Gregorian day (so a live preview follows), and the server reads it the same way
     when the page has no script; picking a Gregorian day shows its Hijri day here. --}}
@props(['for', 'id' => null])
@php
    $target = $id ?? 'f-'.$for;
    $field = $for.'_hijri';
    $error = $errors->first($field);
    $months = implode('|', array_map(fn (int $m) => \App\Support\Hijri::monthName($m), range(1, 12)));
@endphp
@if (app(\App\Support\Dates::class)->mode() !== 'off')
    <div class="field hijri-field" x-data="hijriField"
         data-for="{{ $target }}" data-epoch="{{ \App\Support\HijriTable::EPOCH }}" data-first="{{ \App\Support\HijriTable::FIRST_YEAR }}"
         data-years="{{ implode(',', \App\Support\HijriTable::YEARS) }}" data-months="{{ $months }}" data-pattern="{{ __(':day :month :year AH') }}">
        <label for="{{ $target }}-hijri">{{ __('Or the Hijri date') }}</label>
        <input id="{{ $target }}-hijri" name="{{ $field }}" type="text" inputmode="numeric" dir="ltr" autocomplete="off" placeholder="13/08/1447"
               value="{{ old($field) }}" x-on:input="typed" aria-describedby="{{ $target }}-hijri-hint" @if ($error) aria-invalid="true" @endif>
        <p class="field-hint" id="{{ $target }}-hijri-hint" x-text="hint">{{ __('Day/month/year, for example 13/08/1447.') }}</p>
        @if ($error)<p class="field-error" role="alert">{{ $error }}</p>@endif
    </div>
@endif
