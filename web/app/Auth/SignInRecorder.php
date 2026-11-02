<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Writes one line of a person's sign-in history: who, how, from what device and connection, and whether a proxy shows.
 * It is told about a sign-in or a wrong password by the code that decides which it was; it decides nothing about
 * the sign-in itself, and it throws whatever the database throws (the caller decides that a record that cannot be
 * written must never lock anyone out).
 *
 * What it reads from the request, besides the connection and the user agent: the inputs `timezone` (an IANA name) and
 * `utc_offset` (minutes, east positive) the browser or phone reports, and `device` (an object) the phone app reports
 * about itself. They come from a client, so a value of the wrong type is ignored, never an error. Nothing the person
 * typed (e-mail, phone, password, code) is read, so none of it can be stored.
 */
final class SignInRecorder
{
    /** A wrong password for the same account from the same address is recorded once in this many seconds. */
    private const FAILED_WINDOW_SECONDS = 60;

    public function success(User $user, Request $request, string $method = 'password', string $channel = 'web'): SignInEvent
    {
        return $this->write($user, $request, self::method($method), self::channel($channel), 'success');
    }

    /**
     * A wrong password for an account that exists. Null, and nothing written, when this account already has a failed
     * record from this address within the last minute: a stranger guessing at someone's account cannot fill their
     * history. (The check is the table itself, so it needs no cache and is the same on every driver. Two guesses at the
     * very same instant can both get in; that is a row too many, never a lockout.)
     */
    public function failed(User $user, Request $request, string $method = 'password', string $channel = 'web'): ?SignInEvent
    {
        $method = self::method($method);
        $channel = self::channel($channel);

        $recent = SignInEvent::query()
            ->where('user_id', $user->id)
            ->where('outcome', 'failed')
            ->where('ip', self::ip($request))
            ->where('created_at', '>=', now()->subSeconds(self::FAILED_WINDOW_SECONDS))
            ->exists();

        return $recent ? null : $this->write($user, $request, $method, $channel, 'failed');
    }

    private function write(User $user, Request $request, string $method, string $channel, string $outcome): SignInEvent
    {
        $zone = $request->input('timezone');
        $device = $request->input('device');

        $profile = DeviceProfile::describe($request, $channel, is_array($device) ? $device : null);
        $proxy = ProxyCheck::inspect($request, is_string($zone) ? $zone : null, self::offset($request->input('utc_offset')));

        return SignInEvent::query()->create([
            'user_id' => $user->id,
            'method' => $method,
            'channel' => $channel,
            'outcome' => $outcome,
            'device_type' => $profile['device_type'],
            'os' => $profile['os'],
            'browser' => $profile['browser'],
            'app_version' => $profile['app_version'],
            'device_name' => $profile['device_name'],
            'ip' => self::ip($request),
            'ip_country' => $proxy['ip_country'],
            'proxy' => $proxy['verdict'],
            'proxy_signals' => $proxy['signals'],
            'user_agent' => $profile['user_agent'],
        ]);
    }

    /** How the person proved who they are: a short lower-case word chosen by the calling code (the column holds 16). */
    private static function method(string $method): string
    {
        if (preg_match('/\A[a-z0-9_]{1,16}\z/', $method) !== 1) {
            throw new InvalidArgumentException('A sign-in method is a short lower-case word.');
        }

        return $method;
    }

    private static function channel(string $channel): string
    {
        if (! in_array($channel, ['web', 'app'], true)) {
            throw new InvalidArgumentException("A sign-in channel is web or app, not [{$channel}].");
        }

        return $channel;
    }

    /** The address of the connection as the app sees it (behind the CDN too); 0.0.0.0 when there is none, as in a console. */
    private static function ip(Request $request): string
    {
        return mb_substr($request->ip() ?? '0.0.0.0', 0, 45);
    }

    /** The offset a client reported, if it is a whole number (a number, or the digits of one a form carries); anything else is nothing. */
    private static function offset(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/\A[+-]?\d{1,5}\z/', $value) === 1 ? (int) $value : null;
    }
}
