<?php

namespace App\Domain\Ledger;

use App\Models\Contract;
use App\Models\Installment;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * What a payment would cover before it is taken (Win Plan PP16): the instalments it pays in full or in part, what is
 * left on the last one it touches, and what is owed after. The very allocator RecordPayment uses decides it, and
 * nothing is written. An open contract has no instalments: the amount simply comes off its balance.
 */
final class PaymentPreview
{
    public function __construct(private readonly PaymentAllocator $allocator) {}

    /**
     * @param  string  $amount  positive, at most two decimals
     * @return array{covers: list<array{number: int, due_date: string, amount: string, settles: bool}>, left_on_last: ?string, owed_after: string}
     *
     * @throws ValidationException when the amount could not be recorded either
     */
    public function for(Contract $contract, string $amount): array
    {
        $amount = Money::add(Money::parse($amount), '0', 2);

        if ($contract->isOpen()) {
            return ['covers' => [], 'left_on_last' => null, 'owed_after' => Money::sub(OpenAccount::balance($contract), $amount, 2)];
        }

        $installments = Installment::query()->where('contract_id', $contract->id)->orderBy('number')->get()->keyBy('id');
        $owed = $installments->reduce(fn (string $sum, Installment $i) => Money::add($sum, $i->remaining()), '0');
        if (Money::cmp($amount, $owed) > 0) {
            throw ValidationException::withMessages(['amount' => Money::isZero($owed)
                ? __('Nothing is owed on this contract.')
                : __('The payment is more than the :owed still owed on this contract.', ['owed' => Money::add($owed, '0', 2)])]);
        }

        try {
            $allocations = $this->allocator->allocate($installments->map(fn (Installment $i) => [
                'id' => $i->id, 'number' => $i->number, 'due_date' => $i->due_date->format('Y-m-d'), 'remaining' => $i->remaining(),
            ])->values()->all(), $amount);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $covers = [];
        $left = null;
        foreach ($allocations as $allocation) {
            $installment = $installments[$allocation['installment_id']];
            $after = Money::sub($installment->remaining(), $allocation['amount'], 2);
            $covers[] = [
                'number' => $installment->number,
                'due_date' => $installment->due_date->format('Y-m-d'),
                'amount' => $allocation['amount'],
                'settles' => Money::isZero($after),
            ];
            $left = $after;
        }

        return ['covers' => $covers, 'left_on_last' => $left, 'owed_after' => Money::sub($owed, $amount, 2)];
    }
}
