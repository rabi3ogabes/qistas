<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the language for this request. A language stays chosen: it changes only when the person changes it.
 *
 * In order: an explicit ?lang= (remembered for next time, in this browser and on the person's profile), then what
 * this browser already remembers (the cookie, then the session), then the signed-in person's saved language (a
 * browser with no choice yet takes it up and remembers it), then the browser's Accept-Language, then the default.
 * The browser's own memory comes before the profile because the profile is one value shared by every device: it
 * must not flip a page back to the language a person left behind when they signed up or last used another device.
 * Only languages the product ships are ever accepted, whatever a client sends.
 */
final class SetLocale
{
    public const COOKIE = 'qistas_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $explicit = $request->query('lang');

        if (Locale::isSupported($explicit)) {
            $this->remember($request, $explicit);
            $locale = $explicit;
        } else {
            $locale = $this->fromCookie($request) ?? $this->fromSession($request);

            if ($locale === null && ($locale = $this->fromUser($request)) !== null) {
                // First visit on this browser by someone who has a language on their profile: keep it from now on.
                Cookie::queue(self::COOKIE, $locale, 60 * 24 * 365);
            }

            $locale ??= $this->fromBrowser($request) ?? config('app.locale');
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function remember(Request $request, string $locale): void
    {
        $request->session()->put('locale', $locale);
        Cookie::queue(self::COOKIE, $locale, 60 * 24 * 365);

        $user = $request->user();
        if ($user !== null && $user->locale !== $locale) {
            $user->forceFill(['locale' => $locale])->save();
        }
    }

    private function fromUser(Request $request): ?string
    {
        $locale = $request->user()?->locale;

        return Locale::isSupported($locale) ? $locale : null;
    }

    private function fromSession(Request $request): ?string
    {
        $locale = $request->session()->get('locale');

        return Locale::isSupported($locale) ? $locale : null;
    }

    private function fromCookie(Request $request): ?string
    {
        $locale = $request->cookie(self::COOKIE);

        return Locale::isSupported($locale) ? $locale : null;
    }

    /** The first language in the browser's preference order that the product ships, if any. */
    private function fromBrowser(Request $request): ?string
    {
        foreach ($request->getLanguages() as $tag) {
            $primary = strtolower(strtok(str_replace('_', '-', $tag), '-') ?: '');

            if (Locale::isSupported($primary)) {
                return $primary;
            }
        }

        return null;
    }
}
