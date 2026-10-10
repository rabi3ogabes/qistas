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
 *   daily, weekly, biweekly step 1, 7, 14 days; monthly, bimonthly, quarterly, semiannual and yearly step 1, 2, 3, 6
 *   and 12 months from the FIRST due date, on its day of the month, clamped to the end of shorter months
 *   custom   = the shop's own rows (date, amount): dates strictly increasing, every amount at least one cent, adding up
 *              to the total exactly
 *
 * Cross-checked against shared/schedule-vectors.json (an independent reference) by the test suite.
 */
final class ScheduleGenerator
{
    public const MAX_COUNT = 600;

    /** What contracts offered before flexible schedules; with that feature off these are still allowed. */
    public const BASIC_FREQUENCIES = ['weekly', 'biweekly', 'monthly'];

    public const BASIC_MAX_COUNT = 120;

    /** @var array<string, array{days?: int, months?: int}> each frequency's step */
    public const FREQUENCIES = [
        'daily' => ['days' => 1],
        'weekly' => ['days' => 7],
        'biweekly' => ['days' => 14],
        'monthly' => ['months' => 1],
        'bimonthly' => ['months' => 2],
        'quarterly' => ['months' => 3],
        'semiannual' => ['months' => 6],
        'yearly' => ['months' => 12],
    ];

    public const CUSTOM = 'custom';

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
        if ($r->frequency !== self::CUSTOM && ! array_key_exists($r->frequency, self::FREQUENCIES)) {
            throw new InvalidScheduleException('The frequency must be one of: '.implode(', ', [...array_keys(self::FREQUENCIES), self::CUSTOM]).'.');
        }

        $financed = Money::sub($principal, $down, 2);
        $markup = match ($r->markupType) {
            'none' => '0.00',
            'fixed' => $this->twoDecimals($markupValue, 'fixed markup'),
            'percent' => Money::round(Money::div(Money::mul($financed, $markupValue, 6), '100', 6), 2),
            default => throw new InvalidScheduleException('The markup type must be none, fixed or percent.'),
        };
        $total = Money::add($financed, $markup, 2);

        if ($r->frequency === self::CUSTOM) {
            return new ScheduleResult($financed, $markup, $total, $this->custom($r->customSchedule ?? [], $total));
        }

        if ($r->count < 1 || $r->count > self::MAX_COUNT) {
            throw new InvalidScheduleException('The number of instalments must be between 1 and '.self::MAX_COUNT.'.');
        }
        $first = $this->date($r->firstDueDate);

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
        $step = self::FREQUENCIES[$frequency];

        // Always counted from the first due date (never step by step), so a clamped February never drags later months.
        return isset($step['days']) ? $first->addDays($step['days'] * $n) : $first->addMonthsNoOverflow($step['months'] * $n);
    }

    /**
     * The shop's own rows, checked: at least one and at most MAX_COUNT, dates strictly increasing, amounts of at least
     * one cent with at most two decimals, adding up to the total exactly. A mistake names its row (1-based).
     *
     * @param  list<array{due_date: string, amount: string}>  $rows
     * @return list<array{number: int, due_date: string, amount: string}>
     */
    private function custom(array $rows, string $total): array
    {
        if ($rows === [] || count($rows) > self::MAX_COUNT) {
            throw new InvalidScheduleException('Give between 1 and '.self::MAX_COUNT.' instalments.');
        }

        $out = [];
        $sum = '0.00';
        $previous = null;
        foreach ($rows as $index => $row) {
            $number = $index + 1;
            try {
                $date = $this->date($row['due_date']);
            } catch (InvalidScheduleException) {
                throw new InvalidScheduleException("Row {$number}: the date must be a real date in YYYY-MM-DD format.");
            }
            if ($previous !== null && ! $date->gt($previous)) {
                throw new InvalidScheduleException("Row {$number}: the date must be after the row before it.");
            }
            try {
                $amount = $this->amount($row['amount'], 'amount');
            } catch (InvalidScheduleException) {
                throw new InvalidScheduleException("Row {$number}: the amount is not valid.");
            }
            if (! Money::isPositive($amount)) {
                throw new InvalidScheduleException("Row {$number}: the amount must be more than zero.");
            }

            $out[] = ['number' => $number, 'due_date' => $date->format('Y-m-d'), 'amount' => $amount];
            $sum = Money::add($sum, $amount, 2);
            $previous = $date;
        }

        if (Money::cmp($sum, $total) !== 0) {
            throw new InvalidScheduleException("The instalments add up to {$sum}, but the total is {$total}.");
        }

        return $out;
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
