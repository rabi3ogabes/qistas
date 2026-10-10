<?php

namespace App\Http\Requests;

use App\Domain\Schedule\ScheduleGenerator;
use App\Http\Requests\Concerns\MoneyRules;
use App\Http\Requests\Concerns\ScheduleRules;
use App\Models\Contract;
use App\Models\Investor;
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
    use MoneyRules, ScheduleRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Contract::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $type = $this->input('type') ?? 'scheduled';
        // A cash sale and an open contract (a running tab) have no instalment plan.
        $hasPlan = ! in_array($type, ['cash', 'open'], true);
        $scheduled = Rule::requiredIf($hasPlan);
        $custom = $hasPlan && $this->input('frequency') === 'custom';
        // A plan of the shop's own dates takes its count and its first date from the rows.
        $planned = Rule::requiredIf($hasPlan && ! $custom);

        return [
            // Only this workspace's live customers exist as far as this rule is concerned.
            'customer_id' => ['required', 'string', 'uuid', Rule::exists('customers', 'id')
                ->where('tenant_id', app(CurrentTenant::class)->id())
                ->whereNull('deleted_at')],
            'type' => ['nullable', Rule::in(['scheduled', 'cash', 'open'])],
            // An open contract has no price of its own: what is owed opening it is its opening balance.
            'principal' => [Rule::requiredIf($type !== 'open'), 'nullable', 'string', $this->amountRule(positive: true)],
            'opening_balance' => ['nullable', 'string', $this->amountRule()],
            'credit_limit' => ['nullable', 'string', $this->amountRule()],
            'down_payment' => ['nullable', 'string', $this->amountRule(), $this->belowRule('principal', __('The down payment must be less than the price. If it is all paid today, make it a cash sale instead.'))],
            'markup_type' => ['nullable', Rule::in(['none', 'fixed', 'percent'])],
            'markup_value' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'installment_count' => [$planned, 'nullable', 'integer', 'between:1,'.ScheduleGenerator::MAX_COUNT],
            'frequency' => [$scheduled, 'nullable', $this->frequencyRule()],
            'grace_days' => ['nullable', 'integer', 'between:0,90'],
            ...$this->customScheduleRules($custom),
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'first_due_date' => [$planned, 'nullable', 'date_format:Y-m-d', $this->notBeforeStart()],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Who funds it: one of this workspace's investors still funding contracts, chosen by someone who sees them.
            'investor_id' => ['nullable', 'string', 'uuid', $this->mayChooseInvestor(), Rule::exists('investors', 'id')
                ->where('tenant_id', app(CurrentTenant::class)->id())
                ->whereNull('archived_at')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['investor_id' => __('investor')];
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

        // A cash sale has no plan: whatever instalment fields came along (a form that hid them, an API client
        // that sent them anyway) are not part of it and must not be able to fail it.
        $plan = fn (string $key): ?string => in_array($value('type'), ['cash', 'open'], true) ? null : $value($key);
        $open = fn (string $key): ?string => $value('type') === 'open' ? $value($key) : null;

        $this->merge([
            'customer_id' => $value('customer_id'),
            'type' => $value('type'),
            'principal' => $value('type') === 'open' ? null : $value('principal'),
            'opening_balance' => $open('opening_balance'),
            'credit_limit' => $open('credit_limit'),
            'down_payment' => $plan('down_payment'),
            'markup_type' => $plan('markup_type'),
            'markup_value' => $plan('markup_value'),
            'installment_count' => $plan('installment_count'),
            'frequency' => $plan('frequency'),
            'start_date' => $value('start_date'),
            'first_due_date' => $plan('first_due_date'),
            'grace_days' => $plan('grace_days'),
            'custom_schedule' => in_array($value('type'), ['cash', 'open'], true) ? null : $this->cleanCustomSchedule($this->input('custom_schedule')),
            'notes' => is_scalar($this->input('notes')) && trim((string) $this->input('notes')) !== '' ? trim((string) $this->input('notes')) : null,
            'investor_id' => $value('investor_id'),
        ]);
    }

    /** A collector never sees the investors, so cannot pick one: their contracts are funded by the main investor. */
    private function mayChooseInvestor(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! ($this->user()?->can('viewAny', Investor::class) ?? false)) {
                $fail(__('Choosing who funds a contract is for the people who see the investors.'));
            }
        };
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
