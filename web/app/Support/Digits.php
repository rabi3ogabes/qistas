<?php

namespace App\Support;

final class Digits
{
    /** Arabic-Indic (U+0660..) and Extended Arabic-Indic, used for Persian and Urdu (U+06F0..), to ASCII. */
    private const MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.', // Arabic decimal separator
    ];

    /** Phone numbers, IDs and amounts are often typed on an Arabic or Urdu keyboard; store and search them as ASCII. */
    public static function toAscii(string $value): string
    {
        return strtr($value, self::MAP);
    }
}
