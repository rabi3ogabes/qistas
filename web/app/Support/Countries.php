<?php

namespace App\Support;

use Collator;
use Locale;

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
}
