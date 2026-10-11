<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Decides what a payment settles: the oldest due first (ties by instalment number), each filled completely before the
 * next is touched. With late fees (Win Plan K4, decision D3) the order is what is already due, then the fees (oldest
 * first), then what is not due yet. Pure arithmetic on cent-exact strings, no database and no floats.
 */
final class PaymentAllocator
{
    /**
     * @param  list<array{id: string, number: int, due_date: string, remaining: string}>  $installments
     * @param  string  $amount  a positive amount with at most two decimals, not more than the total remaining
     * @param  ?string  $first  an instalment to fill before any other (Win Plan PP6); the rest then go oldest first
     * @return list<array{installment_id: string, amount: string}> only the instalments that receive money, in order
     *
     * @throws InvalidArgumentException when the amount is not payable against these instalments
     */
    public function allocate(array $installments, string $amount, ?string $first = null): array
    {
        return $this->allocateWithFees($installments, [], $amount, '9999-12-31', $first)['installments'];
    }

    /**
     * The late-fee order (Win Plan K4). [$fees] are the open late fees; each row's number is its instalment's.
     *
     * @param  list<array{id: string, number: int, due_date: string, remaining: string}>  $installments
     * @param  list<array{id: string, charged_on: string, number: int, period: int, remaining: string}>  $fees
     * @param  string  $today  the payment's day (Y-m-d): instalments due by then are paid before the fees
     * @param  ?string  $first  an instalment to fill before anything else
     * @param  bool  $feesFirst  every fee before any instalment (an early settlement: its rebate never comes off a fee)
     * @return array{installments: list<array{installment_id: string, amount: string}>, fees: list<array{late_fee_id: string, amount: string}>}
     *
     * @throws InvalidArgumentException when the amount is not payable against these lines
     */
    public function allocateWithFees(array $installments, array $fees, string $amount, string $today, ?string $first = null, bool $feesFirst = false): array
    {
        $left = Money::parse($amount);
        if (! Money::isPositive($left)) {
            throw new InvalidArgumentException('A payment must be greater than zero.');
        }
        if (Money::decimals($left) > 2) {
            throw new InvalidArgumentException('A payment can have at most two decimals.');
        }

        // 0: the chosen instalment, 1: due by today, 2: fees, 3: not due yet.
        $queue = [];
        foreach ($installments as $installment) {
            $group = match (true) {
                $installment['id'] === $first => 0,
                ! $feesFirst && $installment['due_date'] <= $today => 1,
                default => 3,
            };
            $queue[] = ['kind' => 'installment', 'id' => $installment['id'], 'remaining' => $installment['remaining'], 'key' => [$group, $installment['due_date'], $installment['number'], 0]];
        }
        foreach ($fees as $fee) {
            $queue[] = ['kind' => 'fee', 'id' => $fee['id'], 'remaining' => $fee['remaining'], 'key' => [2, $fee['charged_on'], $fee['number'], $fee['period']]];
        }
        usort($queue, fn (array $a, array $b) => $a['key'] <=> $b['key']);

        $allocations = ['installments' => [], 'fees' => []];
        foreach ($queue as $line) {
            $remaining = Money::parse($line['remaining']);
            if (! Money::isPositive($remaining)) {
                continue;
            }

            $take = Money::cmp($left, $remaining) < 0 ? $left : $remaining;
            if ($line['kind'] === 'fee') {
                $allocations['fees'][] = ['late_fee_id' => $line['id'], 'amount' => Money::add($take, '0', 2)];
            } else {
                $allocations['installments'][] = ['installment_id' => $line['id'], 'amount' => Money::add($take, '0', 2)];
            }
            $left = Money::sub($left, $take);

            if (Money::isZero($left)) {
                return $allocations;
            }
        }

        throw new InvalidArgumentException('The payment is more than what is owed.');
    }
}
