<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MoneyRules;
use App\Models\Contract;
use App\Support\Digits;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation and authorisation for opening a contract, shared by the web app and the API. A cash sale needs
 * only a customer and an amount; a scheduled contract also needs its instalment plan.
 */
class ContractRequest extends FormRequest
{
    use MoneyRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Contract::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $scheduled = Rule::requiredIf(fn () => ($this->input('type') ?? 'scheduled') !== 'cash');

        return [
            // Only this workspace's live customers exist as far as this rule is concerned.
            'customer_id' => ['required', 'string', 'uuid', Rule::exists('customers', 'id')
                ->where('tenant_id', app(CurrentTenant::class)->id())
                ->whereNull('deleted_at')],
            'type' => ['nullable', Rule::in(['scheduled', 'cash'])],
            'principal' => ['required', 'string', $this->amountRule(positive: true)],
            'down_payment' => ['nullable', 'string', $this->amountRule(), $this->belowRule('principal', __('The down payment must be less than the price.'))],
            'markup_type' => ['nullable', Rule::in(['none', 'fixed', 'percent'])],
            'markup_value' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'installment_count' => [$scheduled, 'nullable', 'integer', 'between:1,120'],
            'frequency' => [$scheduled, 'nullable', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'first_due_date' => [$scheduled, 'nullable', 'date_format:Y-m-d', $this->notBeforeStart()],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $value = function (string $key): ?string {
            $raw = $this->input($key);

            return is_scalar($raw) && trim((string) $raw) !== '' ? Digits::toAscii(trim((string) $raw)) : null;
        };

        // A cash sale has no plan: whatever instalment fields came along (a form that hid them, an API client
        // that sent them anyway) are not part of it and must not be able to fail it.
        $plan = fn (string $key): ?string => $value('type') === 'cash' ? null : $value($key);

        $this->merge([
            'customer_id' => $value('customer_id'),
            'type' => $value('type'),
            'principal' => $value('principal'),
            'down_payment' => $plan('down_payment'),
            'markup_type' => $plan('markup_type'),
            'markup_value' => $plan('markup_value'),
            'installment_count' => $plan('installment_count'),
            'frequency' => $plan('frequency'),
            'start_date' => $value('start_date'),
            'first_due_date' => $plan('first_due_date'),
            'notes' => is_scalar($this->input('notes')) && trim((string) $this->input('notes')) !== '' ? trim((string) $this->input('notes')) : null,
        ]);
    }

    private function notBeforeStart(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $start = $this->input('start_date');

            if (is_string($start) && $start !== '' && (string) $value < $start) {
                $fail(__('The first due date cannot be before the contract date.'));
            }
        };
    }
}
