<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

/** Runs something with the words, money and dates of one language, then puts the language back as it was. */
final class InLanguage
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(string $language, Closure $callback): mixed
    {
        $previous = App::getLocale();
        $previousDates = Carbon::getLocale();
        App::setLocale($language);
        Carbon::setLocale($language);

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
            Carbon::setLocale($previousDates);
        }
    }
}
