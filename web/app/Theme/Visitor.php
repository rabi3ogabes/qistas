<?php

declare(strict_types=1);

namespace App\Theme;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Where a visitor is, for an event meant for some countries. In order: the signed-in person's workspace country (the
 * business's own place, steady wherever they travel); the CDN's country header, but only the one named in
 * qistas.geo_header (a header a browser could send itself is never trusted); the region of the browser's language
 * (ar-SA is Saudi Arabia). Nothing about the visitor is stored.
 */
final class Visitor
{
    /** Codes a CDN uses for "unknown" or "anonymous network", which are not countries. */
    private const NOT_COUNTRIES = ['XX', 'T1', 'A1', 'A2', 'O1', 'EU', 'AP'];

    public static function country(Request $request): ?string
    {
        $user = $request->user() ?? auth('sanctum')->user();

        if ($user instanceof User) {
            $country = self::code($user->primaryTenant()?->country);

            if ($country !== null) {
                return $country;
            }
        }

        $header = config('qistas.geo_header');

        if (is_string($header) && $header !== '') {
            $country = self::code($request->header($header));

            if ($country !== null) {
                return $country;
            }
        }

        foreach ($request->getLanguages() as $tag) {
            $country = self::languageRegion($tag);

            if ($country !== null) {
                return $country;
            }
        }

        return null;
    }

    /** The country in a browser language tag (ar-SA is SA), or null when the tag has no region. */
    public static function languageRegion(string $tag): ?string
    {
        return self::code(explode('_', str_replace('-', '_', $tag))[1] ?? null);
    }

    /** A two-letter country code in capitals, from whatever a header or tag carried; null for anything else, and for the codes a CDN uses for "not a country". */
    public static function code(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $code = strtoupper(trim($value));

        return preg_match('/\A[A-Z]{2}\z/', $code) === 1 && ! in_array($code, self::NOT_COUNTRIES, true) ? $code : null;
    }
}
