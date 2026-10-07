<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Something is being undone (a contract cancelled, a payment voided) and the person may say why. */
abstract class ReasonRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }

    public function reason(): ?string
    {
        return $this->validated('reason');
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        $this->merge(['reason' => is_scalar($reason) && trim((string) $reason) !== '' ? trim((string) $reason) : null]);
    }
}
