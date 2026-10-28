<?php

namespace App\Domain\PayPattern;

use Carbon\CarbonInterface;

/**
 * Works out the day of the month a customer really pays on (daily-habit wave A, H6), from the dates of their payments.
 * Pure arithmetic over dates: nothing is read or written, and only the date part of each is used, as it falls on the
 * calendar it was given (the caller hands over the workspace's own dates, not UTC ones).
 *
 *   1. The first payment of each calendar month stands for that month, so several payments in a month count once.
 *   2. The last 12 months that have a payment are used. Fewer than 3 months say nothing.
 *   3. Each day is min(day, 28): the 28th to the 31st all mean "the end of the month".
 *   4. day = the median of those days; for an even count, the lower of the two middle ones.
 *   5. spread = Q3 less Q1 by the nearest-rank method: with the days sorted and n of them, the value at 1-based rank
 *      ceil(p x n) is the p quantile (p = 1/4 for Q1, 3/4 for Q3; the median is p = 1/2).
 *   6. within = the percent of months whose day is within 3 of the median, rounded half up to a whole number.
 *   7. High when there are 4+ months, the spread is 3 or less and within is 75 or more; medium when the spread is 5 or
 *      less and within is 60 or more; otherwise there is nothing to say.
 *
 * A median and a quartile range, not a mean and a deviation, so one late month does not move the answer.
 */
final class PayPatternCalculator
{
    /** Older months say less about how a customer pays now than the latest year does. */
    private const MAX_MONTHS = 12;

    private const MIN_MONTHS = 3;

    /** A month is "on the payday" when its day is this close to it, or closer. */
    private const NEAR_DAYS = 3;

    /**
     * @param  list<CarbonInterface>  $paidOn  the local dates of a customer's instalment payments, in any order
     * @return ?PayPattern null when there is not enough to say
     */
    public static function calculate(array $paidOn): ?PayPattern
    {
        // The first payment of a month is its smallest day: all of them share the year and the month.
        $firstOfMonth = [];
        foreach ($paidOn as $date) {
            $month = $date->format('Y-m');
            $firstOfMonth[$month] = min($firstOfMonth[$month] ?? $date->day, $date->day);
        }

        ksort($firstOfMonth, SORT_STRING); // 'Y-m' sorts in calendar order
        $days = array_map(
            fn (int $day): int => min($day, PayPattern::END_OF_MONTH),
            array_values(array_slice($firstOfMonth, -self::MAX_MONTHS)),
        );

        $months = count($days);
        if ($months < self::MIN_MONTHS) {
            return null;
        }

        sort($days);
        $median = $days[self::rank(2, $months) - 1];
        $spread = $days[self::rank(3, $months) - 1] - $days[self::rank(1, $months) - 1];

        $near = count(array_filter($days, fn (int $day): bool => abs($day - $median) <= self::NEAR_DAYS));
        $within = intdiv(200 * $near + $months, 2 * $months); // 100 x near / months, rounded half up, without a float

        $confidence = match (true) {
            $months >= 4 && $spread <= 3 && $within >= 75 => PayPattern::HIGH,
            $spread <= 5 && $within >= 60 => PayPattern::MEDIUM, // three months at least: checked above
            default => null,
        };

        return $confidence === null ? null : new PayPattern($median, $spread, $months, $within, $confidence);
    }

    /** The 1-based nearest rank of the quantile $quarters / 4 among $count values: ceil($quarters x $count / 4). */
    private static function rank(int $quarters, int $count): int
    {
        return intdiv($quarters * $count + 3, 4);
    }
}
