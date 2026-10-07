<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Support\Digits;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

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
            'phone' => ['required', 'string', $this->phoneNumber()],
            'phone_secondary' => ['nullable', 'string', $this->phoneNumber()],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
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
        ]);
    }

    /** 6 to 15 digits (the E.164 maximum), with the usual separators and an optional leading +. */
    private function phoneNumber(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $value = (string) $value;
            $digits = preg_match_all('/\d/', $value);

            if (! preg_match('/^\+?[\d\s().\-]+$/', $value) || $digits < 6 || $digits > 15 || strlen($value) > 32) {
                $fail(__('Enter a valid phone number.'));
            }
        };
    }
}
