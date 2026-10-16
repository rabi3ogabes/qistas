<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/** Tells crawlers to read the public site and leave the signed-in areas alone. */
final class RobotsController
{
    private const PRIVATE_PATHS = ['/app', '/admin', '/api', '/account', '/user/', '/login', '/register', '/forgot-password', '/reset-password', '/two-factor-challenge'];

    public function __invoke(): Response
    {
        $lines = ['User-agent: *', ...array_map(fn (string $path) => "Disallow: {$path}", self::PRIVATE_PATHS), '', 'Sitemap: '.url('/sitemap.xml'), ''];

        // The same for everyone and changed only by a release: kept for an hour by browsers and the CDN.
        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
