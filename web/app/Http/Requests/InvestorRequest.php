<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MoneyRules;
use App\Models\Investor;
use App\Support\Digits;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding an investor or changing one, from the web app and the API. Who may do it is the policy's business (the
 * controller authorises); the plan's allowance is the action's. `opening_capital` only means something when adding.
 */
class InvestorRequest extends FormRequest
{
    use MoneyRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $adding = $this->route('investor') === null;

        return [
            'name' => [...($adding ? [] : ['sometimes']), 'required', 'string', 'max:120'],
            'commercial_registration' => ['nullable', 'string', 'max:60'],
            'commission_percent' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/', function (string $attribute, mixed $value, Closure $fail): void {
                if ((float) $value > 100) {
                    $fail(__('A commission is a share of the profit: 100% at most.'));
                }
            }],
            'notes' => ['nullable', 'string', 'max:2000'],
            'opening_capital' => $adding ? ['nullable', 'string', $this->amountRule()] : ['prohibited'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['commercial_registration' => __('commercial registration'), 'commission_percent' => __('commission')];
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $key): ?string => is_scalar($this->input($key)) && trim((string) $this->input($key)) !== '' ? trim((string) $this->input($key)) : null;
        $number = fn (string $key): ?string => ($value = $text($key)) === null ? null : Digits::toAscii($value);

        $this->merge(array_filter([
            'name' => $text('name'),
            'commercial_registration' => $number('commercial_registration'),
            'commission_percent' => $number('commission_percent'),
            'notes' => $text('notes'),
            'opening_capital' => $number('opening_capital'),
        ], fn (?string $value, string $key) => $value !== null || $this->has($key), ARRAY_FILTER_USE_BOTH));
    }

    /** The investor being changed, when it is a change. */
    public function investor(): ?Investor
    {
        $investor = $this->route('investor');

        return $investor instanceof Investor ? $investor : null;
    }
}
