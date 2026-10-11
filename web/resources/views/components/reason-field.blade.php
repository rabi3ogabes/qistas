{{-- The "why" that goes with an undoing (cancelling a contract, voiding a payment), optional unless asked for (a plan
     change). Several can share a page, so only a required one shows the error. --}}
@props(['id', 'label' => null, 'required' => false])
@php
    $error = $required ? $errors->first('reason') : null;
@endphp
<div class="field">
    <label for="{{ $id }}">{{ $label ?? ($required ? __('Reason') : __('Reason (optional)')) }}</label>
    <textarea id="{{ $id }}" name="reason" rows="2" maxlength="500" @required($required) @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>{{ $required ? old('reason') : '' }}</textarea>
    @if ($error)<p class="field-error" id="{{ $id }}-error" role="alert">{{ $error }}</p>@endif
</div>
