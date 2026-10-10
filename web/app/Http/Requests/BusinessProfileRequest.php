<?php

namespace App\Http\Requests;

use App\Documents\BusinessProfile;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;

/** Who the documents come from. Only the owner and managers change it; every field is optional. */
final class BusinessProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId !== null && ($this->user()?->roleIn($tenantId)?->canManageSettings() ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];
        foreach (BusinessProfile::FIELDS as $field => $length) {
            $rules[$field] = ['nullable', 'string', 'max:'.$length];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name_ar' => __('name in Arabic'), 'name_en' => __('name in English'), 'phone' => __('phone'), 'address' => __('address'),
            'cr_number' => __('commercial registration number'), 'vat_number' => __('VAT number'), 'footer' => __('footer'),
        ];
    }
}
