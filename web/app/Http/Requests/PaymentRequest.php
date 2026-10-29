<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MoneyRules;
use App\Http\Requests\Concerns\ReadsHijriDates;
use App\Models\Transaction;
use App\Support\Digits;
use App\Support\PaymentDate;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Validation and authorisation for recording a payment, shared by the web app and the API. How much may be
 * paid against which contract is decided by RecordPayment, which has the ledger in front of it.
 */
class PaymentRequest extends FormRequest
{
    use MoneyRules, ReadsHijriDates;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Transaction::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', $this->amountRule(positive: true)],
            'method' => ['required', 'string', Rule::in(config('qistas.payment_methods'))],
            'paid_at' => ['nullable', 'bail', 'date', PaymentDate::notInFuture()],
            'note' => ['nullable', 'string', 'max:1000'],
            'tag' => ['nullable', Rule::in(Transaction::TAGS)],
            // Makes a retry (a double click, a flaky connection) record one payment, not two.
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.:\-]{1,100}$/'],
            // The instalment to fill first (Win Plan PP6); RecordPayment checks it belongs to the contract.
            'apply_to' => ['nullable', 'uuid'],
            // Taken on the customer's page: go back there, not to the contract's.
            'return_to' => ['nullable', Rule::in(['customer'])],
            // The day typed in Hijri on the web form (Win Plan CT26).
            ...$this->hijriRules(['paid_at']),
        ];
    }

    /**
     * When the money was received. A date on its own means that day on the workspace's calendar: right now if it is the
     * workspace's today, otherwise the last second of that day (see PaymentDate). Null means now.
     */
    public function paidAt(): ?CarbonInterface
    {
        $value = $this->validated('paid_at');

        if ($value === null) {
            return null;
        }

        $tenant = app(CurrentTenant::class)->get() ?? throw new LogicException('A payment is dated on a workspace\'s calendar, and no workspace is current.');

        return PaymentDate::moment($tenant, $value);
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
            'tag' => $text('tag'),
            'apply_to' => $text('apply_to'),
            // The API takes it as the Idempotency-Key header; the web form sends it as a field.
            'idempotency_key' => $text('idempotency_key') ?? (trim((string) $this->header('Idempotency-Key')) ?: null),
        ]);
        $this->merge($this->gregorianFromHijri(['paid_at']));
    }
}
