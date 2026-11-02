<?php

declare(strict_types=1);

namespace App\Support;

use App\Theme\Visitor;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * Where a new workspace starts, found without asking the person. The country, in order: the CDN's country header (only
 * the one named in qistas.geo_header: a header a browser could send itself is never trusted), the region of the
 * browser's language (ar-QA is Qatar), then the product's default. The time zone: the real zone a browser reports, else
 * the phone's offset from UTC read against the zones the country has, else the country's usual zone. The owner
 * confirms or changes all of it afterwards; nothing here is stored beyond the workspace's own columns.
 */
final class SignupPlace
{
    /** Used only when qistas.default_country is not a two-letter code (a mistake in the environment). */
    private const FALLBACK_COUNTRY = 'SA';

    /**
     * The first match wins. A code the product has no currency for (PS) is still returned as it is: what the
     * workspace gets for it is decided where the workspace is made.
     *
     * @return array{country: string, source: 'header'|'language'|'default'}
     */
    public static function detect(Request $request): array
    {
        $header = config('qistas.geo_header');

        if (is_string($header) && $header !== '') {
            $country = Visitor::code($request->header($header));

            if ($country !== null) {
                return ['country' => $country, 'source' => 'header'];
            }
        }

        foreach ($request->getLanguages() as $tag) {
            $country = Visitor::languageRegion($tag);

            if ($country !== null) {
                return ['country' => $country, 'source' => 'language'];
            }
        }

        return ['country' => self::defaultCountry(), 'source' => 'default'];
    }

    /** The country a workspace gets when nothing says: qistas.default_country (env QISTAS_DEFAULT_COUNTRY). */
    public static function defaultCountry(): string
    {
        return Visitor::code(config('qistas.default_country')) ?? self::FALLBACK_COUNTRY;
    }

    /**
     * The IANA zone a new workspace starts with: a real name the client sent; else, when the client reported its offset
     * from UTC in minutes (east is positive), the first zone of the country whose offset at [$now] is that (exact where
     * there is no daylight saving, like the Gulf; one of the right offset elsewhere); else the country's usual zone from
     * qistas.timezones; else UTC.
     */
    public static function timezone(string $country, ?string $iana, ?int $utcOffsetMinutes, ?CarbonInterface $now = null): string
    {
        if ($iana !== null && in_array($iana, DateTimeZone::listIdentifiers(), true)) {
            return $iana;
        }

        $country = strtoupper(trim($country));

        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            return 'UTC';
        }

        if ($utcOffsetMinutes !== null) {
            $at = $now ?? now();

            foreach (DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country) as $zone) {
                if ((new DateTimeZone($zone))->getOffset($at) === $utcOffsetMinutes * 60) {
                    return $zone;
                }
            }
        }

        /** @var array<string, string> $table */
        $table = config('qistas.timezones', []);

        return $table[$country] ?? 'UTC';
    }
}
