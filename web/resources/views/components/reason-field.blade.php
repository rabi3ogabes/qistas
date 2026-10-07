{{-- The optional "why" that goes with an undoing (cancelling a contract, voiding a payment). Several can share a page. --}}
@props(['id', 'label' => null])
<div class="field">
    <label for="{{ $id }}">{{ $label ?? __('Reason (optional)') }}</label>
    <textarea id="{{ $id }}" name="reason" rows="2" maxlength="500"></textarea>
</div>
