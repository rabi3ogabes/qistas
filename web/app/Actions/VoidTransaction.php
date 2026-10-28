<?php

namespace App\Actions;

use App\Domain\DayClose\DayCloseGuard;
use App\Domain\Investors\InvestorLedger;
use App\Domain\LateFees\LateFees;
use App\Domain\Ledger\ContractSettlement;
use App\Domain\PayPattern\RecomputePayPattern;
use App\Models\Contract;
use App\Models\ContractAdjustment;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a payment, or what an open contract's customer took, the only way a ledger allows: by adding a reversal. The
 * original stays untouched; the reversal carries the same amounts negated, and re-opens exactly the instalments the
 * payment had paid, and the late fees it had paid. A charge's reversal is a `charge_reversal`, so it is never mistaken
 * for money going back out.
 */
final class VoidTransaction
{
    public function __construct(private readonly CurrentTenant $current, private readonly InvestorLedger $investors) {}

    /**
     * @throws ValidationException when the transaction cannot be voided (on `transaction`, also when the payment's day or
     *                             today is closed and [$by] is not an owner or a manager: see DayCloseGuard)
     */
    public function handle(Transaction $transaction, ?string $reason = null, ?User $by = null): Transaction
    {
        return $this->current->use($transaction->tenant, fn () => DB::transaction(function () use ($transaction, $reason, $by): Transaction {
            $original = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $contract = Contract::query()->whereKey($original->contract_id)->lockForUpdate()->firstOrFail();

            // A down payment is a term of the contract (cancel and reopen it to change it) and a reversal
            // cannot itself be reversed; only payments and charges can be voided.
            if (! in_array($original->type, ['payment', 'charge'], true)) {
                throw ValidationException::withMessages(['transaction' => __('Only instalment payments can be voided.')]);
            }
            if (Transaction::where('reverses_transaction_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['transaction' => __('This payment has already been voided.')]);
            }
            // Once a contract is open, the instalments its earlier payments settled are history: they stay as paid.
            if ($contract->isOpen() && $original->type === 'payment' && TransactionAllocation::where('transaction_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['transaction' => __('This payment was made before the contract became open, so it stays as it is.')]);
            }

            // Voiding a payment touches the day it was taken and today: once either is closed, it is an owner's or a
            // manager's to do (D10), asked before anything is written. A charge moves no cash, so no closed figure.
            if ($original->type === 'payment') {
                DayCloseGuard::assertMayChange($transaction->tenant, $original->paid_at, $by, DayCloseGuard::VOID);
            }

            $at = now();
            $reversal = (new Transaction)->forceFill([
                'contract_id' => $original->contract_id,
                'customer_id' => $original->customer_id,
                'type' => $original->type === 'charge' ? 'charge_reversal' : 'reversal',
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

            // And exactly what it put on late fees (Win Plan K4); a fee waived since stays waived.
            LateFees::reverse($original, $reversal, $at);

            // Voiding the payment that settled a contract early brings back what its rebate had waived (Win Plan PP6).
            $rebate = ContractAdjustment::query()->where('transaction_id', $original->id)->where('type', 'early_rebate')->whereNull('reverses_adjustment_id')->first();
            if ($rebate !== null && ! ContractAdjustment::query()->where('reverses_adjustment_id', $rebate->id)->exists()) {
                foreach ($rebate->details['waived'] ?? [] as $waived) {
                    $installment = Installment::query()->whereKey($waived['installment_id'])->lockForUpdate()->first();
                    if ($installment !== null && $installment->status === 'waived') {
                        $installment->reopen();
                    }
                }
                (new ContractAdjustment)->forceFill([
                    'contract_id' => $contract->id,
                    'type' => 'early_rebate',
                    'amount' => Money::sub('0', $rebate->amount),
                    'reason' => $reason,
                    'transaction_id' => $reversal->id,
                    'reverses_adjustment_id' => $rebate->id,
                    'created_by_user_id' => $by?->id,
                ])->save();
            }

            ContractSettlement::sync($contract, $at);
            // The investor gives back exactly what the payment had credited.
            $this->investors->reverse($reversal);

            // A voided payment no longer tells when the customer paid: their payday pattern is worked out again once the
            // reversal is committed. A failure there never reaches the void. A charge has no part in it.
            if ($original->type === 'payment') {
                RecomputePayPattern::afterCommit($transaction->tenant, $original->customer_id);
            }

            return $reversal;
        }));
    }
}
