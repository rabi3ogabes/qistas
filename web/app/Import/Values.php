<?php

namespace App\Import;

use App\Support\Digits;
use Carbon\CarbonImmutable;

/**
 * A cell read as what it should be (Win Plan K9), the way shops write it: Arabic and Urdu digits, a currency before or
 * after the figures, thousands with commas or spaces, decimals with a point or a comma ("1 200,50" in French), dates as
 * 2026-03-15, 15/03/2026, 15-03-2026 or an Excel day number. Null when it cannot be read; never a guess in between.
 */
final class Values
{
    /** "1200.00", or null when the cell is not an amount in figures. */
    public static function money(string $cell): ?string
    {
        $text = strtr(Digits::toAscii(trim($cell)), ['٫' => '.', '٬' => ',', "\u{00A0}" => '', "\u{202F}" => '', ' ' => '']);
        $text = (string) preg_replace('/^[^\d\-]+|[^\d]+$/u', '', $text);

        if ($text === '' || preg_match('/^-?[\d.,]+$/', $text) !== 1) {
            return null;
        }

        if (str_contains($text, ',') && str_contains($text, '.')) {
            // The last of the two is the decimal point: 1,200.50 or 1.200,50.
            $text = strrpos($text, ',') > strrpos($text, '.') ? strtr($text, ['.' => '', ',' => '.']) : str_replace(',', '', $text);
        } elseif (str_contains($text, ',')) {
            $text = preg_match('/^-?\d{1,3}(,\d{3})+$/', $text) === 1 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3}){2,}$/', $text) === 1) {
            $text = str_replace('.', '', $text);
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            return null;
        }

        return number_format(round((float) $text, 2), 2, '.', '');
    }

    /** A whole number of instalments, or null. */
    public static function count(string $cell): ?int
    {
        $text = Digits::toAscii(trim($cell));
        if (preg_match('/^\d+(\.0+)?$/', $text) !== 1) {
            return null;
        }

        return (int) $text;
    }

    /** "2026-03-15", or null when the cell is not a day. Day before month, as the region writes it. */
    public static function date(string $cell): ?string
    {
        $text = Digits::toAscii(trim($cell));

        if (preg_match('/^\d{5}(\.\d+)?$/', $text) === 1 && (int) $text >= 20000 && (int) $text <= 80000) {
            // An Excel day number: days since 1899-12-30.
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $text)->format('Y-m-d');
        }

        $formats = ['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y', 'j-n-Y', 'j.n.Y', 'd/m/y', 'j/n/y'];
        foreach ($formats as $format) {
            $day = \DateTimeImmutable::createFromFormat('!'.$format, $text);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($day !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $day->format($format) === $text) {
                return $day->format('Y-m-d');
            }
        }

        return null;
    }
}
