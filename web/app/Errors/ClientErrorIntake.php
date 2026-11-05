<?php

declare(strict_types=1);

namespace App\Errors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The one door for what a browser or a phone tells us went wrong (design section 9). Both reporters are untrusted: any
 * visitor can post to them. So what comes in here is already validated (the controllers do that), and this class decides
 * the rest, and the sender decides none of it:
 *
 *   - Only two things can come in: a browser's script error (source `site`, kind `script`, platform `web`) and a
 *     phone's crash (source `app`, kind `crash`, platform `android` or `ios`). Anything else, whatever the sender claimed, is
 *     turned away, so a report can never be passed off as a server exception or a database failure (and so can never be
 *     taken for something that sends the instant e-mail).
 *   - It reads only the fields it knows (class, message, trace, route, version, device). A status, a method, a time, a
 *     fingerprint or anything else in the data is not read. The sender's address, browser and cookies never get here, except
 *     as the opaque key $sender (see below).
 *   - The phone's `phone` is stored as `mobile`; `tablet` stays `tablet`.
 *   - The person is the user id the controller read from the app token, or none. The recorder turns it into a short HMAC
 *     and counts it; the id itself is never stored.
 *   - The route is never the page's own word for it. A page's address can hold a secret (the code in /verify/K7MPQ2RTXW opens
 *     a document to anyone who has it), and no rule can tell every such code from a word, so after the answer the path is
 *     matched against the router's own routes and what is stored is the route's pattern (`/verify/{code}`). A path the
 *     script has made a pattern of already (it has a brace: `/app/customers/{id}`) and a path no route serves become the
 *     pattern of the path (numbers and ids hidden); anything that is not a plain path as sent (no leading slash, markup, a
 *     script address) becomes none, and a segment that is markup once its percent escapes are read becomes `{x}`
 *     (ErrorScrubber::route, the one rule for every route).
 *   - $sender is an opaque key for who sent the report (ClientSender::quotaKey: the person on a phone's app token, else the
 *     address): the recorder uses it to hold one sender to a few new issues an hour. It is used for that and nothing else.
 *
 * Recording runs after the answer (ErrorCapture::guarded) and swallows every Throwable, and so does this method: a
 * reporter is never given an error back (ruling R1). The recorder already puts a report in the spool when the database is
 * away; a report over a budget or a sender's share is counted as dropped (ErrorRecorder, spec 8).
 */
final class ClientErrorIntake
{
    /**
     * What a route may be made of (the part before any query string or fragment): the characters of a URL path, and the
     * braces of a pattern. No space, quote, angle bracket, backtick or backslash; so no markup.
     */
    private const ROUTE = '#^/[A-Za-z0-9/_.\-~%!$&\'()*+,;=:@{}]*$#D';

    /** What each client source may report, and from which platforms. */
    private const CLIENTS = [
        'site' => ['kind' => 'script', 'platforms' => ['web']],
        'app' => ['kind' => 'crash', 'platforms' => ['android', 'ios']],
    ];

    /** What the phone calls its sizes, and what the error center calls them. */
    private const DEVICES = ['phone' => 'mobile', 'tablet' => 'tablet'];

    /** The verbs a path is asked about: a page's script error is a GET's, but a path is a path. */
    private const VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param  array<string, mixed>  $data  the validated report: class, message, trace, version, route, device
     * @param  string|null  $userId  the id of the signed-in person on the app's token, if there is one
     * @param  string|null  $sender  who sent it, as an opaque key (ClientSender)
     */
    public function accept(string $source, string $kind, array $data, ?string $userId, string $platform, ?string $sender = null): void
    {
        try {
            $client = self::CLIENTS[$source] ?? null;

            if ($client === null || $client['kind'] !== $kind || ! in_array($platform, $client['platforms'], true)) {
                return;
            }

            $at = now()->toIso8601String();
            $route = self::text($data, 'route');
            $fields = [
                'class' => self::text($data, 'class') ?? '',
                'message' => self::text($data, 'message') ?? '',
                'trace' => self::text($data, 'trace') ?? '',
                'version' => self::text($data, 'version'),
                'device' => self::DEVICES[self::text($data, 'device') ?? ''] ?? null,
            ];
            $userId = $userId === null || $userId === '' ? null : $userId;

            // The path is matched against the routes after the answer, with the rest of the work.
            ErrorCapture::guarded(static function () use ($source, $kind, $platform, $fields, $route, $userId, $sender, $at): void {
                $event = new ErrorEvent(
                    source: $source,
                    kind: $kind,
                    class: $fields['class'],
                    message: $fields['message'],
                    trace: $fields['trace'],
                    route: self::routeOf($route),
                    version: $fields['version'],
                    platform: $platform,
                    device: $fields['device'],
                    userId: $userId,
                    at: $at,
                );

                app(ErrorRecorder::class)->record($event, $sender);
            });
        } catch (Throwable) {
            // A reporter is answered whatever happens here: there is nobody to tell and nothing to report it with.
        }
    }

    /**
     * The pattern of the route a page's path belongs to (`/verify/K7MPQ2RTXW` is `/verify/{code}`), else the pattern of the
     * path itself, else none. Never the text it was given.
     */
    private static function routeOf(?string $given): ?string
    {
        if ($given === null) {
            return null;
        }

        $path = (string) preg_replace('~[?#].*$~s', '', $given);

        if (preg_match(self::ROUTE, $path) !== 1) {
            return null;
        }

        // A brace means the script has made a pattern of the page's address already (it hides ids as {id}): asking the
        // router again would cost a few milliseconds for every report and could only rename the parameter.
        if (str_contains($path, '{')) {
            return ErrorFingerprint::routePattern($path);
        }

        try {
            return self::matched($path) ?? ErrorFingerprint::routePattern($path);
        } catch (Throwable) {
            return ErrorFingerprint::routePattern($path);
        }
    }

    /**
     * The pattern of the route that serves $path, or null when none does. The fallback route answers every unknown GET, so a
     * path that only a POST (or another verb) serves is found by asking again with the next verb; the first real route wins.
     */
    private static function matched(string $path): ?string
    {
        foreach (self::VERBS as $verb) {
            try {
                $route = Route::getRoutes()->match(Request::create('http://localhost'.$path, $verb));
            } catch (Throwable) {
                continue; // not found, or known for another verb only
            }

            if (! $route->isFallback) {
                return '/'.ltrim($route->uri(), '/');
            }
        }

        return null;
    }

    /**
     * The field as text, or null when it is missing, empty or not text.
     *
     * @param  array<string, mixed>  $data
     */
    private static function text(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
