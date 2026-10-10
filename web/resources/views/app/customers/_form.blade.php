{{-- Shared by "add" and "edit". $customer is the model (new or existing), $action the URL, $method the HTTP verb. --}}
@php($editing = $customer->exists)
<form method="POST" action="{{ $action }}" class="form form-grid" novalidate>
    @csrf
    @if ($method !== 'POST') @method($method) @endif

    <x-field wide name="name" :label="__('Name')" :value="$customer->name" autocomplete="off" required autofocus />
    <x-field name="phone" type="tel" :label="__('Phone')" :value="$customer->phone" inputmode="tel" autocomplete="off" dir="ltr" required />
    <x-field name="phone_secondary" type="tel" :label="__('Second phone (optional)')" :value="$customer->phone_secondary" inputmode="tel" autocomplete="off" dir="ltr" />
    <x-field wide name="email" type="email" :label="__('Email (optional)')" :value="$customer->email" inputmode="email" autocapitalize="none" autocomplete="off" dir="ltr" />
    <x-field wide name="job" :label="__('Job or employer (optional)')" :value="$customer->job" autocomplete="off" maxlength="120" />

    @if ($editing && $customer->maskedNationalId())
        <x-field wide name="national_id" :label="__('National ID')" autocomplete="off"
                 :hint="__('Stored as :id. Leave blank to keep it, or type a new one to replace it.', ['id' => $customer->maskedNationalId()])" />
        <label class="check field-wide"><input type="checkbox" name="remove_national_id" value="1" @checked(old('remove_national_id'))> <span>{{ __('Remove the stored national ID') }}</span></label>
    @else
        <x-field wide name="national_id" :label="__('National ID (optional)')" :hint="__('Stored encrypted. Only the last three digits are ever shown.')" autocomplete="off" />
    @endif

    <x-field wide type="textarea" name="address" :label="__('Address (optional)')" :value="$customer->address" rows="2" />
    <x-field wide type="textarea" name="notes" :label="__('Notes (optional)')" :value="$customer->notes" rows="3" />

    <div class="form-actions field-wide">
        <button class="btn">{{ $editing ? __('Save changes') : __('Add customer') }}</button>
        <a class="btn btn-ghost" href="{{ $editing ? route('app.customers.show', $customer) : route('app.customers.index') }}">{{ __('Cancel') }}</a>
    </div>
</form>
