<?php

namespace App\Site;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * The public pages (Sprint 2, decision D10). Each answers at its plain address, where the reader's own choice sets the
 * language (?lang=, then what was remembered, then the browser), and at each language's path (/ar/pricing), which always
 * reads in that language. Search engines are given the language paths, with the plain address as the x-default. A
 * page's own links stay in the path it was opened in.
 */
final class SitePages
{
    /** Route name => plain path, for every page that also answers in the language paths. */
    public const PAGES = [
        'home' => '/',
        'calculator' => '/installment-calculator',
        'pricing' => '/pricing',
        'switch.notebook' => '/switch/notebook',
        'switch.excel' => '/switch/excel',
        'switch.app' => '/switch/another-app',
        'status' => '/status',
        'terms' => '/terms',
        'privacy' => '/privacy',
        'account.delete.info' => '/account/delete',
    ];

    /** The pages listed for search engines; the status page changes by the minute and is left out. */
    public const LISTED = ['home', 'calculator', 'pricing', 'switch.notebook', 'switch.excel', 'switch.app', 'terms', 'privacy'];

    /** The language-path routes are these names with this prefix: `localized.pricing`. */
    public const PREFIX = 'localized.';

    /** Where SetLocale leaves the language a request's path named. */
    public const ATTRIBUTE = 'qistas.path_locale';

    /** The language this request's path names ('ar' for /ar/pricing), or null on a plain address. */
    public static function pathLocale(): ?string
    {
        $locale = request()->attributes->get(self::ATTRIBUTE);

        return is_string($locale) ? $locale : null;
    }

    /** The plain name of the public page being shown ('pricing' for /pricing and /ar/pricing), or null. */
    public static function current(): ?string
    {
        $name = Route::currentRouteName();
        if ($name === null) {
            return null;
        }
        $name = str_starts_with($name, self::PREFIX) ? substr($name, strlen(self::PREFIX)) : $name;

        return array_key_exists($name, self::PAGES) ? $name : null;
    }

    /**
     * The address of a public page: in [$locale]'s path when one is given, else in the path this request came in on,
     * else the plain address.
     *
     * @param  array<string, mixed>  $query
     */
    public static function url(string $name, array $query = [], ?string $locale = null): string
    {
        return self::address(self::path($name, $locale ?? self::pathLocale()), $query);
    }

    /** The plain address of a public page, which reads in the reader's own language: the x-default. */
    public static function plain(string $name): string
    {
        return self::address(self::path($name, null), []);
    }

    /** The page being shown, in [$locale]'s path, keeping the rest of its address; null when it is not a public page. */
    public static function here(string $locale): ?string
    {
        $name = self::current();

        return $name === null ? null : self::url($name, Arr::except(request()->query(), ['lang']), $locale);
    }

    /** '/pricing' in [$locale]'s path: '/ar/pricing' ('/ar' for the home page), or as it is with no language. */
    private static function path(string $name, ?string $locale): string
    {
        $path = self::PAGES[$name] ?? throw new InvalidArgumentException("Not a public page: {$name}");

        return $locale === null ? $path : '/'.$locale.($path === '/' ? '' : $path);
    }

    /** @param  array<string, mixed>  $query */
    private static function address(string $path, array $query): string
    {
        $url = url($path);

        return $query === [] ? $url : $url.'?'.Arr::query($query);
    }
}
