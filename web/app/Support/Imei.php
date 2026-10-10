<?php

namespace App\Support;

/**
 * A phone's IMEI (Win Plan PP7): fifteen digits whose last one is a Luhn check digit, so a mistyped number is caught at
 * the counter rather than in a dispute.
 */
final class Imei
{
    /** Fifteen digits and nothing else: what is checked; any other serial is taken as typed. */
    public static function looksLikeOne(string $serial): bool
    {
        return preg_match('/^\d{15}$/', $serial) === 1;
    }

    public static function valid(string $imei): bool
    {
        if (! self::looksLikeOne($imei)) {
            return false;
        }

        $sum = 0;
        foreach (str_split(strrev($imei)) as $i => $digit) {
            $value = (int) $digit;
            if ($i % 2 === 1) {
                $value *= 2;
                if ($value > 9) {
                    $value -= 9;
                }
            }
            $sum += $value;
        }

        return $sum % 10 === 0;
    }
}
