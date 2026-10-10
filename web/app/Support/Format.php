<?php

namespace App\Support;

use Illuminate\Support\Number;

/** Presentation of numbers for people. Storage and arithmetic never go through here. */
final class Format
{
    /**
     * An amount of money in the reader's language, with Western (0-9) digits in every language: the amounts people
     * type, the figures in contracts and the numbers on receipts must all look the same.
     */
    public static function money(string $amount, string $currency, int $decimals = 2): string
    {
        return (string) Number::currency((float) $amount, $currency, app()->getLocale().'-u-nu-latn', $decimals);
    }

    /** An amount without its currency, for a column whose heading names it: 1,090.00, with Western digits in every language. */
    public static function amount(string $amount, int $decimals = 2): string
    {
        return (string) Number::format((float) $amount, $decimals, locale: app()->getLocale().'-u-nu-latn');
    }
}
