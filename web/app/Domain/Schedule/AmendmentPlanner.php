<?php

namespace App\Domain\Schedule;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Works out a contract's new plan (Win Plan PP6) from the instalments nobody has paid towards, without writing
 * anything. Whatever the change, the new instalments add up to exactly what the old ones did, so the agreed total never
 * moves; only how and when it is paid.
 *
 *   reschedule  what is left over a new count (equal amounts, the cents left over on the last) or at a new amount (the
 *               remainder last), from a new first date, on the contract's frequency or another
 *   skip        one instalment and every later one move one step on
 *   move        one instalment moves to another date
 *
 * A mistake throws InvalidArgumentException; its code names the field (see FIELDS).
 */
final class AmendmentPlanner
{
    /** The field each mistake belongs to, by exception code. */
    public const FIELDS = [1 => 'count', 2 => 'amount', 3 => 'first_due_date', 4 => 'installment_id', 5 => 'due_date', 6 => 'type'];

    /**
     * @param  list<array{id: string, number: int, due_date: string, amount: string}>  $untouched  in due-date order
     * @param  array{type: string, count?: int|null, amount?: string|null, first_due_date?: string|null, frequency?: string|null, installment_id?: string|null, due_date?: string|null}  $change
     * @return list<array{due_date: string, amount: string}> in due-date order
     */
    public function plan(array $untouched, string $contractFrequency, array $change, CarbonImmutable $today): array
    {
        if ($untouched === []) {
            throw new InvalidArgumentException(__('Every instalment has been paid towards; there is nothing left to change.'), 6);
        }

        return match ($change['type']) {
            'reschedule' => $this->reschedule($untouched, $contractFrequency, $change, $today),
            'skip' => $this->skip($untouched, $contractFrequency, $this->chosen($untouched, $change)),
            'move' => $this->move($untouched, $this->chosen($untouched, $change), $change, $today),
            default => throw new InvalidArgumentException(__('Choose how the plan changes.'), 6),
        };
    }

    /**
     * @param  list<array{id: string, number: int, due_date: string, amount: string}>  $untouched
     * @param  array<string, mixed>  $change
     * @return list<array{due_date: string, amount: string}>
     */
    private function reschedule(array $untouched, string $contractFrequency, array $change, CarbonImmutable $today): array
    {
        $first = $this->date($change['first_due_date'] ?? null, 3);
        if ($first->lt($today)) {
            throw new InvalidArgumentException(__('The first new instalment cannot be in the past.'), 3);
        }
        $frequency = $change['frequency'] ?? null;
        $frequency = is_string($frequency) && isset(ScheduleGenerator::FREQUENCIES[$frequency]) ? $frequency : $this->stepOf($contractFrequency);

        $left = '0';
        foreach ($untouched as $row) {
            $left = bcadd($left, $row['amount'], 2);
        }
        $leftCents = bcmul($left, '100', 0);

        $count = $change['count'] ?? null;
        $amount = $change['amount'] ?? null;
        if ($count !== null) {
            $count = (int) $count;
            if ($count < 1 || $count > ScheduleGenerator::MAX_COUNT) {
                throw new InvalidArgumentException(__('Choose between 1 and 600 instalments.'), 1);
            }
            if (bccomp($leftCents, (string) $count, 0) < 0) {
                throw new InvalidArgumentException(__('What is left is too small to give every instalment at least one cent.'), 1);
            }
            $baseCents = bcdiv($leftCents, (string) $count, 0);
            $amounts = array_fill(0, $count - 1, $baseCents);
            $amounts[] = bcsub($leftCents, bcmul($baseCents, (string) ($count - 1), 0), 0);
        } elseif ($amount !== null) {
            if (! preg_match('/^\d{1,14}(\.\d{1,2})?$/', (string) $amount) || bccomp((string) $amount, '0', 2) <= 0) {
                throw new InvalidArgumentException(__('Enter an instalment amount greater than zero, with at most two decimals.'), 2);
            }
            $amountCents = bcmul((string) $amount, '100', 0);
            if (bccomp($amountCents, $leftCents, 0) > 0) {
                throw new InvalidArgumentException(__('The instalment cannot be more than what is left.'), 2);
            }
            $whole = (int) bcdiv($leftCents, $amountCents, 0);
            $rest = bcmod($leftCents, $amountCents, 0);
            $count = $whole + (bccomp($rest, '0', 0) > 0 ? 1 : 0);
            if ($count > ScheduleGenerator::MAX_COUNT) {
                throw new InvalidArgumentException(__('That amount needs more than 600 instalments.'), 2);
            }
            $amounts = array_fill(0, $whole, $amountCents);
            if (bccomp($rest, '0', 0) > 0) {
                $amounts[] = $rest;
            }
        } else {
            throw new InvalidArgumentException(__('Give a number of instalments or an amount for each.'), 1);
        }

        $rows = [];
        foreach ($amounts as $n => $cents) {
            $rows[] = ['due_date' => $this->step($first, $frequency, $n)->format('Y-m-d'), 'amount' => bcdiv($cents, '100', 2)];
        }

        return $rows;
    }

    /**
     * @param  list<array{id: string, number: int, due_date: string, amount: string}>  $untouched
     * @param  array{id: string, number: int, due_date: string, amount: string}  $chosen
     * @return list<array{due_date: string, amount: string}>
     */
    private function skip(array $untouched, string $contractFrequency, array $chosen): array
    {
        $frequency = $this->stepOf($contractFrequency);

        return $this->sorted(array_map(fn (array $row) => [
            'due_date' => $row['due_date'] >= $chosen['due_date'] ? $this->step(CarbonImmutable::parse($row['due_date']), $frequency, 1)->format('Y-m-d') : $row['due_date'],
            'amount' => bcadd($row['amount'], '0', 2),
        ], $untouched));
    }

    /**
     * @param  list<array{id: string, number: int, due_date: string, amount: string}>  $untouched
     * @param  array{id: string, number: int, due_date: string, amount: string}  $chosen
     * @param  array<string, mixed>  $change
     * @return list<array{due_date: string, amount: string}>
     */
    private function move(array $untouched, array $chosen, array $change, CarbonImmutable $today): array
    {
        $date = $this->date($change['due_date'] ?? null, 5);
        if ($date->lt($today)) {
            throw new InvalidArgumentException(__('The new date cannot be in the past.'), 5);
        }

        return $this->sorted(array_map(fn (array $row) => [
            'due_date' => $row['id'] === $chosen['id'] ? $date->format('Y-m-d') : $row['due_date'],
            'amount' => bcadd($row['amount'], '0', 2),
        ], $untouched));
    }

    /**
     * @param  list<array{id: string, number: int, due_date: string, amount: string}>  $untouched
     * @param  array<string, mixed>  $change
     * @return array{id: string, number: int, due_date: string, amount: string}
     */
    private function chosen(array $untouched, array $change): array
    {
        foreach ($untouched as $row) {
            if ($row['id'] === ($change['installment_id'] ?? null)) {
                return $row;
            }
        }

        throw new InvalidArgumentException(__('Choose an instalment nobody has paid towards yet.'), 4);
    }

    /** A month for the shop's own dates (custom), else the contract's own step. */
    private function stepOf(string $frequency): string
    {
        return isset(ScheduleGenerator::FREQUENCIES[$frequency]) ? $frequency : 'monthly';
    }

    /** [$n] steps on from [$first], always counted from it so a clamped February never drags the later months. */
    private function step(CarbonImmutable $first, string $frequency, int $n): CarbonImmutable
    {
        $step = ScheduleGenerator::FREQUENCIES[$frequency];

        return isset($step['days']) ? $first->addDays($step['days'] * $n) : $first->addMonthsNoOverflow($step['months'] * $n);
    }

    private function date(mixed $value, int $code): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException(__('Enter a date.'), $code);
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    /**
     * @param  list<array{due_date: string, amount: string}>  $rows
     * @return list<array{due_date: string, amount: string}>
     */
    private function sorted(array $rows): array
    {
        // Stable: two instalments on the same day keep their order.
        $keyed = [];
        foreach ($rows as $i => $row) {
            $keyed[] = [$row['due_date'], $i, $row];
        }
        sort($keyed);

        return array_map(fn (array $entry) => $entry[2], $keyed);
    }
}
