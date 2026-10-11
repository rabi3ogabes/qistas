<?php

namespace App\Http\Requests;

use App\Actions\WaiveLateFee;
use App\Models\LateFee;

/** Waiving a late fee (Win Plan K4) is for owners and managers, and always says why. */
class WaiveLateFeeRequest extends ReasonRequest
{
    public function authorize(): bool
    {
        $fee = $this->route('fee');

        return $fee instanceof LateFee && ($this->user()?->can('waiveFees', $fee->contract) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:'.WaiveLateFee::REASON_MAX]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => __('Say why the fee is waived.')];
    }
}
