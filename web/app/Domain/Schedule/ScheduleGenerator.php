<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Builds an instalment schedule.
 *
 *   financed = principal - down payment
 *   markup   = fixed amount | percent of the financed amount (rounded half up to 2 dp) | none
 *   total    = financed + markup, split in whole cents; the LAST instalment absorbs the remainder
 *   monthly  = the day of the FIRST due date each month, clamped to the end of shorter months
 *
 * Cross-checked against shared/schedule-vectors.json (an independent reference) by the test suite.
 */
final class ScheduleGenerator
{
    public const MAX_COUNT = 120;

    public function generate(ScheduleRequest $r): ScheduleResult
    {
        $principal = $this->amount($r->principal, 'principal');
        $down = $this->amount($r->downPayment, 'down payment');
        $markupValue = $this->parse($r->markupValue, 'markup');

        if (! Money::isPositive($principal)) {
            throw new InvalidScheduleException('The principal must be greater than zero.');
        }
        if (Money::isNegative($down) || Money::cmp($down, $principal) >= 0) {
            throw new InvalidScheduleException('The down payment must be zero or more and less than the principal.');
        }
        if (Money::isNegative($markupValue)) {
            throw new InvalidScheduleException('The markup cannot be negative.');
        }
        if ($r->count < 1 || $r->count > self::MAX_COUNT) {
            throw new InvalidScheduleException('The number of instalments must be between 1 and '.self::MAX_COUNT.'.');
        }
        if (! in_array($r->frequency, ['weekly', 'biweekly', 'monthly'], true)) {
            throw new InvalidScheduleException('The frequency must be weekly, biweekly or monthly.');
        }
        $first = $this->date($r->firstDueDate);

        $financed = Money::sub($principal, $down, 2);
        $markup = match ($r->markupType) {
            'none' => '0.00',
            'fixed' => $this->twoDecimals($markupValue, 'fixed markup'),
            'percent' => Money::round(Money::div(Money::mul($financed, $markupValue, 6), '100', 6), 2),
            default => throw new InvalidScheduleException('The markup type must be none, fixed or percent.'),
        };
        $total = Money::add($financed, $markup, 2);

        $totalCents = bcmul($total, '100', 0);
        if (bccomp($totalCents, (string) $r->count, 0) < 0) {
            throw new InvalidScheduleException('The total is too small to give every instalment at least one cent.');
        }
        $baseCents = bcdiv($totalCents, (string) $r->count, 0);
        $lastCents = bcsub($totalCents, bcmul($baseCents, (string) ($r->count - 1), 0), 0);

        $rows = [];
        for ($n = 0; $n < $r->count; $n++) {
            $cents = $n === $r->count - 1 ? $lastCents : $baseCents;
            $rows[] = [
                'number' => $n + 1,
                'due_date' => $this->due($first, $r->frequency, $n)->format('Y-m-d'),
                'amount' => bcdiv($cents, '100', 2),
            ];
        }

        return new ScheduleResult($financed, $markup, $total, $rows);
    }

    private function due(CarbonImmutable $first, string $frequency, int $n): CarbonImmutable
    {
        return match ($frequency) {
            'weekly' => $first->addDays(7 * $n),
            'biweekly' => $first->addDays(14 * $n),
            default => $first->addMonthsNoOverflow($n),
        };
    }

    private function date(string $value): CarbonImmutable
    {
        try {
            $d = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidArgumentException) {
            $d = false; // Carbon throws on trailing or malformed data
        }
        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw new InvalidScheduleException('The first due date must be a real date in YYYY-MM-DD format.');
        }

        return $d;
    }

    private function parse(string $value, string $label): string
    {
        try {
            return Money::parse($value);
        } catch (InvalidArgumentException) {
            throw new InvalidScheduleException("The {$label} is not a valid amount.");
        }
    }

    private function amount(string $value, string $label): string
    {
        return $this->twoDecimals($this->parse($value, $label), $label);
    }

    private function twoDecimals(string $value, string $label): string
    {
        if (Money::decimals($value) > 2) {
            throw new InvalidScheduleException("The {$label} can have at most 2 decimals.");
        }

        return Money::round($value, 2);
    }
}
