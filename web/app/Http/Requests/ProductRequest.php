<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MoneyRules;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;

/** A product of the list, added or changed, from the web app and the API. Who may is the controller's to authorise. */
class ProductRequest extends FormRequest
{
    use MoneyRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $adding = $this->route('product') === null;

        return [
            'name' => [...($adding ? [] : ['sometimes']), 'required', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:60'],
            'default_price' => ['nullable', 'string', $this->amountRule()],
            'cost' => ['nullable', 'string', $this->amountRule()],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $key): ?string => is_scalar($this->input($key)) && trim((string) $this->input($key)) !== '' ? trim((string) $this->input($key)) : null;
        $amount = fn (string $key): ?string => ($v = $text($key)) === null ? null : str_replace([',', ' '], '', Digits::toAscii($v));

        $this->merge(array_filter([
            'name' => $text('name'),
            'sku' => $text('sku'),
            'default_price' => $amount('default_price'),
            'cost' => $amount('cost'),
        ], fn (?string $v, string $key) => $v !== null || $this->has($key), ARRAY_FILTER_USE_BOTH));
    }
}
