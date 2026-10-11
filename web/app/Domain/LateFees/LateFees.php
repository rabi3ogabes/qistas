<?php

namespace App\Domain\LateFees;

use App\Models\Contract;
use App\Models\Installment;
use App\Models\LateFee;
use App\Models\LateFeeAllocation;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A contract's late fees as the ledger sees them (Win Plan K4): what is still owed on them, the lines the payment
 * allocator takes, and writing or reversing what a payment put on them. Runs in the caller's workspace and transaction.
 */
final class LateFees
{
    /** What the contract's fees still owe (nothing on a cancelled contract). */
    public static function owed(Contract $contract): string
    {
        if ($contract->status === 'cancelled') {
            return '0.00';
        }

        return Money::fromDatabase(LateFee::query()->where('contract_id', $contract->id)->where('status', 'open')->sum(DB::raw('amount - paid_amount')));
    }

    /**
     * The fees still owed on these contracts, oldest first.
     *
     * @param  list<string>  $contractIds
     * @return Collection<int, LateFee>
     */
    public static function open(array $contractIds, bool $lock = false): Collection
    {
        return LateFee::query()->whereIn('contract_id', $contractIds)->where('status', 'open')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('charged_on')->orderBy('period')->get();
    }

    /**
     * The fees as the payment allocator takes them; ties on a day go to the older instalment.
     *
     * @param  Collection<int, LateFee>  $fees
     * @return list<array{id: string, charged_on: string, number: int, period: int, remaining: string}>
     */
    public static function rows(Collection $fees): array
    {
        $numbers = Installment::query()->whereIn('id', $fees->pluck('installment_id')->unique()->all())->pluck('number', 'id');

        return $fees->map(fn (LateFee $fee) => [
            'id' => $fee->id, 'charged_on' => $fee->charged_on->format('Y-m-d'), 'number' => (int) ($numbers[$fee->installment_id] ?? 0),
            'period' => $fee->period, 'remaining' => $fee->remaining(),
        ])->values()->all();
    }

    /**
     * Puts what the allocator gave each fee on it, with an allocation line for each.
     *
     * @param  Collection<int, LateFee>  $fees
     * @param  list<array{late_fee_id: string, amount: string}>  $allocations
     */
    public static function apply(Transaction $payment, Collection $fees, array $allocations, CarbonInterface $at): void
    {
        $byId = $fees->keyBy('id');
        foreach ($allocations as $allocation) {
            $byId[$allocation['late_fee_id']]->applyPayment($allocation['amount'], $at);
            (new LateFeeAllocation)->forceFill([
                'transaction_id' => $payment->id,
                'late_fee_id' => $allocation['late_fee_id'],
                'amount' => $allocation['amount'],
            ])->save();
        }
    }

    /** Takes back exactly what [$original] put on fees, written as [$reversal]'s own (negative) allocations. */
    public static function reverse(Transaction $original, Transaction $reversal, CarbonInterface $at): void
    {
        foreach (LateFeeAllocation::query()->where('transaction_id', $original->id)->get() as $allocation) {
            $negated = Money::sub('0', $allocation->amount);

            LateFee::query()->whereKey($allocation->late_fee_id)->lockForUpdate()->firstOrFail()->applyPayment($negated, $at);
            (new LateFeeAllocation)->forceFill([
                'transaction_id' => $reversal->id,
                'late_fee_id' => $allocation->late_fee_id,
                'amount' => $negated,
            ])->save();
        }
    }

    /** What of [$payment] went to late fees. */
    public static function paidBy(Transaction $payment): string
    {
        return Money::fromDatabase(LateFeeAllocation::query()->where('transaction_id', $payment->id)->sum('amount'));
    }
}
