<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Umm al-Qura calendar (Sprint 2 Task 2, Win Plan CT26), read from HijriTable, which is generated from ICU, so the
 * website, the PDFs and the app (which reads the same table) agree to the day whatever ICU a server has. Covers
 * HijriTable::FIRST_YEAR to LAST_YEAR (1937 to 2077); outside it there is no Hijri date and callers show the Gregorian
 * one alone. Stored dates are always Gregorian: this only shows them and reads what people type.
 */
final class Hijri
{
    /** @var list<int>|null days from the epoch to the start of each month, then to the end of the table */
    private static ?array $starts = null;

    /**
     * The Hijri day of a Gregorian one, or null outside the table.
     *
     * @return array{year: int, month: int, day: int}|null
     */
    public static function fromGregorian(CarbonInterface|string $date): ?array
    {
        $starts = self::starts();
        $days = self::daysFromEpoch($date);
        if ($days < 0 || $days >= $starts[count($starts) - 1]) {
            return null;
        }

        // The last month that starts on or before the day.
        $low = 0;
        $high = count($starts) - 2;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($starts[$mid] <= $days) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return ['year' => HijriTable::FIRST_YEAR + intdiv($low, 12), 'month' => $low % 12 + 1, 'day' => $days - $starts[$low] + 1];
    }

    /** The Gregorian day of a Hijri one; null for a day the month does not have, or outside the table. */
    public static function toGregorian(int $year, int $month, int $day): ?CarbonImmutable
    {
        $length = self::monthLength($year, $month);
        if ($length === null || $day < 1 || $day > $length) {
            return null;
        }

        $index = ($year - HijriTable::FIRST_YEAR) * 12 + $month - 1;

        return CarbonImmutable::parse(HijriTable::EPOCH, 'UTC')->addDays(self::starts()[$index] + $day - 1)->startOfDay();
    }

    /** 29 or 30; null outside the table. */
    public static function monthLength(int $year, int $month): ?int
    {
        if ($year < HijriTable::FIRST_YEAR || $year > HijriTable::LAST_YEAR || $month < 1 || $month > 12) {
            return null;
        }

        return HijriTable::YEARS[$year - HijriTable::FIRST_YEAR][$month - 1] === '1' ? 30 : 29;
    }

    /**
     * A Hijri date as people type it, year first (1447-08-13) or day first (13/08/1447), with any of - / . or spaces
     * between, in Western, Arabic or Persian digits. Null when it is not a Hijri day the table has.
     */
    public static function parse(string $typed): ?CarbonImmutable
    {
        $typed = trim(Digits::toAscii($typed));
        if (preg_match('/^(\d{4})[-\/. ](\d{1,2})[-\/. ](\d{1,2})$/', $typed, $m)) {
            return self::toGregorian((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[-\/. ](\d{1,2})[-\/. ](\d{4})$/', $typed, $m)) {
            return self::toGregorian((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    /** "13 Shaban 1447 AH" in the language that is set; null outside the table. */
    public static function format(CarbonInterface|string $date): ?string
    {
        $hijri = self::fromGregorian($date);
        if ($hijri === null) {
            return null;
        }

        return __(':day :month :year AH', ['day' => $hijri['day'], 'month' => self::monthName($hijri['month']), 'year' => $hijri['year']]);
    }

    /** The month's name in the language that is set. */
    public static function monthName(int $month): string
    {
        return match ($month) {
            1 => __('Muharram'),
            2 => __('Safar'),
            3 => __('Rabi al-Awwal'),
            4 => __('Rabi al-Akhir'),
            5 => __('Jumada al-Ula'),
            6 => __('Jumada al-Akhirah'),
            7 => __('Rajab'),
            8 => __('Shaban'),
            9 => __('Ramadan'),
            10 => __('Shawwal'),
            11 => __('Dhu al-Qadah'),
            default => __('Dhu al-Hijjah'),
        };
    }

    private static function daysFromEpoch(CarbonInterface|string $date): int
    {
        $day = is_string($date) ? substr($date, 0, 10) : $date->format('Y-m-d');
        $epoch = new \DateTimeImmutable(HijriTable::EPOCH.' 00:00:00', new \DateTimeZone('UTC'));
        $diff = $epoch->diff(new \DateTimeImmutable($day.' 00:00:00', new \DateTimeZone('UTC')));

        return $diff->invert === 1 ? -(int) $diff->days : (int) $diff->days;
    }

    /** @return list<int> */
    private static function starts(): array
    {
        if (self::$starts === null) {
            $starts = [0];
            foreach (HijriTable::YEARS as $year) {
                foreach (str_split($year) as $bit) {
                    $starts[] = $starts[count($starts) - 1] + ($bit === '1' ? 30 : 29);
                }
            }
            self::$starts = $starts;
        }

        return self::$starts;
    }
}
