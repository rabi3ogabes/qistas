<?php

namespace App\Http\Requests;

use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The first-run card: the business's name, and optionally its country and time zone. The owner alone confirms it.
 * Nothing else is read from the request, whatever else is sent.
 */
final class WorkspaceSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId !== null && $this->user()?->roleIn($tenantId) === TenantRole::Owner;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'country' => ['bail', 'nullable', 'string', Rule::in(array_keys((array) config('qistas.countries')))],
            'timezone' => ['bail', 'nullable', 'string', 'timezone:all'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => __('Enter the name of your business.'),
            'name.string' => __('Enter the name of your business.'),
            'name.max' => __('The business name can be at most :max characters.'),
            'country.string' => __('Choose one of the countries in the list.'),
            'country.in' => __('Choose one of the countries in the list.'),
            'timezone.string' => __('Choose a time zone from the list.'),
            'timezone.timezone' => __('Choose a time zone from the list.'),
        ];
    }

    /** The name without the spaces around it, and the country in capitals, so that a name of spaces is no name. */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $country = $this->input('country');

        $this->merge([
            'name' => is_string($name) ? Str::trim($name) : $name,
            'country' => is_string($country) ? strtoupper(Str::trim($country)) : $country,
        ]);
    }
}
