<?php

use App\Auth\DeviceProfile;
use Illuminate\Http\Request;

/** A request that carries only this user agent (an empty one really is empty: Request::create would invent "Symfony"). */
function deviceProfileRequest(string $userAgent): Request
{
    return Request::create('/login', 'POST', [], [], [], ['HTTP_USER_AGENT' => $userAgent]);
}

// Real user agents, as the browsers send them. What the library makes of each is pinned by composer.lock.
const DEVICE_PROFILE_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const DEVICE_PROFILE_ANDROID_PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
const DEVICE_PROFILE_ANDROID_TABLET = 'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const DEVICE_PROFILE_IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
// An iPad in "desktop website" mode sends exactly what Safari on a Mac sends: the two cannot be told apart.
const DEVICE_PROFILE_MAC_SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
const DEVICE_PROFILE_WINDOWS_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const DEVICE_PROFILE_LINUX_FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0';
const DEVICE_PROFILE_SAMSUNG_INTERNET = 'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36';
const DEVICE_PROFILE_PHABLET = 'Mozilla/5.0 (Linux; Android 9; SM-N960F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
const DEVICE_PROFILE_KAIOS = 'Mozilla/5.0 (Mobile; Nokia 8110 4G; rv:48.0) Gecko/48.0 Firefox/48.0 KAIOS/2.5';
const DEVICE_PROFILE_GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const DEVICE_PROFILE_SMART_TV = 'Mozilla/5.0 (SMART-TV; LINUX; Tizen 6.5) AppleWebKit/537.36 (KHTML, like Gecko) 85.0.4183.93/6.5 TV Safari/537.36';
const DEVICE_PROFILE_PLAYSTATION = 'Mozilla/5.0 (PlayStation 5 3.00) AppleWebKit/605.1.15 (KHTML, like Gecko)';
const DEVICE_PROFILE_CAR = 'Mozilla/5.0 (X11; GNU/Linux) AppleWebKit/601.1 (KHTML, like Gecko) Tesla QtCarBrowser Safari/601.1';
const DEVICE_PROFILE_DART = 'Dart/3.5 (dart:io)';

dataset('sign-in device user agents', [
    'iPhone Safari' => [DEVICE_PROFILE_IPHONE, 'mobile', 'iOS', 'Mobile Safari'],
    'Android Chrome phone' => [DEVICE_PROFILE_ANDROID_PHONE, 'mobile', 'Android', 'Chrome Mobile'],
    'Android tablet (Chrome without the Mobile token)' => [DEVICE_PROFILE_ANDROID_TABLET, 'tablet', 'Android', 'Chrome'],
    'iPad Safari on its mobile user agent' => [DEVICE_PROFILE_IPAD, 'tablet', 'iPadOS', 'Mobile Safari'],
    'Safari on a Mac' => [DEVICE_PROFILE_MAC_SAFARI, 'desktop', 'Mac', 'Safari'],
    'Chrome on Windows' => [DEVICE_PROFILE_WINDOWS_CHROME, 'desktop', 'Windows', 'Chrome'],
    'Firefox on Linux' => [DEVICE_PROFILE_LINUX_FIREFOX, 'desktop', 'GNU/Linux', 'Firefox'],
    'Samsung Internet' => [DEVICE_PROFILE_SAMSUNG_INTERNET, 'mobile', 'Android', 'Samsung Browser'],
    'a phablet' => [DEVICE_PROFILE_PHABLET, 'mobile', 'Android', 'Chrome Mobile'],
    'a feature phone' => [DEVICE_PROFILE_KAIOS, 'mobile', 'KaiOS', 'Firefox Mobile'],
]);

dataset('sign-in user agents that are not a person at a screen', [
    'a search bot' => DEVICE_PROFILE_GOOGLEBOT,
    'a smart TV' => DEVICE_PROFILE_SMART_TV,
    'a game console' => DEVICE_PROFILE_PLAYSTATION,
    'a car browser' => DEVICE_PROFILE_CAR,
    'an HTTP library' => DEVICE_PROFILE_DART,
    'an unparsable string' => '!!!',
    'an empty user agent' => '',
]);

describe('on the web', function () {
    it('reads the device type, system and browser from real user agents', function (string $userAgent, string $type, string $os, string $browser) {
        $profile = DeviceProfile::describe(deviceProfileRequest($userAgent), 'web');

        expect($profile)->toBe([
            'device_type' => $type,
            'os' => $os,
            'browser' => $browser,
            'app_version' => null,
            'device_name' => null,
            'user_agent' => $userAgent,
        ]);
    })->with('sign-in device user agents');

    it('records anything that is not a person at a screen as unknown', function (string $userAgent) {
        expect(DeviceProfile::describe(deviceProfileRequest($userAgent), 'web')['device_type'])->toBe('unknown');
    })->with('sign-in user agents that are not a person at a screen');

    it('gives a bot no system and no browser, and an empty user agent nothing at all', function () {
        expect(DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_GOOGLEBOT), 'web'))->toMatchArray([
            'device_type' => 'unknown', 'os' => null, 'browser' => null,
        ]);

        expect(DeviceProfile::describe(deviceProfileRequest(''), 'web'))->toBe([
            'device_type' => 'unknown', 'os' => null, 'browser' => null,
            'app_version' => null, 'device_name' => null, 'user_agent' => null,
        ]);
    });

    it('records an iPad in desktop mode as a desktop: a documented limit, since it sends the same as Safari on a Mac', function () {
        // If this ever starts to read "tablet", the library has learned to tell them apart and the screens can say so.
        expect(DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_MAC_SAFARI), 'web')['device_type'])->toBe('desktop');
    });

    it('cuts the user agent to 255 characters, on a character and not in the middle of one', function () {
        $long = DEVICE_PROFILE_WINDOWS_CHROME.' '.str_repeat('é', 400);

        $stored = DeviceProfile::describe(deviceProfileRequest($long), 'web')['user_agent'];

        expect(mb_strlen((string) $stored))->toBe(255)
            ->and(mb_check_encoding((string) $stored, 'UTF-8'))->toBeTrue()
            ->and($stored)->toStartWith(DEVICE_PROFILE_WINDOWS_CHROME);
    });

    it('keeps a user agent that is not valid UTF-8, or carries a NUL, storable', function () {
        $stored = DeviceProfile::describe(deviceProfileRequest("Mozilla/5.0 \xC3\x28 bad\0byte"), 'web')['user_agent'];

        expect(mb_check_encoding((string) $stored, 'UTF-8'))->toBeTrue()
            ->and((string) $stored)->not->toContain("\0")
            ->and((string) $stored)->toContain('Mozilla/5.0');
    });

    it('ignores what the phone app says about itself when the sign-in is not the app', function () {
        $profile = DeviceProfile::describe(
            deviceProfileRequest(DEVICE_PROFILE_WINDOWS_CHROME),
            'web',
            ['type' => 'tablet', 'os' => 'android', 'model' => 'SM-X200', 'app_version' => '1.29.0'],
        );

        expect($profile)->toMatchArray([
            'device_type' => 'desktop', 'os' => 'Windows', 'app_version' => null, 'device_name' => null,
        ]);
    });
});

describe('from the phone app', function () {
    it('takes the device class, system, model and version the app reports', function () {
        $profile = DeviceProfile::describe(
            deviceProfileRequest(DEVICE_PROFILE_DART),
            'app',
            ['type' => 'tablet', 'os' => 'android', 'model' => 'SM-X200', 'app_version' => '1.29.0'],
        );

        expect($profile)->toBe([
            'device_type' => 'tablet',
            'os' => 'android',
            'browser' => null,
            'app_version' => '1.29.0',
            'device_name' => 'SM-X200',
            'user_agent' => DEVICE_PROFILE_DART,
        ]);
    });

    it('lets the reported class win over what the user agent says', function () {
        $profile = DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_IPHONE), 'app', ['type' => 'tablet']);

        expect($profile['device_type'])->toBe('tablet');
    });

    it('ignores a type that is neither mobile nor tablet and reads the user agent instead', function (mixed $type) {
        $profile = DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_ANDROID_TABLET), 'app', [
            'type' => $type, 'os' => 'android', 'model' => 'SM-X200', 'app_version' => '1.29.0',
        ]);

        // The user agent says tablet; the rest of the hint is still good and is kept.
        expect($profile)->toMatchArray([
            'device_type' => 'tablet', 'os' => 'android', 'device_name' => 'SM-X200', 'app_version' => '1.29.0',
        ]);

        $phone = DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_IPHONE), 'app', ['type' => $type]);

        expect($phone['device_type'])->toBe('mobile');
    })->with(['a made-up class' => 'watch', 'desktop' => 'desktop', 'a number' => 3, 'a list' => [['tablet']], 'empty' => '', 'null' => null, 'blank' => '   ']);

    it('accepts the class in any case and with spaces', function () {
        $profile = DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_DART), 'app', ['type' => '  Tablet ']);

        expect($profile['device_type'])->toBe('tablet');
    });

    it('trims every value and keeps it short: system 64, model 100, version 16', function () {
        $profile = DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_DART), 'app', [
            'type' => 'mobile',
            'os' => '  '.str_repeat('o', 100),
            'model' => str_repeat('m', 200).'  ',
            'app_version' => '  '.str_repeat('1', 40),
        ]);

        expect($profile['os'])->toBe(str_repeat('o', 64))
            ->and($profile['device_name'])->toBe(str_repeat('m', 100))
            ->and($profile['app_version'])->toBe(str_repeat('1', 16));

        $trimmed = DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_DART), 'app', ['os' => '  android  ', 'app_version' => " 1.29.0\n"]);

        expect($trimmed['os'])->toBe('android')->and($trimmed['app_version'])->toBe('1.29.0');
    });

    it('cuts on a character, not in the middle of one', function () {
        $profile = DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_DART), 'app', ['model' => str_repeat('é', 150)]);

        expect(mb_strlen((string) $profile['device_name']))->toBe(100)
            ->and(mb_check_encoding((string) $profile['device_name'], 'UTF-8'))->toBeTrue();
    });

    it('ignores values of the wrong type or empty, and falls back to the user agent', function () {
        $profile = DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_ANDROID_PHONE), 'app', [
            'type' => ['mobile'], 'os' => ['android'], 'model' => 42, 'app_version' => true,
        ]);

        expect($profile)->toMatchArray([
            'device_type' => 'mobile', 'os' => 'Android', 'device_name' => null, 'app_version' => null,
        ]);

        $blank = DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_ANDROID_PHONE), 'app', ['os' => '   ', 'model' => '', 'app_version' => "\t"]);

        expect($blank)->toMatchArray(['os' => 'Android', 'device_name' => null, 'app_version' => null]);
    });

    it('works without any hint, from the user agent alone', function () {
        foreach ([null, []] as $reported) {
            expect(DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_DART), 'app', $reported))->toMatchArray([
                'device_type' => 'unknown', 'os' => null, 'browser' => null, 'app_version' => null, 'device_name' => null,
            ]);
        }
    });

    it('drops control characters from what it keeps', function () {
        $profile = DeviceProfile::describe(deviceProfileRequest(DEVICE_PROFILE_DART), 'app', ['model' => "SM-X200\0\x07", 'os' => "an\ndroid"]);

        expect($profile['device_name'])->toBe('SM-X200')->and($profile['os'])->toBe('android');
    });
});
