<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Decides which instalments a payment settles: the oldest due first (ties by instalment number), each filled
 * completely before the next is touched. Pure arithmetic on cent-exact strings, no database and no floats.
 */
final class PaymentAllocator
{
    /**
     * @param  list<array{id: string, number: int, due_date: string, remaining: string}>  $installments
     * @param  string  $amount  a positive amount with at most two decimals, not more than the total remaining
     * @return list<array{installment_id: string, amount: string}> only the instalments that receive money, in order
     *
     * @throws InvalidArgumentException when the amount is not payable against these instalments
     */
    public function allocate(array $installments, string $amount): array
    {
        $left = Money::parse($amount);
        if (! Money::isPositive($left)) {
            throw new InvalidArgumentException('A payment must be greater than zero.');
        }
        if (Money::decimals($left) > 2) {
            throw new InvalidArgumentException('A payment can have at most two decimals.');
        }

        usort($installments, fn (array $a, array $b) => [$a['due_date'], $a['number']] <=> [$b['due_date'], $b['number']]);

        $allocations = [];
        foreach ($installments as $installment) {
            $remaining = Money::parse($installment['remaining']);
            if (! Money::isPositive($remaining)) {
                continue;
            }

            $take = Money::cmp($left, $remaining) < 0 ? $left : $remaining;
            $allocations[] = ['installment_id' => $installment['id'], 'amount' => Money::add($take, '0', 2)];
            $left = Money::sub($left, $take);

            if (Money::isZero($left)) {
                return $allocations;
            }
        }

        throw new InvalidArgumentException('The payment is more than what is owed.');
    }
}
