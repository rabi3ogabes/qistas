<?php

namespace App\Support;

use Collator;
use IntlDateFormatter;
use Locale;
use Throwable;

final class Countries
{
    /**
     * The countries a workspace can be opened in (those with a starting currency in config/qistas.php),
     * as code => name in $locale, sorted the way that language sorts.
     *
     * @return array<string, string>
     */
    public static function options(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        $names = [];
        foreach (array_keys(config('qistas.countries')) as $code) {
            $names[$code] = Locale::getDisplayRegion('-'.$code, $locale);
        }

        (new Collator($locale))->asort($names);

        return $names;
    }

    /**
     * The name of one country as people say it in $locale ("Qatar", "قطر"), for any two-letter country code, not only
     * the ones a workspace can be opened in. Null for anything else: no code, a value that is not two letters, or a code
     * ICU has no country for (it answers with the code itself or with "Unknown Region"), so a screen can leave it out.
     */
    public static function name(?string $code, ?string $locale = null): ?string
    {
        $code = strtoupper(trim((string) $code));
        if (preg_match('/\A[A-Z]{2}\z/', $code) !== 1) {
            return null;
        }

        $locale ??= app()->getLocale();
        $name = Locale::getDisplayRegion('-'.$code, $locale);

        return $name === '' || $name === $code || $name === Locale::getDisplayRegion('-ZZ', $locale) ? null : $name;
    }

    /**
     * The name of an IANA time zone as people say it in $locale: "Qatar Time", "توقيت قطر", never an offset or a code.
     * A zone the system does not know (or no ICU data) is shown as its own name with the underscores taken out.
     */
    public static function timeZoneName(string $zone, ?string $locale = null): string
    {
        try {
            $name = (new IntlDateFormatter($locale ?? app()->getLocale(), IntlDateFormatter::NONE, IntlDateFormatter::NONE, $zone, IntlDateFormatter::GREGORIAN, 'VVVV'))->format(0);
        } catch (Throwable) {
            $name = false;
        }

        return is_string($name) && $name !== '' ? $name : str_replace('_', ' ', $zone);
    }
}
