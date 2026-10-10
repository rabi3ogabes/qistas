<?php

namespace App\Http\Requests;

use App\Domain\Schedule\ScheduleGenerator;
use App\Http\Requests\Concerns\MoneyRules;
use App\Http\Requests\Concerns\ScheduleRules;
use App\Models\Contract;
use App\Models\Investor;
use App\Support\Digits;
use App\Support\Imei;
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
            // Contract details (Win Plan PP7) and a discount at sale (PP6).
            'title' => ['nullable', 'string', 'max:120'],
            'own_reference' => ['nullable', 'string', 'max:40', 'regex:/^[\pL\pN][\pL\pN\/._\- ]*$/u', $this->uniqueOwnReference()],
            'cost_price' => ['nullable', 'string', $this->amountRule()],
            'tax_percent' => ['nullable', 'string', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'discount_type' => ['nullable', Rule::in(['none', 'fixed', 'percent'])],
            'discount_value' => ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'items' => ['nullable', 'array', 'max:10'],
            'items.*.name' => ['required', 'string', 'max:120'],
            'items.*.quantity' => ['nullable', 'integer', 'between:1,999'],
            'items.*.serial' => ['nullable', 'string', 'max:60', $this->imeiRule()],
            'items.*.cost' => ['nullable', 'string', $this->amountRule()],
            'items.*.price' => ['nullable', 'string', $this->amountRule()],
            'items.*.product_id' => ['nullable', 'string', 'uuid', Rule::exists('products', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            // Who funds it: one of this workspace's investors still funding contracts, chosen by someone who sees them.
            'investor_id' => ['nullable', 'string', 'uuid', $this->mayChooseInvestor(), Rule::exists('investors', 'id')
                ->where('tenant_id', app(CurrentTenant::class)->id())
                ->whereNull('archived_at')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['investor_id' => __('investor'), 'own_reference' => __('contract number'), 'items' => __('items')];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->customScheduleMessages(),
            'items.max' => __('A contract lists up to 10 items.'),
            'items.*.name.required' => __('Item :position: enter what it is.'),
            'own_reference.regex' => __('Use letters, digits and - / . _ for the contract number.'),
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
            'title' => is_scalar($this->input('title')) && trim((string) $this->input('title')) !== '' ? trim((string) $this->input('title')) : null,
            'own_reference' => $value('own_reference'),
            'cost_price' => $plan('cost_price') ?? ($value('type') === 'cash' ? $value('cost_price') : null),
            'tax_percent' => $value('tax_percent'),
            'discount_type' => $value('discount_type'),
            'discount_value' => $value('discount_value'),
            'items' => $this->cleanItems($this->input('items')),
        ]);
    }

    /**
     * The items as typed, with blank rows dropped and digits made plain.
     *
     * @return list<array<string, mixed>>|null
     */
    private function cleanItems(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $text = fn (mixed $v): ?string => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        $items = [];
        foreach ($raw as $row) {
            if (! is_array($row) || array_filter(array_map($text, $row)) === []) {
                continue;
            }
            $items[] = array_filter([
                'name' => $text($row['name'] ?? null),
                'quantity' => ($q = $text($row['quantity'] ?? null)) === null ? null : Digits::toAscii($q),
                'serial' => ($s = $text($row['serial'] ?? null)) === null ? null : preg_replace('/\s+/', '', Digits::toAscii($s)),
                'cost' => ($c = $text($row['cost'] ?? null)) === null ? null : Digits::toAscii($c),
                'price' => ($p = $text($row['price'] ?? null)) === null ? null : Digits::toAscii($p),
                'product_id' => $text($row['product_id'] ?? null),
            ], fn (?string $v) => $v !== null) + ['name' => null];
        }

        return $items;
    }

    /** The shop's own number is unique in the workspace, whatever its case. */
    private function uniqueOwnReference(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $taken = Contract::query()->whereRaw('lower(own_reference) = ?', [mb_strtolower((string) $value)])->exists();
            if ($taken) {
                $fail(__('Another contract already has this number.'));
            }
        };
    }

    /** Fifteen digits are an IMEI, and must pass its check digit; any other serial is taken as typed. */
    private function imeiRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (Imei::looksLikeOne((string) $value) && ! Imei::valid((string) $value)) {
                $fail(__('This IMEI is not valid: check the 15 digits.'));
            }
        };
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
