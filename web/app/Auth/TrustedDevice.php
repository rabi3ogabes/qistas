<?php

namespace App\Auth;

use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie as Cookies;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * A browser a person chose to trust after typing a correct code: for 30 days, signing in there asks only for the
 * password. The cookie holds an expiry and a signature over the person, that expiry, their password hash, their
 * two-factor secret and the last "forget trusted devices", so a new password, a new secret or that button ends the
 * trust everywhere at once. The cookie is also encrypted, like every cookie of the site.
 */
final class TrustedDevice
{
    public const DAYS = 30;

    /** One cookie per person, so two people sharing a browser never borrow each other's trust. */
    public static function cookieName(User $user): string
    {
        return 'qistas_device_'.substr(hash_hmac('sha256', (string) $user->getKey(), self::key()), 0, 16);
    }

    /** The cookie that remembers this browser for the given person, from now for DAYS days. */
    public function cookieFor(User $user): Cookie
    {
        $expires = now()->addDays(self::DAYS)->getTimestamp();

        return Cookies::make(self::cookieName($user), $expires.'.'.$this->sign($user, $expires), self::DAYS * 24 * 60, httpOnly: true, sameSite: 'lax');
    }

    /** Remembers the browser of this request and records it. */
    public function trust(User $user): void
    {
        Cookies::queue($this->cookieFor($user));
        Audit::record('two_factor.device_trusted', userId: $user->id);
    }

    public function trusts(Request $request, User $user): bool
    {
        return $this->trustedUntil($request, $user) !== null;
    }

    /** Until when this browser skips the code for the person; null when it does not. */
    public function trustedUntil(Request $request, User $user): ?CarbonImmutable
    {
        if (! $user->hasConfirmedTwoFactor()) {
            return null;
        }

        $parts = explode('.', (string) $request->cookie(self::cookieName($user)), 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0])) {
            return null;
        }

        $expires = (int) $parts[0];
        if ($expires <= now()->getTimestamp() || ! hash_equals($this->sign($user, $expires), $parts[1])) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($expires, config('app.timezone'));
    }

    /** Every browser the person trusted asks for the code again; this one included. */
    public function forgetAll(User $user): void
    {
        $user->forceFill(['trusted_devices_reset_at' => now()])->save();
        Cookies::queue(Cookies::forget(self::cookieName($user)));
        Audit::record('two_factor.devices_forgotten', userId: $user->id);
    }

    private function sign(User $user, int $expires): string
    {
        return hash_hmac('sha256', implode('|', [
            $user->getKey(),
            $expires,
            $user->getAuthPassword(),
            (string) $user->getRawOriginal('two_factor_secret'),
            (string) $user->trusted_devices_reset_at?->getTimestamp(),
        ]), self::key());
    }

    private static function key(): string
    {
        return 'trusted-device|'.config('app.key');
    }
}
