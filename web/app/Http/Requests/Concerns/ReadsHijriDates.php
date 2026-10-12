<?php

namespace App\Http\Requests\Concerns;

use App\Support\Hijri;
use Closure;

/**
 * A date field's Hijri twin (`<field>_hijri`, Win Plan CT26): when a person typed a Hijri day, it replaces the field
 * with its Gregorian day before validation, so what is stored is always Gregorian. A Hijri day that does not exist is
 * an error on the twin, never a silent fallback to the Gregorian field.
 */
trait ReadsHijriDates
{
    /**
     * What to merge before validation: each field whose twin holds a real Hijri day, as that Gregorian day.
     *
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    protected function gregorianFromHijri(array $fields): array
    {
        $merge = [];
        foreach ($fields as $field) {
            $typed = $this->input($field.'_hijri');
            $day = is_scalar($typed) ? Hijri::parse((string) $typed) : null;
            if ($day !== null) {
                $merge[$field] = $day->format('Y-m-d');
            }
        }

        return $merge;
    }

    /**
     * The twins' own rule: empty, or a Hijri day the calendar has.
     *
     * @param  list<string>  $fields
     * @return array<string, list<mixed>>
     */
    protected function hijriRules(array $fields): array
    {
        $rule = function (string $attribute, mixed $value, Closure $fail): void {
            if (is_scalar($value) && trim((string) $value) !== '' && Hijri::parse((string) $value) === null) {
                $fail(__('Write the Hijri date as day/month/year, for example 13/08/1447.'));
            }
        };

        return array_fill_keys(array_map(fn (string $field) => $field.'_hijri', $fields), ['nullable', $rule]);
    }
}
