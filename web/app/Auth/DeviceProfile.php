<?php

declare(strict_types=1);

namespace App\Auth;

use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;

/**
 * What kind of device a sign-in came from, for the person's own history: the class (desktop, mobile, tablet), the
 * system, the browser, and for the phone app the version and model it reports. It reads the user agent with
 * matomo/device-detector, and on the app channel takes the phone's own account of itself as a hint that wins where it is
 * valid. Everything it returns is bounded to the width of its column and is safe to store (valid UTF-8, no control
 * characters); nothing here is trusted for anything but showing the person what signed in.
 *
 * Honest limit: an iPad in "desktop website" mode sends what Safari on a Mac sends, so it is recorded as a desktop.
 */
final class DeviceProfile
{
    private const OS_MAX = 64;

    private const BROWSER_MAX = 64;

    private const MODEL_MAX = 100;

    private const APP_VERSION_MAX = 16;

    private const USER_AGENT_MAX = 255;

    /** The most of a user agent the parser reads: real ones are far shorter, and a pasted wall of text costs time. */
    private const USER_AGENT_PARSED_MAX = 1024;

    /**
     * @param  array<string, mixed>|null  $reported  what the phone app says about itself (a hint): type (mobile or tablet), os, model, app_version.
     *                                               It arrives from a client, so every value is checked here; only the app channel reads it.
     * @return array{device_type: 'desktop'|'mobile'|'tablet'|'unknown', os: ?string, browser: ?string, app_version: ?string, device_name: ?string, user_agent: ?string}
     */
    public static function describe(Request $request, string $channel, ?array $reported = null): array
    {
        $userAgent = self::text($request->userAgent(), self::USER_AGENT_PARSED_MAX);
        [$type, $os, $browser] = self::parse($userAgent);

        $hint = $channel === 'app' ? ($reported ?? []) : [];

        // The classes the phone app may report for itself are mobile and tablet; anything else is ignored and the user agent decides.
        $reportedType = is_string($hint['type'] ?? null) ? strtolower(trim($hint['type'])) : null;

        return [
            'device_type' => match ($reportedType) {
                'mobile' => 'mobile',
                'tablet' => 'tablet',
                default => $type,
            },
            'os' => self::text($hint['os'] ?? null, self::OS_MAX) ?? $os,
            'browser' => $browser,
            'app_version' => self::text($hint['app_version'] ?? null, self::APP_VERSION_MAX),
            'device_name' => self::text($hint['model'] ?? null, self::MODEL_MAX),
            'user_agent' => self::text($userAgent, self::USER_AGENT_MAX),
        ];
    }

    /**
     * The class, system and browser the user agent names. A bot, a TV, a console, a car, a library, an empty or an
     * unparsable user agent is "unknown". Only a real browser is named as the browser (an HTTP library is not one).
     *
     * @return array{0: 'desktop'|'mobile'|'tablet'|'unknown', 1: ?string, 2: ?string}
     */
    private static function parse(?string $userAgent): array
    {
        if ($userAgent === null) {
            return ['unknown', null, null];
        }

        $detector = new DeviceDetector($userAgent);
        $detector->parse();

        if ($detector->isBot()) {
            return ['unknown', null, null];
        }

        $type = match ($detector->getDeviceName()) {
            'smartphone', 'feature phone', 'phablet' => 'mobile',
            'tablet' => 'tablet',
            'desktop' => 'desktop',
            default => 'unknown',
        };

        $os = $detector->getOs('name');
        $client = $detector->getClient('name');

        return [
            $type,
            $os === DeviceDetector::UNKNOWN ? null : self::text($os, self::OS_MAX),
            $detector->getClient('type') === 'browser' && $client !== DeviceDetector::UNKNOWN ? self::text($client, self::BROWSER_MAX) : null,
        ];
    }

    /**
     * Something a client sent, made safe to store and show: only text counts (anything else is null), invalid UTF-8 is
     * repaired, control and invisible formatting characters (a NUL a database refuses, a right-to-left override that
     * could disguise a name) are dropped, the ends are trimmed, and it is cut to $max characters. Empty is null.
     */
    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', mb_scrub($value));
        $clean = rtrim(mb_substr(trim($clean ?? ''), 0, $max));

        return $clean === '' ? null : $clean;
    }
}
