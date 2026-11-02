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
