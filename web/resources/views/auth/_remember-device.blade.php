{{-- Ticked by default: on their own computer or phone people want the code once a month, not at every sign-in. --}}
<div class="field">
    <label class="check"><input type="checkbox" name="remember_device" value="1" checked> <span>{{ __('Don\'t ask for a code on this device for 30 days') }}</span></label>
    <p class="field-hint">{{ __('Untick this on a shared or public computer.') }}</p>
</div>
