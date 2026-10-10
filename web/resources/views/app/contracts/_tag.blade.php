{{-- A line's optional tag (Win Plan PP4): an advance, a refund, an early-payment discount, or something left unpaid. --}}
<div class="field">
    <label for="{{ $id }}">{{ __('Tag (optional)') }}</label>
    <select id="{{ $id }}" name="tag">
        <option value="">{{ __('No tag') }}</option>
        @foreach (['advance' => __('Advance'), 'refund' => __('Refund'), 'early_discount' => __('Early-payment discount'), 'unpaid' => __('Unpaid')] as $value => $label)
            <option value="{{ $value }}" @selected(old('tag') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    @error('tag', $bag ?? 'default')<p class="field-error" role="alert">{{ $message }}</p>@enderror
</div>
