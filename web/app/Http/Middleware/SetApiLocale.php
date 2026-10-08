<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language of an API response: an explicit ?lang=, then the client's Accept-Language, then the signed-in
 * person's saved language, then the default. No session, no cookie: the API is stateless.
 */
final class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $explicit = $request->query('lang');

        $locale = match (true) {
            Locale::isSupported($explicit) => $explicit,
            ($fromHeader = $this->fromHeader($request)) !== null => $fromHeader,
            // The route's authentication has not run yet, so ask the token guard directly.
            Locale::isSupported($saved = $request->user('sanctum')?->locale) => $saved,
            default => config('app.locale'),
        };

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function fromHeader(Request $request): ?string
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
