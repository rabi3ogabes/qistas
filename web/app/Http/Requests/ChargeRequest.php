<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MoneyRules;
use App\Models\Transaction;
use App\Support\Digits;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** "They took" on an open contract, from the web app and the API. Who may write it is the controller's to authorise. */
class ChargeRequest extends FormRequest
{
    use MoneyRules;

    /** On the web page, "they took" sits beside "they paid": its mistakes are shown on its own form. */
    protected $errorBag = 'took';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', $this->amountRule(positive: true)],
            'tag' => ['nullable', Rule::in(Transaction::TAGS)],
            'note' => ['nullable', 'string', 'max:1000'],
            'charged_at' => ['nullable', 'date', 'before_or_equal:now'],
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.:\-]{1,100}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['tag.in' => __('Choose one of the tags offered.')];
    }

    /** When they took it: a date on its own is that day (now, if it is today); null is now. */
    public function chargedAt(): ?CarbonInterface
    {
        $value = $this->validated('charged_at');
        if ($value === null) {
            return null;
        }
        $moment = Carbon::parse($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $moment;
        }

        return $moment->isToday() ? now() : $moment->endOfDay();
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $key): ?string => is_scalar($this->input($key)) && trim((string) $this->input($key)) !== '' ? trim((string) $this->input($key)) : null;

        $this->merge([
            'amount' => ($amount = $text('amount')) === null ? null : str_replace([',', ' '], '', Digits::toAscii($amount)),
            'tag' => $text('tag'),
            'note' => $text('note'),
            'charged_at' => $text('charged_at'),
            'idempotency_key' => $text('idempotency_key') ?? (trim((string) $this->header('Idempotency-Key')) ?: null),
        ]);
    }
}
