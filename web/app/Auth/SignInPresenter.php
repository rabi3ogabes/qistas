<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\SignInEvent;
use App\Support\Countries;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * One line of a person's sign-in history, in words: what signed in, the proxy verdict and what was seen, and when. The
 * security page and the admin business page show the same rows, so the wording lives here once (the markup is the
 * `x-sign-in-row` component, which only lays this out). It is the only place that turns the stored codes (`mobile`,
 * `possible`, `via_header`) into sentences, and it never returns the user agent, which is stored for support and shown
 * to no one. The caller escapes everything it prints.
 *
 * The verdict is worded as the spec requires: "Proxy not detected", "Proxy possible", "Proxy detected", never "certain".
 */
final class SignInPresenter
{
    /** What separates the parts of a line. Not a comma, so it reads the same in every language. */
    private const JOIN = ' · ';

    /** @var array<string, int>|null the names of the time zones PHP knows, as keys (built once, not for each row) */
    private static ?array $zones = null;

    /** @var array<string, string> a zone's name as people say it, by language and zone (built once for each, not for each row) */
    private static array $zoneNames = [];

    /**
     * @param  string  $zone  the IANA time zone the exact time and the day are written in (an unknown name falls back to UTC)
     * @return array{failed: bool, device: string, verdict: string, level: 'quiet'|'noted', hint: ?string, ago: string, day: ?string, exact: string, iso: string, ip: string}
     */
    public static function present(SignInEvent $event, string $zone = 'UTC'): array
    {
        $zone = self::zone($zone);
        $moment = CarbonImmutable::instance($event->created_at);
        $local = $moment->setTimezone($zone);

        return [
            'failed' => $event->outcome === 'failed',
            'device' => self::device($event),
            'verdict' => self::verdict($event->proxy),
            'level' => in_array($event->proxy, ['possible', 'detected'], true) ? 'noted' : 'quiet',
            'hint' => self::hint($event),
            'ago' => $moment->diffForHumans(),
            // "5 days ago" is vague; from the second day on, the day is printed beside it (the exact time stays in the title).
            'day' => $moment->lessThan(CarbonImmutable::now()->subDay()) ? $local->translatedFormat('j M Y') : null,
            'exact' => $local->translatedFormat('j M Y, H:i').' ('.self::zoneName($zone).')',
            'iso' => $moment->toIso8601String(),
            'ip' => $event->ip,
        ];
    }

    /** [$name] if PHP knows it as a time zone, else UTC. */
    private static function zone(string $name): string
    {
        self::$zones ??= array_flip(DateTimeZone::listIdentifiers());

        return isset(self::$zones[$name]) ? $name : 'UTC';
    }

    /** "Saudi Arabia Time" for Asia/Riyadh, in the language of the page. Only known zones come here, so the memo stays small. */
    private static function zoneName(string $zone): string
    {
        return self::$zoneNames[app()->getLocale().'|'.$zone] ??= Countries::timeZoneName($zone);
    }

    /**
     * The web: "Mobile · Chrome on Android · Qatar". The phone app: "Pixel 8 · Android · App 1.29.0 · Qatar", where the
     * phone's own name stands in for the device word when it told us one. A part that is not known is left out.
     */
    private static function device(SignInEvent $event): string
    {
        $kind = match ($event->device_type) {
            'desktop' => __('Desktop'),
            'mobile' => __('Mobile'),
            'tablet' => __('Tablet'),
            default => __('Unknown device'),
        };

        $os = self::text($event->os);
        $browser = self::text($event->browser);

        if ($event->channel === 'app') {
            $version = self::text($event->app_version);
            $parts = [self::text($event->device_name) ?? $kind, $os, $version === null ? __('App') : __('App :version', ['version' => $version])];
        } else {
            $parts = [$kind, match (true) {
                $browser !== null && $os !== null => __(':browser on :system', ['browser' => $browser, 'system' => $os]),
                default => $browser ?? $os,
            }];
        }

        $parts[] = Countries::name($event->ip_country);

        return implode(self::JOIN, array_filter($parts, fn (?string $part) => $part !== null));
    }

    private static function verdict(string $proxy): string
    {
        return match ($proxy) {
            'detected' => __('Proxy detected'),
            'possible' => __('Proxy possible'),
            default => __('Proxy not detected'),
        };
    }

    /** What was seen, in plain words, for a row whose verdict is "possible" or "detected"; nothing for any other row. */
    private static function hint(SignInEvent $event): ?string
    {
        if (! in_array($event->proxy, ['possible', 'detected'], true)) {
            return null;
        }

        $seen = [];
        foreach ($event->proxy_signals as $signal) {
            $words = match ($signal) {
                'tor' => __('the Tor network'),
                'via_header' => __('a proxy announcing itself'),
                'forwarded_chain' => __('more forwarding steps than usual'),
                'timezone_country_mismatch' => __('the device’s time zone does not match the connection’s country'),
                default => null,
            };
            if ($words !== null) {
                $seen[] = $words;
            }
        }

        return $seen === [] ? null : __('What we noticed: :signals', ['signals' => implode(self::JOIN, array_unique($seen))]);
    }

    /** A stored name, or null for one that is empty. */
    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
