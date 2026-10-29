<?php

namespace App\Domain\PayPattern;

/**
 * The words a stored payday pattern is shown in (daily-habit wave A, H6), in the language of the request. The API's
 * `label` and `basis` and the chip on the customer's web page are the same sentences because they are written here, once.
 * The numbers sit in them as placeholders, so no wording depends on a count (Arabic needs no plural forms).
 */
final class PayPatternWords
{
    /** "Usually pays around day 27 of the month", or "Usually pays at the end of the month" for the 28th to the 31st. */
    public static function label(int $day): string
    {
        return $day === PayPattern::END_OF_MONTH
            ? __('Usually pays at the end of the month')
            : __('Usually pays around day :day of the month', ['day' => $day]);
    }

    /** How often it held: "Seen in 83% of recent months". */
    public static function basis(int $within): string
    {
        return __('Seen in :percent% of recent months', ['percent' => $within]);
    }
}
