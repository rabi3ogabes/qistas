<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MoneyRules;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Money an investor puts in or takes out, recorded by hand. The amount is always positive; the type says which way. */
class InvestorEntryRequest extends FormRequest
{
    use MoneyRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['deposit', 'withdrawal'])],
            'amount' => ['required', 'string', $this->amountRule(positive: true)],
            'occurred_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['occurred_on.before_or_equal' => __('The date cannot be in the future.')];
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $key): ?string => is_scalar($this->input($key)) && trim((string) $this->input($key)) !== '' ? trim((string) $this->input($key)) : null;

        $this->merge([
            'amount' => ($amount = $text('amount')) === null ? null : str_replace([',', ' '], '', Digits::toAscii($amount)),
            'occurred_on' => ($day = $text('occurred_on')) === null ? null : Digits::toAscii($day),
            'note' => $text('note'),
        ]);
    }
}
