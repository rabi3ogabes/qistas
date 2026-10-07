<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use App\Support\Digits;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation and authorisation for recording a payment, shared by the web app and the API. How much may be
 * paid against which contract is decided by RecordPayment, which has the ledger in front of it.
 */
class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Transaction::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! preg_match('/^\d{1,14}(\.\d{1,2})?$/', (string) $value) || (float) $value <= 0) {
                    $fail(__('Enter an amount greater than zero, with at most two decimals.'));
                }
            }],
            'method' => ['required', 'string', Rule::in(config('qistas.payment_methods'))],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $key): ?string => is_scalar($this->input($key)) && trim((string) $this->input($key)) !== ''
            ? trim((string) $this->input($key))
            : null;

        $this->merge([
            'amount' => ($amount = $text('amount')) === null ? null : Digits::toAscii($amount),
            'method' => $text('method'),
            'paid_at' => $text('paid_at'),
            'note' => $text('note'),
        ]);
    }
}
