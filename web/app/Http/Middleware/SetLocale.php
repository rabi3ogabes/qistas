<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the language for this request. In order: an explicit ?lang= (remembered for next time), the
 * signed-in user's saved language, the session, the cookie, the browser's Accept-Language, the default.
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
            $locale = $this->fromUser($request)
                ?? $this->fromSession($request)
                ?? $this->fromCookie($request)
                ?? $this->fromBrowser($request)
                ?? config('app.locale');
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
