<?php

namespace App\Domain\LateFees;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Which late fees a contract owes up to a day and has not been charged yet (Win Plan K4, decision D2). Pure arithmetic
 * on dates and cent-exact strings: no database, no clock.
 *
 * Period 1 starts the day after the instalment's last day of grace; a daily policy starts a new period each day, a
 * weekly one each seventh day and a monthly one on the same day of each later month (the month's last day when it is
 * shorter). A period that began before the contract was entered into Qistas is never charged: an old deal typed in
 * today does not owe for the months before it. The caps count every fee charged, waived ones too, so a waiver is never
 * charged again; the fee that reaches a cap is clipped to it. Fees are charged oldest period first across the
 * contract, so the cap per contract is spent in the order the fees fell due.
 */
final class LateFeeCalculator
{
    /**
     * [$installments] are the contract's instalments still owed.
     *
     * @param  list<array{id: string, number: int, grace_until: string, remaining: string}>  $installments
     * @param  list<array{installment_id: string, period: int, amount: string}>  $charged  every fee the contract already has
     * @param  string  $from  the day the contract was entered (Y-m-d); earlier periods are never charged
     * @param  string  $today  the last day to charge up to (Y-m-d)
     * @return list<array{installment_id: string, period: int, charged_on: string, amount: string}> oldest first
     */
    public function due(LateFeePolicy $policy, array $installments, array $charged, string $from, string $today): array
    {
        if (! Money::isPositive($policy->amount)) {
            return [];
        }

        $exists = [];
        $perInstallment = [];
        $perContract = '0';
        foreach ($charged as $fee) {
            $exists[$fee['installment_id'].'|'.$fee['period']] = true;
            $perInstallment[$fee['installment_id']] = Money::add($perInstallment[$fee['installment_id']] ?? '0', $fee['amount']);
            $perContract = Money::add($perContract, $fee['amount']);
        }

        $candidates = [];
        foreach ($installments as $installment) {
            if (! Money::isPositive($installment['remaining'])) {
                continue;
            }
            foreach ($this->periods($policy->frequency, $installment['grace_until'], $today) as $period => $startsOn) {
                if ($startsOn >= $from && ! isset($exists[$installment['id'].'|'.$period])) {
                    $candidates[] = ['installment' => $installment, 'period' => $period, 'charged_on' => $startsOn];
                }
            }
        }
        usort($candidates, fn (array $a, array $b) => [$a['charged_on'], $a['installment']['number'], $a['period']] <=> [$b['charged_on'], $b['installment']['number'], $b['period']]);

        $fees = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['installment']['id'];
            $fee = $policy->type === 'percent'
                ? Money::round(Money::div(Money::mul($candidate['installment']['remaining'], $policy->amount, 8), '100', 8), 2)
                : Money::add($policy->amount, '0', 2);
            if ($policy->capPerInstallment !== null) {
                $fee = self::atMost($fee, Money::sub($policy->capPerInstallment, $perInstallment[$id] ?? '0', 2));
            }
            if ($policy->capPerContract !== null) {
                $fee = self::atMost($fee, Money::sub($policy->capPerContract, $perContract, 2));
            }
            if (! Money::isPositive($fee)) {
                continue;
            }

            $fees[] = ['installment_id' => $id, 'period' => $candidate['period'], 'charged_on' => $candidate['charged_on'], 'amount' => $fee];
            $perInstallment[$id] = Money::add($perInstallment[$id] ?? '0', $fee);
            $perContract = Money::add($perContract, $fee);
        }

        return $fees;
    }

    /**
     * Each period that has begun by [$today], by number.
     *
     * @return array<int, string> the day each period begins (Y-m-d)
     */
    private function periods(string $frequency, string $graceUntil, string $today): array
    {
        $first = CarbonImmutable::parse($graceUntil)->addDay();
        $periods = [];
        for ($period = 1; ; $period++) {
            $startsOn = (match ($frequency) {
                'daily' => $first->addDays($period - 1),
                'weekly' => $first->addWeeks($period - 1),
                'monthly' => $first->addMonthsNoOverflow($period - 1),
                default => $first,
            })->format('Y-m-d');
            if ($startsOn > $today) {
                break;
            }
            $periods[$period] = $startsOn;
            if (! in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
                break;
            }
        }

        return $periods;
    }

    private static function atMost(string $fee, string $left): string
    {
        return Money::cmp($fee, $left) > 0 ? $left : $fee;
    }
}
