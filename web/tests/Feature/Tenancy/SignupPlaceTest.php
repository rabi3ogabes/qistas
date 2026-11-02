<?php

use App\Support\SignupPlace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/*
| A new workspace starts in the place the visitor is in, found without asking: the CDN's country header first (only the
| one the site configured, never one a browser could send itself), then the region of the browser's language, then the
| product's default. The time zone follows the same spirit: a real name if the client knows it, else the phone's offset
| from UTC read against the country's zones, else the country's usual zone.
*/

/**
 * A sign-up request as the CDN and the browser would send it. Symfony gives every request it builds an Accept-Language of
 * en-US unless told otherwise, so "no language" has to be said.
 */
function placeRequest(array $headers = []): Request
{
    $server = ['HTTP_ACCEPT_LANGUAGE' => ''];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create('/register', 'POST', server: $server);
}

describe('the country', function () {
    beforeEach(fn () => config(['qistas.geo_header' => 'CF-IPCountry']));

    it('is the CDN’s header before the browser’s language', function () {
        $place = SignupPlace::detect(placeRequest(['CF-IPCountry' => 'QA', 'Accept-Language' => 'ar-SA,ar;q=0.9']));

        expect($place)->toBe(['country' => 'QA', 'source' => 'header']);
    });

    it('reads a header in lower case or with spaces around it', function (string $sent) {
        expect(SignupPlace::detect(placeRequest(['CF-IPCountry' => $sent])))->toBe(['country' => 'AE', 'source' => 'header']);
    })->with(['lower case' => 'ae', 'padded' => ' AE ', 'mixed' => 'aE']);

    it('does not take what the CDN uses for "unknown" as a country, and tries the language next', function (string $code) {
        $place = SignupPlace::detect(placeRequest(['CF-IPCountry' => $code, 'Accept-Language' => 'ar-KW,ar;q=0.9']));

        expect($place)->toBe(['country' => 'KW', 'source' => 'language']);
    })->with(['unknown' => 'XX', 'Tor' => 'T1', 'anonymous proxy' => 'A1', 'satellite' => 'A2', 'other' => 'O1']);

    it('ignores the header when the site names none, since a browser could send it itself', function () {
        foreach ([null, ''] as $unset) {
            config(['qistas.geo_header' => $unset]);

            expect(SignupPlace::detect(placeRequest(['CF-IPCountry' => 'QA'])))->toBe(['country' => config('qistas.default_country'), 'source' => 'default']);
        }
    });

    it('ignores a header other than the configured one', function () {
        config(['qistas.geo_header' => 'X-Vercel-IP-Country']);

        expect(SignupPlace::detect(placeRequest(['CF-IPCountry' => 'QA'])))->toBe(['country' => config('qistas.default_country'), 'source' => 'default']);
    });

    it('is the region of the browser’s language when there is no header', function () {
        expect(SignupPlace::detect(placeRequest(['Accept-Language' => 'ar-QA,ar;q=0.9'])))->toBe(['country' => 'QA', 'source' => 'language']);
    });

    it('skips a language with no region and takes the first one that has one', function () {
        expect(SignupPlace::detect(placeRequest(['Accept-Language' => 'ar,en-GB;q=0.8'])))->toBe(['country' => 'GB', 'source' => 'language']);
    });

    it('is the configured default when nothing says', function () {
        expect(SignupPlace::detect(placeRequest()))->toBe(['country' => 'SA', 'source' => 'default']);

        config(['qistas.default_country' => 'AE']);
        expect(SignupPlace::detect(placeRequest(['Accept-Language' => 'ar'])))->toBe(['country' => 'AE', 'source' => 'default']);
    });

    it('keeps a country the product has no currency for, as it is', function () {
        expect(SignupPlace::detect(placeRequest(['CF-IPCountry' => 'PS'])))->toBe(['country' => 'PS', 'source' => 'header'])
            ->and(config('qistas.countries.PS'))->toBeNull();
    });

    it('has a default that is one of the countries the product knows', function () {
        expect(config('qistas.countries'))->toHaveKey(config('qistas.default_country'));
    });
});

describe('the time zone', function () {
    it('is the real zone a client sends, whatever the country', function () {
        expect(SignupPlace::timezone('SA', 'Asia/Qatar', null))->toBe('Asia/Qatar')
            ->and(SignupPlace::timezone('SA', 'Asia/Qatar', 240))->toBe('Asia/Qatar');
    });

    it('ignores a name that is not a real zone', function (string $name) {
        expect(SignupPlace::timezone('SA', $name, null))->toBe('Asia/Riyadh');
    })->with(['made up' => 'Mars/Olympus', 'wrong case' => 'asia/qatar', 'empty' => '', 'an offset' => '+03:00', 'markup' => '<b>Asia/Qatar</b>']);

    it('reads the phone’s offset against the zones of the country', function (string $country, int $offset, string $zone) {
        expect(SignupPlace::timezone($country, null, $offset))->toBe($zone);
    })->with([
        'Saudi Arabia' => ['SA', 180, 'Asia/Riyadh'],
        'the Emirates' => ['AE', 240, 'Asia/Dubai'],
        'western Australia, not the table’s Sydney' => ['AU', 480, 'Australia/Perth'],
    ]);

    it('follows daylight saving: the same country, two offsets, depending on the date', function () {
        $january = CarbonImmutable::parse('2026-01-15 12:00:00', 'UTC');
        $july = CarbonImmutable::parse('2026-07-15 12:00:00', 'UTC');
        $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US');

        $winter = SignupPlace::timezone('US', null, -300, $january);
        $summer = SignupPlace::timezone('US', null, -240, $july);

        expect($zones)->toContain($winter)->toContain($summer)
            ->and((new DateTimeZone($winter))->getOffset($january))->toBe(-300 * 60)
            ->and((new DateTimeZone($summer))->getOffset($july))->toBe(-240 * 60);
    });

    it('falls back to the country’s usual zone when no zone of the country has that offset', function () {
        $january = CarbonImmutable::parse('2026-01-15 12:00:00', 'UTC');

        expect(SignupPlace::timezone('SA', null, 540))->toBe('Asia/Riyadh')
            ->and(SignupPlace::timezone('US', null, 600, $january))->toBe('America/New_York');
    });

    it('is the country’s usual zone when the client says nothing', function () {
        expect(SignupPlace::timezone('QA', null, null))->toBe('Asia/Qatar')
            ->and(SignupPlace::timezone('qa', null, null))->toBe('Asia/Qatar');
    });

    it('is UTC for a country with no zone in the table', function (string $country) {
        expect(SignupPlace::timezone($country, null, null))->toBe('UTC')
            ->and(SignupPlace::timezone($country, null, 180))->toBe('UTC');
    })->with(['unknown' => 'ZZ', 'not a code' => 'Saudi', 'empty' => '']);

    it('still reads an offset for a country the table lacks, from the zones that country has', function () {
        $zone = SignupPlace::timezone('PS', null, 120, CarbonImmutable::parse('2026-01-15 12:00:00', 'UTC'));

        expect($zone)->toBeIn(DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'PS'));
    });
});
