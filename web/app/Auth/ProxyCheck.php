<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\SignupPlace;
use App\Theme\Visitor;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * Whether a sign-in seems to come through a proxy, from free signals only, and the country of the connection. The
 * screens say "proxy detected", "possible" or "not detected" and never "certain": every signal can be wrong. A paid
 * IP-intelligence service can replace this behind the same method.
 *
 * Signals, in the order they are listed:
 *  - tor: the CDN's own header says T1 (Tor). Only believed when a CDN header is configured (qistas.geo_header): a
 *    header a browser could send itself decides nothing.
 *  - via_header: the request carries a Via header, which a proxy adds to announce itself.
 *  - forwarded_chain: X-Forwarded-For lists more addresses than qistas.proxy_chain_allowed (the hops a normal visit
 *    already has through the CDN and the host's own proxy; the owner checks the number against a normal sign-in).
 *  - timezone_country_mismatch: the country of the connection is known, and the time zone the browser reports is not
 *    one of that country's zones (or, for a phone that reports only its offset from UTC, no zone of that country has
 *    that offset now). A weak sign, so on its own it is only "possible".
 *
 * "detected" when tor, via_header or forwarded_chain is present; "possible" when only the mismatch is; else "not
 * detected". The country comes only from the configured CDN header, never from the person's workspace or language.
 */
final class ProxyCheck
{
    /** The furthest a real time zone is from UTC, in minutes (UTC+14 and UTC-12 are the extremes; this leaves room). */
    private const OFFSET_LIMIT_MINUTES = 840;

    /**
     * @param  string|null  $reportedZone  the IANA zone name the browser reported, if any (a zone that names no place, such as UTC, says nothing)
     * @param  int|null  $reportedUtcOffset  the phone's offset from UTC in minutes, east positive; read only when no zone name was reported
     * @return array{verdict: 'not_detected'|'possible'|'detected', signals: list<string>, ip_country: ?string}
     */
    public static function inspect(Request $request, ?string $reportedZone, ?int $reportedUtcOffset): array
    {
        $header = config('qistas.geo_header');
        $header = is_string($header) && trim($header) !== '' ? trim($header) : null;

        $rawCountry = $header === null ? null : $request->header($header);
        $country = $header === null ? null : Visitor::code($rawCountry);

        $signals = [];

        if ($header !== null && is_string($rawCountry) && strtoupper(trim($rawCountry)) === 'T1') {
            $signals[] = 'tor';
        }

        if (trim((string) $request->header('Via')) !== '') {
            $signals[] = 'via_header';
        }

        if (self::forwardedAddresses($request) > self::chainAllowed()) {
            $signals[] = 'forwarded_chain';
        }

        $strong = $signals !== [];

        if ($country !== null && self::zoneDisagrees($country, $reportedZone, $reportedUtcOffset)) {
            $signals[] = 'timezone_country_mismatch';
        }

        return [
            'verdict' => $strong ? 'detected' : ($signals !== [] ? 'possible' : 'not_detected'),
            'signals' => $signals,
            'ip_country' => $country,
        ];
    }

    /** How many addresses X-Forwarded-For lists, over all its header lines; empty entries do not count. */
    private static function forwardedAddresses(Request $request): int
    {
        $count = 0;

        foreach ($request->headers->all('x-forwarded-for') as $line) {
            foreach (explode(',', (string) $line) as $address) {
                if (trim($address) !== '') {
                    $count++;
                }
            }
        }

        return $count;
    }

    private static function chainAllowed(): int
    {
        $allowed = config('qistas.proxy_chain_allowed');

        return is_numeric($allowed) ? max(0, (int) $allowed) : 2;
    }

    /**
     * Is the time zone (or, failing a name, the offset) one the country does not have? False whenever there is nothing
     * to compare: no zone and no offset, a name that is no zone or names no place, an offset no place has, or a country
     * PHP lists no zones for.
     */
    private static function zoneDisagrees(string $country, ?string $zone, ?int $offsetMinutes): bool
    {
        // A name was reported: it alone decides, even when it is useless (a browser that hides its zone says UTC and a
        // "UTC+0" offset with it; that is not a mismatch for everyone who does so).
        if ($zone !== null && $zone !== '') {
            return SignupPlace::zoneCountry($zone) !== null && ! self::countryHasZone($country, $zone);
        }

        if ($offsetMinutes === null || abs($offsetMinutes) > self::OFFSET_LIMIT_MINUTES) {
            return false;
        }

        $zones = self::zonesOf($country);

        if ($zones === []) {
            return false;
        }

        $now = now();

        foreach ($zones as $name) {
            if ((new DateTimeZone($name))->getOffset($now) === $offsetMinutes * 60) {
                return false;
            }
        }

        return true;
    }

    private static function countryHasZone(string $country, string $zone): bool
    {
        $zones = self::zonesOf($country);

        // A country PHP lists no zones for gives nothing to compare with.
        return $zones === [] || in_array($zone, $zones, true);
    }

    /**
     * The zones PHP lists for a country. The code is checked first: PHP throws for anything but two letters, and the
     * code is whatever a header carried.
     *
     * @return list<string>
     */
    private static function zonesOf(string $country): array
    {
        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            return [];
        }

        return DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country);
    }
}
