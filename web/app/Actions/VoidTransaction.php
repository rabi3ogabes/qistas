<?php

namespace App\Actions;

use App\Domain\Ledger\ContractSettlement;
use App\Models\Contract;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a payment the only way a ledger allows: by adding a reversal. The original stays untouched; the
 * reversal carries the same amounts negated, and re-opens exactly the instalments the payment had paid.
 */
final class VoidTransaction
{
    public function __construct(private readonly CurrentTenant $current) {}

    /** @throws ValidationException when the transaction cannot be voided */
    public function handle(Transaction $transaction, ?string $reason = null, ?User $by = null): Transaction
    {
        return $this->current->use($transaction->tenant, fn () => DB::transaction(function () use ($transaction, $reason, $by): Transaction {
            $original = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $contract = Contract::query()->whereKey($original->contract_id)->lockForUpdate()->firstOrFail();

            // A down payment is a term of the contract (cancel and reopen it to change it) and a reversal
            // cannot itself be reversed; only instalment payments can be voided.
            if ($original->type !== 'payment') {
                throw ValidationException::withMessages(['transaction' => __('Only instalment payments can be voided.')]);
            }
            if (Transaction::where('reverses_transaction_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['transaction' => __('This payment has already been voided.')]);
            }

            $at = now();
            $reversal = (new Transaction)->forceFill([
                'contract_id' => $original->contract_id,
                'customer_id' => $original->customer_id,
                'type' => 'reversal',
                'method' => $original->method,
                'amount' => Money::sub('0', $original->amount),
                'paid_at' => $at,
                'note' => $reason,
                'reverses_transaction_id' => $original->id,
                'created_by_user_id' => $by?->id,
            ]);
            $reversal->save();

            foreach (TransactionAllocation::where('transaction_id', $original->id)->get() as $allocation) {
                $negated = Money::sub('0', $allocation->amount);

                Installment::query()->whereKey($allocation->installment_id)->lockForUpdate()->firstOrFail()->applyPayment($negated, $at);
                (new TransactionAllocation)->forceFill([
                    'transaction_id' => $reversal->id,
                    'installment_id' => $allocation->installment_id,
                    'amount' => $negated,
                ])->save();
            }

            ContractSettlement::sync($contract, $at);

            return $reversal;
        }));
    }
}
