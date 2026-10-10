@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'bag' => 'default', 'wide' => false, 'id' => null])
@php
    // Two forms on one page may share a field name: give one of them its own id.
    $id ??= 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->getBag($bag)->first($name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<div @class(['field', 'field-wide' => $wide])>
    <label for="{{ $id }}">{{ $label }}</label>
    @if ($type === 'textarea')
        <textarea
            id="{{ $id }}"
            name="{{ $name }}"
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes }}
        >{{ old($name, $value) }}</textarea>
    @else
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            value="{{ in_array($type, ['password'], true) ? '' : old($name, $value) }}"
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes }}
        >
    @endif
    @if ($hint)<p class="field-hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
    @if ($error)<p class="field-error" id="{{ $id }}-error" role="alert">{{ $error }}</p>@endif
</div>
