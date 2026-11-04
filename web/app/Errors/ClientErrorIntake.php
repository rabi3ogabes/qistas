<?php

declare(strict_types=1);

namespace App\Errors;

use Throwable;

/**
 * The one door for what a browser or a phone tells us went wrong (design section 9). Both reporters are untrusted: any
 * visitor can post to them. So what comes in here is already validated (the controllers do that), and this class decides
 * the rest, and the sender decides none of it:
 *
 *   - Only two things can come in: a browser's script error (source `site`, kind `script`, platform `web`) and a phone's
 *     crash (source `app`, kind `crash`, platform `android` or `ios`). Anything else, whatever the sender claimed, is
 *     turned away, so a report can never be passed off as a server exception or a database failure (and so can never be
 *     taken for something that sends the instant e-mail).
 *   - It reads only the fields it knows (class, message, trace, version, route, device). A status, a method, a time, a
 *     fingerprint or anything else in the data is not read. The sender's address, browser and cookies never get here.
 *   - The phone's `phone` is stored as `mobile`; `tablet` stays `tablet`.
 *   - The person is the user id the controller read from the app token, or none. The recorder turns it into a short HMAC
 *     and counts it; the id itself is never stored.
 *
 * Recording runs after the answer (ErrorCapture::guarded) and swallows every Throwable, and so does this method: a
 * reporter is never given an error back (ruling R1). The recorder already puts a report in the spool when the database is
 * away; a report over the budget of client reports is counted as dropped (ErrorRecorder, spec 8).
 */
final class ClientErrorIntake
{
    /** What each client source may report, and from which platforms. */
    private const CLIENTS = [
        'site' => ['kind' => 'script', 'platforms' => ['web']],
        'app' => ['kind' => 'crash', 'platforms' => ['android', 'ios']],
    ];

    /** What the phone calls its sizes, and what the error center calls them. */
    private const DEVICES = ['phone' => 'mobile', 'tablet' => 'tablet'];

    /**
     * @param  array<string, mixed>  $data  the validated report: class, message, trace, version, route, device
     * @param  string|null  $userId  the id of the signed-in person on the app's token, if there is one
     */
    public function accept(string $source, string $kind, array $data, ?string $userId, string $platform): void
    {
        try {
            $client = self::CLIENTS[$source] ?? null;

            if ($client === null || $client['kind'] !== $kind || ! in_array($platform, $client['platforms'], true)) {
                return;
            }

            $event = new ErrorEvent(
                source: $source,
                kind: $kind,
                class: self::text($data, 'class') ?? '',
                message: self::text($data, 'message') ?? '',
                trace: self::text($data, 'trace') ?? '',
                route: self::text($data, 'route'),
                version: self::text($data, 'version'),
                platform: $platform,
                device: self::DEVICES[self::text($data, 'device') ?? ''] ?? null,
                userId: $userId === null || $userId === '' ? null : $userId,
            );

            ErrorCapture::guarded(static function () use ($event): void {
                app(ErrorRecorder::class)->record($event);
            });
        } catch (Throwable) {
            // A reporter is answered whatever happens here: there is nobody to tell and nothing to report it with.
        }
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
