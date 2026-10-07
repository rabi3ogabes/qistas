<?php

namespace App\Support;

use Illuminate\Http\Request;

/** The languages the product ships and how each is written. */
final class Locale
{
    /** Each language's name in its own script, in the order of config('qistas.locales'). */
    private const NAMES = [
        'en' => 'English',
        'ar' => 'العربية',
        'fr' => 'Français',
        'es' => 'Español',
        'ur' => 'اردو',
    ];

    /** @return array<string, string> code => native name, for the shipped languages */
    public static function options(): array
    {
        $options = [];
        foreach (config('qistas.locales') as $code) {
            $options[$code] = self::NAMES[$code] ?? $code;
        }

        return $options;
    }

    public static function current(): string
    {
        return app()->getLocale();
    }

    public static function isRtl(?string $locale = null): bool
    {
        return in_array($locale ?? self::current(), config('qistas.rtl_locales'), true);
    }

    /** The value for the `dir` attribute. */
    public static function direction(?string $locale = null): string
    {
        return self::isRtl($locale) ? 'rtl' : 'ltr';
    }

    /**
     * The currency a visitor most likely thinks in, from the country in their browser's language (ar-SA -> SAR),
     * so examples read naturally. USD when the browser does not say.
     */
    public static function currencyFor(Request $request): string
    {
        foreach ($request->getLanguages() as $tag) {
            $region = strtoupper((string) (explode('_', str_replace('-', '_', $tag))[1] ?? ''));

            if ($region !== '' && isset(config('qistas.countries')[$region])) {
                return config('qistas.countries')[$region];
            }
        }

        return config('qistas.currency_default');
    }

    /**
     * Every currency a workspace can start with, each once and in alphabetical order.
     *
     * @return list<string>
     */
    public static function currencies(): array
    {
        $currencies = array_values(array_unique(config('qistas.countries')));
        sort($currencies);

        return $currencies;
    }

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, config('qistas.locales'), true);
    }
}
