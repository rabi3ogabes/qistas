<?php

namespace App\Http\Requests;

use App\Domain\Schedule\ScheduleGenerator;
use App\Http\Requests\Concerns\MoneyRules;
use App\Http\Requests\Concerns\ScheduleRules;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The public instalment calculator: anyone may ask "what would this plan look like?". Nothing is stored. */
class SchedulePreviewRequest extends FormRequest
{
    use MoneyRules, ScheduleRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $custom = $this->input('frequency') === ScheduleGenerator::CUSTOM;

        return [
            'principal' => ['required', 'string', $this->amountRule(positive: true)],
            'down_payment' => ['nullable', 'string', $this->amountRule(), $this->belowRule('principal', __('The down payment must be less than the price. If it is all paid today, make it a cash sale instead.'))],
            'markup_type' => ['nullable', Rule::in(['none', 'fixed', 'percent'])],
            'markup_value' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'count' => [Rule::requiredIf(! $custom), 'nullable', 'integer', 'between:1,'.ScheduleGenerator::MAX_COUNT],
            'frequency' => ['required', $this->frequencyRule()],
            'first_due_date' => ['nullable', 'date_format:Y-m-d'],
            'discount_type' => ['nullable', Rule::in(['none', 'fixed', 'percent'])],
            'discount_value' => ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            ...$this->customScheduleRules($custom),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->customScheduleMessages();
    }

    protected function prepareForValidation(): void
    {
        $value = function (string $key): ?string {
            $raw = $this->input($key);

            return is_scalar($raw) && trim((string) $raw) !== '' ? Digits::toAscii(trim((string) $raw)) : null;
        };

        $this->merge([
            'principal' => $value('principal'),
            'down_payment' => $value('down_payment'),
            'markup_type' => $value('markup_type'),
            'markup_value' => $value('markup_value'),
            'count' => $value('count'),
            'frequency' => $value('frequency'),
            'first_due_date' => $value('first_due_date'),
            'discount_type' => $value('discount_type'),
            'discount_value' => $value('discount_value'),
            'custom_schedule' => $this->cleanCustomSchedule($this->input('custom_schedule')),
        ]);
    }
}
