<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The first look at a report from a browser or a phone (design section 9), before anything is read from it. Both
 * reporters take reports from anyone, so what is checked here is only what can be checked from the headers and the size,
 * and a refusal is the status and nothing else: no body, no words, so a stranger learns nothing about why.
 *
 *   site (the website's scripts): the Origin, when the browser sends one, must be this site's own (403); the body at most
 *   8 KB (413); and its content type must be application/json itself (415). A missing Origin is let through: a
 *   same-origin beacon sends it, but some privacy settings strip it, and a page's error is no less true without it.
 *   app (the phone): the request must not come from a web browser (403): a browser always says where from, on a
 *   cross-site post, with an Origin or a Sec-Fetch-Site header, and the app's HTTP libraries send neither, so a page of
 *   another site cannot use a visitor's browser to post here (a plain form or a no-cors fetch would pass any other test:
 *   it needs no preflight, and the phone's endpoint takes any content type). Then the body at most 16 KB (413).
 *
 * Used as `GuardClientReport::class.':'.GuardClientReport::SITE` (or `::APP`), BEFORE the throttle: a refusal costs the
 * database nothing and is not counted against the address. Anything that is not one of the two is held to the strictest rules.
 */
final class GuardClientReport
{
    public const SITE = 'site';

    public const APP = 'app';

    /** The most a body may be, in bytes. */
    private const LIMITS = [self::SITE => 8192, self::APP => 16384];

    public function handle(Request $request, Closure $next, string $client = self::SITE): Response
    {
        $site = $client !== self::APP;

        if ($site ? ! $this->ownOrigin($request) : $this->fromBrowser($request)) {
            return $this->refuse(403);
        }

        if ($this->tooBig($request, self::LIMITS[$client] ?? self::LIMITS[self::SITE])) {
            return $this->refuse(413);
        }

        if ($site && ! $this->isJson($request)) {
            return $this->refuse(415);
        }

        return $next($request);
    }

    /** Did a web browser send this? It says where from: an app's HTTP library does not. */
    private function fromBrowser(Request $request): bool
    {
        return $request->headers->has('Origin') || $request->headers->has('Sec-Fetch-Site');
    }

    /**
     * Is the Origin this site's own, or none? Only the host is compared: the scheme can be hidden by a proxy that ends
     * HTTPS (the request then says http while the browser says https), and the port is the same site for every purpose
     * that matters here. `null` (a sandboxed page), a user name in front of a foreign host, and anything that is not an
     * address are all foreign.
     */
    private function ownOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null) {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        return in_array(self::host($host), $this->ownHosts($request), true);
    }

    /** @return list<string> */
    private function ownHosts(Request $request): array
    {
        $hosts = [];

        try {
            $hosts[] = self::host($request->getHost());
        } catch (Throwable) {
            // a host the framework refuses is not ours
        }

        $configured = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($configured) && $configured !== '') {
            $hosts[] = self::host($configured);
        }

        return $hosts;
    }

    private static function host(string $host): string
    {
        return rtrim(strtolower($host), '.');
    }

    /**
     * Is the content type application/json? The media type itself is compared (the part before any `;`, in any case):
     * `application/jsonp`, `text/json` or `text/plain; a=/json` merely have "json" in them.
     */
    private function isJson(Request $request): bool
    {
        $type = $request->headers->get('Content-Type');

        return is_string($type) && strtolower(trim(explode(';', $type, 2)[0])) === 'application/json';
    }

    /** Is the body more than $limit bytes? The length it says it has is believed only when it says too much. */
    private function tooBig(Request $request, int $limit): bool
    {
        $declared = $request->headers->get('Content-Length');

        if (is_string($declared) && ctype_digit($declared) && (int) $declared > $limit) {
            return true;
        }

        return strlen($request->getContent()) > $limit;
    }

    private function refuse(int $status): Response
    {
        return response('', $status);
    }
}
