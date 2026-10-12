<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Rules\PhoneNumber;
use App\Support\Digits;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation and authorisation for adding or editing a customer, shared by the web app and the API. */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer
            ? ($this->user()?->can('update', $customer) ?? false)
            : ($this->user()?->can('create', Customer::class) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new PhoneNumber],
            'phone_secondary' => ['nullable', 'string', new PhoneNumber],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'job' => ['nullable', 'string', 'max:120'],
            // Tags of this business only (Win Plan PP12); the whole list each time, an empty one clears them.
            'tags' => ['sometimes', 'nullable', 'array', 'max:20'],
            'tags.*' => ['uuid', 'distinct', Rule::exists('tags', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            // Only when editing: clear the stored national ID. A blank ID field means "keep what is stored".
            'remove_national_id' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $key): ?string => ($value = trim((string) $this->input($key))) === '' ? null : $value;
        $digits = fn (string $key): ?string => ($value = $text($key)) === null ? null : Digits::toAscii($value);

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => $digits('phone') ?? '',
            'phone_secondary' => $digits('phone_secondary'),
            'email' => $text('email'),
            'national_id' => $digits('national_id'),
            'address' => $text('address'),
            'notes' => $text('notes'),
            'job' => $text('job'),
        ]);
    }
}
