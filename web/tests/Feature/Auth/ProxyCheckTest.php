<?php

use App\Auth\ProxyCheck;
use Illuminate\Http\Request;

/**
 * A sign-in request with these headers. Each value is set once, except a list, which adds one header line per item
 * (a proxy may send X-Forwarded-For more than once).
 *
 * @param  array<string, string|list<string>>  $headers
 */
function proxyCheckRequest(array $headers = []): Request
{
    $request = Request::create('/login', 'POST');

    foreach ($headers as $name => $value) {
        foreach ((array) $value as $line) {
            $request->headers->set($name, $line, false);
        }
    }

    return $request;
}

beforeEach(function () {
    // The live site's CDN header; the default of the product is that none is configured.
    config(['qistas.geo_header' => 'CF-IPCountry', 'qistas.proxy_chain_allowed' => 2]);
});

describe('a plain request', function () {
    it('shows nothing: not detected, no signals, no country', function () {
        expect(ProxyCheck::inspect(proxyCheckRequest(), null, null))->toBe([
            'verdict' => 'not_detected', 'signals' => [], 'ip_country' => null,
        ]);
    });

    it('is also not detected with the usual country header and a time zone that fits it', function () {
        $result = ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'SA']), 'Asia/Riyadh', null);

        expect($result)->toBe(['verdict' => 'not_detected', 'signals' => [], 'ip_country' => 'SA']);
    });
});

describe('the country of the connection', function () {
    it('comes from the configured CDN header, in capitals', function () {
        expect(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => ' qa ']), null, null)['ip_country'])->toBe('QA');
    });

    it('is nothing for the codes a CDN uses for "not a country"', function (string $code) {
        expect(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => $code]), null, null)['ip_country'])->toBeNull();
    })->with(['unknown' => 'XX', 'Tor' => 'T1', 'anonymous' => 'A1', 'satellite' => 'A2', 'other' => 'O1', 'nonsense' => 'Saudi', 'empty' => '']);

    it('is never read from a header when none is configured', function (mixed $configured) {
        config(['qistas.geo_header' => $configured]);

        $request = proxyCheckRequest(['CF-IPCountry' => 'SA', 'X-Vercel-IP-Country' => 'SA', 'CF-Connecting-Country' => 'SA']);

        expect(ProxyCheck::inspect($request, null, null)['ip_country'])->toBeNull();
    })->with(['not set' => null, 'empty' => '', 'blank' => '   ']);

    it('is never the country of the signed-in person\'s workspace', function () {
        [$user] = makeAccount(tenant: ['country' => 'QA']);
        $request = proxyCheckRequest();
        $request->setUserResolver(fn () => $user);

        expect(ProxyCheck::inspect($request, null, null)['ip_country'])->toBeNull();
    });

    it('is never the region of the browser\'s language', function () {
        $request = proxyCheckRequest(['Accept-Language' => 'ar-SA,ar;q=0.9']);

        expect(ProxyCheck::inspect($request, null, null)['ip_country'])->toBeNull();
    });
});

describe('Tor', function () {
    it('is the CDN saying T1, and alone it is a detected proxy', function (string $header) {
        $result = ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => $header]), null, null);

        expect($result)->toBe(['verdict' => 'detected', 'signals' => ['tor'], 'ip_country' => null]);
    })->with(['capitals' => 'T1', 'lower case' => 't1', 'with spaces' => '  T1 ']);

    it('is nothing when no CDN header is configured, whatever a browser sends', function (mixed $configured) {
        config(['qistas.geo_header' => $configured]);

        expect(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'T1']), null, null))
            ->toBe(['verdict' => 'not_detected', 'signals' => [], 'ip_country' => null]);
    })->with(['not set' => null, 'empty' => '']);

    it('is nothing for any other code, and nothing when the configured header is a different one', function () {
        expect(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'SA']), null, null)['signals'])->toBe([]);

        config(['qistas.geo_header' => 'X-Vercel-IP-Country']);

        expect(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'T1']), null, null)['signals'])->toBe([]);
    });
});

describe('a Via header', function () {
    it('shows a proxy that announces itself, and alone it is detected', function () {
        $result = ProxyCheck::inspect(proxyCheckRequest(['Via' => '1.1 proxy.example.net']), null, null);

        expect($result)->toBe(['verdict' => 'detected', 'signals' => ['via_header'], 'ip_country' => null]);
    });

    it('is nothing when empty', function (string $value) {
        expect(ProxyCheck::inspect(proxyCheckRequest(['Via' => $value]), null, null)['signals'])->toBe([]);
    })->with(['empty' => '', 'blank' => '   ']);
});

describe('a chain of forwarding hops', function () {
    it('is nothing at the allowed length', function () {
        $request = proxyCheckRequest(['X-Forwarded-For' => '203.0.113.9, 198.51.100.7']);

        expect(ProxyCheck::inspect($request, null, null))->toBe(['verdict' => 'not_detected', 'signals' => [], 'ip_country' => null]);
    });

    it('is forwarded_chain with one address more, and alone it is detected', function () {
        $request = proxyCheckRequest(['X-Forwarded-For' => '203.0.113.9, 198.51.100.7, 192.0.2.44']);

        expect(ProxyCheck::inspect($request, null, null))->toBe(['verdict' => 'detected', 'signals' => ['forwarded_chain'], 'ip_country' => null]);
    });

    it('follows the allowed length that is configured', function () {
        config(['qistas.proxy_chain_allowed' => 1]);

        expect(ProxyCheck::inspect(proxyCheckRequest(['X-Forwarded-For' => '203.0.113.9']), null, null)['signals'])->toBe([])
            ->and(ProxyCheck::inspect(proxyCheckRequest(['X-Forwarded-For' => '203.0.113.9, 198.51.100.7']), null, null)['signals'])->toBe(['forwarded_chain']);

        config(['qistas.proxy_chain_allowed' => 4]);

        expect(ProxyCheck::inspect(proxyCheckRequest(['X-Forwarded-For' => '1.1.1.1, 2.2.2.2, 3.3.3.3, 4.4.4.4']), null, null)['signals'])->toBe([]);
    });

    it('counts addresses, not commas or spaces', function () {
        $request = proxyCheckRequest(['X-Forwarded-For' => ' 203.0.113.9 ,, 198.51.100.7 , ,']);

        expect(ProxyCheck::inspect($request, null, null)['signals'])->toBe([]);
    });

    it('counts the addresses of every header line', function () {
        $request = proxyCheckRequest(['X-Forwarded-For' => ['203.0.113.9, 198.51.100.7', '192.0.2.44']]);

        expect(ProxyCheck::inspect($request, null, null)['signals'])->toBe(['forwarded_chain']);
    });

    it('is nothing without the header', function () {
        expect(ProxyCheck::inspect(proxyCheckRequest(), null, null)['signals'])->toBe([]);
    });
});

describe('the time zone against the country', function () {
    it('is a possible proxy, never more, when the zone is not one of the country\'s', function () {
        $result = ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'SA']), 'Asia/Dubai', null);

        expect($result)->toBe(['verdict' => 'possible', 'signals' => ['timezone_country_mismatch'], 'ip_country' => 'SA']);
    });

    it('is nothing for one of the country\'s own zones, including a country with several', function () {
        expect(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'SA']), 'Asia/Riyadh', null)['signals'])->toBe([])
            ->and(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'US']), 'America/Los_Angeles', null)['signals'])->toBe([])
            ->and(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'US']), 'Pacific/Honolulu', null)['signals'])->toBe([]);
    });

    it('needs a known country: without one there is nothing to compare', function (array $headers) {
        $result = ProxyCheck::inspect(proxyCheckRequest($headers), 'Asia/Dubai', null);

        expect($result['signals'])->toBe([])->and($result['ip_country'])->toBeNull();
    })->with([
        'no header' => [[]],
        'unknown country' => [['CF-IPCountry' => 'XX']],
        'a nonsense value' => [['CF-IPCountry' => 'Saudi Arabia']],
    ]);

    it('needs a known country also for an offset', function () {
        expect(ProxyCheck::inspect(proxyCheckRequest(), null, 240)['signals'])->toBe([]);
    });

    it('says nothing for a zone that names no place, or one PHP does not know', function (string $zone) {
        $result = ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'SA']), $zone, null);

        expect($result)->toBe(['verdict' => 'not_detected', 'signals' => [], 'ip_country' => 'SA']);
    })->with(['UTC' => 'UTC', 'GMT' => 'GMT', 'Etc' => 'Etc/GMT-3', 'unknown' => 'Mars/Olympus_Mons', 'empty' => '', 'junk' => "../etc/passwd\0"]);

    it('says nothing, and does not fail, for a country PHP has no zones for', function () {
        // ZZ is two capital letters, so the header is taken as it is; PHP has no zone for it.
        $result = ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'ZZ']), 'Asia/Riyadh', null);

        expect($result)->toBe(['verdict' => 'not_detected', 'signals' => [], 'ip_country' => 'ZZ']);

        expect(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'ZZ']), null, 180)['signals'])->toBe([]);
    });
});

describe('the offset from UTC against the country, when only an offset is reported', function () {
    it('is nothing when a zone of the country has that offset now', function () {
        expect(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'SA']), null, 180)['signals'])->toBe([])
            ->and(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'AE']), null, 240)['signals'])->toBe([]);
    });

    it('is a possible proxy when no zone of the country has it', function (int $offset) {
        $result = ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'SA']), null, $offset);

        expect($result)->toBe(['verdict' => 'possible', 'signals' => ['timezone_country_mismatch'], 'ip_country' => 'SA']);
    })->with(['an hour east' => 240, 'a way off' => -300, 'UTC itself' => 0]);

    it('reads the offset as it is now, so daylight saving counts', function () {
        $us = proxyCheckRequest(['CF-IPCountry' => 'US']);

        // US zones: -300 is New York in winter; -240 is New York in summer and no zone in winter.
        $this->travelTo('2026-01-15 12:00:00');
        expect(ProxyCheck::inspect($us, null, -300)['signals'])->toBe([])
            ->and(ProxyCheck::inspect($us, null, -240)['signals'])->toBe(['timezone_country_mismatch']);

        $this->travelTo('2026-07-15 12:00:00');
        expect(ProxyCheck::inspect($us, null, -240)['signals'])->toBe([])
            ->and(ProxyCheck::inspect($us, null, -300)['signals'])->toBe([]);
    });

    it('ignores an offset no place on Earth has', function (int $offset) {
        expect(ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'SA']), null, $offset)['signals'])->toBe([]);
    })->with(['too far east' => 841, 'too far west' => -841, 'silly' => 99999]);

    it('is decided by the zone when a zone is reported too, even one that names no place', function () {
        $sa = proxyCheckRequest(['CF-IPCountry' => 'SA']);

        // A real zone of the country wins over an offset that disagrees...
        expect(ProxyCheck::inspect($sa, 'Asia/Riyadh', 0)['signals'])->toBe([]);
        // ...a real zone of another country wins over an offset that agrees...
        expect(ProxyCheck::inspect($sa, 'Asia/Dubai', 180)['signals'])->toBe(['timezone_country_mismatch']);
        // ...and a browser that hides its zone as UTC is not made a mismatch by the offset it sends with it.
        expect(ProxyCheck::inspect($sa, 'UTC', 0)['signals'])->toBe([]);
    });
});

describe('the verdict from several signals', function () {
    it('is detected with two signals, listed in a fixed order', function () {
        $request = proxyCheckRequest([
            'CF-IPCountry' => 'SA',
            'X-Forwarded-For' => '203.0.113.9, 198.51.100.7, 192.0.2.44',
            'Via' => '1.1 proxy.example.net',
        ]);

        $result = ProxyCheck::inspect($request, 'Europe/Paris', null);

        expect($result)->toBe([
            'verdict' => 'detected',
            'signals' => ['via_header', 'forwarded_chain', 'timezone_country_mismatch'],
            'ip_country' => 'SA',
        ]);
    });

    it('is detected when a weak mismatch comes with a strong signal', function () {
        $result = ProxyCheck::inspect(proxyCheckRequest(['CF-IPCountry' => 'QA', 'Via' => 'HTTP/1.0 squid']), 'Asia/Riyadh', null);

        expect($result)->toBe(['verdict' => 'detected', 'signals' => ['via_header', 'timezone_country_mismatch'], 'ip_country' => 'QA']);
    });

    it('puts Tor first, and Tor has no country so no mismatch can come with it', function () {
        $request = proxyCheckRequest(['CF-IPCountry' => 'T1', 'Via' => '1.1 exit.example.net']);

        expect(ProxyCheck::inspect($request, 'Asia/Dubai', null))
            ->toBe(['verdict' => 'detected', 'signals' => ['tor', 'via_header'], 'ip_country' => null]);
    });
});
