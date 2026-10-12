<?php

namespace App\Support;

/**
 * An amount in words for a promissory note (Win Plan K5): "ten thousand eight hundred Saudi Riyal", "عشرة آلاف
 * وثمانمائة ريال سعودي", with the hundredths as a fraction when there are any. English, French and Spanish numbers come
 * from ICU; Arabic is written by the rules below, because ICU writes the hundreds as two words ("ثمانية مائة") where a
 * cheque or a note says "ثمانمائة". Currency names come from ICU in every language.
 */
final class AmountInWords
{
    private const ONES = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];

    private const TENS = ['', 'عشرة', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];

    private const HUNDREDS = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];

    /** Each power of a thousand: [one of it, two of it, three to ten of it, eleven or more of it]. */
    private const SCALES = [
        1 => ['ألف', 'ألفان', 'آلاف', 'ألف'],
        2 => ['مليون', 'مليونان', 'ملايين', 'مليون'],
        3 => ['مليار', 'ملياران', 'مليارات', 'مليار'],
    ];

    public static function say(string $amount, string $currency, string $language): string
    {
        $amount = Money::round($amount, 2);
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-')), 2, '00');

        $words = $language === 'ar'
            ? self::arabic((int) $whole)
            : (string) (new \NumberFormatter($language, \NumberFormatter::SPELLOUT))->format((int) $whole);
        $sentence = trim($words.' '.self::currencyName($currency, $language));

        return (int) $fraction === 0 ? $sentence : __(':whole and :fraction/100', ['whole' => $sentence, 'fraction' => str_pad($fraction, 2, '0')], $language);
    }

    /** A whole number in Arabic words, the way a cheque writes it: groups of three joined by "و", largest first. */
    private static function arabic(int $number): string
    {
        if ($number === 0) {
            return 'صفر';
        }

        $parts = [];
        foreach ([3, 2, 1, 0] as $power) {
            $group = intdiv($number, 1000 ** $power) % 1000;
            if ($group === 0) {
                continue;
            }

            if ($power === 0) {
                $parts[] = self::arabicBelowThousand($group);

                continue;
            }

            [$one, $two, $few, $many] = self::SCALES[$power];
            $parts[] = match (true) {
                $group === 1 => $one,
                $group === 2 => $two,
                $group <= 10 => self::arabicBelowThousand($group).' '.$few,
                default => self::arabicBelowThousand($group).' '.$many,
            };
        }

        return implode(' و', $parts);
    }

    private static function arabicBelowThousand(int $number): string
    {
        $parts = [];
        if ($number >= 100) {
            $parts[] = self::HUNDREDS[intdiv($number, 100)];
            $number %= 100;
        }

        $parts[] = match (true) {
            $number === 0 => null,
            $number < 10 => self::ONES[$number],
            $number === 10 => 'عشرة',
            $number === 11 => 'أحد عشر',
            $number === 12 => 'اثنا عشر',
            $number < 20 => self::ONES[$number - 10].' عشر',
            $number % 10 === 0 => self::TENS[intdiv($number, 10)],
            default => self::ONES[$number % 10].' و'.self::TENS[intdiv($number, 10)],
        };

        return implode(' و', array_filter($parts, fn (?string $part) => $part !== null));
    }

    /** "Saudi Riyal", "ريال سعودي"; the code itself when ICU does not know it. */
    private static function currencyName(string $currency, string $language): string
    {
        $bundle = \ResourceBundle::create($language, 'ICUDATA-curr');
        $entry = $bundle?->get('Currencies')?->get(strtoupper($currency));
        $name = $entry instanceof \ResourceBundle ? $entry->get(1) : null;

        return is_string($name) && $name !== '' ? $name : strtoupper($currency);
    }
}
