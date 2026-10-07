@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'bag' => 'default'])
@php
    $id = 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->getBag($bag)->first($name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<div class="field">
    <label for="{{ $id }}">{{ $label }}</label>
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ in_array($type, ['password'], true) ? '' : old($name, $value) }}"
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes }}
    >
    @if ($hint)<p class="field-hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
    @if ($error)<p class="field-error" id="{{ $id }}-error" role="alert">{{ $error }}</p>@endif
</div>
