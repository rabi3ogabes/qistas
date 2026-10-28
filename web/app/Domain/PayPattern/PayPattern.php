<?php

namespace App\Domain\PayPattern;

/**
 * What the calculator found: the day of the month a customer usually pays on and how sure it is. Internal to the
 * workspace; never shown to the customer.
 */
final readonly class PayPattern
{
    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    /** The 28th to the 31st all count as this day, so a short month never looks like a different payday. */
    public const END_OF_MONTH = 28;

    /**
     * @param  int  $day  the median day of the month, 1 to 28
     * @param  int  $spread  the interquartile range of the days, in days
     * @param  int  $months  how many months it was worked out from, 3 to 12
     * @param  int  $within  the percent of those months paid within 3 days of $day
     * @param  string  $confidence  self::HIGH or self::MEDIUM
     */
    public function __construct(
        public int $day,
        public int $spread,
        public int $months,
        public int $within,
        public string $confidence,
    ) {}

    /** Does this customer pay at the end of the month, whatever its length? */
    public function endOfMonth(): bool
    {
        return $this->day === self::END_OF_MONTH;
    }
}
